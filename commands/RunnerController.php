<?php

declare(strict_types=1);

namespace app\commands;

use app\components\CredentialInjector;
use app\components\RunnerHttpClient;
use app\components\RunnerProcessExecutor;
use app\components\RunnerTokenResolver;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Pull-based Ansible runner.
 *
 * Reads RUNNER_TOKEN and API_URL from the environment, polls the ansilume
 * server for queued jobs, executes ansible-playbook locally, and streams
 * results back via the runner HTTP API.
 *
 * Usage:
 *   php yii runner/start
 *
 * Environment variables:
 *   RUNNER_TOKEN             — the runner's authentication token; if omitted,
 *                              self-registration is attempted using the variables below
 *   RUNNER_NAME              — name to register under (required for self-registration)
 *   RUNNER_BOOTSTRAP_SECRET  — shared secret that authorises self-registration
 *   API_URL                  — base URL of the ansilume server, e.g. https://your-host (required)
 */
class RunnerController extends Controller
{
    private const POLL_INTERVAL = 5;
    private const HEARTBEAT_INTERVAL = 30;

    protected ?RunnerHttpClient $http = null;
    protected ?RunnerTokenResolver $tokenResolver = null;
    protected bool $running = true;

    public function actionStart(): int
    {
        $apiUrl = rtrim($_ENV['API_URL'] ?? '', '/');

        if ($apiUrl === '') {
            $this->stderr("ERROR: API_URL environment variable is required.\n");
            return ExitCode::CONFIG;
        }

        $this->http ??= new RunnerHttpClient($apiUrl, '');
        $this->tokenResolver ??= new RunnerTokenResolver($this->http, $this);

        $token = $this->tokenResolver->resolve();
        if ($token === '') {
            $this->stderr(
                "ERROR: No runner token available.\n" .
                "Set RUNNER_TOKEN, or set RUNNER_NAME + RUNNER_BOOTSTRAP_SECRET for auto-registration.\n"
            );
            return ExitCode::CONFIG;
        }

        $this->http->setToken($token);

        $info = $this->verifyTokenWithRetry();
        if ($info === null || empty($info['ok'])) {
            $this->stderr("ERROR: Failed to authenticate with the server. Check RUNNER_TOKEN and API_URL.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $data = is_array($info['data'] ?? null) ? $info['data'] : [];
        $runnerName = (string)($data['runner_name'] ?? 'unknown');
        $groupName = (string)($data['group_name'] ?? 'unknown');
        $this->stdout("Runner '{$runnerName}' started. Group: '{$groupName}'. Polling {$apiUrl}\n");

        $this->registerSignalHandlers();
        $this->pollLoop();

        $this->stdout("Runner shutting down.\n");
        return ExitCode::OK;
    }

    /**
     * Attempt heartbeat, retrying once with a fresh token on 401.
     *
     * @return array<string, mixed>|null
     */
    protected function verifyTokenWithRetry(): ?array
    {
        $http = $this->http;
        $resolver = $this->tokenResolver;
        if ($http === null || $resolver === null) {
            return null;
        }

        $info = $http->post('/api/runner/v1/heartbeat', []);

        if ($http->getLastHttpStatus() === 401 && $resolver->hasCacheFile()) {
            $token = $resolver->clearCacheAndResolve();
            if ($token !== '') {
                $http->setToken($token);
                $info = $http->post('/api/runner/v1/heartbeat', []);
            }
        }

        return $info;
    }

    protected function registerSignalHandlers(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function (): void {
                $this->running = false;
            });
            pcntl_signal(SIGINT, function (): void {
                $this->running = false;
            });
        }
    }

    protected function pollLoop(): void
    {
        $lastHeartbeat = time();

        while ($this->running) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            if (time() - $lastHeartbeat >= self::HEARTBEAT_INTERVAL) {
                $this->http()->post('/api/runner/v1/heartbeat', []);
                $lastHeartbeat = time();
            }

            $payload = $this->claimJob();

            if ($payload === null) {
                sleep(self::POLL_INTERVAL);
                continue;
            }

            $jobId = (int)($payload['job_id'] ?? 0);
            $scmType = (string)($payload['scm_type'] ?? 'manual');
            $playbook = basename((string)($payload['playbook_path'] ?? 'unknown'));
            $this->stdout("Claimed job #{$jobId}. Playbook: {$playbook}, SCM: {$scmType}\n");

            $this->executeJob($jobId, $payload);

            $lastHeartbeat = time();
        }
    }

    /**
     * @return array<string, mixed>|null Job payload or null if no job available.
     */
    private function claimJob(): ?array
    {
        $result = $this->http()->post('/api/runner/v1/jobs/claim', []);
        if ($result === null || !isset($result['data'])) {
            return null;
        }
        return is_array($result['data']) ? $result['data'] : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function executeJob(int $jobId, array $payload): void
    {
        // Sync project from git before execution.
        // In standalone / prebuilt deployments the runner has its own filesystem
        // and the server's queue-worker cannot clone repos into it. The runner
        // must pull the project itself using the scm metadata from the payload.
        $syncError = $this->syncProject($payload);
        if ($syncError !== null) {
            $this->failJob($jobId, $syncError);
            return;
        }

        $callbackFile = sys_get_temp_dir() . '/ansilume_tasks_' . $jobId . '_' . uniqid('', true) . '.ndjson';
        /** @var array<int, string> $cmdFromServer */
        $cmdFromServer = is_array($payload['command'] ?? null) ? $payload['command'] : [];
        $cmd = array_map('strval', $cmdFromServer);
        $env = $this->buildProcessEnv($callbackFile);

        $inventoryTmpFile = null;
        if (($payload['inventory_type'] ?? '') === 'static') {
            $inventoryTmpFile = $this->writeInventoryTempFile((string)($payload['inventory_content'] ?? "localhost\n"));
            $cmd = array_map(
                fn ($part) => $part === '__INVENTORY_TMP__' ? $inventoryTmpFile : $part,
                $cmd
            );
        }

        // Inject credential (SSH key, vault password, etc.) into command and env
        $credentialInjector = new CredentialInjector();
        /** @var array{credential_type: string, username: string|null, secrets: array<string, string>}|null $credData */
        $credData = $payload['credential'] ?? null;
        $injection = $credentialInjector->inject(is_array($credData) ? $credData : null);
        $cmd = array_merge($cmd, $injection->args);
        $env = array_merge($env, $injection->env);

        try {
            $executor = new RunnerProcessExecutor($this->http(), $this);
            $timeoutMinutes = (int)($payload['timeout_minutes'] ?? 120);
            [$exitCode] = $executor->run($jobId, $cmd, $payload, $env, $timeoutMinutes);

            $this->collectAndSendTasks($jobId, $callbackFile);

            $this->http()->post("/api/runner/v1/jobs/{$jobId}/complete", [
                'exit_code' => $exitCode,
                'has_changes' => false,
            ]);

            $this->stdout("Job #{$jobId} finished with exit code {$exitCode}.\n");
        } finally {
            CredentialInjector::cleanup($injection->tempFiles);
            if ($inventoryTmpFile) {
                \app\helpers\FileHelper::safeUnlink($inventoryTmpFile);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function buildProcessEnv(string $callbackFile): array
    {
        $pluginDir = dirname(__DIR__) . '/ansible/callback_plugins';

        return array_merge(getenv() ?: [], [
            'ANSIBLE_CALLBACK_PLUGINS' => $pluginDir,
            'ANSIBLE_CALLBACKS_ENABLED' => 'ansilume_callback',
            'ANSIBLE_CALLBACK_WHITELIST' => 'ansilume_callback',
            'ANSILUME_CALLBACK_FILE' => $callbackFile,
            'ANSIBLE_FORCE_COLOR' => '1',
            'PYTHONUNBUFFERED' => '1',
        ]);
    }

    private function collectAndSendTasks(int $jobId, string $callbackFile): void
    {
        if (!file_exists($callbackFile)) {
            return;
        }

        $tasks = [];
        $lines = file($callbackFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if (is_array($data)) {
                $tasks[] = $data;
            }
        }
        \app\helpers\FileHelper::safeUnlink($callbackFile);

        if (!empty($tasks)) {
            $this->http()->post("/api/runner/v1/jobs/{$jobId}/tasks", ['tasks' => $tasks]);
        }
    }

    private function http(): RunnerHttpClient
    {
        if ($this->http === null) {
            throw new \RuntimeException('HTTP client not initialized.');
        }
        return $this->http;
    }

    private function writeInventoryTempFile(string $content): string
    {
        $path = sys_get_temp_dir() . '/ansilume_inv_' . uniqid('', true) . '.yml';
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * Clone or pull the project from git if the payload carries SCM metadata.
     * Returns null on success or when no sync is needed; returns an error
     * message string on failure so the caller can fail the job cleanly.
     *
     * @param array<string, mixed> $payload
     */
    protected function syncProject(array $payload): ?string
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
        $redactedUrl = $this->redactGitUrl($scmUrl);
        $this->stdout("{$action} project: {$redactedUrl} (branch: {$scmBranch}) → {$projectPath}\n");

        return $this->runGitCommand($cmd, $projectPath, $isClone);
    }

    /**
     * Run a git command with safe environment and return null on success
     * or an error message string on failure.
     *
     * @param array<int, string> $cmd
     */
    private function runGitCommand(array $cmd, string $projectPath, bool $isClone): ?string
    {
        $env = $this->buildGitEnv();
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $startTime = microtime(true);
        $proc = proc_open($cmd, $descriptors, $pipes, null, $env);
        if ($proc === false) {
            $diag = $this->collectGitDiagnostics($projectPath, $isClone);
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
            $diag = $this->collectGitDiagnostics($projectPath, $isClone);
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

        $this->stdout("Git sync completed in {$elapsed}s\n");
        return null;
    }

    private function logGitFailure(int $exitCode, float $elapsed, string $stdout, string $stderr, string $diagnostics): void
    {
        $this->stderr("Git sync failed after {$elapsed}s (exit {$exitCode})\n");
        if ($stderr !== '') {
            $this->stderr("  stderr: {$stderr}\n");
        }
        if ($stdout !== '') {
            $this->stderr("  stdout: {$stdout}\n");
        }
        $this->stderr($diagnostics);
    }

    /**
     * Build environment variables for git subprocess.
     * Mirrors ProjectService::baseGitEnv() to ensure consistent behavior
     * between queue-based and pull-based runners.
     *
     * @return array<string, string>
     */
    private function buildGitEnv(): array
    {
        return [
            'HOME' => getenv('HOME') ?: '/root',
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'safe.directory',
            'GIT_CONFIG_VALUE_0' => '*',
        ];
    }

    /**
     * Redact credentials (user:pass@) from a git URL so logs never
     * contain tokens or passwords embedded in URLs.
     */
    private function redactGitUrl(string $url): string
    {
        return (string)preg_replace('#(://)[^/@\s]+@#', '$1***@', $url);
    }

    /**
     * Format a git command for logging, redacting any credentials that
     * may be embedded in URL arguments.
     *
     * @param array<int, string> $cmd
     */
    private function redactGitCmd(array $cmd): string
    {
        return implode(' ', array_map(fn (string $arg): string => $this->redactGitUrl($arg), $cmd));
    }

    /**
     * Collect diagnostic information about the runner environment and
     * target path state to help debug git sync failures. Never includes
     * secrets. Output is bounded so it stays readable in the job log.
     */
    private function collectGitDiagnostics(string $projectPath, bool $isClone): string
    {
        $lines = ['--- Git sync diagnostics ---'];
        $lines[] = 'git: ' . $this->diagGitVersion();
        $lines[] = 'runner user: ' . $this->diagRunnerUser();
        $lines[] = 'git env: GIT_TERMINAL_PROMPT=0, safe.directory=* (via GIT_CONFIG_*)';

        foreach ($this->diagPath('target path', $projectPath) as $l) {
            $lines[] = $l;
        }
        foreach ($this->diagPath('parent dir', dirname($projectPath)) as $l) {
            $lines[] = $l;
        }
        $lines[] = $this->diagDiskFree(dirname($projectPath));

        if (!$isClone && is_dir($projectPath . '/.git')) {
            foreach ($this->diagGitRepoState($projectPath) as $l) {
                $lines[] = $l;
            }
        }

        $lines[] = '----------------------------';
        return implode("\n", $lines) . "\n";
    }

    private function diagGitVersion(): string
    {
        $out = $this->captureShortCmd(['git', '--version']);
        return $out !== '' ? $out : 'unavailable';
    }

    private function diagRunnerUser(): string
    {
        if (!function_exists('posix_geteuid')) {
            return 'posix extension not available';
        }
        $uid = posix_geteuid();
        $gid = posix_getegid();
        $name = 'unknown';
        if (function_exists('posix_getpwuid')) {
            $info = posix_getpwuid($uid);
            if (is_array($info)) {
                $name = (string)$info['name'];
            }
        }
        return sprintf('%s (uid=%d, gid=%d)', $name, $uid, $gid);
    }

    /**
     * @return array<int, string>
     */
    private function diagPath(string $label, string $path): array
    {
        $lines = [$label . ': ' . $path];
        if (!file_exists($path)) {
            $lines[] = '  exists=no';
            return $lines;
        }
        $lines[] = sprintf(
            '  exists=yes is_dir=%s writable=%s mode=%04o',
            is_dir($path) ? 'yes' : 'no',
            is_writable($path) ? 'yes' : 'no',
            fileperms($path) & 0777,
        );
        $ownerId = fileowner($path);
        $ownerName = (string)$ownerId;
        if ($ownerId !== false && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid($ownerId);
            if (is_array($info)) {
                $ownerName = $info['name'] . ' (' . $ownerId . ')';
            }
        }
        $lines[] = '  owner: ' . $ownerName;
        return $lines;
    }

    private function diagDiskFree(string $path): string
    {
        if (!is_dir($path)) {
            return 'disk free: (parent dir missing)';
        }
        $df = disk_free_space($path);
        if ($df === false) {
            return 'disk free: unavailable';
        }
        return sprintf('disk free on %s: %.1f MB', $path, $df / 1048576);
    }

    /**
     * Collect state of an existing git checkout — effective config, remote
     * URL, branch, status. Run from inside the target directory so we see
     * exactly what git sees. URLs are redacted before logging.
     *
     * @return array<int, string>
     */
    private function diagGitRepoState(string $projectPath): array
    {
        $lines = ['git repo state (from ' . $projectPath . '):'];

        $remote = $this->captureShortCmd(['git', '-C', $projectPath, 'remote', '-v']);
        if ($remote !== '') {
            $lines[] = '  remote:';
            foreach (array_slice(explode("\n", $remote), 0, 5) as $l) {
                $lines[] = '    ' . $this->redactGitUrl($l);
            }
        }

        $branch = $this->captureShortCmd(['git', '-C', $projectPath, 'rev-parse', '--abbrev-ref', 'HEAD']);
        if ($branch !== '') {
            $lines[] = '  current branch: ' . $branch;
        }

        $config = $this->captureShortCmd(
            ['git', '-C', $projectPath, 'config', '--list', '--show-origin']
        );
        if ($config !== '') {
            $lines[] = '  effective config (first 20 lines):';
            foreach (array_slice(explode("\n", $config), 0, 20) as $l) {
                $lines[] = '    ' . $this->redactGitUrl($l);
            }
        }

        return $lines;
    }

    /**
     * Run a short diagnostic command and return trimmed stdout. Uses
     * proc_open (not shell_exec) to comply with the security check that
     * forbids shell_exec / exec outside services/. Returns empty string
     * on any failure — diagnostics must never throw.
     *
     * @param array<int, string> $cmd
     */
    private function captureShortCmd(array $cmd): string
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, null, $this->buildGitEnv());
        if (!is_resource($proc)) {
            return '';
        }
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return trim($out);
    }

    /**
     * Send an error log chunk and mark the job as failed via the runner API.
     */
    private function failJob(int $jobId, string $errorMessage): void
    {
        $this->stderr("Job #{$jobId} failed: {$errorMessage}\n");
        $this->http()->post("/api/runner/v1/jobs/{$jobId}/logs", [
            'stream' => 'stderr',
            'content' => $errorMessage,
            'sequence' => 0,
        ]);
        $this->http()->post("/api/runner/v1/jobs/{$jobId}/complete", [
            'exit_code' => 1,
            'has_changes' => false,
        ]);
    }
}
