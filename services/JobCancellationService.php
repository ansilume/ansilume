<?php

declare(strict_types=1);

namespace app\services;

use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\Job;
use app\models\NotificationTemplate;
use app\models\WorkflowJobStep;
use app\services\notification\JobPayloadBuilder;
use yii\base\Component;

/**
 * Cancels a job, and with it the approval request the job waits for.
 *
 * The transition is one transaction. It locks the job's approval request
 * first (SELECT ... FOR UPDATE by job_id; most jobs have none), then moves
 * the job to canceled with a conditional UPDATE, only from a status that can
 * be canceled (pending, queued, running, pending_approval, as
 * Job::isCancelable()). A job that finished meanwhile (its runner reported,
 * its approval was decided, another cancel came first) stays as it is, and
 * nothing at all is written: the caller's model may be stale. When the job
 * changed and its request is still pending, the request is closed as
 * canceled, with resolved_at, in the same transaction; a decided request
 * keeps its decision, which is the true history.
 *
 * Lock order: approval_request before job, as on every path that writes
 * both (votes, timeouts), so they cannot deadlock. A vote that waited on the
 * lock finds the request canceled; one that committed first leaves an
 * approved request, and the cancel cancels the queued job.
 *
 * Audit entries, the notification and the workflow advance follow after the
 * commit, and only when this call canceled the job, so they never hold the
 * row locks and never run twice.
 */
class JobCancellationService extends Component
{
    /** A user canceled the job, in the web UI or through the API. */
    public const REASON_OPERATOR = 'operator';

    /** Its job template was deleted. */
    public const REASON_TEMPLATE_DELETED = 'template_deleted';

    /** Its workflow run was canceled. */
    public const REASON_WORKFLOW_CANCELED = 'workflow_canceled';

    /** The workflow template of its run was deleted, which canceled the run. */
    public const REASON_WORKFLOW_TEMPLATE_DELETED = 'workflow_template_deleted';

    /**
     * It waited for an approval request that does not exist (2.9.0 and
     * older deleted the requests of a deleted approval rule): nobody could
     * ever decide it. The maintenance run cancels such jobs (StrandedJobSweep).
     */
    public const REASON_APPROVAL_MISSING = 'approval_request_missing';

    /**
     * Why a cancel changed nothing: the job finished first. sprintf(): the
     * job ID, its status in words (Job::statusLabel(), lower case).
     */
    public const NOT_CANCELED = 'Job #%d was not canceled: it is already %s.';

    /** A reason this method does not take; a programming error. sprintf(): the reason, the ones it takes. */
    public const UNKNOWN_REASON = 'Unknown cancel reason "%s"; expected one of: %s.';

    /** Reads the job's approval request, if any, and locks it until the transaction ends. */
    private const REQUEST_LOCK_SQL = 'SELECT * FROM {{%approval_request}} WHERE [[job_id]] = :job FOR UPDATE';

    /**
     * Cancel $job, for a user (REASON_OPERATOR), because its job template
     * was deleted (REASON_TEMPLATE_DELETED) or because the approval request
     * it waits for is gone (REASON_APPROVAL_MISSING). On success: the audit
     * entries job.canceled (with the reason, unless it is REASON_OPERATOR)
     * and, when a pending request was closed, approval.canceled; the
     * job.canceled notification; and, for the job of a workflow step, the
     * workflow advance, as for a job that failed (a job that never got past
     * its approval is a closed gate, see WorkflowStepRouter::closedGate()).
     *
     * $job is refreshed either way, so it shows the status the job has now.
     *
     * @param int|null $actorId the user, for the audit entries; null: the signed-in user, if any
     * @return bool whether this call canceled the job; false when it had finished already
     * @throws \InvalidArgumentException for a reason other than the three above
     */
    public function cancel(Job $job, ?int $actorId, string $reason = self::REASON_OPERATOR): bool
    {
        self::assertReason($reason, [self::REASON_OPERATOR, self::REASON_TEMPLATE_DELETED, self::REASON_APPROVAL_MISSING]);
        $outcome = $this->transition($job);
        if (!$outcome['changed']) {
            return false;
        }

        $context = $reason === self::REASON_OPERATOR ? [] : ['reason' => $reason];
        $this->audit($job, $actorId, $reason, $outcome['closed'], $context);
        $this->notifyCanceled($job);
        $this->advanceWorkflow($job);

        return true;
    }

    /**
     * Cancel the job of a running step of workflow run #$workflowJobId,
     * which the caller has canceled: the same transition and audit entries
     * as cancel(), with the run's ID in the job.canceled entry, but no
     * job.canceled notification (the run's workflow.canceled covers it) and
     * no workflow advance (the run has ended).
     *
     * $job is refreshed either way.
     *
     * @param int|null $actorId the user, for the audit entries; null: the signed-in user, if any
     * @param string $reason REASON_WORKFLOW_CANCELED or REASON_WORKFLOW_TEMPLATE_DELETED
     * @return bool whether this call canceled the job; false when it had finished already
     * @throws \InvalidArgumentException for another reason
     */
    public function cancelWithinWorkflow(
        Job $job,
        ?int $actorId,
        int $workflowJobId,
        string $reason = self::REASON_WORKFLOW_CANCELED
    ): bool {
        self::assertReason($reason, [self::REASON_WORKFLOW_CANCELED, self::REASON_WORKFLOW_TEMPLATE_DELETED]);
        $outcome = $this->transition($job);
        if (!$outcome['changed']) {
            return false;
        }

        $this->audit($job, $actorId, $reason, $outcome['closed'], [
            'reason' => $reason,
            'workflow_job_id' => $workflowJobId,
        ]);

        return true;
    }

    /**
     * NOT_CANCELED for $job as it is now (refreshed by a cancel that
     * returned false).
     */
    public static function notCanceledMessage(Job $job): string
    {
        return sprintf(self::NOT_CANCELED, $job->id, strtolower(Job::statusLabel((string)$job->status)));
    }

    /**
     * The cancel itself, in one transaction (see the class description);
     * $job is refreshed after the commit.
     *
     * @return array{changed: bool, closed: ApprovalRequest|null} whether the job
     *     changed, and the request this closed (null: none was pending)
     */
    private function transition(Job $job): array
    {
        $jobId = (int)$job->id;
        /** @var array{changed: bool, closed: ApprovalRequest|null} $outcome */
        $outcome = Job::getDb()->transaction(fn (): array => $this->transitionLocked($jobId));
        $job->refresh();

        return $outcome;
    }

    /**
     * Runs inside the transaction. The request row is locked before the job
     * row is written. Under the lock a pending request stays pending until
     * the commit, so the conditional close always matches it.
     *
     * @return array{changed: bool, closed: ApprovalRequest|null}
     */
    private function transitionLocked(int $jobId): array
    {
        $request = ApprovalRequest::findBySql(self::REQUEST_LOCK_SQL, [':job' => $jobId])->one();
        $now = time();
        $changed = Job::updateAll(
            ['status' => Job::STATUS_CANCELED, 'finished_at' => $now, 'updated_at' => $now],
            ['id' => $jobId, 'status' => self::cancelableStatuses()]
        ) === 1;
        if (!$changed || !$request instanceof ApprovalRequest || $request->status !== ApprovalRequest::STATUS_PENDING) {
            return ['changed' => $changed, 'closed' => null];
        }

        ApprovalRequest::updateAll(
            ['status' => ApprovalRequest::STATUS_CANCELED, 'resolved_at' => $now],
            ['id' => $request->id, 'status' => ApprovalRequest::STATUS_PENDING]
        );

        return ['changed' => true, 'closed' => $request];
    }

    /**
     * The statuses a job can be canceled from: those of Job::isCancelable(),
     * the waiting ones and running.
     *
     * @return list<string>
     */
    private static function cancelableStatuses(): array
    {
        return [...Job::waitingStatuses(), Job::STATUS_RUNNING];
    }

    /**
     * job.canceled, and approval.canceled for the request the cancel closed.
     *
     * @param array<string, int|string> $context of job.canceled
     */
    private function audit(Job $job, ?int $actorId, string $reason, ?ApprovalRequest $closed, array $context): void
    {
        /** @var AuditService $audit */
        $audit = \Yii::$app->get('auditService');
        $audit->log(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id, $actorId, $context);
        if ($closed === null) {
            return;
        }

        $audit->log(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', (int)$closed->id, $actorId, [
            'job_id' => (int)$job->id,
            // As stored: a rule ID, or null once its rule may be deleted.
            'rule_id' => $closed->getAttribute('approval_rule_id'),
            'reason' => $reason,
        ]);
    }

    private function notifyCanceled(Job $job): void
    {
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');
        $dispatcher->dispatch(NotificationTemplate::EVENT_JOB_CANCELED, JobPayloadBuilder::build($job));
    }

    /**
     * The job of a workflow step ends the step as failed, and the run
     * follows the step's route, as for a job that failed. Without this the
     * step would stay running, and its run with it.
     */
    private function advanceWorkflow(Job $job): void
    {
        if (!WorkflowJobStep::find()->where(['job_id' => $job->id])->exists()) {
            return;
        }

        /** @var WorkflowExecutionService $engine */
        $engine = \Yii::$app->get('workflowExecutionService');
        $engine->onChildJobCompleted($job);
    }

    /**
     * @param list<string> $allowed
     * @throws \InvalidArgumentException when $reason is not one of $allowed
     */
    private static function assertReason(string $reason, array $allowed): void
    {
        if (!in_array($reason, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf(self::UNKNOWN_REASON, $reason, implode(', ', $allowed)));
        }
    }
}
