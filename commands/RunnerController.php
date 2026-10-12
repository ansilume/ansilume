<?php

declare(strict_types=1);

namespace app\commands;

use app\components\CredentialInjector;
use app\components\OutputMasker;
use app\components\PlaybookEnvironment;
use app\components\ProcessHardening;
use app\components\RunnerHttpClient;
use app\components\RunnerProcessExecutor;
use app\components\RunnerProjectSync;
use app\components\RunnerSecretVars;
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

    /** The job fails with this when its private directory, task records file or static inventory cannot be created. */
    public const JOB_FILES_FAILED = "The runner could not create the job's private directory or files in its temp directory.";

    /** The callback plugin's task records file, in the job's private directory. */
    public const TASKS_FILE = 'tasks.ndjson';

    /** A static inventory, in the job's private directory; .yml keeps the YAML inventory plugin in play. */
    public const INVENTORY_FILE = 'inventory.yml';

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
        // Before anything that can fail: a runner that cannot reach the
        // server still removes the job files (plaintext answers, inventories)
        // a killed process left, and names those it may not remove and the
        // files older runners left. Best effort: nothing in it may keep the
        // runner from starting.
        try {
            $this->removeStaleSecretFiles();
        } catch (\Throwable $e) {
            $this->stderr('WARNING: The check for job directories left behind failed (' . get_class($e) . ").\n");
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
    private function executeJob(int $jobId, #[\SensitiveParameter] array $payload): void
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

        // The job's private directory and everything in it (task records,
        // static inventory, survey answers) and the vault decoy go whatever
        // happens, also when the credential injection throws.
        $jobFiles = $this->secretVars();
        try {
            $this->runInJobDirectory($jobId, $payload, $vaultMode, $jobFiles);
        } finally {
            $vaultMode->cleanup();
            $jobFiles->cleanup();
        }
    }

    /**
     * Runs the job with its own files in a new directory that only the
     * runner's user can open. A static inventory must not sit in the shared
     * temp directory: Ansible loads group_vars/ and host_vars/ from the
     * inventory's directory, whoever owns them, so any local user could add
     * variables, connection variables included, to the job. When the
     * directory cannot be created, the job fails without running.
     *
     * @param array<string, mixed> $payload
     */
    private function runInJobDirectory(
        int $jobId,
        #[\SensitiveParameter] array $payload,
        RunnerVaultMode $vaultMode,
        RunnerSecretVars $jobFiles
    ): void {
        $directory = $jobFiles->jobDirectory();
        if ($directory === null) {
            $this->failJob($jobId, self::JOB_FILES_FAILED);
            return;
        }
        $callbackFile = $directory . '/' . self::TASKS_FILE;
        $inventoryFile = null;
        /** @var array<int, string> $cmdFromServer */
        $cmdFromServer = is_array($payload['command'] ?? null) ? $payload['command'] : [];
        $cmd = array_map('strval', $cmdFromServer);
        if (($payload['inventory_type'] ?? '') === 'static') {
            $inventoryFile = $directory . '/' . self::INVENTORY_FILE;
            $cmd = array_map(
                static fn (string $part): string => $part === '__INVENTORY_TMP__' ? $inventoryFile : $part,
                $cmd
            );
        }

        // Inject every template credential (primary + additional) into
        // command args and env. The runner used to call inject() with only
        // the primary credential, so operators who attached a secondary
        // token credential (e.g. a 1Password service account) got their SSH
        // key injected but not the token — and
        // lookup('env', 'OP_SERVICE_ACCOUNT_TOKEN') came back empty.
        $injection = (new CredentialInjector())->injectAll($this->resolveCredentialList($payload));
        try {
            $cmd = array_merge($cmd, $injection->args);
            $env = array_merge($this->buildProcessEnv($callbackFile), $injection->env);
            // Last, so neither a Token credential nor an ANSIBLE_* variable of
            // the runner host can undo the vault isolation.
            ['command' => $cmd, 'env' => $env] = $vaultMode->apply($cmd, $env);

            $this->runCommand($jobId, $cmd, $env, $payload, $callbackFile, $inventoryFile, $jobFiles);
        } finally {
            CredentialInjector::cleanup($injection->tempFiles);
        }
    }

    /**
     * Runs the prepared command and reports the result. The job's files come
     * first (task records, static inventory, survey answers), inside
     * executeJob()'s try, so its finally removes them with the job's
     * directory; when one cannot be written the job fails instead of running
     * without it. Output and task records are masked with the answers.
     *
     * @param list<string> $cmd
     * @param array<string, string> $env
     * @param array<string, mixed> $payload
     */
    private function runCommand(
        int $jobId,
        #[\SensitiveParameter] array $cmd,
        #[\SensitiveParameter] array $env,
        #[\SensitiveParameter] array $payload,
        string $callbackFile,
        ?string $inventoryFile,
        RunnerSecretVars $jobFiles
    ): void {
        try {
            $this->writeJobFiles($callbackFile, $inventoryFile, $payload);
            $cmd = array_merge($cmd, $jobFiles->prepare($payload));
        } catch (\RuntimeException $e) {
            $this->failJob($jobId, $e->getMessage());
            return;
        }
        $masker = new OutputMasker(RunnerSecretVars::answers($payload));
        $executor = new RunnerProcessExecutor($this->http(), $this, $masker);
        $timeoutMinutes = (int)($payload['timeout_minutes'] ?? 120);
        [$exitCode, , $timedOut] = $executor->run($jobId, $cmd, $payload, $env, $timeoutMinutes);

        $this->collectAndSendTasks($jobId, $callbackFile, $masker);

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
    private function resolveCredentialList(#[\SensitiveParameter] array $payload): array
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

    /**
     * Posts the task records of the callback plugin. Ansible templates task
     * names, so their name and host go through the masker first.
     */
    private function collectAndSendTasks(int $jobId, string $callbackFile, OutputMasker $masker): void
    {
        if (!file_exists($callbackFile)) {
            return;
        }

        $tasks = [];
        $lines = file($callbackFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if (is_array($data)) {
                $tasks[] = $masker->maskValues($data, ['name', 'host']);
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

    /**
     * Creates the callback plugin's task records file, whose task names are
     * templated and can carry answers, and writes the static inventory,
     * which can hold host passwords, in the job's private directory: both
     * readable by the runner's user only. The plugin opens its file with
     * open(path, 'w'), which keeps the mode of the file it finds.
     *
     * @param array<string, mixed> $payload
     * @throws \RuntimeException JOB_FILES_FAILED
     */
    private function writeJobFiles(string $callbackFile, ?string $inventoryFile, #[\SensitiveParameter] array $payload): void
    {
        $written = RunnerSecretVars::writePrivateFile($callbackFile, '')
            && ($inventoryFile === null
                || RunnerSecretVars::writePrivateFile($inventoryFile, (string)($payload['inventory_content'] ?? "localhost\n")));
        if (!$written) {
            throw new \RuntimeException(self::JOB_FILES_FAILED);
        }
    }

    /**
     * Clone or pull the project from git if the payload carries SCM metadata.
     * Returns null on success or when no sync is needed; returns an error
     * message string on failure so the caller can fail the job cleanly.
     *
     * @param array<string, mixed> $payload the claim payload, with the job's credentials and survey answers
     */
    protected function syncProject(#[\SensitiveParameter] array $payload): ?string
    {
        return (new RunnerProjectSync($this))->sync($payload);
    }

    /**
     * The private directory of one job, with its survey answers file, in
     * tempDirectory().
     */
    protected function secretVars(): RunnerSecretVars
    {
        return new RunnerSecretVars($this->tempDirectory());
    }

    /**
     * Removes the job directories that a runner process killed during a job
     * left behind (a container keeps its temp directory across restarts).
     * A stale match it may not remove (another user's directory, a file, a
     * link, a directory it could not empty, any match while it cannot tell
     * its own user) is named once, so the operator can remove it: it can
     * hold answers or host passwords in plain text. So is every file that a
     * runner of 2.8.1 or older wrote straight into the temp directory, which
     * it never removes, since such a runner may still run on the host. Only
     * names of the runner's own formats are named, and those hold fixed
     * text, digits and hex digits only. Each path is printed with its
     * control characters escaped (printablePath()), as the temp directory's
     * own name is the operator's: no path adds a line or a terminal
     * sequence to the log.
     */
    protected function removeStaleSecretFiles(): void
    {
        $jobFiles = $this->secretVars();
        $sweep = $jobFiles->removeStale();
        foreach ($sweep['skipped'] as $path => $reason) {
            $this->stderr(
                'WARNING: Left ' . self::printablePath($path) . " in place ({$reason}). It may hold survey answers"
                . " or host passwords of an earlier runner process; remove it by hand.\n"
            );
        }
        if ($sweep['removed'] > 0) {
            $this->stdout("Removed the private directories of {$sweep['removed']} job(s) that an earlier runner process left behind.\n");
        }
        foreach ($jobFiles->filesOfOlderRunners() as $path) {
            $this->stderr(
                'WARNING: Left ' . self::printablePath($path) . ' in place (a runner of 2.8.1 or older left it).'
                . ' It may hold host passwords or survey answers; remove it by hand once no runner of 2.8.1 or older'
                . " runs on this host.\n"
            );
        }
    }

    /**
     * $path as the start-up check prints it: the control characters, bytes
     * 0x00 to 0x1F and 0x7F, escaped as in C (a line break as \n, an escape
     * as \033), so that a path is one line in the log and sends no terminal
     * sequence.
     */
    private static function printablePath(string $path): string
    {
        return addcslashes($path, "\0..\37\177");
    }

    /**
     * Where the jobs' private directories go.
     */
    protected function tempDirectory(): string
    {
        return sys_get_temp_dir();
    }

    /**
     * How the job treats the repository's ansible.cfg vault settings.
     *
     * @param array<string, mixed> $payload
     * @throws \RuntimeException when the project asks for Ansilume's vault password only and the runner cannot provide it
     */
    protected function vaultMode(#[\SensitiveParameter] array $payload): RunnerVaultMode
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
