<?php

declare(strict_types=1);

namespace app\jobs;

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
        } catch (\Throwable $e) {
            \Yii::error("RunAnsibleJob: job #{$job->id} threw exception: " . $e->getMessage(), __CLASS__);
            $this->appendLog($job, JobLog::STREAM_STDERR, 'Runner error: ' . $e->getMessage());
            $this->transitionToFailed($job, -1);
        }
    }

    private function transitionToRunning(Job $job): void
    {
        $job->status     = Job::STATUS_RUNNING;
        $job->started_at = time();
        $job->pid        = null;
        $job->worker_id  = gethostname() . ':' . getmypid();
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
        $job->exit_code   = $exitCode;
        $job->finished_at = time();
        $job->status      = $exitCode === 0 ? Job::STATUS_SUCCEEDED : Job::STATUS_FAILED;
        $job->save(false);

        \Yii::$app->get('auditService')->log(
            AuditService::ACTION_JOB_FINISHED,
            'job',
            $job->id,
            null,
            ['exit_code' => $exitCode, 'status' => $job->status]
        );

        /** @var WebhookService $ws */
        $ws    = \Yii::$app->get('webhookService');
        $event = $job->status === Job::STATUS_SUCCEEDED
            ? Webhook::EVENT_JOB_SUCCESS
            : Webhook::EVENT_JOB_FAILURE;
        $ws->dispatch($event, $job);

        if ($job->status === Job::STATUS_FAILED) {
            /** @var NotificationService $ns */
            $ns = \Yii::$app->get('notificationService');
            $ns->notifyJobFailed($job);
        }
    }

    private function transitionToFailed(Job $job, int $exitCode): void
    {
        $this->transitionToFinished($job, $exitCode);
    }

    /**
     * Execute ansible-playbook as a subprocess.
     * Returns the process exit code.
     */
    private function runPlaybook(Job $job): int
    {
        $payload      = json_decode($job->runner_payload ?? '{}', true);
        $cmd          = $this->buildCommand($job, $payload);
        $callbackFile = sys_get_temp_dir() . '/ansilume_tasks_' . $job->id . '_' . uniqid('', true) . '.ndjson';
        $pluginDir    = dirname(__DIR__) . '/ansible/callback_plugins';

        \Yii::info("RunAnsibleJob: starting job #{$job->id}: " . implode(' ', $cmd), __CLASS__);

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = array_merge(getenv() ?: [], [
            'ANSIBLE_CALLBACK_PLUGINS'  => $pluginDir,
            'ANSIBLE_CALLBACKS_ENABLED' => 'ansilume_callback',
            'ANSIBLE_CALLBACK_WHITELIST' => 'ansilume_callback',
            'ANSILUME_CALLBACK_FILE'    => $callbackFile,
            'ANSIBLE_FORCE_COLOR'       => '1',
            'PYTHONUNBUFFERED'          => '1',
        ]);

        $process = proc_open($cmd, $descriptorspec, $pipes, null, $env);

        if (!is_resource($process)) {
            throw new \RuntimeException('proc_open failed for job #' . $job->id);
        }

        fclose($pipes[0]);

        $sequence = 0;
        while (!feof($pipes[1]) || !feof($pipes[2])) {
            $stdout = fread($pipes[1], 4096);
            if ($stdout !== false && $stdout !== '') {
                $this->appendLog($job, JobLog::STREAM_STDOUT, $stdout, $sequence++);
            }
            $stderr = fread($pipes[2], 4096);
            if ($stderr !== false && $stderr !== '') {
                $this->appendLog($job, JobLog::STREAM_STDERR, $stderr, $sequence++);
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $job->pid = null;
        $job->save(false);

        $this->saveTaskResults($job, $callbackFile);

        return $exitCode;
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

        $hasChanges = false;

        try {
            $lines = file($callbackFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $data = json_decode($line, true);
                if (!is_array($data)) {
                    continue;
                }

                $task              = new JobTask();
                $task->job_id      = $job->id;
                $task->sequence    = (int)($data['seq'] ?? 0);
                $task->task_name   = (string)($data['name'] ?? '');
                $task->task_action = (string)($data['action'] ?? '');
                $task->host        = (string)($data['host'] ?? '');
                $task->status      = (string)($data['status'] ?? 'ok');
                $task->changed     = (int)(bool)($data['changed'] ?? false);
                $task->duration_ms = (int)($data['duration_ms'] ?? 0);
                $task->created_at  = time();
                $task->save(false);

                if ($task->changed) {
                    $hasChanges = true;
                }
            }
        } finally {
            @unlink($callbackFile);
        }

        if ($hasChanges) {
            $job->has_changes = 1;
            $job->save(false);
        }
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

    private function buildAnsibleCommand(array $payload): array
    {
        $cmd = ['ansible-playbook'];

        // Inventory
        $inventoryArg = $this->resolveInventoryArg((int)($payload['inventory_id'] ?? 0));
        if ($inventoryArg !== null) {
            $cmd[] = '-i';
            $cmd[] = $inventoryArg;
        }

        // Playbook
        $cmd[] = $this->resolvePlaybookPath($payload);

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
        $limit = $payload['limit'] ?? null;
        if (!empty($limit)) {
            $cmd[] = '--limit';
            $cmd[] = $limit;
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
        $extraVars = $payload['extra_vars'] ?? null;
        if (!empty($extraVars)) {
            $cmd[] = '--extra-vars';
            $cmd[] = $extraVars;
        }

        return $cmd;
    }

    /**
     * Wrap an ansible-playbook command in `docker run --rm` for container isolation.
     *
     * Mounts the project workspace and any temp inventory file into the container.
     * The container is ephemeral (--rm) and runs as the current user to avoid
     * file ownership issues on mounted volumes.
     */
    private function wrapInDocker(array $ansibleCmd, array $payload): array
    {
        $image       = $_ENV['RUNNER_DOCKER_IMAGE'] ?? 'cytopia/ansible:latest';
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

    private function appendLog(Job $job, string $stream, string $content, int $sequence = 0): void
    {
        $log           = new JobLog();
        $log->job_id   = $job->id;
        $log->stream   = $stream;
        $log->content  = $content;
        $log->sequence = $sequence;
        $log->created_at = time();
        if (!$log->save()) {
            \Yii::error("RunAnsibleJob: failed to save log chunk for job #{$job->id}: " . json_encode($log->errors), __CLASS__);
        }
    }
}
