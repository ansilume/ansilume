<?php

declare(strict_types=1);

namespace app\tests\unit\commands;

use app\commands\RunnerController;
use app\components\RunnerHttpClient;
use app\components\RunnerTokenResolver;
use PHPUnit\Framework\TestCase;
use yii\console\ExitCode;

/**
 * Tests for RunnerController startup auth logic.
 *
 * Uses stub RunnerHttpClient and RunnerTokenResolver injected via the
 * controller's protected properties, so no real HTTP or filesystem calls are made.
 *
 * Critical regression covered: a 401 response with a parseable JSON body
 * ({"ok":false,"error":"..."}) must NOT be treated as a successful heartbeat.
 * Before the fix, apiPost() returned the decoded body (not null), so the
 * $info === null guard was bypassed and the runner entered the polling loop
 * with an invalid token.
 */
class RunnerControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['API_URL'] = 'http://test-server';
        $_ENV['RUNNER_NAME'] = 'test-runner';
    }

    protected function tearDown(): void
    {
        unset($_ENV['API_URL'], $_ENV['RUNNER_NAME'], $_ENV['RUNNER_BOOTSTRAP_SECRET'], $_ENV['RUNNER_TOKEN']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a testable RunnerController with stub collaborators.
     *
     * @param list<array{int, ?array}> $apiSequence   Each entry is [httpStatus, responseBody].
     * @param list<string>             $tokenSequence Tokens returned by successive resolve() calls.
     * @param bool                     $hasCacheFile  Whether a cache file should appear to exist.
     */
    private function makeController(
        array $apiSequence,
        array $tokenSequence = ['some-token'],
        bool $hasCacheFile = false,
    ): RunnerController {
        $stubHttp = new StubRunnerHttpClient($apiSequence);
        $stubTokenResolver = new StubRunnerTokenResolver($tokenSequence, $hasCacheFile);

        $ctrl = new class ('runner', \Yii::$app, $stubHttp, $stubTokenResolver) extends RunnerController {
            public function __construct($id, $module, RunnerHttpClient $http, RunnerTokenResolver $tokenResolver)
            {
                parent::__construct($id, $module);
                $this->http = $http;
                $this->tokenResolver = $tokenResolver;
            }

            // Stop the poll loop after exhausting scripted responses.
            protected function pollLoop(): void
            {
                // Do not actually poll — startup tests only care about auth.
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

        // Wire the stop-on-exhaust callback.
        $stubHttp->setOnExhausted(function () use ($ctrl): void {
            // This shouldn't be reached in startup tests, but safety net.
        });

        return $ctrl;
    }

    // -------------------------------------------------------------------------
    // Startup heartbeat — failure cases
    // -------------------------------------------------------------------------

    /**
     * REGRESSION: before the fix, a 401 response with a JSON body was returned
     * as ['ok' => false, ...] by apiPost(). The old guard ($info === null) did
     * not catch this, so the runner printed "Runner 'unknown' started" and
     * entered the polling loop with an invalid token.
     */
    public function testExitsWhenHeartbeatReturnsOkFalseWith401(): void
    {
        $ctrl = $this->makeController(
            apiSequence:   [[401, ['ok' => false, 'error' => 'Invalid runner token.']]],
            hasCacheFile:  false,
        );

        $result = $ctrl->actionStart();

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $result);
    }

    public function testExitsWhenHeartbeatReturnsNull(): void
    {
        $ctrl = $this->makeController(
            apiSequence: [[0, null]],
        );

        $result = $ctrl->actionStart();

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $result);
    }

    public function testExitsWhenHeartbeatReturnsOkFalseWithoutCacheFile(): void
    {
        $ctrl = $this->makeController(
            apiSequence:  [[401, ['ok' => false, 'error' => 'Invalid runner token.']]],
            hasCacheFile: false,
        );

        $result = $ctrl->actionStart();

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $result);
    }

    public function testExitsWhenApiUrlIsEmpty(): void
    {
        unset($_ENV['API_URL']);

        $ctrl = $this->makeController(apiSequence: []);
        $result = $ctrl->actionStart();

        $this->assertSame(ExitCode::CONFIG, $result);
    }

    public function testExitsWhenResolvedTokenIsEmpty(): void
    {
        $ctrl = $this->makeController(apiSequence: [], tokenSequence: ['']);
        $result = $ctrl->actionStart();

        $this->assertSame(ExitCode::CONFIG, $result);
    }

    // -------------------------------------------------------------------------
    // Startup heartbeat — recovery via stale cache
    // -------------------------------------------------------------------------

    public function testClearsStaleTokenAndReRegistersOn401(): void
    {
        // Sequence: first heartbeat → 401, second heartbeat (after re-register) → 200 ok
        // tokenSequence: first resolve() returns stale token, second returns fresh one
        $ctrl = $this->makeController(
            apiSequence:   [
                [401, ['ok' => false, 'error' => 'Invalid runner token.']],
                [200, ['ok' => true, 'data' => ['runner_name' => 'test-runner', 'group_name' => 'default', 'server_time' => time()]]],
            ],
            tokenSequence: ['stale-token', 'fresh-token'],
            hasCacheFile:  true,
        );

        $result = $ctrl->actionStart();

        $this->assertSame(ExitCode::OK, $result);
    }

    public function testExitsWhenReRegistrationFailsAfter401(): void
    {
        // First resolve() returns stale token so actionStart proceeds past the empty-token check.
        // First heartbeat → 401, cache file exists → clears it, calls resolve() again → ''
        // No second heartbeat is attempted, runner exits.
        $ctrl = $this->makeController(
            apiSequence:   [[401, ['ok' => false, 'error' => 'Invalid runner token.']]],
            tokenSequence: ['stale-token', ''], // second call returns '' (re-registration failed)
            hasCacheFile:  true,
        );

        $result = $ctrl->actionStart();

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $result);
    }

    // -------------------------------------------------------------------------
    // Startup heartbeat — success
    // -------------------------------------------------------------------------

    public function testStartsSuccessfullyWhenHeartbeatReturnsOk(): void
    {
        $ctrl = $this->makeController(
            apiSequence:   [[200, ['ok' => true, 'data' => ['runner_name' => 'test-runner', 'group_name' => 'default', 'server_time' => time()]]]],
            tokenSequence: ['valid-token'],
        );

        $result = $ctrl->actionStart();

        $this->assertSame(ExitCode::OK, $result);
    }
}

// ---------------------------------------------------------------------------
// syncProject() unit tests — uses a real tmpdir git repo to test clone/pull.
// ---------------------------------------------------------------------------

/**
 * Tests for RunnerController::syncProject() via a testable subclass.
 * Uses real filesystem git operations so git must be available in the test env.
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
                $reflection = new \ReflectionMethod(RunnerController::class, 'buildGitEnv');
                $reflection->setAccessible(true);
                /** @var array<string, string> $env */
                $env = $reflection->invokeArgs($this, [$scmUrl, $scmCredential, &$sshKeyFile]);
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
        exec('git clone --quiet ' . escapeshellarg($bare) . ' ' . escapeshellarg($work));
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
     * This test reads the source to verify that proc_open is called
     * with an explicit env array (4th argument) containing the required
     * git configuration. This is a source-level guardrail because we
     * cannot intercept proc_open without modifying production code.
     */
    public function testSyncProjectPassesGitEnvToProcOpen(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/commands/RunnerController.php'
        );
        $this->assertNotFalse($source);

        // Extract the syncProject method body
        $this->assertMatchesRegularExpression(
            '/function\s+syncProject/',
            $source,
            'syncProject method must exist'
        );

        // The method must set GIT_TERMINAL_PROMPT to prevent interactive prompts
        $this->assertStringContainsString(
            'GIT_TERMINAL_PROMPT',
            $source,
            'syncProject must set GIT_TERMINAL_PROMPT=0 to prevent hangs'
        );

        // The method must configure git safe.directory via GIT_CONFIG_*
        $this->assertStringContainsString(
            'safe.directory',
            $source,
            'syncProject must configure git safe.directory to avoid "dubious ownership" errors'
        );

        // proc_open must receive an env argument (not just cmd + descriptors + pipes)
        // The 4th arg to proc_open is $cwd, the 5th is $env_vars.
        // We check that the proc_open call in syncProject includes env.
        $this->assertMatchesRegularExpression(
            '/proc_open\s*\(\s*\$cmd\s*,\s*\$descriptors\s*,\s*\$pipes\s*,\s*null\s*,\s*\$/',
            $source,
            'proc_open in syncProject must pass env as 5th argument'
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
    // with StrictHostKeyChecking=no + BatchMode=yes. HTTPS uses a
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
            $this->assertStringContainsString('StrictHostKeyChecking=no', $env['GIT_SSH_COMMAND']);
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

// ---------------------------------------------------------------------------
// Stub collaborators — defined in the same file for test isolation.
// ---------------------------------------------------------------------------

/**
 * Stub HTTP client that returns scripted responses.
 */
class StubRunnerHttpClient extends RunnerHttpClient
{
    /** @var list<array{int, ?array}> */
    private array $seq;
    private int $seqIdx = 0;
    private int $lastStatus = 0;
    /** @var callable|null */
    private $onExhausted;

    /**
     * @param list<array{int, ?array}> $seq
     */
    public function __construct(array $seq)
    {
        parent::__construct('http://test-server', '');
        $this->seq = $seq;
    }

    public function setOnExhausted(callable $cb): void
    {
        $this->onExhausted = $cb;
    }

    public function post(string $path, array $body): ?array
    {
        if ($this->seqIdx >= count($this->seq)) {
            if ($this->onExhausted) {
                ($this->onExhausted)();
            }
            return null;
        }

        [$status, $result] = $this->seq[$this->seqIdx++];
        $this->lastStatus = $status;
        return $result;
    }

    public function postUnauthenticated(string $path, array $body): ?array
    {
        return $this->post($path, $body);
    }

    public function getLastHttpStatus(): int
    {
        return $this->lastStatus;
    }

    public function setToken(string $token): void
    {
        // No-op in stub.
    }
}

/**
 * Stub token resolver that returns scripted tokens.
 */
class StubRunnerTokenResolver extends RunnerTokenResolver
{
    /** @var list<string> */
    private array $tokens;
    private int $tokenIdx = 0;
    private bool $stubHasCacheFile;

    /**
     * @param list<string> $tokens
     */
    public function __construct(array $tokens, bool $hasCacheFile = false)
    {
        parent::__construct(
            new RunnerHttpClient('http://stub', ''),
            new \yii\console\Controller('stub', \Yii::$app),
        );
        $this->tokens = $tokens;
        $this->stubHasCacheFile = $hasCacheFile;
    }

    public function resolve(): string
    {
        if ($this->tokenIdx >= count($this->tokens)) {
            return '';
        }
        return $this->tokens[$this->tokenIdx++];
    }

    public function clearCacheAndResolve(): string
    {
        return $this->resolve();
    }

    public function hasCacheFile(): bool
    {
        return $this->stubHasCacheFile;
    }
}
