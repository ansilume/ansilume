<?php

declare(strict_types=1);

namespace app\tests\unit\commands;

use app\commands\RunnerController;
use app\components\RunnerProjectSync;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RunnerController::syncProject() via a testable subclass; the
 * work happens in {@see RunnerProjectSync}.
 * Uses real filesystem git operations so git must be available in the test env.
 *
 * Regression: this class used to share RunnerControllerTest.php. PHPUnit 10
 * loads one test class per file, so none of these tests ran, and the SSH
 * option assertion went stale (accept-new replaced StrictHostKeyChecking=no).
 */
class RunnerControllerSyncProjectTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ansilume_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            exec('rm -rf ' . escapeshellarg($this->tmpDir));
        }
    }

    private function makeSyncableController(): RunnerController
    {
        return new class ('runner', \Yii::$app) extends RunnerController {
            /** Expose syncProject for testing. */
            public function callSyncProject(array $payload): ?string
            {
                return $this->syncProject($payload);
            }

            /**
             * Expose buildGitEnv for direct inspection so regression tests
             * can assert that GIT_SSH_COMMAND / credential helpers get
             * wired up when the payload carries a scm_credential.
             *
             * @param array{credential_type: string, username: string|null, env_var_name?: string|null, secrets: array<string, string>}|null $scmCredential
             * @return array<string, string>
             */
            public function callBuildGitEnv(string $scmUrl, ?array $scmCredential, ?string &$sshKeyFile = null): array
            {
                $reflection = new \ReflectionMethod(RunnerProjectSync::class, 'buildGitEnv');
                $reflection->setAccessible(true);
                /** @var array<string, string> $env */
                $env = $reflection->invokeArgs(new RunnerProjectSync($this), [$scmUrl, $scmCredential, &$sshKeyFile]);
                return $env;
            }

            public function stdout($string): int
            {
                return 0;
            }

            public function stderr($string): int
            {
                return 0;
            }
        };
    }

    private function makeLocalBareRepo(): string
    {
        $bare = $this->tmpDir . '/bare.git';
        mkdir($bare, 0755, true);
        exec('git init --bare ' . escapeshellarg($bare) . ' -q');

        // Create an initial commit via a temp clone.
        $work = $this->tmpDir . '/work';
        // The bare repository is still empty, which git warns about on stderr.
        exec('git clone --quiet ' . escapeshellarg($bare) . ' ' . escapeshellarg($work) . ' 2>/dev/null');
        file_put_contents($work . '/README.md', 'test');
        exec('git -C ' . escapeshellarg($work) . ' config user.email test@test.com');
        exec('git -C ' . escapeshellarg($work) . ' config user.name Test');
        exec('git -C ' . escapeshellarg($work) . ' add README.md');
        exec('git -C ' . escapeshellarg($work) . ' commit -q -m init');
        exec('git -C ' . escapeshellarg($work) . ' push -q origin HEAD:main');
        exec('rm -rf ' . escapeshellarg($work));

        return $bare;
    }

    public function testSkipsWhenScmTypeIsManual(): void
    {
        $ctrl = $this->makeSyncableController();
        $result = $ctrl->callSyncProject([
            'scm_type' => 'manual',
            'scm_url' => 'https://github.com/example/repo.git',
            'project_path' => '/some/path',
        ]);

        $this->assertNull($result);
    }

    public function testSkipsWhenScmUrlIsEmpty(): void
    {
        $ctrl = $this->makeSyncableController();
        $result = $ctrl->callSyncProject([
            'scm_type' => 'git',
            'scm_url' => '',
            'project_path' => '/some/path',
        ]);

        $this->assertNull($result);
    }

    public function testSkipsWhenProjectPathIsEmpty(): void
    {
        $ctrl = $this->makeSyncableController();
        $result = $ctrl->callSyncProject([
            'scm_type' => 'git',
            'scm_url' => 'https://github.com/example/repo.git',
            'project_path' => '',
        ]);

        $this->assertNull($result);
    }

    public function testClonesRepoWhenProjectPathDoesNotExist(): void
    {
        $bare = $this->makeLocalBareRepo();
        $dest = $this->tmpDir . '/cloned';

        $ctrl = $this->makeSyncableController();
        $result = $ctrl->callSyncProject([
            'scm_type' => 'git',
            'scm_url' => $bare,
            'scm_branch' => 'main',
            'project_path' => $dest,
        ]);

        $this->assertNull($result, 'Expected successful clone but got: ' . ($result ?? 'null'));
        $this->assertDirectoryExists($dest);
        $this->assertFileExists($dest . '/README.md');
    }

    public function testPullsWhenProjectAlreadyCloned(): void
    {
        $bare = $this->makeLocalBareRepo();
        $dest = $this->tmpDir . '/cloned';

        // Initial clone.
        exec('git clone --quiet --branch main ' . escapeshellarg($bare) . ' ' . escapeshellarg($dest));

        $ctrl = $this->makeSyncableController();
        $result = $ctrl->callSyncProject([
            'scm_type' => 'git',
            'scm_url' => $bare,
            'scm_branch' => 'main',
            'project_path' => $dest,
        ]);

        $this->assertNull($result, 'Expected successful pull but got: ' . ($result ?? 'null'));
    }

    public function testReturnsErrorStringOnInvalidUrl(): void
    {
        $dest = $this->tmpDir . '/cloned';

        $ctrl = $this->makeSyncableController();
        $result = $ctrl->callSyncProject([
            'scm_type' => 'git',
            'scm_url' => 'https://invalid.example.invalid/no-such-repo.git',
            'scm_branch' => 'main',
            'project_path' => $dest,
        ]);

        $this->assertNotNull($result);
        $this->assertStringContainsString('Git sync failed', $result);
    }

    // ── Regression: issue #10 — git sync must use safe directory env ─────

    /**
     * Regression test for issue #10: the runner's syncProject() must pass
     * environment variables to the git subprocess — specifically
     * GIT_TERMINAL_PROMPT=0 and GIT_CONFIG_* for safe.directory.
     *
     * ProjectService::baseGitEnv() sets these for web-triggered syncs,
     * but the runner's syncProject() originally used bare proc_open
     * without an env argument, causing "dubious ownership" failures
     * and potential hangs waiting for credential prompts in Docker.
     *
     * The env the git command gets is checked directly; the source check
     * that proc_open receives it (5th argument) is a guardrail because
     * proc_open cannot be intercepted without changing production code.
     */
    public function testSyncProjectPassesGitEnvToProcOpen(): void
    {
        $env = $this->makeSyncableController()->callBuildGitEnv('', null);
        $this->assertSame('0', $env['GIT_TERMINAL_PROMPT'], 'syncProject must set GIT_TERMINAL_PROMPT=0 to prevent hangs');
        $this->assertSame('safe.directory', $env['GIT_CONFIG_KEY_0'], 'syncProject must configure git safe.directory to avoid "dubious ownership" errors');
        $this->assertSame('*', $env['GIT_CONFIG_VALUE_0']);

        $controller = file_get_contents(dirname(__DIR__, 3) . '/commands/RunnerController.php');
        $this->assertNotFalse($controller);
        $this->assertMatchesRegularExpression(
            '/function\s+syncProject\(array \$payload\): \?string\s*\{\s*return \(new RunnerProjectSync\(\$this\)\)->sync\(\$payload\);/',
            $controller,
            'syncProject must delegate to RunnerProjectSync'
        );

        $source = file_get_contents(dirname(__DIR__, 3) . '/components/RunnerProjectSync.php');
        $this->assertNotFalse($source);
        $this->assertMatchesRegularExpression(
            '/\$env = \$this->buildGitEnv\(\$scmUrl, \$scmCredential, \$sshKeyFile\);/',
            $source,
            'the git command must get the env built by buildGitEnv()'
        );
        // The 4th arg to proc_open is $cwd, the 5th is $env_vars.
        $this->assertMatchesRegularExpression(
            '/proc_open\s*\(\s*\$cmd\s*,\s*\$descriptors\s*,\s*\$pipes\s*,\s*null\s*,\s*\$env\s*\)/',
            $source,
            'proc_open in RunnerProjectSync must pass env as 5th argument'
        );
    }

    // ── Regression: private git URLs need credential handling ──────────────
    //
    // Bug: prebuilt runner image hit `git@github.com:...` with no
    // GIT_SSH_COMMAND → ssh defaulted to StrictHostKeyChecking=ask → in
    // batch mode (GIT_TERMINAL_PROMPT=0) that aborts with
    // "Host key verification failed". Even with a host key, the runner
    // had no SSH key to authenticate.
    //
    // Fix: buildGitEnv accepts the SCM credential from the payload,
    // writes the private key to a 0600 tempfile, and sets GIT_SSH_COMMAND
    // with StrictHostKeyChecking=accept-new + BatchMode=yes. HTTPS uses a
    // GIT_CONFIG credential helper for token / username_password creds.

    public function testBuildGitEnvWiresGitSshCommandForSshUrlWithSshKeyCredential(): void
    {
        $ctrl = $this->makeSyncableController();
        $sshKeyFile = null;
        $env = $ctrl->callBuildGitEnv(
            'git@github.com:we-push-it/ansible-master.git',
            [
                'credential_type' => 'ssh_key',
                'username' => 'git',
                'env_var_name' => null,
                'secrets' => ['private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n"],
            ],
            $sshKeyFile,
        );

        try {
            $this->assertArrayHasKey('GIT_SSH_COMMAND', $env, 'SSH URL with ssh_key credential must produce GIT_SSH_COMMAND.');
            $this->assertStringContainsString('-i ', $env['GIT_SSH_COMMAND']);
            // accept-new: unknown hosts are accepted once, changed host keys are refused.
            $this->assertStringContainsString('StrictHostKeyChecking=accept-new', $env['GIT_SSH_COMMAND']);
            $this->assertStringContainsString('BatchMode=yes', $env['GIT_SSH_COMMAND']);
            $this->assertNotNull($sshKeyFile, 'SSH key must have been written to a tempfile.');
            $this->assertFileExists($sshKeyFile);
            $this->assertSame('0600', substr(sprintf('%o', fileperms($sshKeyFile)), -4));
        } finally {
            if ($sshKeyFile !== null && is_file($sshKeyFile)) {
                unlink($sshKeyFile);
            }
        }
    }

    public function testBuildGitEnvOmitsSshCommandWhenNoCredential(): void
    {
        $ctrl = $this->makeSyncableController();
        $sshKeyFile = null;
        $env = $ctrl->callBuildGitEnv('git@github.com:example/repo.git', null, $sshKeyFile);

        $this->assertArrayNotHasKey('GIT_SSH_COMMAND', $env);
        $this->assertNull($sshKeyFile);
    }

    public function testBuildGitEnvInjectsHttpsCredentialHelperForTokenCredential(): void
    {
        $ctrl = $this->makeSyncableController();
        $sshKeyFile = null;
        $env = $ctrl->callBuildGitEnv(
            'https://github.com/we-push-it/ansible-master.git',
            [
                'credential_type' => 'token',
                'username' => null,
                'env_var_name' => null,
                'secrets' => ['token' => 'ghp_fake_token_value'],
            ],
            $sshKeyFile,
        );

        $this->assertNull($sshKeyFile, 'HTTPS path must not write an SSH key file.');
        $this->assertArrayNotHasKey('GIT_SSH_COMMAND', $env, 'HTTPS URLs must not set GIT_SSH_COMMAND.');

        // GIT_CONFIG_COUNT grew by one and the new slot is a credential.helper.
        $count = (int)$env['GIT_CONFIG_COUNT'];
        $this->assertGreaterThanOrEqual(2, $count);
        $helperKey = null;
        for ($i = 0; $i < $count; $i++) {
            if (($env['GIT_CONFIG_KEY_' . $i] ?? '') === 'credential.helper') {
                $helperKey = $i;
                break;
            }
        }
        $this->assertNotNull($helperKey, 'A credential.helper entry must be registered in GIT_CONFIG_*.');
        $this->assertStringContainsString('username=x-access-token', $env['GIT_CONFIG_VALUE_' . $helperKey]);
        $this->assertStringContainsString('password=ghp_fake_token_value', $env['GIT_CONFIG_VALUE_' . $helperKey]);
    }

    public function testBuildGitEnvInjectsHttpsCredentialHelperForUsernamePasswordCredential(): void
    {
        $ctrl = $this->makeSyncableController();
        $sshKeyFile = null;
        $env = $ctrl->callBuildGitEnv(
            'https://gitlab.example.com/team/repo.git',
            [
                'credential_type' => 'username_password',
                'username' => 'deploy-bot',
                'env_var_name' => null,
                'secrets' => ['password' => 'sekret'],
            ],
            $sshKeyFile,
        );

        $this->assertNull($sshKeyFile);
        $count = (int)$env['GIT_CONFIG_COUNT'];
        $helperKey = null;
        for ($i = 0; $i < $count; $i++) {
            if (($env['GIT_CONFIG_KEY_' . $i] ?? '') === 'credential.helper') {
                $helperKey = $i;
                break;
            }
        }
        $this->assertNotNull($helperKey);
        $this->assertStringContainsString('username=deploy-bot', $env['GIT_CONFIG_VALUE_' . $helperKey]);
        $this->assertStringContainsString('password=sekret', $env['GIT_CONFIG_VALUE_' . $helperKey]);
    }

    public function testBuildGitEnvIgnoresCredentialWithWrongTypeForUrlScheme(): void
    {
        // A token credential pointed at an SSH URL is inert — the runner
        // must not pretend it can build a GIT_SSH_COMMAND from a token.
        $ctrl = $this->makeSyncableController();
        $sshKeyFile = null;
        $env = $ctrl->callBuildGitEnv(
            'git@github.com:example/repo.git',
            [
                'credential_type' => 'token',
                'username' => null,
                'env_var_name' => null,
                'secrets' => ['token' => 'ghp_fake'],
            ],
            $sshKeyFile,
        );

        $this->assertNull($sshKeyFile);
        $this->assertArrayNotHasKey('GIT_SSH_COMMAND', $env);
    }
}
