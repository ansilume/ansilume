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
use app\services\notification\JobPayloadBuilder;
use yii\base\Component;

/**
 * Handles job completion transitions, audit logging, webhooks, and notifications.
 * Shared by the runner API and any other execution path.
 *
 * complete(), completeTimedOut() and failBeforeExecution() end a job with
 * one conditional UPDATE, only from a status that has not finished (pending,
 * queued, running, pending_approval), and never from the caller's model:
 * the runner API and the claim load the job before they report, and a
 * cancel (by an operator, a template delete or a workflow cancel) or the
 * stale-job reclaim can commit in between. The first writer wins. When the job had finished already the report changes
 * nothing: no audit entry, webhook, notification or workflow advance, only a
 * "... ignored." line in the job log (none for a failure before execution).
 * The line that explains a failure (no host matched, the reason a claimed
 * job cannot run) is written in the same transaction as the status, so the
 * two show together.
 */
class JobCompletionService extends Component
{
    /**
     * Job log line for a runner's completion report that came after the job
     * had finished. sprintf(): the reported exit code, the job's status.
     */
    public const LATE_COMPLETION = 'Runner reported completion (exit_code=%d) after job was already '
        . 'in terminal status "%s" — ignored.';

    /**
     * Job log line for a runner's timeout report that came after the job had
     * finished. sprintf(): the job's status.
     */
    public const LATE_TIMEOUT = 'Runner reported timeout after job was already in terminal status "%s" — ignored.';

    /** Job log line for a playbook that exited 0 without running against any host. */
    public const NO_HOST_MATCHED = "Job did not execute against any host.\n"
        . "The playbook's `hosts:` pattern did not match any entries in the inventory.\n"
        . 'Check that the inventory contains the hosts/groups the playbook targets.';

    public function complete(Job $job, int $exitCode, bool $hasChanges = false): void
    {
        // ansible-playbook exits 0 when the playbook's `hosts:` pattern
        // matches nothing in the inventory — it prints a "Could not match
        // supplied host pattern" warning, produces an empty PLAY RECAP,
        // and returns cleanly. From an operator's perspective this is a
        // misconfiguration, not a success: nothing ran. Surface it as a
        // failure so the UI doesn't silently lie about what happened.
        $noHostMatched = $exitCode === 0 && $this->didNothing($job);
        $outcome = [
            'exit_code' => $exitCode,
            'status' => $exitCode === 0 && !$noHostMatched ? Job::STATUS_SUCCEEDED : Job::STATUS_FAILED,
        ];
        if ($hasChanges) {
            $outcome['has_changes'] = 1;
        }

        if (!$this->transition($job, $outcome, $noHostMatched ? self::NO_HOST_MATCHED : null)) {
            // Runner races with an earlier terminal decision — operator cancel,
            // server-side timeout, approval rejection. Honouring a late
            // complete() would overwrite that decision (e.g. resurrect a
            // canceled job as succeeded) and would redundantly dispatch
            // webhooks/notifications that the cancel path already fired.
            // Keep the prior status/finished_at/audit; leave a forensic log
            // line so operators can see the runner eventually reported back.
            $this->appendSystemLog($job, sprintf(self::LATE_COMPLETION, $exitCode, $job->status));
            return;
        }

        $this->appendVerbosityNote($job);
        $this->announceFinished($job, ['exit_code' => $exitCode, 'status' => $job->status]);
    }

    /**
     * Did the job execute any work against any host? The callback plugin
     * only writes a JobTask row per matched host+task pair, and the
     * runner only emits a JobHostSummary per host that ansible touched.
     * Zero of both means ansible ran a playbook but produced nothing —
     * almost always because the `hosts:` pattern didn't match.
     */
    protected function didNothing(Job $job): bool
    {
        $hasTasks = JobTask::find()->where(['job_id' => $job->id])->exists();
        if ($hasTasks) {
            return false;
        }
        $hasHostSummary = JobHostSummary::find()->where(['job_id' => $job->id])->exists();
        return !$hasHostSummary;
    }

    /**
     * Append a system-level log line after the runner-streamed logs.
     * Uses a sequence one past the max so it renders at the end of the
     * Output panel. `stream=stderr` so the UI picks the warning colour.
     */
    private function appendSystemLog(Job $job, string $message): void
    {
        $nextSequence = (int)JobLog::find()->where(['job_id' => $job->id])->max('sequence') + 1;
        $this->appendLog($job, 'stderr', $message, $nextSequence);
    }

    /**
     * When the claim lowered the job's verbosity for a runner that cannot
     * take encrypted survey answers ({@see JobClaimSecrets}), say so at the
     * end of the log, once the job has finished. Never at claim time: a line
     * written then would sort among the runner's first chunks. A failure is
     * logged and ignored, so the job still finishes; for that it is written
     * after the status, outside its transaction.
     */
    private function appendVerbosityNote(Job $job): void
    {
        try {
            $note = JobClaimSecrets::verbosityNote($job);
            if ($note !== null) {
                $this->appendSystemLog($job, $note);
            }
        } catch (\Throwable $e) {
            \Yii::warning(
                'JobCompletionService: the verbosity note for job #' . $job->id . ' could not be written (' . get_class($e) . ').',
                __CLASS__
            );
        }
    }

    public function completeTimedOut(Job $job): void
    {
        if (!$this->transition($job, ['exit_code' => -1, 'status' => Job::STATUS_TIMED_OUT], null)) {
            // Same race-guard as complete(): an operator cancel / approval
            // rejection must not be overwritten by the runner's belated
            // timeout report.
            $this->appendSystemLog($job, sprintf(self::LATE_TIMEOUT, $job->status));
            return;
        }

        $this->appendVerbosityNote($job);
        $this->announceFinished($job, ['exit_code' => -1, 'status' => Job::STATUS_TIMED_OUT]);
    }

    /**
     * The job was claimed but cannot run, for example because one of its
     * credentials was deleted or cannot be decrypted, or the claim refused
     * it (JobClaimRefusedException: it needs an approval it does not have,
     * or its inventory is gone or names no hosts). Ends it as failed, with
     * the reason in the job log, so it never stays "running" and workflows,
     * webhooks and notifications see the failure. A job that finished
     * meanwhile (a cancel came first) keeps its outcome, and nothing is
     * written.
     *
     * @param array<string, int|string> $auditContext what job.finished records after exit_code, status
     *     and reason, which win over keys of the same name (for a refused claim
     *     JobClaimRefusedException::auditContext())
     */
    public function failBeforeExecution(Job $job, string $reason, array $auditContext = []): void
    {
        if (!$this->transition($job, ['exit_code' => -1, 'status' => Job::STATUS_FAILED], $reason)) {
            return;
        }

        $this->announceFinished($job, [
            'exit_code' => -1,
            'status' => Job::STATUS_FAILED,
            'reason' => 'pre_execution_failure',
        ] + $auditContext);
    }

    /**
     * The terminal transition: one conditional UPDATE that writes $outcome
     * (status, exit_code, optionally has_changes) with finished_at, only
     * while the job has not finished, and in the same transaction the job
     * log line $explanation (null: none). $job is refreshed either way, so
     * it shows the status the job has now.
     *
     * @param array<string, int|string> $outcome
     * @return bool whether this call ended the job; false when it had
     *     finished already, and nothing was written
     */
    protected function transition(Job $job, array $outcome, ?string $explanation): bool
    {
        $jobId = (int)$job->id;
        $now = time();
        /** @var bool $ended */
        $ended = Job::getDb()->transaction(function () use ($job, $jobId, $outcome, $explanation, $now): bool {
            $rows = Job::updateAll(
                $outcome + ['finished_at' => $now, 'updated_at' => $now],
                ['id' => $jobId, 'status' => [...Job::waitingStatuses(), Job::STATUS_RUNNING]]
            );
            if ($rows !== 1) {
                return false;
            }
            if ($explanation !== null) {
                $this->appendSystemLog($job, $explanation);
            }

            return true;
        });
        $job->refresh();

        return $ended;
    }

    /**
     * After this call ended the job: the job.finished audit entry, the
     * success or failure webhook, the notification, and for the job of a
     * workflow step the workflow advance.
     *
     * @param array<string, int|string> $auditContext
     */
    private function announceFinished(Job $job, array $auditContext): void
    {
        \Yii::$app->get('auditService')->log(
            AuditService::ACTION_JOB_FINISHED,
            'job',
            $job->id,
            null,
            $auditContext
        );

        /** @var WebhookService $ws */
        $ws = \Yii::$app->get('webhookService');
        $ws->dispatch(
            $job->status === Job::STATUS_SUCCEEDED ? Webhook::EVENT_JOB_SUCCESS : Webhook::EVENT_JOB_FAILURE,
            $job
        );

        $this->dispatchNotifications($job);

        if (WorkflowJobStep::find()->where(['job_id' => $job->id])->exists()) {
            /** @var WorkflowExecutionService $wes */
            $wes = \Yii::$app->get('workflowExecutionService');
            $wes->onChildJobCompleted($job);
        }
    }

    /**
     * Operator-initiated cancel, kept for its callers: it is
     * JobCancellationService::cancel() with REASON_OPERATOR, which the web
     * and API controllers call themselves.
     *
     * The cancel is a conditional transition, so a job that finished
     * meanwhile keeps its outcome even when $job is stale (first writer
     * wins), and it closes the job's pending approval request in the same
     * transaction. A canceled child job still moves its workflow step out
     * of running: the step fails and the run follows its route.
     *
     * @return bool whether this call canceled the job; false when it had finished already
     */
    public function cancel(Job $job, ?int $cancelerUserId = null): bool
    {
        /** @var JobCancellationService $cancellation */
        $cancellation = \Yii::$app->get('jobCancellationService');

        return $cancellation->cancel($job, $cancelerUserId, JobCancellationService::REASON_OPERATOR);
    }

    private function dispatchNotifications(Job $job): void
    {
        // Timed-out jobs surface as job.failed — operators do not want a
        // separate subscription per failure flavor.
        $event = match ($job->status) {
            Job::STATUS_SUCCEEDED => NotificationTemplate::EVENT_JOB_SUCCEEDED,
            Job::STATUS_FAILED, Job::STATUS_TIMED_OUT => NotificationTemplate::EVENT_JOB_FAILED,
            default => null,
        };
        if ($event === null) {
            return;
        }
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');
        $dispatcher->dispatch($event, JobPayloadBuilder::build($job));
    }

    /** Maximum log chunk size in bytes (1 MB). */
    private const MAX_LOG_CHUNK_SIZE = 1048576;

    public function appendLog(Job $job, string $stream, #[\SensitiveParameter] string $content, int $sequence): void
    {
        if (strlen($content) > self::MAX_LOG_CHUNK_SIZE) {
            $content = substr($content, 0, self::MAX_LOG_CHUNK_SIZE)
                . "\n\n[truncated — chunk exceeded 1 MB limit]";
        }

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
     * Saves the task results a runner reports, with a host summary per host
     * they name, unless one of them cannot be saved as it is
     * (RunnerReportNumbers::taskListRefusal()): then none is saved.
     *
     * @param array<mixed> $tasks the task results as the runner reports them
     * @return string|null why the tasks were refused, null when they were saved
     */
    public function saveTasks(Job $job, #[\SensitiveParameter] array $tasks): ?string
    {
        $refusal = RunnerReportNumbers::taskListRefusal($tasks);
        if ($refusal !== null) {
            return $refusal;
        }
        $hasChanges = false;
        $hostBuckets = [];

        /** @var array<string, mixed> $data taskListRefusal() refuses any other */
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

        return null;
    }

    /**
     * @param array<string, mixed> $data a task result that taskListRefusal() accepted
     */
    private function persistTask(Job $job, #[\SensitiveParameter] array $data): JobTask
    {
        $task = new JobTask();
        $task->job_id = $job->id;
        $task->sequence = RunnerReportNumbers::taskNumber($data['seq'] ?? null) ?? 0;
        $task->task_name = isset($data['name']) ? (string)$data['name'] : '';
        $task->task_action = isset($data['action']) ? (string)$data['action'] : '';
        $task->host = isset($data['host']) ? (string)$data['host'] : '';
        $task->status = isset($data['status']) ? (string)$data['status'] : 'ok';
        $task->changed = !empty($data['changed']) ? 1 : 0;
        $task->duration_ms = RunnerReportNumbers::taskNumber($data['duration_ms'] ?? null) ?? 0;
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
