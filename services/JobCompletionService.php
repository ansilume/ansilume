<?php

declare(strict_types=1);

namespace app\services;

use app\models\Job;
use app\models\JobHostSummary;
use app\models\JobLog;
use app\models\JobTask;
use app\models\NotificationTemplate;
use app\models\Webhook;
use app\models\WorkflowJobStep;
use yii\base\Component;

/**
 * Handles job completion transitions, audit logging, webhooks, and notifications.
 * Shared by the runner API and any other execution path.
 */
class JobCompletionService extends Component
{
    public function complete(Job $job, int $exitCode, bool $hasChanges = false): void
    {
        $job->exit_code = $exitCode;
        $job->finished_at = time();
        $job->status = $exitCode === 0 ? Job::STATUS_SUCCEEDED : Job::STATUS_FAILED;
        if ($hasChanges) {
            $job->has_changes = 1;
        }
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

        $this->dispatchNotifications($job);
        $this->advanceWorkflow($job);
    }

    public function completeTimedOut(Job $job): void
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

        $this->dispatchNotifications($job);
        $this->advanceWorkflow($job);
    }

    /**
     * If this job belongs to a workflow step, advance the workflow.
     */
    private function advanceWorkflow(Job $job): void
    {
        /** @var WorkflowJobStep|null $wjs */
        $wjs = WorkflowJobStep::findOne(['job_id' => $job->id]);
        if ($wjs === null) {
            return;
        }

        /** @var WorkflowExecutionService $wes */
        $wes = \Yii::$app->get('workflowExecutionService');
        $wes->onChildJobCompleted($job);
    }

    private function dispatchNotifications(Job $job): void
    {
        // New notification templates (multi-channel)
        $event = match ($job->status) {
            Job::STATUS_SUCCEEDED => NotificationTemplate::EVENT_JOB_SUCCEEDED,
            Job::STATUS_FAILED => NotificationTemplate::EVENT_JOB_FAILED,
            Job::STATUS_TIMED_OUT => NotificationTemplate::EVENT_JOB_TIMED_OUT,
            default => null,
        };
        if ($event !== null) {
            /** @var NotificationDispatcher $dispatcher */
            $dispatcher = \Yii::$app->get('notificationDispatcher');
            $dispatcher->dispatch($event, $job);
        }

        // Legacy email notifications (backward compatibility)
        /** @var NotificationService $ns */
        $ns = \Yii::$app->get('notificationService');
        if ($job->status === Job::STATUS_FAILED || $job->status === Job::STATUS_TIMED_OUT) {
            $ns->notifyJobFailed($job);
        } elseif ($job->status === Job::STATUS_SUCCEEDED) {
            $ns->notifyJobSucceeded($job);
        }
    }

    public function appendLog(Job $job, string $stream, string $content, int $sequence): void
    {
        $log = new JobLog();
        $log->job_id = $job->id;
        $log->stream = $stream;
        $log->content = $content;
        $log->sequence = $sequence;
        $log->created_at = time();
        if (!$log->save()) {
            \Yii::error('JobCompletionService: failed to save log for job #' . $job->id . ': ' . json_encode($log->errors));
        }
    }

    /**
     * @param array<int, array<string, mixed>> $tasks
     */
    public function saveTasks(Job $job, array $tasks): void
    {
        $hasChanges = false;
        $hostBuckets = [];

        foreach ($tasks as $data) {
            $task = $this->persistTask($job, $data);

            if ($task->changed) {
                $hasChanges = true;
            }

            $this->accumulateHostBucket($hostBuckets, $task);
        }

        $this->persistHostSummaries($job, $hostBuckets);

        if ($hasChanges && !$job->has_changes) {
            $job->has_changes = 1;
            $job->save(false);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function persistTask(Job $job, array $data): JobTask
    {
        $task = new JobTask();
        $task->job_id = $job->id;
        $task->sequence = isset($data['seq']) ? (int)$data['seq'] : 0;
        $task->task_name = isset($data['name']) ? (string)$data['name'] : '';
        $task->task_action = isset($data['action']) ? (string)$data['action'] : '';
        $task->host = isset($data['host']) ? (string)$data['host'] : '';
        $task->status = isset($data['status']) ? (string)$data['status'] : 'ok';
        $task->changed = !empty($data['changed']) ? 1 : 0;
        $task->duration_ms = isset($data['duration_ms']) ? (int)$data['duration_ms'] : 0;
        $task->created_at = time();
        $task->save(false);

        return $task;
    }

    /**
     * @param array<string, array{ok: int, changed: int, failed: int, skipped: int, unreachable: int, rescued: int}> $hostBuckets
     */
    private function accumulateHostBucket(array &$hostBuckets, JobTask $task): void
    {
        $host = $task->host;
        if ($host === '') {
            return;
        }

        if (!isset($hostBuckets[$host])) {
            $hostBuckets[$host] = ['ok' => 0, 'changed' => 0, 'failed' => 0, 'skipped' => 0, 'unreachable' => 0, 'rescued' => 0];
        }

        if ($task->changed) {
            $hostBuckets[$host]['changed']++;
        } elseif (isset($hostBuckets[$host][$task->status])) {
            $hostBuckets[$host][$task->status]++;
        }
    }

    /**
     * @param array<string, array{ok: int, changed: int, failed: int, skipped: int, unreachable: int, rescued: int}> $hostBuckets
     */
    private function persistHostSummaries(Job $job, array $hostBuckets): void
    {
        $now = time();
        foreach ($hostBuckets as $host => $counts) {
            /** @var JobHostSummary|null $existing */
            $existing = JobHostSummary::findOne(['job_id' => $job->id, 'host' => $host]);
            $summary = $existing ?? new JobHostSummary();
            $summary->job_id = $job->id;
            $summary->host = $host;
            $summary->ok += $counts['ok'];
            $summary->changed += $counts['changed'];
            $summary->failed += $counts['failed'];
            $summary->skipped += $counts['skipped'];
            $summary->unreachable += $counts['unreachable'];
            $summary->rescued += $counts['rescued'];
            if ($summary->isNewRecord) {
                $summary->created_at = $now;
            }
            $summary->save(false);
        }
    }
}
