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
    public function sync(array $payload): ?string
    {
        $scmType = (string)($payload['scm_type'] ?? '');
        $scmUrl = (string)($payload['scm_url'] ?? '');
        $scmBranch = (string)($payload['scm_branch'] ?? 'main');
        $projectPath = (string)($payload['project_path'] ?? '');

        if ($scmType !== 'git' || $scmUrl === '' || $projectPath === '') {
            return null;
        }

        $isClone = !is_dir($projectPath . '/.git');
        $cmd = $isClone
            ? ['git', 'clone', '--depth', '1', '--branch', $scmBranch, $scmUrl, $projectPath]
            : ['git', '-C', $projectPath, 'pull', '--ff-only', 'origin', $scmBranch];

        $action = $isClone ? 'Cloning' : 'Pulling';
        $redactedUrl = GitSyncDiagnostics::redactUrl($scmUrl);
        $this->controller->stdout("{$action} project: {$redactedUrl} (branch: {$scmBranch}) → {$projectPath}\n");

        /** @var array{credential_type: string, username: string|null, env_var_name?: string|null, secrets: array<string, string>}|null $scmCredential */
        $scmCredential = is_array($payload['scm_credential'] ?? null) ? $payload['scm_credential'] : null;
        $sshKeyFile = null;
        try {
            $error = $this->runGitCommand($cmd, $projectPath, $isClone, $scmUrl, $scmCredential, $sshKeyFile);
        } finally {
            if ($sshKeyFile !== null && is_file($sshKeyFile)) {
                FileHelper::safeUnlink($sshKeyFile);
            }
        }
        return $error;
    }

    /**
     * Run a git command with safe environment and return null on success
     * or an error message string on failure.
     *
     * @param array<int, string> $cmd
     * @param array{credential_type: string, username: string|null, env_var_name?: string|null, secrets: array<string, string>}|null $scmCredential
     * @param string|null $sshKeyFile Set to the temp path when an SSH key was written (caller unlinks).
     */
    private function runGitCommand(
        array $cmd,
        string $projectPath,
        bool $isClone,
        string $scmUrl = '',
        ?array $scmCredential = null,
        ?string &$sshKeyFile = null,
    ): ?string {
        $env = $this->buildGitEnv($scmUrl, $scmCredential, $sshKeyFile);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $startTime = microtime(true);
        $proc = proc_open($cmd, $descriptors, $pipes, null, $env);
        if ($proc === false) {
            $diag = $this->diagnostics->collect($projectPath, $isClone);
            return "Failed to start git process.\n" . $diag;
        }

        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
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

        $this->controller->stdout("Git sync completed in {$elapsed}s\n");
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
    private function buildGitEnv(string $scmUrl, ?array $scmCredential, ?string &$sshKeyFile): array
    {
        return $this->gitEnv->build($scmUrl, $scmCredential, $sshKeyFile);
    }

    /**
     * Format a git command for logging, redacting any credentials that
     * may be embedded in URL arguments.
     *
     * @param array<int, string> $cmd
     */
    private function redactGitCmd(array $cmd): string
    {
        return implode(' ', array_map(fn (string $arg): string => GitSyncDiagnostics::redactUrl($arg), $cmd));
    }
}
