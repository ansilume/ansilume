<?php

declare(strict_types=1);

namespace app\jobs;

use app\components\AnsibleJobCommandBuilder;
use app\components\AnsibleJobProcess;
use app\components\ArtifactCollector;
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
        $builder = new AnsibleJobCommandBuilder();
        $cmd = $builder->build($payload);
        $callbackFile = sys_get_temp_dir() . '/ansilume_tasks_' . $job->id . '_' . uniqid('', true) . '.ndjson';

        \Yii::info("RunAnsibleJob: starting job #{$job->id}: " . implode(' ', $cmd), __CLASS__);

        $artifactDir = sys_get_temp_dir() . '/ansilume_artifacts_' . $job->id . '_' . uniqid('', true);
        mkdir($artifactDir, 0750, true);

        $env = $this->buildProcessEnv($callbackFile, $artifactDir);
        $timeoutMinutes = (int)($payload['timeout_minutes'] ?? 120);

        $process = new AnsibleJobProcess();
        $exitCode = $process->run($job, $cmd, $payload, $env, $timeoutMinutes);

        $this->saveTaskResults($job, $callbackFile);

        $collector = new ArtifactCollector();
        $collector->collect($job, $env);

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
