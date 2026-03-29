<?php

declare(strict_types=1);

namespace app\jobs;

use app\jobs\JobTimeoutException;
use app\models\Job;
use app\models\JobLog;
use app\models\JobTask;
use app\models\Webhook;
use app\services\AuditService;
use app\services\NotificationService;
use app\services\WebhookService;
use yii\base\BaseObject;
use yii\queue\JobInterface;

/**
 * Queue job that executes an Ansible playbook for a given Job record.
 *
 * This class is the boundary between the web application and the worker process.
 * It must:
 *  - Load the persisted Job record.
 *  - Transition status from queued → running → succeeded/failed.
 *  - Write stdout/stderr chunks to job_log.
 *  - Never expose raw secrets in logs.
 */
class RunAnsibleJob extends BaseObject implements JobInterface
{
    public int $jobId = 0;

    public function execute($queue): void
    {
        $job = Job::findOne($this->jobId);
        if ($job === null) {
            \Yii::error("RunAnsibleJob: job #{$this->jobId} not found.", __CLASS__);
            return;
        }

        if (!in_array($job->status, [Job::STATUS_QUEUED, Job::STATUS_PENDING], true)) {
            \Yii::warning("RunAnsibleJob: job #{$job->id} is in unexpected status {$job->status}, skipping.", __CLASS__);
            return;
        }

        $this->transitionToRunning($job);

        try {
            $exitCode = $this->runPlaybook($job);
            $this->transitionToFinished($job, $exitCode);
        } catch (JobTimeoutException $e) {
            \Yii::warning("RunAnsibleJob: job #{$job->id} timed out after {$e->getTimeoutMinutes()} minutes.", __CLASS__);
            $this->appendLog($job, JobLog::STREAM_STDERR, "\n[ansilume] Job timed out after {$e->getTimeoutMinutes()} minutes and was terminated.");
            $this->transitionToTimedOut($job);
        } catch (\Throwable $e) {
            \Yii::error("RunAnsibleJob: job #{$job->id} threw exception: " . $e->getMessage(), __CLASS__);
            $this->appendLog($job, JobLog::STREAM_STDERR, 'Runner error: ' . $e->getMessage());
            $this->transitionToFailed($job, -1);
        }
    }

    private function transitionToRunning(Job $job): void
    {
        $job->status = Job::STATUS_RUNNING;
        $job->started_at = time();
        $job->pid = null;
        $job->worker_id = gethostname() . ':' . getmypid();
        $job->save(false);

        \Yii::$app->get('auditService')->log(
            AuditService::ACTION_JOB_STARTED,
            'job',
            $job->id,
        );

        /** @var WebhookService $ws */
        $ws = \Yii::$app->get('webhookService');
        $ws->dispatch(Webhook::EVENT_JOB_STARTED, $job);
    }

    private function transitionToFinished(Job $job, int $exitCode): void
    {
        $job->exit_code = $exitCode;
        $job->finished_at = time();
        $job->status = $exitCode === 0 ? Job::STATUS_SUCCEEDED : Job::STATUS_FAILED;
        $job->save(false);

        \Yii::$app->get('auditService')->log(
            AuditService::ACTION_JOB_FINISHED,
            'job',
            $job->id,
            null,
            ['exit_code' => $exitCode, 'status' => $job->status]
        );

        /** @var WebhookService $ws */
        $ws = \Yii::$app->get('webhookService');
        $event = $job->status === Job::STATUS_SUCCEEDED
            ? Webhook::EVENT_JOB_SUCCESS
            : Webhook::EVENT_JOB_FAILURE;
        $ws->dispatch($event, $job);

        /** @var NotificationService $ns */
        $ns = \Yii::$app->get('notificationService');
        if ($job->status === Job::STATUS_FAILED) {
            $ns->notifyJobFailed($job);
        } elseif ($job->status === Job::STATUS_SUCCEEDED) {
            $ns->notifyJobSucceeded($job);
        }
    }

    private function transitionToFailed(Job $job, int $exitCode): void
    {
        $this->transitionToFinished($job, $exitCode);
    }

    private function transitionToTimedOut(Job $job): void
    {
        $job->exit_code = -1;
        $job->finished_at = time();
        $job->status = Job::STATUS_TIMED_OUT;
        $job->save(false);

        \Yii::$app->get('auditService')->log(
            AuditService::ACTION_JOB_FINISHED,
            'job',
            $job->id,
            null,
            ['exit_code' => -1, 'status' => Job::STATUS_TIMED_OUT]
        );

        /** @var WebhookService $ws */
        $ws = \Yii::$app->get('webhookService');
        $ws->dispatch(Webhook::EVENT_JOB_FAILURE, $job);

        /** @var NotificationService $ns */
        $ns = \Yii::$app->get('notificationService');
        $ns->notifyJobFailed($job);
    }

    /**
     * Execute ansible-playbook as a subprocess.
     * Returns the process exit code.
     */
    private function runPlaybook(Job $job): int
    {
        $payload = json_decode($job->runner_payload ?? '{}', true);
        $cmd = $this->buildCommand($job, $payload);
        $callbackFile = sys_get_temp_dir() . '/ansilume_tasks_' . $job->id . '_' . uniqid('', true) . '.ndjson';

        \Yii::info("RunAnsibleJob: starting job #{$job->id}: " . implode(' ', $cmd), __CLASS__);

        $artifactDir = sys_get_temp_dir() . '/ansilume_artifacts_' . $job->id . '_' . uniqid('', true);
        mkdir($artifactDir, 0750, true);

        $env = $this->buildProcessEnv($callbackFile, $artifactDir);
        $process = $this->startProcess($cmd, $payload, $env);
        $pipes = $process['pipes'];

        $timeoutMinutes = (int)($payload['timeout_minutes'] ?? 120);
        $timedOut = $this->streamProcessOutput($job, $pipes, $timeoutMinutes, $process['resource']);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process['resource']);
        $job->pid = null;
        $job->save(false);

        if ($timedOut) {
            throw new JobTimeoutException($timeoutMinutes);
        }

        $this->saveTaskResults($job, $callbackFile);
        $this->collectArtifacts($job, $env);

        return $exitCode;
    }

    /**
     * Build the environment variables for the Ansible subprocess.
     */
    protected function buildProcessEnv(string $callbackFile, string $artifactDir): array
    {
        return array_merge(getenv() ?: [], [
            'ANSIBLE_CALLBACK_PLUGINS' => dirname(__DIR__) . '/ansible/callback_plugins',
            'ANSIBLE_CALLBACKS_ENABLED' => 'ansilume_callback',
            'ANSIBLE_CALLBACK_WHITELIST' => 'ansilume_callback',
            'ANSILUME_CALLBACK_FILE' => $callbackFile,
            'ANSILUME_ARTIFACT_DIR' => $artifactDir,
            'ANSIBLE_FORCE_COLOR' => '1',
            'PYTHONUNBUFFERED' => '1',
        ]);
    }

    /**
     * Open the ansible-playbook subprocess.
     *
     * @return array{resource: resource, pipes: array}
     */
    private function startProcess(array $cmd, array $payload, array $env): array
    {
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $projectCwd = $this->resolveProjectPath($payload);
        $process = proc_open($cmd, $descriptorspec, $pipes, is_dir($projectCwd) ? $projectCwd : null, $env);

        if (!is_resource($process)) {
            throw new \RuntimeException('proc_open failed');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return ['resource' => $process, 'pipes' => $pipes];
    }

    /**
     * Read stdout/stderr from the subprocess, writing log chunks.
     * Returns true if the process was killed due to timeout.
     */
    private function streamProcessOutput(Job $job, array $pipes, int $timeoutMinutes, $process): bool
    {
        $deadline = time() + ($timeoutMinutes * 60);
        $sequence = 0;

        while (!feof($pipes[1]) || !feof($pipes[2])) {
            $remaining = $deadline - time();
            if ($remaining <= 0) {
                $this->killTimedOutProcess($process);
                return true;
            }

            $sequence = $this->drainAndAppendLogs($job, $pipes, $sequence, $remaining);
        }

        return false;
    }

    /**
     * Kill a process that exceeded its timeout.
     *
     * @param resource $process
     */
    private function killTimedOutProcess($process): void
    {
        proc_terminate($process, 15);
        sleep(3);
        proc_terminate($process, 9);
    }

    /**
     * Read available output from process pipes and append as log entries.
     *
     * @return int Updated sequence number.
     */
    private function drainAndAppendLogs(Job $job, array $pipes, int $sequence, int $remaining): int
    {
        $read = array_filter([$pipes[1], $pipes[2]], fn($p) => is_resource($p) && !feof($p));
        $write = null;
        $except = null;
        $changed = stream_select($read, $write, $except, min($remaining, 5));

        if ($changed === false || $changed === 0) {
            return $sequence;
        }

        foreach ($read as $stream) {
            $chunk = fread($stream, 4096);
            if ($chunk !== false && $chunk !== '') {
                $streamName = ($stream === $pipes[1]) ? JobLog::STREAM_STDOUT : JobLog::STREAM_STDERR;
                $this->appendLog($job, $streamName, $chunk, $sequence++);
            }
        }

        return $sequence;
    }

    /**
     * Parse the NDJSON callback file and persist JobTask records.
     * Sets job->has_changes if any task reported a change.
     */
    private function saveTaskResults(Job $job, string $callbackFile): void
    {
        if (!file_exists($callbackFile)) {
            return;
        }

        try {
            $lines = file($callbackFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $hasChanges = $this->persistTaskLines($job, $lines ?: []);
        } finally {
            \app\helpers\FileHelper::safeUnlink($callbackFile);
        }

        if ($hasChanges) {
            $job->has_changes = 1;
            $job->save(false);
        }
    }

    /**
     * Parse NDJSON lines and persist each as a JobTask record.
     * Returns true if any task reported a change.
     */
    protected function persistTaskLines(Job $job, array $lines): bool
    {
        $hasChanges = false;

        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if (!is_array($data)) {
                continue;
            }

            $task = new JobTask();
            $task->job_id = $job->id;
            $task->sequence = (int)($data['seq'] ?? 0);
            $task->task_name = (string)($data['name'] ?? '');
            $task->task_action = (string)($data['action'] ?? '');
            $task->host = (string)($data['host'] ?? '');
            $task->status = (string)($data['status'] ?? 'ok');
            $task->changed = (int)(bool)($data['changed'] ?? false);
            $task->duration_ms = (int)($data['duration_ms'] ?? 0);
            $task->created_at = time();
            $task->save(false);

            if ($task->changed) {
                $hasChanges = true;
            }
        }

        return $hasChanges;
    }

    /**
     * Build the ansible-playbook command from the runner payload.
     * All user-controlled values are passed as arguments, never interpolated into shell strings.
     *
     * When RUNNER_MODE=docker, wraps the command in `docker run --rm`.
     */
    private function buildCommand(Job $job, array $payload): array
    {
        $ansibleCmd = $this->buildAnsibleCommand($payload);

        $runnerMode = $_ENV['RUNNER_MODE'] ?? 'local';
        if ($runnerMode === 'docker') {
            return $this->wrapInDocker($ansibleCmd, $payload);
        }

        return $ansibleCmd;
    }

    protected function buildAnsibleCommand(array $payload): array
    {
        $cmd = ['ansible-playbook'];

        $inventoryArg = $this->resolveInventoryArg((int)($payload['inventory_id'] ?? 0));
        if ($inventoryArg !== null) {
            $cmd[] = '-i';
            $cmd[] = $inventoryArg;
        }

        $cmd[] = $this->resolvePlaybookPath($payload);

        $this->addPlaybookOptions($cmd, $payload);

        return $cmd;
    }

    /**
     * Append optional playbook flags (verbosity, forks, become, limit, tags, extra-vars).
     */
    protected function addPlaybookOptions(array &$cmd, array $payload): void
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

    /**
     * Wrap an ansible-playbook command in `docker run --rm` for container isolation.
     *
     * Mounts the project workspace and any temp inventory file into the container.
     * The container is ephemeral (--rm) and runs as the current user to avoid
     * file ownership issues on mounted volumes.
     */
    protected function wrapInDocker(array $ansibleCmd, array $payload): array
    {
        $image = $_ENV['RUNNER_DOCKER_IMAGE'] ?? 'cytopia/ansible:latest';
        $projectPath = $this->resolveProjectPath($payload);

        $dockerCmd = [
            'docker', 'run', '--rm',
            '--user', posix_getuid() . ':' . posix_getgid(),
            // Mount the project workspace read-only
            '-v', $projectPath . ':/workspace:ro',
            // Mount /tmp so inventory temp files are accessible
            '-v', sys_get_temp_dir() . ':' . sys_get_temp_dir(),
            '--workdir', '/workspace',
            $image,
        ];

        // Translate the ansible-playbook args: rebase paths inside the container
        foreach ($ansibleCmd as $i => $part) {
            if ($i === 0) {
                // 'ansible-playbook' — skip, image entrypoint or override
                continue;
            }
            // Rebase project-relative playbook path to /workspace
            if (str_starts_with($part, $projectPath)) {
                $dockerCmd[] = '/workspace/' . ltrim(substr($part, strlen($projectPath)), '/');
            } else {
                $dockerCmd[] = $part;
            }
        }

        return $dockerCmd;
    }

    private function resolveProjectPath(array $payload): string
    {
        $project = \app\models\Project::findOne($payload['project_id'] ?? 0);
        return $project?->local_path ?? '/tmp/ansilume/projects';
    }

    private function resolveInventoryArg(int $inventoryId): ?string
    {
        $inventory = \app\models\Inventory::findOne($inventoryId);
        if ($inventory === null) {
            return null;
        }
        // For static inventories, write content to a temp file
        if ($inventory->inventory_type === \app\models\Inventory::TYPE_STATIC) {
            return $this->writeInventoryTempFile($inventory->content ?? '');
        }
        return $inventory->source_path;
    }

    private function writeInventoryTempFile(string $content): string
    {
        $path = sys_get_temp_dir() . '/ansilume_inv_' . uniqid('', true);
        file_put_contents($path, $content);
        return $path;
    }

    private function resolvePlaybookPath(array $payload): string
    {
        $base = $this->resolveProjectPath($payload);
        return rtrim($base, '/') . '/' . ltrim($payload['playbook'] ?? 'site.yml', '/');
    }

    /**
     * Collect artifacts from the job's artifact directory if present.
     */
    private function collectArtifacts(Job $job, array $env): void
    {
        $artifactDir = $env['ANSILUME_ARTIFACT_DIR'] ?? null;
        if ($artifactDir === null || !is_dir($artifactDir)) {
            return;
        }

        try {
            /** @var \app\services\ArtifactService $service */
            $service = \Yii::$app->get('artifactService');
            $artifacts = $service->collectFromDirectory($job, $artifactDir);
            if (!empty($artifacts)) {
                \Yii::info("RunAnsibleJob: collected " . count($artifacts) . " artifact(s) for job #{$job->id}", __CLASS__);
            }
        } catch (\Throwable $e) {
            \Yii::error("RunAnsibleJob: artifact collection failed for job #{$job->id}: " . $e->getMessage(), __CLASS__);
        } finally {
            $this->cleanupDirectory($artifactDir);
        }
    }

    /**
     * Recursively remove a temporary directory.
     *
     * Symlinks are removed (unlinked) but never followed — this prevents
     * a malicious playbook from tricking cleanup into deleting files
     * outside the artifact directory.
     */
    protected function cleanupDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $realDir = realpath($dir);
        if ($realDir === false) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $this->removeItem($item, $realDir);
        }

        \app\helpers\FileHelper::safeRmdir($dir);
    }

    /**
     * Remove a single filesystem item during cleanup.
     * Symlinks are unlinked but never followed; real paths are verified
     * to be inside the parent directory (defense in depth).
     */
    protected function removeItem(\SplFileInfo $item, string $realDir): void
    {
        $path = $item->getPathname();

        if ($item->isLink()) {
            \app\helpers\FileHelper::safeUnlink($path);
            return;
        }

        $realPath = $item->getRealPath();
        if ($realPath === false || !str_starts_with($realPath, $realDir)) {
            return;
        }

        $item->isDir()
            ? \app\helpers\FileHelper::safeRmdir($path)
            : \app\helpers\FileHelper::safeUnlink($path);
    }

    private function appendLog(Job $job, string $stream, string $content, int $sequence = 0): void
    {
        $log = new JobLog();
        $log->job_id = $job->id;
        $log->stream = $stream;
        $log->content = $content;
        $log->sequence = $sequence;
        $log->created_at = time();
        if (!$log->save()) {
            \Yii::error("RunAnsibleJob: failed to save log chunk for job #{$job->id}: " . json_encode($log->errors), __CLASS__);
        }
    }
}
