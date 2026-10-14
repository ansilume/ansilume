<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\GitSyncDiagnostics;
use app\components\RunnerProjectSync;
use app\helpers\FileHelper;
use app\tests\unit\GitRemoteFixture;
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
    /** Test values, not secrets. */
    private const OLD_TOKEN = 'w5d-old-token-5208';
    private const NEW_TOKEN = 'w5d-new-token-6631';
    private const OLD_URL = 'https://deploy:' . self::OLD_TOKEN . '@git.example.invalid/org/playbooks.git';
    private const NEW_URL = 'https://deploy:' . self::NEW_TOKEN . '@git.example.invalid/moved/playbooks.git';

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
        $this->assertStringContainsString("command: git -C {$dest} merge --ff-only FETCH_HEAD\n", $error);
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
            "command: git clone --depth 1 --branch main -- https://***@invalid.example.invalid/team/repo.git {$dest}\n",
            $error
        );
        $this->assertStringContainsString('Cloning project: https://***@invalid.example.invalid/team/repo.git', $this->controller->capturedStdout);
        $this->assertStringNotContainsString('s3cr3t-token', $error . $this->controller->capturedStdout . $this->controller->capturedStderr);
    }

    /**
     * regression: with a raw "@" in the password of the URL, the job log and
     * the runner's own log showed the rest of the password: in the command
     * (https://***@<rest>@host, redactUrl ended the userinfo at its first
     * "@") and in git's stderr, which names the URL from its first "@" on
     * (https://<rest>@host/...), as it was. Real git against a host that
     * cannot resolve.
     */
    public function testRegressionARawAtInThePasswordShowedItsRest(): void
    {
        $dest = $this->dir . '/checkout';

        $error = (string)$this->sync([
            'scm_type' => 'git',
            'scm_url' => 'https://deploy:s3cr3t@w5c-rest-of-the-password@invalid.example.invalid/team/repo.git',
            'project_path' => $dest,
        ]);

        $everything = $error . $this->controller->capturedStdout . $this->controller->capturedStderr;
        $this->assertStringNotContainsString('w5c-rest-of-the-password', $everything);
        $this->assertStringNotContainsString('s3cr3t', $everything);
        $this->assertStringContainsString(
            "command: git clone --depth 1 --branch main -- https://***@invalid.example.invalid/team/repo.git {$dest}\n",
            $error
        );
        $this->assertStringContainsString("'https://***@invalid.example.invalid/team/repo.git/'", $error, 'git named the URL, redacted');
        $this->assertStringContainsString('Cloning project: https://***@invalid.example.invalid/team/repo.git', $this->controller->capturedStdout);
    }

    /**
     * regression: a pull used the origin the checkout was cloned with, so a
     * changed repository URL (a rotated token, a moved repository) was not
     * fetched from until the checkout was deleted, and the old credentials
     * stayed in its .git/config. The runner clones from one URL, the
     * project's URL changes, and the next sync fetches from the new one.
     */
    public function testRegressionAPullFetchedFromTheUrlTheCheckoutWasClonedWith(): void
    {
        $remotes = $this->remotes();
        $dest = $this->dir . '/checkout';
        $payload = ['scm_type' => 'git', 'scm_branch' => 'main', 'project_path' => $dest];

        $this->withRewrites($remotes, function () use ($payload, $dest): void {
            $this->assertNull($this->sync(['scm_url' => self::OLD_URL] + $payload));
            $this->assertSame(['config'], GitRemoteFixture::filesHolding($dest . '/.git', self::OLD_TOKEN), 'a clone keeps its URL with the credentials');
            $this->assertNull($this->sync(['scm_url' => self::NEW_URL] + $payload));
        });

        $this->assertSame("second\n", file_get_contents($dest . '/README.md'), 'the sync fetched from the new URL');
        $this->assertSame([], GitRemoteFixture::filesHolding($dest, self::OLD_TOKEN), 'the old credentials left the checkout');
        $this->assertStringContainsString("\turl = " . self::NEW_URL . "\n", (string)file_get_contents($dest . '/.git/config'));
        $this->assertStringContainsString(
            'Pulling project: ' . GitSyncDiagnostics::redactUrl(self::NEW_URL) . " (branch: main) → {$dest}\n",
            $this->controller->capturedStdout
        );
        $completed = '/^' . sprintf(preg_quote(RunnerProjectSync::SYNC_COMPLETED, '/'), '[\d.]+') . '$/m';
        $this->assertSame(2, preg_match_all($completed, $this->controller->capturedStdout), 'once per sync');
        $this->assertSame('', $this->controller->capturedStderr);
        $this->assertStringNotContainsString(self::NEW_TOKEN, $this->controller->capturedStdout);
    }

    /**
     * regression: the branch went to git pull after "origin", where git read
     * a branch that starts with "-" as an option: --upload-pack=<command>
     * ran <command> (on the runner here, where the fetch reads a local
     * repository; over ssh on the git server). "--" would not have helped,
     * as git pull hands the branch on to its fetch without it: the runner
     * fetches with "--" and merges itself, and git refuses the branch as a
     * refspec.
     */
    public function testRegressionABranchWasReadAsAnOptionOfGitPull(): void
    {
        $remotes = $this->remotes();
        $dest = $this->dir . '/checkout';
        $marker = $this->dir . '/upload-pack-ran';
        $branch = '--upload-pack=touch ' . $marker . '; git-upload-pack';
        $payload = ['scm_type' => 'git', 'scm_url' => self::OLD_URL, 'project_path' => $dest];

        $error = $this->withRewrites($remotes, function () use ($payload, $branch): ?string {
            $this->assertNull($this->sync(['scm_branch' => 'main'] + $payload));

            return $this->sync(['scm_branch' => $branch] + $payload);
        });

        $this->assertFileDoesNotExist($marker, 'git ran no command that the branch named');
        $this->assertMatchesRegularExpression('/^Git sync failed \(exit 128, [\d.]+s\):\n/', (string)$error);
        $this->assertStringContainsString("command: git -C {$dest} fetch -- origin {$branch}\n", (string)$error);
        $this->assertStringContainsString("fatal: invalid refspec '{$branch}'", (string)$error);
    }

    /**
     * A checkout without an origin fails where origin is set, and the
     * commands after it do not run. The command in the job log names the URL
     * without its credentials.
     */
    public function testACheckoutWithoutOriginFailsWhereOriginIsSet(): void
    {
        $dest = $this->dir . '/checkout';
        $this->git(['init', '-q', '-b', 'main', $dest]);

        $error = (string)$this->sync(['scm_type' => 'git', 'scm_url' => self::NEW_URL, 'scm_branch' => 'main', 'project_path' => $dest]);

        $this->assertMatchesRegularExpression('/^Git sync failed \(exit \d+, [\d.]+s\):\n/', $error);
        $this->assertStringContainsString("command: git -C {$dest} remote set-url -- origin " . GitSyncDiagnostics::redactUrl(self::NEW_URL) . "\n", $error);
        $this->assertStringContainsString("No such remote 'origin'", $error);
        $this->assertFileDoesNotExist($dest . '/.git/FETCH_HEAD', 'nothing was fetched');
        $this->assertStringNotContainsString((string)strstr(RunnerProjectSync::SYNC_COMPLETED, '%', true), $this->controller->capturedStdout);
        $this->assertStringNotContainsString(self::NEW_TOKEN, $error . $this->controller->capturedStdout . $this->controller->capturedStderr);
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
     * The repository of OLD_URL, whose main branch has README.md = "first",
     * and that of NEW_URL, its history with a commit that sets it to
     * "second".
     */
    private function remotes(): GitRemoteFixture
    {
        $remotes = new GitRemoteFixture($this->dir . '/remotes');
        $remotes->add(self::OLD_URL, "first\n");
        $remotes->add(self::NEW_URL, "second\n", self::OLD_URL);

        return $remotes;
    }

    /**
     * Runs $sync with a git first in PATH that rewrites the URLs of $remotes
     * to their repositories: the runner builds git's environment itself, so
     * the rewrites come in as -c options of a wrapper around the real git.
     *
     * @template T
     * @param callable(): T $sync
     * @return T
     */
    private function withRewrites(GitRemoteFixture $remotes, callable $sync): mixed
    {
        $script = "#!/bin/sh\nexec " . escapeshellarg($this->realGit());
        foreach ($remotes->rewrites() as $key => $value) {
            $script .= ' -c ' . escapeshellarg($key . '=' . $value);
        }
        mkdir($this->dir . '/bin');
        file_put_contents($this->dir . '/bin/git', $script . " \"\$@\"\n");
        chmod($this->dir . '/bin/git', 0o755);
        $path = new TemporaryEnvironment(['PATH' => $this->dir . '/bin:' . (string)getenv('PATH')]);
        try {
            return $sync();
        } finally {
            $path->restore();
        }
    }

    /**
     * The git that PATH names.
     */
    private function realGit(): string
    {
        foreach (explode(':', (string)getenv('PATH')) as $directory) {
            if ($directory !== '' && is_file($directory . '/git') && is_executable($directory . '/git')) {
                return $directory . '/git';
            }
        }
        $this->fail('git is not in PATH');
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
