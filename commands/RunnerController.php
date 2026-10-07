<?php

declare(strict_types=1);

namespace app\commands;

use app\components\CredentialInjector;
use app\components\PlaybookEnvironment;
use app\components\ProcessHardening;
use app\components\RunnerHttpClient;
use app\components\RunnerProcessExecutor;
use app\components\RunnerProjectSync;
use app\components\RunnerTokenResolver;
use app\components\RunnerVaultMode;
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
 *   RUNNER_GROUP             — target runner group name (optional, defaults to "default")
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
        foreach (PlaybookEnvironment::startupNotices(getenv() ?: [], new ProcessHardening()) as $notice) {
            $this->stdout($notice . "\n");
        }

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

        // Prepared before any temp file exists, so a failure leaves nothing
        // to clean up and Ansible never runs with the repository's vault
        // settings when the project asked for Ansilume's password only.
        try {
            $vaultMode = $this->vaultMode($payload);
        } catch (\RuntimeException $e) {
            $this->failJob($jobId, $e->getMessage());
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

        // Inject every template credential (primary + additional) into
        // command args and env. The runner used to call inject() with only
        // the primary credential, so operators who attached a secondary
        // token credential (e.g. a 1Password service account) got their SSH
        // key injected but not the token — and
        // lookup('env', 'OP_SERVICE_ACCOUNT_TOKEN') came back empty.
        $credentialInjector = new CredentialInjector();
        $injection = $credentialInjector->injectAll($this->resolveCredentialList($payload));
        $cmd = array_merge($cmd, $injection->args);
        $env = array_merge($env, $injection->env);
        // Last, so neither a Token credential nor an ANSIBLE_* variable of
        // the runner host can undo the vault isolation.
        ['command' => $cmd, 'env' => $env] = $vaultMode->apply($cmd, $env);

        try {
            $executor = new RunnerProcessExecutor($this->http(), $this);
            $timeoutMinutes = (int)($payload['timeout_minutes'] ?? 120);
            [$exitCode, , $timedOut] = $executor->run($jobId, $cmd, $payload, $env, $timeoutMinutes);

            $this->collectAndSendTasks($jobId, $callbackFile);

            $this->http()->post("/api/runner/v1/jobs/{$jobId}/complete", [
                'exit_code' => $exitCode,
                'has_changes' => false,
                // Lets the server distinguish a genuine non-zero exit from
                // a deadline-exceeded kill. Without this the job would land
                // on STATUS_FAILED and the "timed_out" filter wouldn't find it.
                'timed_out' => $timedOut,
            ]);

            $this->stdout(
                sprintf("Job #%d finished with exit code %d%s.\n", $jobId, $exitCode, $timedOut ? ' (timed out)' : ''),
            );
        } finally {
            CredentialInjector::cleanup($injection->tempFiles);
            if ($inventoryTmpFile) {
                \app\helpers\FileHelper::safeUnlink($inventoryTmpFile);
            }
            $vaultMode->cleanup();
        }
    }

    /**
     * Collect every credential attached to the template (primary plus
     * pivot rows) from the runner payload. Falls back to the single
     * `credential` field for pre-multi-credential server responses so
     * older runners talking to older servers still work.
     *
     * @param array<string, mixed> $payload
     * @return list<array{credential_type: string, username: string|null, env_var_name?: string|null, secrets: array<string, string>}>
     */
    private function resolveCredentialList(array $payload): array
    {
        $list = $payload['credentials'] ?? null;
        if (is_array($list) && $list !== []) {
            /** @var list<array{credential_type: string, username: string|null, env_var_name?: string|null, secrets: array<string, string>}> $list */
            return array_values($list);
        }
        $primary = $payload['credential'] ?? null;
        if (is_array($primary)) {
            /** @var array{credential_type: string, username: string|null, env_var_name?: string|null, secrets: array<string, string>} $primary */
            return [$primary];
        }
        return [];
    }

    /**
     * @return array<string, string>
     */
    private function buildProcessEnv(string $callbackFile): array
    {
        // Allowlisted environment only: playbooks must not see the runner's
        // own secrets (RUNNER_BOOTSTRAP_SECRET, RUNNER_TOKEN, the dev .env).
        return PlaybookEnvironment::build(getenv() ?: [], $callbackFile);
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
        return (new RunnerProjectSync($this))->sync($payload);
    }

    /**
     * How the job treats the repository's ansible.cfg vault settings.
     *
     * @param array<string, mixed> $payload
     * @throws \RuntimeException when the project asks for Ansilume's vault password only and the runner cannot provide it
     */
    protected function vaultMode(array $payload): RunnerVaultMode
    {
        return RunnerVaultMode::fromPayload($payload);
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
