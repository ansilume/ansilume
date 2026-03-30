<?php

declare(strict_types=1);

namespace app\commands;

use app\components\RunnerCommandBuilder;
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

        $runnerName = $info['data']['runner_name'] ?? 'unknown';
        $groupName = $info['data']['group_name'] ?? 'unknown';
        $this->stdout("Runner '{$runnerName}' started. Group: '{$groupName}'. Polling {$apiUrl}\n");

        $this->registerSignalHandlers();
        $this->pollLoop();

        $this->stdout("Runner shutting down.\n");
        return ExitCode::OK;
    }

    protected function verifyTokenWithRetry(): ?array
    {
        $info = $this->http->post('/api/runner/v1/heartbeat', []);

        if ($this->http->getLastHttpStatus() === 401 && $this->tokenResolver->hasCacheFile()) {
            $token = $this->tokenResolver->clearCacheAndResolve();
            if ($token !== '') {
                $this->http->setToken($token);
                $info = $this->http->post('/api/runner/v1/heartbeat', []);
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
                $this->http->post('/api/runner/v1/heartbeat', []);
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

    private function claimJob(): ?array
    {
        $result = $this->http->post('/api/runner/v1/jobs/claim', []);
        if ($result === null || !isset($result['data'])) {
            return null;
        }
        return $result['data'];
    }

    private function executeJob(int $jobId, array $payload): void
    {
        $callbackFile = sys_get_temp_dir() . '/ansilume_tasks_' . $jobId . '_' . uniqid('', true) . '.ndjson';
        $builder = new RunnerCommandBuilder();
        $cmd = $builder->build($payload);
        $env = $this->buildProcessEnv($callbackFile);

        $inventoryTmpFile = null;
        if ($payload['inventory_type'] === 'static') {
            $inventoryTmpFile = $this->writeInventoryTempFile($payload['inventory_content'] ?? "localhost\n");
            $cmd = array_map(
                fn ($part) => $part === '__INVENTORY_TMP__' ? $inventoryTmpFile : $part,
                $cmd
            );
        }

        $executor = new RunnerProcessExecutor($this->http, $this);
        $timeoutMinutes = (int)($payload['timeout_minutes'] ?? 120);
        [$exitCode, $sequence] = $executor->run($jobId, $cmd, $payload, $env, $timeoutMinutes);

        $this->collectAndSendTasks($jobId, $callbackFile);

        $this->http->post("/api/runner/v1/jobs/{$jobId}/complete", [
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
            $this->http->post("/api/runner/v1/jobs/{$jobId}/tasks", ['tasks' => $tasks]);
        }
    }

    private function writeInventoryTempFile(string $content): string
    {
        $path = sys_get_temp_dir() . '/ansilume_inv_' . uniqid('', true);
        file_put_contents($path, $content);
        return $path;
    }
}
