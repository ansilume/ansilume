<?php

declare(strict_types=1);

namespace app\commands;

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
    private const POLL_INTERVAL = 5; // seconds between claim attempts
    private const HEARTBEAT_INTERVAL = 30; // seconds between heartbeats
    private const LOG_CHUNK_BYTES = 8192;

    protected string $token = '';
    protected string $apiUrl = '';
    protected bool $running = true;
    protected int $lastHttpStatus = 0;

    public function actionStart(): int
    {
        $this->apiUrl = rtrim($_ENV['API_URL'] ?? '', '/');

        if ($this->apiUrl === '') {
            $this->stderr("ERROR: API_URL environment variable is required.\n");
            return ExitCode::CONFIG;
        }

        $this->token = $this->resolveToken();
        if ($this->token === '') {
            $this->stderr(
                "ERROR: No runner token available.\n" .
                "Set RUNNER_TOKEN, or set RUNNER_NAME + RUNNER_BOOTSTRAP_SECRET for auto-registration.\n"
            );
            return ExitCode::CONFIG;
        }

        $info = $this->verifyTokenWithRetry();
        if ($info === null || empty($info['ok'])) {
            $this->stderr("ERROR: Failed to authenticate with the server. Check RUNNER_TOKEN and API_URL.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $runnerName = $info['data']['runner_name'] ?? 'unknown';
        $groupName = $info['data']['group_name'] ?? 'unknown';
        $this->stdout("Runner '{$runnerName}' started. Group: '{$groupName}'. Polling {$this->apiUrl}\n");

        $this->registerSignalHandlers();
        $this->pollLoop();

        $this->stdout("Runner shutting down.\n");
        return ExitCode::OK;
    }

    // -------------------------------------------------------------------------
    // Start-up helpers
    // -------------------------------------------------------------------------

    /**
     * Verify the token via heartbeat, retrying with re-registration on 401.
     */
    protected function verifyTokenWithRetry(): ?array
    {
        $info = $this->apiPost('/api/runner/v1/heartbeat', []);

        if ($this->lastHttpStatus === 401) {
            $name = $_ENV['RUNNER_NAME'] ?? '';
            $cacheFile = $name !== '' ? $this->tokenCacheFile($name) : '';
            if ($cacheFile !== '' && file_exists($cacheFile)) {
                $this->stdout("Cached token rejected (401) — clearing cache and re-registering...\n");
                \app\helpers\FileHelper::safeUnlink($cacheFile);
                $this->token = $this->resolveToken();
                if ($this->token !== '') {
                    $info = $this->apiPost('/api/runner/v1/heartbeat', []);
                }
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
                $this->apiPost('/api/runner/v1/heartbeat', []);
                $lastHeartbeat = time();
            }

            $payload = $this->claimJob();

            if ($payload === null) {
                sleep(self::POLL_INTERVAL);
                continue;
            }

            $jobId = (int)($payload['job_id'] ?? 0);
            $this->stdout("Claimed job #{$jobId}. Executing...\n");

            $this->executeJob($jobId, $payload);

            $lastHeartbeat = time();
        }
    }

    // -------------------------------------------------------------------------
    // Token resolution — static env var or self-registration
    // -------------------------------------------------------------------------

    protected function resolveToken(): string
    {
        $explicit = $_ENV['RUNNER_TOKEN'] ?? '';
        if ($explicit !== '') {
            return $explicit;
        }

        $name = $_ENV['RUNNER_NAME'] ?? '';
        $bootstrapSecret = $_ENV['RUNNER_BOOTSTRAP_SECRET'] ?? '';

        if ($name === '' || $bootstrapSecret === '') {
            return '';
        }

        $cached = $this->readCachedToken($name);
        if ($cached !== '') {
            return $cached;
        }

        return $this->selfRegister($name, $bootstrapSecret);
    }

    protected function readCachedToken(string $name): string
    {
        $cacheFile = $this->tokenCacheFile($name);
        if (file_exists($cacheFile)) {
            $cached = trim((string)file_get_contents($cacheFile));
            if ($cached !== '') {
                return $cached;
            }
        }
        return '';
    }

    protected function selfRegister(string $name, string $bootstrapSecret): string
    {
        $this->stdout("No token found — registering as '{$name}' with the server...\n");

        $response = $this->apiPostUnauthenticated('/api/runner/v1/register', [
            'name' => $name,
            'bootstrap_secret' => $bootstrapSecret,
        ]);

        if ($response === null) {
            $this->stderr("ERROR: Could not reach the server at {$this->apiUrl} for registration.\n");
            return '';
        }

        if (empty($response['ok']) || empty($response['data']['token'])) {
            $error = $response['error'] ?? 'unknown error';
            $this->stderr("ERROR: Registration failed: {$error}\n");
            return '';
        }

        $token = $response['data']['token'];
        $this->cacheToken($name, $token);

        $this->stdout("Registered successfully. Token cached.\n");
        return $token;
    }

    private function cacheToken(string $name, string $token): void
    {
        $cacheFile = $this->tokenCacheFile($name);
        \app\helpers\FileHelper::safeFilePutContents($cacheFile, $token);
        \app\helpers\FileHelper::safeChmod($cacheFile, 0600);
    }

    // -------------------------------------------------------------------------
    // Job execution
    // -------------------------------------------------------------------------

    private function claimJob(): ?array
    {
        $result = $this->apiPost('/api/runner/v1/jobs/claim', []);
        if ($result === null || !isset($result['data'])) {
            return null;
        }
        return $result['data'];
    }

    private function executeJob(int $jobId, array $payload): void
    {
        $callbackFile = sys_get_temp_dir() . '/ansilume_tasks_' . $jobId . '_' . uniqid('', true) . '.ndjson';
        $cmd = $this->buildCommand($payload, $callbackFile);
        $env = $this->buildProcessEnv($callbackFile);

        $inventoryTmpFile = null;
        if ($payload['inventory_type'] === 'static') {
            $inventoryTmpFile = $this->writeInventoryTempFile($payload['inventory_content'] ?? "localhost\n");
            $cmd = array_map(
                fn($part) => $part === '__INVENTORY_TMP__' ? $inventoryTmpFile : $part,
                $cmd
            );
        }

        $process = $this->startProcess($jobId, $cmd, $payload, $env);
        if ($process === null) {
            return;
        }

        [$pipes, $proc] = $process;
        $timeoutMinutes = (int)($payload['timeout_minutes'] ?? 120);

        [$exitCode, $sequence] = $this->streamProcessOutput($jobId, $proc, $pipes, $timeoutMinutes);

        $this->collectAndSendTasks($jobId, $callbackFile);

        $this->apiPost("/api/runner/v1/jobs/{$jobId}/complete", [
            'exit_code' => $exitCode,
            'has_changes' => false,
        ]);

        if ($inventoryTmpFile) {
            \app\helpers\FileHelper::safeUnlink($inventoryTmpFile);
        }

        $this->stdout("Job #{$jobId} finished with exit code {$exitCode}.\n");
    }

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

    /**
     * @return array{array, resource}|null  [pipes, process] or null on failure
     */
    private function startProcess(int $jobId, array $cmd, array $payload, array $env): ?array
    {
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $projectCwd = $payload['project_path'] ?? null;
        $cwd = ($projectCwd && is_dir($projectCwd)) ? $projectCwd : null;
        $process = proc_open($cmd, $descriptorspec, $pipes, $cwd, $env);

        if (!is_resource($process)) {
            $this->apiPost("/api/runner/v1/jobs/{$jobId}/logs", [
                'stream' => 'stderr',
                'content' => "proc_open failed — cannot execute ansible-playbook\n",
                'sequence' => 0,
            ]);
            $this->apiPost("/api/runner/v1/jobs/{$jobId}/complete", ['exit_code' => -1]);
            return null;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$pipes, $process];
    }

    /**
     * Read stdout/stderr from the process and stream to the server.
     *
     * @return array{int, int}  [exit code, final sequence number]
     */
    private function streamProcessOutput(int $jobId, $process, array $pipes, int $timeoutMinutes): array
    {
        $deadline = time() + ($timeoutMinutes * 60);
        $sequence = 0;
        $timedOut = false;

        while (!feof($pipes[1]) || !feof($pipes[2])) {
            $remaining = $deadline - time();
            if ($remaining <= 0) {
                $this->killTimedOutProcess($jobId, $process, $timeoutMinutes);
                $timedOut = true;
                break;
            }

            $sequence = $this->drainAndStreamLogs($jobId, $pipes, $sequence, $remaining);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($timedOut) {
            $this->sendTimeoutLog($jobId, $sequence++, $timeoutMinutes);
            $exitCode = -1;
        }

        return [$exitCode, $sequence];
    }

    /**
     * Kill a process that exceeded its timeout.
     *
     * @param resource $process
     */
    private function killTimedOutProcess(int $jobId, $process, int $timeoutMinutes): void
    {
        $this->stdout("Job #{$jobId} exceeded timeout of {$timeoutMinutes}m — killing process.\n");
        proc_terminate($process, 15);
        sleep(3);
        proc_terminate($process, 9);
    }

    /**
     * Read available output from process pipes and stream to the server.
     *
     * @return int Updated sequence number.
     */
    private function drainAndStreamLogs(int $jobId, array $pipes, int $sequence, int $remaining): int
    {
        $read = $this->selectReadablePipes($pipes, $remaining);
        if ($read === null) {
            return $sequence;
        }

        foreach ($read as $stream) {
            $sequence = $this->readAndPostChunk($jobId, $stream, $pipes[1], $sequence);
        }

        return $sequence;
    }

    /**
     * Wait for readable pipes via stream_select.
     * Returns the readable pipes, or null if nothing is ready.
     *
     * @return resource[]|null
     */
    private function selectReadablePipes(array $pipes, int $remaining): ?array
    {
        $read = array_filter([$pipes[1], $pipes[2]], fn($p) => is_resource($p) && !feof($p));
        $write = null;
        $except = null;
        // stream_select emits E_WARNING on signal interruption (SIGCHLD) — not actionable
        $changed = @stream_select($read, $write, $except, min($remaining, 5)); // @phpcs:ignore

        if ($changed === false || $changed === 0) {
            return null;
        }

        return $read;
    }

    /**
     * Read a chunk from a stream and POST it to the server.
     *
     * @param resource $stream     The pipe to read from.
     * @param resource $stdoutPipe Reference pipe to distinguish stdout from stderr.
     * @return int Updated sequence number.
     */
    private function readAndPostChunk(int $jobId, $stream, $stdoutPipe, int $sequence): int
    {
        $chunk = fread($stream, self::LOG_CHUNK_BYTES);
        if ($chunk === false || $chunk === '') {
            return $sequence;
        }

        $streamName = ($stream === $stdoutPipe) ? 'stdout' : 'stderr';
        $this->apiPost("/api/runner/v1/jobs/{$jobId}/logs", [
            'stream' => $streamName,
            'content' => $chunk,
            'sequence' => $sequence,
        ]);

        return $sequence + 1;
    }

    private function sendTimeoutLog(int $jobId, int $sequence, int $timeoutMinutes): void
    {
        $this->apiPost("/api/runner/v1/jobs/{$jobId}/logs", [
            'stream' => 'stderr',
            'content' => "\n[ansilume] Job killed: exceeded timeout of {$timeoutMinutes} minutes.\n",
            'sequence' => $sequence,
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
            $this->apiPost("/api/runner/v1/jobs/{$jobId}/tasks", ['tasks' => $tasks]);
        }
    }

    private function buildCommand(array $payload, string $callbackFile): array
    {
        $cmd = ['ansible-playbook'];

        $this->addInventoryArgs($cmd, $payload);

        $cmd[] = $payload['playbook_path'];

        $this->addPlaybookOptions($cmd, $payload);

        return $cmd;
    }

    private function addInventoryArgs(array &$cmd, array $payload): void
    {
        if ($payload['inventory_type'] === 'static') {
            $cmd[] = '-i';
            $cmd[] = '__INVENTORY_TMP__';
        } elseif (!empty($payload['inventory_path'])) {
            $cmd[] = '-i';
            $cmd[] = $payload['inventory_path'];
        }
    }

    private function addPlaybookOptions(array &$cmd, array $payload): void
    {
        $this->addVerbosityFlag($cmd, (int)($payload['verbosity'] ?? 0));
        $this->addBecomeFlags($cmd, $payload);

        $optionMap = [
            'forks' => '--forks',
            'limit' => '--limit',
            'tags' => '--tags',
            'skip_tags' => '--skip-tags',
            'extra_vars' => '--extra-vars',
        ];

        foreach ($optionMap as $key => $flag) {
            if (!empty($payload[$key])) {
                $cmd[] = $flag;
                $cmd[] = $key === 'forks' ? (string)(int)$payload[$key] : $payload[$key];
            }
        }
    }

    private function addVerbosityFlag(array &$cmd, int $verbosity): void
    {
        if ($verbosity > 0) {
            $cmd[] = '-' . str_repeat('v', min($verbosity, 5));
        }
    }

    private function addBecomeFlags(array &$cmd, array $payload): void
    {
        if (empty($payload['become'])) {
            return;
        }

        $cmd[] = '--become';
        $cmd[] = '--become-method';
        $cmd[] = $payload['become_method'] ?? 'sudo';
        $cmd[] = '--become-user';
        $cmd[] = $payload['become_user'] ?? 'root';
    }

    protected function tokenCacheFile(string $name): string
    {
        return '/var/www/runtime/runner-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) . '.token';
    }

    private function writeInventoryTempFile(string $content): string
    {
        $path = sys_get_temp_dir() . '/ansilume_inv_' . uniqid('', true);
        file_put_contents($path, $content);
        return $path;
    }

    // -------------------------------------------------------------------------
    // HTTP helpers — no Yii HTTP client, just raw curl/fopen
    // -------------------------------------------------------------------------

    /**
     * POST JSON to the ansilume API with Bearer authentication.
     */
    protected function apiPost(string $path, array $body): ?array
    {
        return $this->httpPost($path, $body, [
            'Authorization: Bearer ' . $this->token,
        ]);
    }

    /**
     * POST JSON to the ansilume API without authentication (for registration).
     */
    protected function apiPostUnauthenticated(string $path, array $body): ?array
    {
        return $this->httpPost($path, $body);
    }

    /**
     * Low-level HTTP POST. Returns decoded JSON response or null on network error / empty body.
     *
     * @param string[] $extraHeaders Additional HTTP headers.
     */
    private function httpPost(string $path, array $body, array $extraHeaders = []): ?array
    {
        $url = $this->apiUrl . $path;
        $payload = json_encode($body);

        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . strlen($payload),
        ], $extraHeaders);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'ignore_errors' => true,
                'timeout' => 30,
            ],
            'ssl' => [
                'verify_peer' => true,
            ],
        ]);

        $raw = $this->fetchUrl($url, $context);

        if ($raw === false || $raw === '') {
            return null;
        }

        return json_decode($raw, true) ?: null;
    }

    /**
     * Execute file_get_contents with error handling and HTTP status extraction.
     *
     * @param resource $context
     * @return string|false
     */
    private function fetchUrl(string $url, $context)
    {
        set_error_handler(function (): bool {
            return true;
        }, E_WARNING);
        $raw = file_get_contents($url, false, $context);
        restore_error_handler();

        $this->lastHttpStatus = 0;
        if (!empty($http_response_header)) {
            preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m);
            $this->lastHttpStatus = (int)($m[1] ?? 0);
        }

        return $raw;
    }
}
