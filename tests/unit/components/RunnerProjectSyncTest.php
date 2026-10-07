<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\RunnerProjectSync;
use app\helpers\FileHelper;
use app\tests\unit\TemporaryEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * The runner's own git sync before a job, with real git against local
 * repositories. RunnerControllerSyncProjectTest covers the same code through
 * RunnerController::syncProject(); these tests pin the failure report and
 * the secret handling.
 */
class RunnerProjectSyncTest extends TestCase
{
    private string $dir;
    private CapturingController $controller;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runner_project_sync_' . uniqid('', true);
        mkdir($this->dir . '/home', 0o700, true);
        $this->controller = new CapturingController('runner', \Yii::$app);
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->dir);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function nothingToSyncProvider(): array
    {
        return [
            'manual project' => [['scm_type' => 'manual', 'scm_url' => 'https://example.com/r.git', 'project_path' => '/srv/p']],
            'no scm type' => [['scm_url' => 'https://example.com/r.git', 'project_path' => '/srv/p']],
            'no url' => [['scm_type' => 'git', 'scm_url' => '', 'project_path' => '/srv/p']],
            'no project path' => [['scm_type' => 'git', 'scm_url' => 'https://example.com/r.git']],
        ];
    }

    /**
     * @dataProvider nothingToSyncProvider
     * @param array<string, mixed> $payload
     */
    public function testNothingIsSyncedWithoutGitMetadata(array $payload): void
    {
        $this->assertNull($this->sync($payload));
        $this->assertSame('', $this->controller->capturedStdout . $this->controller->capturedStderr);
    }

    public function testAFreshCheckoutIsCloned(): void
    {
        $dest = $this->dir . '/checkout';

        $this->assertNull($this->sync(['scm_type' => 'git', 'scm_url' => $this->remoteWithCommit(), 'project_path' => $dest]));

        $this->assertSame("first\n", file_get_contents($dest . '/README.md'));
        $this->assertStringContainsString("Cloning project: {$this->dir}/remote.git (branch: main) → {$dest}\n", $this->controller->capturedStdout);
        $this->assertMatchesRegularExpression('/Git sync completed in [\d.]+s\n/', $this->controller->capturedStdout);
    }

    public function testAnExistingCheckoutIsPulled(): void
    {
        $remote = $this->remoteWithCommit();
        $dest = $this->dir . '/checkout';
        $this->git(['clone', '-q', '--branch', 'main', $remote, $dest]);
        $this->pushChange($remote, "second\n");

        $this->assertNull($this->sync(['scm_type' => 'git', 'scm_url' => $remote, 'scm_branch' => 'main', 'project_path' => $dest]));

        $this->assertSame("second\n", file_get_contents($dest . '/README.md'));
        $this->assertStringContainsString('Pulling project: ', $this->controller->capturedStdout);
    }

    /**
     * The job log gets git's stdout and stderr, the command and the
     * diagnostics; the runner's own log gets the same.
     */
    public function testAFailedPullReportsBothStreamsAndTheRepositoryState(): void
    {
        $remote = $this->remoteWithCommit();
        $dest = $this->dir . '/checkout';
        $this->git(['clone', '-q', '--branch', 'main', $remote, $dest]);
        $this->pushChange($remote, "second\n");
        file_put_contents($dest . '/README.md', "local edit\n");

        $error = (string)$this->sync(['scm_type' => 'git', 'scm_url' => $remote, 'scm_branch' => 'main', 'project_path' => $dest]);

        $this->assertMatchesRegularExpression('/^Git sync failed \(exit 1, [\d.]+s\):\n/', $error);
        $this->assertStringContainsString("command: git -C {$dest} pull --ff-only origin main\n", $error);
        $this->assertStringContainsString('Updating ', $error, 'stdout');
        $this->assertStringContainsString('Your local changes', $error, 'stderr');
        $this->assertStringContainsString("--- Git sync diagnostics ---\n", $error);
        $this->assertStringContainsString("git repo state (from {$dest}):", $error);
        $log = $this->controller->capturedStderr;
        $this->assertMatchesRegularExpression('/^Git sync failed after [\d.]+s \(exit 1\)\n/', $log);
        $this->assertStringContainsString('  stderr: ', $log);
        $this->assertStringContainsString('  stdout: Updating ', $log);
        $this->assertStringContainsString('--- Git sync diagnostics ---', $log);
    }

    /**
     * Security: credentials embedded in the URL never reach the logs.
     */
    public function testCredentialsInTheUrlAreRedacted(): void
    {
        $dest = $this->dir . '/checkout';

        $error = (string)$this->sync([
            'scm_type' => 'git',
            'scm_url' => 'https://deploy:s3cr3t-token@invalid.example.invalid/team/repo.git',
            'project_path' => $dest,
        ]);

        $this->assertStringContainsString(
            "command: git clone --depth 1 --branch main https://***@invalid.example.invalid/team/repo.git {$dest}\n",
            $error
        );
        $this->assertStringContainsString('Cloning project: https://***@invalid.example.invalid/team/repo.git', $this->controller->capturedStdout);
        $this->assertStringNotContainsString('s3cr3t-token', $error . $this->controller->capturedStdout . $this->controller->capturedStderr);
    }

    /**
     * Security: the SCM private key exists only while git runs. A fake ssh
     * first in PATH records the key file git hands it.
     */
    public function testTheSshKeyIsRemovedAfterTheSync(): void
    {
        mkdir($this->dir . '/bin');
        file_put_contents(
            $this->dir . '/bin/ssh',
            "#!/bin/sh\nif [ -f \"\$2\" ]; then state=present; else state=missing; fi\n"
            . 'printf "%s %s" "$state" "$2" > ' . escapeshellarg($this->dir . '/ssh-call') . "\n"
            . "echo 'fake ssh: connection refused' >&2\nexit 255\n"
        );
        chmod($this->dir . '/bin/ssh', 0o755);
        $path = new TemporaryEnvironment(['PATH' => $this->dir . '/bin:' . (string)getenv('PATH')]);
        try {
            $error = $this->sync([
                'scm_type' => 'git',
                'scm_url' => 'ssh://git@127.0.0.1:1/team/repo.git',
                'project_path' => $this->dir . '/checkout',
                'scm_credential' => [
                    'credential_type' => 'ssh_key',
                    'username' => 'git',
                    'env_var_name' => null,
                    'secrets' => ['private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nnot-a-key\n-----END OPENSSH PRIVATE KEY-----\n"],
                ],
            ]);
        } finally {
            $path->restore();
        }

        $this->assertStringStartsWith('Git sync failed', (string)$error);
        $this->assertStringContainsString('fake ssh: connection refused', (string)$error);
        $this->assertFileExists($this->dir . '/ssh-call', 'git ran ssh with the key');
        [$state, $keyFile] = explode(' ', (string)file_get_contents($this->dir . '/ssh-call'), 2);
        $this->assertSame('present', $state, 'the key existed while git ran');
        $this->assertStringContainsString('/ansilume_ssh_', $keyFile);
        $this->assertFileDoesNotExist($keyFile, 'the key is removed after the sync');
    }

    // -- Helpers ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     */
    private function sync(array $payload): ?string
    {
        return (new RunnerProjectSync($this->controller))->sync($payload);
    }

    /**
     * A bare repository whose main branch has README.md = "first".
     */
    private function remoteWithCommit(): string
    {
        $remote = $this->dir . '/remote.git';
        $this->git(['init', '-q', '--bare', '-b', 'main', $remote]);
        $this->pushChange($remote, "first\n");

        return $remote;
    }

    private function pushChange(string $remote, string $readme): void
    {
        $work = $this->dir . '/work-' . uniqid();
        $this->git(['clone', '-q', $remote, $work]);
        file_put_contents($work . '/README.md', $readme);
        $this->git(['-C', $work, 'add', 'README.md']);
        $this->git(['-C', $work, 'commit', '-q', '-m', 'change']);
        $this->git(['-C', $work, 'push', '-q', 'origin', 'HEAD:main']);
        FileHelper::removeDirectory($work);
    }

    /**
     * @param list<string> $args
     */
    private function git(array $args): void
    {
        $process = proc_open(['git', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir, [
            'PATH' => (string)getenv('PATH'),
            'HOME' => $this->dir . '/home',
            'GIT_AUTHOR_NAME' => 'Test',
            'GIT_AUTHOR_EMAIL' => 'test@example.com',
            'GIT_COMMITTER_NAME' => 'Test',
            'GIT_COMMITTER_EMAIL' => 'test@example.com',
        ]);
        $this->assertIsResource($process);
        stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $err);
    }
}
