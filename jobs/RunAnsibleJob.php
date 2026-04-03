<?php

declare(strict_types=1);

namespace app\jobs;

use app\components\AnsibleJobProcess;
use app\components\ArtifactCollector;
use app\components\CredentialInjector;
use app\components\DockerCommandWrapper;
use app\jobs\JobTimeoutException;
use app\models\Job;
use app\models\JobLog;
use app\models\Webhook;
use app\services\AuditService;
use app\services\JobClaimService;
use app\services\JobCompletionService;
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
        /** @var Job|null $job */
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
        /** @var JobCompletionService $completionService */
        $completionService = \Yii::$app->get('jobCompletionService');
        $completionService->complete($job, $exitCode);
    }

    private function transitionToFailed(Job $job, int $exitCode): void
    {
        $this->transitionToFinished($job, $exitCode);
    }

    private function transitionToTimedOut(Job $job): void
    {
        /** @var JobCompletionService $completionService */
        $completionService = \Yii::$app->get('jobCompletionService');
        $completionService->completeTimedOut($job);
    }

    /**
     * Execute ansible-playbook as a subprocess.
     * Uses JobClaimService to resolve the payload and build the canonical command
     * (same code path as pull-based runners — single source of truth).
     * Returns the process exit code.
     */
    private function runPlaybook(Job $job): int
    {
        /** @var JobClaimService $claimService */
        $claimService = \Yii::$app->get('jobClaimService');
        $payload = $claimService->buildExecutionPayload($job);

        /** @var array<int, string> $cmd */
        $cmd = $payload['command'];

        $runnerMode = $_ENV['RUNNER_MODE'] ?? 'local';
        if ($runnerMode === 'docker') {
            $cmd = DockerCommandWrapper::wrap($cmd, (string)($payload['project_path'] ?? ''));
        }

        $callbackFile = sys_get_temp_dir() . '/ansilume_tasks_' . $job->id . '_' . uniqid('', true) . '.ndjson';

        \Yii::info("RunAnsibleJob: starting job #{$job->id}: " . implode(' ', $cmd), __CLASS__);

        $artifactDir = sys_get_temp_dir() . '/ansilume_artifacts_' . $job->id . '_' . uniqid('', true);
        mkdir($artifactDir, 0750, true);

        $env = $this->buildProcessEnv($callbackFile, $artifactDir);
        $timeoutMinutes = (int)($payload['timeout_minutes'] ?? 120);

        $inventoryTmpFile = null;
        if ($payload['inventory_type'] === 'static') {
            $inventoryTmpFile = $this->writeInventoryTempFile((string)($payload['inventory_content'] ?? "localhost\n"));
            $cmd = array_map(
                fn (string $part) => $part === '__INVENTORY_TMP__' ? $inventoryTmpFile : $part,
                $cmd
            );
        }

        $credentialInjector = new CredentialInjector();
        /** @var array{credential_type: string, username: string|null, secrets: array<string, string>}|null $credData */
        $credData = $payload['credential'];
        $injection = $credentialInjector->inject($credData);
        $cmd = array_merge($cmd, $injection->args);
        $env = array_merge($env, $injection->env);

        try {
            $process = new AnsibleJobProcess();
            $exitCode = $process->run($job, $cmd, $payload, $env, $timeoutMinutes);

            $this->saveTaskResults($job, $callbackFile);

            $collector = new ArtifactCollector();
            $collector->collect($job, $env);

            return $exitCode;
        } finally {
            CredentialInjector::cleanup($injection->tempFiles);
            if ($inventoryTmpFile) {
                \app\helpers\FileHelper::safeUnlink($inventoryTmpFile);
            }
        }
    }

    private function writeInventoryTempFile(string $content): string
    {
        $path = sys_get_temp_dir() . '/ansilume_inv_' . uniqid('', true) . '.yml';
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * Build the environment variables for the Ansible subprocess.
     *
     * @return array<string, string>
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
     * Parse the NDJSON callback file and persist JobTask records via JobCompletionService.
     */
    private function saveTaskResults(Job $job, string $callbackFile): void
    {
        if (!file_exists($callbackFile)) {
            return;
        }

        try {
            $tasks = $this->parseCallbackFile($callbackFile);
        } finally {
            \app\helpers\FileHelper::safeUnlink($callbackFile);
        }

        if (!empty($tasks)) {
            /** @var JobCompletionService $completionService */
            $completionService = \Yii::$app->get('jobCompletionService');
            $completionService->saveTasks($job, $tasks);
        }
    }

    /**
     * Parse NDJSON callback file into an array of task data.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function parseCallbackFile(string $callbackFile): array
    {
        $tasks = [];
        $lines = file($callbackFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if (is_array($data)) {
                $tasks[] = $data;
            }
        }

        return $tasks;
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
