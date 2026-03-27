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
    private const POLL_INTERVAL      = 5;   // seconds between claim attempts
    private const HEARTBEAT_INTERVAL = 30;  // seconds between heartbeats
    private const LOG_CHUNK_BYTES    = 8192;

    protected string $token         = '';
    protected string $apiUrl        = '';
    protected bool   $running       = true;
    protected int    $lastHttpStatus = 0;

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

        // Verify token on startup
        $info = $this->apiPost('/api/runner/v1/heartbeat', []);
        if ($this->lastHttpStatus === 401) {
            $name      = $_ENV['RUNNER_NAME'] ?? '';
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
        if ($info === null || empty($info['ok'])) {
            $this->stderr("ERROR: Failed to authenticate with the server. Check RUNNER_TOKEN and API_URL.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $runnerName = $info['data']['runner_name'] ?? 'unknown';
        $groupName  = $info['data']['group_name']  ?? 'unknown';
        $this->stdout("Runner '{$runnerName}' started. Group: '{$groupName}'. Polling {$this->apiUrl}\n");

        // Graceful shutdown on SIGTERM/SIGINT
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function (): void { $this->running = false; });
            pcntl_signal(SIGINT,  function (): void { $this->running = false; });
        }

        $lastHeartbeat = time();

        while ($this->running) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // Periodic heartbeat independent of job polling
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

            $lastHeartbeat = time(); // we just posted, reset timer
        }

        $this->stdout("Runner shutting down.\n");
        return ExitCode::OK;
    }

    // -------------------------------------------------------------------------
    // Token resolution — static env var or self-registration
    // -------------------------------------------------------------------------

    /**
     * Return the runner token to use, in order of preference:
     *   1. RUNNER_TOKEN env var (explicit)
     *   2. Cached token file (from a previous self-registration)
     *   3. Self-registration via RUNNER_BOOTSTRAP_SECRET
     */
    protected function resolveToken(): string
    {
        $explicit = $_ENV['RUNNER_TOKEN'] ?? '';
        if ($explicit !== '') {
            return $explicit;
        }

        $name            = $_ENV['RUNNER_NAME'] ?? '';
        $bootstrapSecret = $_ENV['RUNNER_BOOTSTRAP_SECRET'] ?? '';

        if ($name === '' || $bootstrapSecret === '') {
            return '';
        }

        // Check for a cached token from a previous registration
        $cacheFile = $this->tokenCacheFile($name);
        if (file_exists($cacheFile)) {
            $cached = trim((string)file_get_contents($cacheFile));
            if ($cached !== '') {
                return $cached;
            }
        }

        // Self-register with the server
        $this->stdout("No token found — registering as '{$name}' with the server...\n");

        $url     = $this->apiUrl . '/api/runner/v1/register';
        $payload = json_encode(['name' => $name, 'bootstrap_secret' => $bootstrapSecret]);

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Content-Length: ' . strlen($payload),
                ]),
                'content'       => $payload,
                'ignore_errors' => true,
                'timeout'       => 30,
            ],
        ]);

        $networkError = '';
        set_error_handler(function (int $errno, string $errstr) use (&$networkError): bool {
            $networkError = $errstr;
            return true;
        }, E_WARNING);
        $raw = file_get_contents($url, false, $context);
        restore_error_handler();
        if ($raw === false || $raw === '') {
            $detail = $networkError !== '' ? " ({$networkError})" : '';
            $this->stderr("ERROR: Could not reach the server at {$this->apiUrl} for registration.{$detail}\n");
            return '';
        }

        $response = json_decode($raw, true);
        if (empty($response['ok']) || empty($response['data']['token'])) {
            $error = $response['error'] ?? 'unknown error';
            $this->stderr("ERROR: Registration failed: {$error}\n");
            return '';
        }

        $token = $response['data']['token'];

        // Cache the token so we don't re-register on every restart
        \app\helpers\FileHelper::safeFilePutContents($cacheFile, $token);
        \app\helpers\FileHelper::safeChmod($cacheFile, 0600);

        $this->stdout("Registered successfully. Token cached.\n");
        return $token;
    }

    // -------------------------------------------------------------------------
    // Job execution
    // -------------------------------------------------------------------------

    private function claimJob(): ?array
    {
        $result = $this->apiPost('/api/runner/v1/jobs/claim', []);
        if ($result === null || !isset($result['data'])) {
            return null; // 204 or error
        }
        return $result['data'];
    }

    private function executeJob(int $jobId, array $payload): void
    {
        $callbackFile = sys_get_temp_dir() . '/ansilume_tasks_' . $jobId . '_' . uniqid('', true) . '.ndjson';
        $pluginDir    = dirname(__DIR__) . '/ansible/callback_plugins';

        $cmd = $this->buildCommand($payload, $callbackFile);

        $env = array_merge(getenv() ?: [], [
            'ANSIBLE_CALLBACK_PLUGINS'   => $pluginDir,
            'ANSIBLE_CALLBACKS_ENABLED'  => 'ansilume_callback',
            'ANSIBLE_CALLBACK_WHITELIST' => 'ansilume_callback',
            'ANSILUME_CALLBACK_FILE'     => $callbackFile,
            'ANSIBLE_FORCE_COLOR'        => '1',
            'PYTHONUNBUFFERED'           => '1',
        ]);

        $inventoryTmpFile = null;
        if ($payload['inventory_type'] === 'static') {
            $inventoryTmpFile = $this->writeInventoryTempFile($payload['inventory_content'] ?? "localhost\n");
            // Replace the placeholder path in cmd
            $cmd = array_map(
                fn($part) => $part === '__INVENTORY_TMP__' ? $inventoryTmpFile : $part,
                $cmd
            );
        }

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Run from the project root so Ansible finds ansible.cfg there,
        // which resolves roles_path, collections_path, etc. correctly.
        $projectCwd = $payload['project_path'] ?? null;
        $process    = proc_open($cmd, $descriptorspec, $pipes, ($projectCwd && is_dir($projectCwd)) ? $projectCwd : null, $env);

        if (!is_resource($process)) {
            $this->apiPost("/api/runner/v1/jobs/{$jobId}/logs", [
                'stream'   => 'stderr',
                'content'  => "proc_open failed — cannot execute ansible-playbook\n",
                'sequence' => 0,
            ]);
            $this->apiPost("/api/runner/v1/jobs/{$jobId}/complete", ['exit_code' => -1]);
            return;
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $timeoutMinutes = (int)($payload['timeout_minutes'] ?? 120);
        $deadline       = time() + ($timeoutMinutes * 60);
        $sequence       = 0;
        $timedOut       = false;

        while (!feof($pipes[1]) || !feof($pipes[2])) {
            $remaining = $deadline - time();
            if ($remaining <= 0) {
                $this->stdout("Job #{$jobId} exceeded timeout of {$timeoutMinutes}m — killing process.\n");
                proc_terminate($process, 15); // SIGTERM
                sleep(3);
                proc_terminate($process, 9);  // SIGKILL
                $timedOut = true;
                break;
            }

            $read    = array_filter([$pipes[1], $pipes[2]], fn($p) => is_resource($p) && !feof($p));
            $write   = null;
            $except  = null;
            // stream_select emits E_WARNING on signal interruption (SIGCHLD) — not actionable
            $changed = @stream_select($read, $write, $except, min($remaining, 5)); // @phpcs:ignore

            if ($changed === false || $changed === 0) {
                continue;
            }

            foreach ($read as $stream) {
                $chunk = fread($stream, self::LOG_CHUNK_BYTES);
                if ($chunk !== false && $chunk !== '') {
                    $streamName = ($stream === $pipes[1]) ? 'stdout' : 'stderr';
                    $this->apiPost("/api/runner/v1/jobs/{$jobId}/logs", [
                        'stream'   => $streamName,
                        'content'  => $chunk,
                        'sequence' => $sequence++,
                    ]);
                }
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($timedOut) {
            $this->apiPost("/api/runner/v1/jobs/{$jobId}/logs", [
                'stream'   => 'stderr',
                'content'  => "\n[ansilume] Job killed: exceeded timeout of {$timeoutMinutes} minutes.\n",
                'sequence' => $sequence++,
            ]);
            $exitCode = -1;
        }

        // Send task results
        if (file_exists($callbackFile)) {
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

        $hasChanges = false;
        // has_changes is determined server-side from task data

        $this->apiPost("/api/runner/v1/jobs/{$jobId}/complete", [
            'exit_code'   => $exitCode,
            'has_changes' => $hasChanges,
        ]);

        if ($inventoryTmpFile) {
            \app\helpers\FileHelper::safeUnlink($inventoryTmpFile);
        }

        $this->stdout("Job #{$jobId} finished with exit code {$exitCode}.\n");
    }

    private function buildCommand(array $payload, string $callbackFile): array
    {
        $cmd = ['ansible-playbook'];

        // Inventory
        if ($payload['inventory_type'] === 'static') {
            $cmd[] = '-i';
            $cmd[] = '__INVENTORY_TMP__'; // replaced after temp file is written
        } elseif (!empty($payload['inventory_path'])) {
            $cmd[] = '-i';
            $cmd[] = $payload['inventory_path'];
        }

        // Playbook
        $cmd[] = $payload['playbook_path'];

        // Verbosity
        $verbosity = (int)($payload['verbosity'] ?? 0);
        if ($verbosity > 0) {
            $cmd[] = '-' . str_repeat('v', min($verbosity, 5));
        }

        // Forks
        if (!empty($payload['forks'])) {
            $cmd[] = '--forks';
            $cmd[] = (string)(int)$payload['forks'];
        }

        // Become
        if (!empty($payload['become'])) {
            $cmd[] = '--become';
            $cmd[] = '--become-method';
            $cmd[] = $payload['become_method'] ?? 'sudo';
            $cmd[] = '--become-user';
            $cmd[] = $payload['become_user'] ?? 'root';
        }

        // Limit
        if (!empty($payload['limit'])) {
            $cmd[] = '--limit';
            $cmd[] = $payload['limit'];
        }

        // Tags
        if (!empty($payload['tags'])) {
            $cmd[] = '--tags';
            $cmd[] = $payload['tags'];
        }
        if (!empty($payload['skip_tags'])) {
            $cmd[] = '--skip-tags';
            $cmd[] = $payload['skip_tags'];
        }

        // Extra vars
        if (!empty($payload['extra_vars'])) {
            $cmd[] = '--extra-vars';
            $cmd[] = $payload['extra_vars'];
        }

        return $cmd;
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
     * POST JSON to the ansilume API. Returns decoded response body or null on error / 204.
     */
    protected function apiPost(string $path, array $body): ?array
    {
        $url     = $this->apiUrl . $path;
        $payload = json_encode($body);

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => implode("\r\n", [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->token,
                    'Accept: application/json',
                    'Content-Length: ' . strlen($payload),
                ]),
                'content'         => $payload,
                'ignore_errors'   => true,
                'timeout'         => 30,
            ],
            'ssl' => [
                'verify_peer' => true,
            ],
        ]);

        $networkError = '';
        set_error_handler(function (int $errno, string $errstr) use (&$networkError): bool {
            $networkError = $errstr;
            return true;
        }, E_WARNING);
        $raw = file_get_contents($url, false, $context);
        restore_error_handler();

        $this->lastHttpStatus = 0;
        if (!empty($http_response_header)) {
            preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m);
            $this->lastHttpStatus = (int)($m[1] ?? 0);
        }

        if ($raw === false || $raw === '') {
            return null;
        }

        return json_decode($raw, true) ?: null;
    }
}
