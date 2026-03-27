<?php

declare(strict_types=1);

namespace app\services;

use app\models\Job;
use app\models\JobHostSummary;
use app\models\JobLog;
use app\models\JobTask;
use app\models\Webhook;
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

        /** @var NotificationService $ns */
        $ns = \Yii::$app->get('notificationService');
        if ($job->status === Job::STATUS_FAILED) {
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

    public function saveTasks(Job $job, array $tasks): void
    {
        $hasChanges = false;
        $hostBuckets = []; // host => [ok, changed, failed, skipped, unreachable, rescued]

        foreach ($tasks as $data) {
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

            // Accumulate per-host recap counters
            $host = $task->host;
            if ($host !== '') {
                if (!isset($hostBuckets[$host])) {
                    $hostBuckets[$host] = ['ok' => 0, 'changed' => 0, 'failed' => 0, 'skipped' => 0, 'unreachable' => 0, 'rescued' => 0];
                }
                $status = $task->status;
                if ($task->changed) {
                    $hostBuckets[$host]['changed']++;
                } elseif (isset($hostBuckets[$host][$status])) {
                    $hostBuckets[$host][$status]++;
                }
            }
        }

        // Persist host summaries (upsert by job_id + host)
        $now = time();
        foreach ($hostBuckets as $host => $counts) {
            $summary = JobHostSummary::findOne(['job_id' => $job->id, 'host' => $host])
                ?? new JobHostSummary();
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

        if ($hasChanges && !$job->has_changes) {
            $job->has_changes = 1;
            $job->save(false);
        }
    }
}
