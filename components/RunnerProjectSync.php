<?php

declare(strict_types=1);

namespace app\components;

use app\helpers\FileHelper;
use yii\console\Controller;

/**
 * Clones or pulls a git project on the runner before a job runs.
 *
 * In standalone and prebuilt deployments the runner has its own filesystem
 * and the server's queue-worker cannot clone into it, so the runner syncs
 * the project itself from the scm metadata in the claim payload.
 *
 * Extracted from RunnerController (with {@see GitSyncDiagnostics}) so the
 * command stays small and this code is measured by the test coverage.
 */
final class RunnerProjectSync
{
    /** Written to the runner's output once the git commands of a sync succeeded; sprintf(): the seconds they took. */
    public const SYNC_COMPLETED = 'Git sync completed in %ss';

    /**
     * The job's error when a git command did not start, followed by the
     * diagnostics; sprintf(): why (ProcessStarter::NOT_STARTED).
     */
    public const GIT_NOT_STARTED = 'Git sync failed: %s';

    public function __construct(
        private readonly Controller $controller,
        private readonly GitEnvBuilder $gitEnv = new GitEnvBuilder(),
        private readonly GitSyncDiagnostics $diagnostics = new GitSyncDiagnostics(),
    ) {
    }

    /**
     * Clone or pull the project from git if the payload carries SCM metadata.
     * Returns null on success or when no sync is needed; returns an error
     * message string on failure so the caller can fail the job cleanly.
     *
     * @param array<string, mixed> $payload
     */
    public function sync(#[\SensitiveParameter] array $payload): ?string
    {
        $scmType = (string)($payload['scm_type'] ?? '');
        $scmUrl = (string)($payload['scm_url'] ?? '');
        $scmBranch = (string)($payload['scm_branch'] ?? 'main');
        $projectPath = (string)($payload['project_path'] ?? '');

        if ($scmType !== 'git' || $scmUrl === '' || $projectPath === '') {
            return null;
        }

        $isClone = !is_dir($projectPath . '/.git');
        $action = $isClone ? 'Cloning' : 'Pulling';
        $redactedUrl = GitSyncDiagnostics::redactUrl($scmUrl);
        $this->controller->stdout("{$action} project: {$redactedUrl} (branch: {$scmBranch}) → {$projectPath}\n");

        /** @var array{credential_type: string, username: string|null, env_var_name?: string|null, secrets: array<string, string>}|null $scmCredential */
        $scmCredential = is_array($payload['scm_credential'] ?? null) ? $payload['scm_credential'] : null;
        $sshKeyFile = null;
        try {
            $env = $this->buildGitEnv($scmUrl, $scmCredential, $sshKeyFile);
            $commands = $this->gitCommands($isClone, $scmUrl, $scmBranch, $projectPath);
            $error = $this->runGitCommands($commands, $env, $projectPath, $isClone);
        } finally {
            if ($sshKeyFile !== null && is_file($sshKeyFile)) {
                FileHelper::safeUnlink($sshKeyFile);
            }
        }
        return $error;
    }

    /**
     * The git commands that bring the checkout at $projectPath to the head
     * of $scmBranch at $scmUrl, the project's current repository URL: a
     * clone; or, for an existing checkout, origin set to $scmUrl, a fetch
     * and a fast-forward.
     *
     * A checkout keeps the URL it was cloned with as its origin, credentials
     * included, in its .git/config. Origin is set first, so that the fetch
     * uses a changed URL (a rotated token, a moved repository) and the
     * credentials of the old URL leave the checkout. "--" ends git's
     * options, so that neither the URL nor the branch is read as one; git
     * pull would hand the branch on to its fetch without it, so the runner
     * fetches and merges itself.
     *
     * @return list<list<string>>
     */
    private function gitCommands(
        bool $isClone,
        #[\SensitiveParameter] string $scmUrl,
        string $scmBranch,
        string $projectPath
    ): array {
        if ($isClone) {
            return [['git', 'clone', '--depth', '1', '--branch', $scmBranch, '--', $scmUrl, $projectPath]];
        }

        return [
            ['git', '-C', $projectPath, 'remote', 'set-url', '--', 'origin', $scmUrl],
            ['git', '-C', $projectPath, 'fetch', '--', 'origin', $scmBranch],
            ['git', '-C', $projectPath, 'merge', '--ff-only', 'FETCH_HEAD'],
        ];
    }

    /**
     * Run $commands in turn with $env and stop at the first that fails:
     * null when all of them succeeded, else the error message of the one
     * that failed.
     *
     * @param list<list<string>> $commands The commands, the repository URL with its credentials included.
     * @param array<string, string> $env
     */
    private function runGitCommands(
        #[\SensitiveParameter] array $commands,
        #[\SensitiveParameter] array $env,
        string $projectPath,
        bool $isClone
    ): ?string {
        $startTime = microtime(true);
        foreach ($commands as $cmd) {
            $error = $this->runGitCommand($cmd, $env, $projectPath, $isClone);
            if ($error !== null) {
                return $error;
            }
        }

        $elapsed = round(microtime(true) - $startTime, 2);
        $this->controller->stdout(sprintf(self::SYNC_COMPLETED, $elapsed) . "\n");
        return null;
    }

    /**
     * Run a git command with the environment of buildGitEnv() and return
     * null on success or an error message string on failure.
     *
     * @param array<int, string> $cmd The command, the repository URL with its credentials included.
     * @param array<string, string> $env
     */
    private function runGitCommand(
        #[\SensitiveParameter] array $cmd,
        #[\SensitiveParameter] array $env,
        string $projectPath,
        bool $isClone
    ): ?string {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $startTime = microtime(true);
        $proc = ProcessStarter::open($cmd, $descriptors, $pipes, null, $env, $failure);
        if ($proc === null) {
            $diag = $this->diagnostics->collect($projectPath, $isClone);
            return sprintf(self::GIT_NOT_STARTED, $failure) . "\n" . $diag;
        }

        fclose($pipes[0]);
        // Git's messages can name the URL: with a raw "@" in its password,
        // the rest of the password as the user (GitSyncDiagnostics::redactUrl).
        $stdout = GitSyncDiagnostics::redactUrl((string)stream_get_contents($pipes[1]));
        $stderr = GitSyncDiagnostics::redactUrl((string)stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        $elapsed = round(microtime(true) - $startTime, 2);

        if ($exitCode !== 0) {
            $diag = $this->diagnostics->collect($projectPath, $isClone);
            $this->logGitFailure($exitCode, $elapsed, $stdout, $stderr, $diag);
            return sprintf(
                "Git sync failed (exit %d, %.1fs):\ncommand: %s\n%s%s\n%s",
                $exitCode,
                $elapsed,
                $this->redactGitCmd($cmd),
                $stdout,
                $stderr,
                $diag,
            );
        }

        return null;
    }

    private function logGitFailure(int $exitCode, float $elapsed, string $stdout, string $stderr, string $diagnostics): void
    {
        $this->controller->stderr("Git sync failed after {$elapsed}s (exit {$exitCode})\n");
        if ($stderr !== '') {
            $this->controller->stderr("  stderr: {$stderr}\n");
        }
        if ($stdout !== '') {
            $this->controller->stderr("  stdout: {$stdout}\n");
        }
        $this->controller->stderr($diagnostics);
    }

    /**
     * Build environment variables for the git subprocess. Delegates to
     * {@see GitEnvBuilder} so the SSH-key materialisation + HTTPS
     * credential-helper logic lives in one focused class.
     *
     * @param array{credential_type: string, username: string|null, env_var_name?: string|null, secrets: array<string, string>}|null $scmCredential
     * @param string|null $sshKeyFile Written if an SSH key was materialised — caller must unlink.
     * @return array<string, string>
     */
    private function buildGitEnv(
        #[\SensitiveParameter] string $scmUrl,
        #[\SensitiveParameter] ?array $scmCredential,
        ?string &$sshKeyFile
    ): array {
        return $this->gitEnv->build($scmUrl, $scmCredential, $sshKeyFile);
    }

    /**
     * Format a git command for logging, redacting any credentials that
     * may be embedded in URL arguments (as ProjectService logs its git
     * commands).
     *
     * @param array<int, string> $cmd
     */
    private function redactGitCmd(#[\SensitiveParameter] array $cmd): string
    {
        return GitSyncDiagnostics::redactUrl(implode(' ', $cmd));
    }
}
