<?php

declare(strict_types=1);

namespace app\services;

use app\components\DbErrorSummary;
use app\models\AuditLog;
use app\models\Job;
use app\models\NotificationTemplate;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowTemplate;
use yii\base\Component;

/**
 * Cancels a workflow run.
 *
 * The run is claimed first, with a conditional UPDATE from running to
 * canceled. Only the call that claims it goes on, so two cancels (a user
 * and a template deletion, or two users) cannot both act, and a child job
 * that completes, or an approval decided, while the run is being canceled
 * finds the run finished and takes no route: the engine advances a run only
 * while it is running.
 *
 * From the claim on, the run is canceled: its workflow.canceled audit entry
 * (with the reason) is written and its workflow.canceled notification sent
 * at once, so a step that starts meanwhile finds the reason and the actor
 * (WorkflowStepDispatcher::settleStart(), through cancelOf()).
 *
 * Then each step still running is failed, with an error message that names
 * the reason, and its job, if it has one, is canceled through
 * JobCancellationService::cancelWithinWorkflow(): a waiting or running job
 * ends canceled, and the pending approval request of an approval step's
 * placeholder, or of a job step that waits for approval, is closed as
 * canceled. A job that finished meanwhile keeps its outcome. A job running on
 * a runner is marked canceled; the runner is not told and finishes the
 * playbook, whose late report is ignored.
 *
 * Each step is a unit of its own: a job cancel that hits a lock conflict is
 * tried once more, and a step that still fails is logged (STEP_CANCEL_FAILED,
 * without values) while the other steps are canceled all the same. Its job
 * cannot run meanwhile: no runner claims a job of a run that is not active
 * (JobClaimService), and the maintenance run's StrandedJobSweep ends the
 * step and cancels the job. Each step write and each job cancel is a
 * statement or transaction of its own after the claim, so this never holds
 * the run's row while it locks a request (the vote path locks the request
 * and the job, and touches the run after its commit).
 *
 * No access checks: the callers check (WorkflowExecutionService::cancel() for
 * users; the template deletion and the maintenance sweep act for the system).
 */
class WorkflowCancellationService extends Component
{
    /** The error message of a step that was running when a user canceled its run. */
    public const STEP_RUN_CANCELED = 'Not finished: the run was canceled.';

    /** The error message of a step that was running when its workflow template was deleted. */
    public const STEP_TEMPLATE_DELETED = 'Not finished: the run was canceled because its workflow template '
        . 'was deleted.';

    /**
     * Logged when a step of a run that this call canceled could not be
     * ended, or its job not canceled, also after a second attempt after a
     * lock conflict; sprintf(): the run ID, the step's ID (its execution,
     * as in the API's steps of the run), then the exception's class, SQLSTATE
     * and driver error code (DbErrorSummary). Never the message.
     */
    public const STEP_CANCEL_FAILED = 'Run #%d was canceled, but ending its step #%d failed: %s (SQLSTATE %s, '
        . 'driver error %s); no runner takes the step\'s job, and the maintenance run ends the step and cancels the job.';

    /**
     * For each reason a run is canceled for: the reason its jobs are canceled
     * for, and the error message of its running steps.
     */
    private const REASONS = [
        JobCancellationService::REASON_OPERATOR => [
            'job' => JobCancellationService::REASON_WORKFLOW_CANCELED,
            'step' => self::STEP_RUN_CANCELED,
        ],
        JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED => [
            'job' => JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED,
            'step' => self::STEP_TEMPLATE_DELETED,
        ],
    ];

    /**
     * Cancel $run, if it is still running (see the class description). Once
     * the run is claimed, nothing a step does stops the cancel: this returns
     * true, also when a step could not be canceled (logged).
     *
     * $run is refreshed either way, so it shows the status the run has now.
     *
     * @param int|null $actorId the user, for the audit entries; null: the signed-in user, if any
     * @param string $reason JobCancellationService::REASON_OPERATOR (a user canceled
     *     the run) or JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED
     * @return bool whether this call canceled the run; false when it had finished already
     * @throws \InvalidArgumentException for another reason
     */
    public function cancelRun(WorkflowJob $run, ?int $actorId, string $reason): bool
    {
        $mapping = self::mapping($reason);
        $claimed = $this->claim($run);
        $run->refresh();
        if (!$claimed) {
            return false;
        }

        $runId = (int)$run->id;
        /** @var AuditService $audit */
        $audit = \Yii::$app->get('auditService');
        $audit->log(AuditLog::ACTION_WORKFLOW_CANCELED, 'workflow_job', $runId, $actorId, ['reason' => $reason]);
        (new WorkflowRunEvents())->notify(NotificationTemplate::EVENT_WORKFLOW_CANCELED, $run);

        foreach ($this->runningSteps($runId) as $step) {
            try {
                $this->failStep($step, $mapping['step']);
                $this->cancelStepJob($step, $actorId, $runId, $mapping['job']);
            } catch (\Throwable $e) {
                \Yii::error(sprintf(self::STEP_CANCEL_FAILED, $runId, (int)$step->id, ...DbErrorSummary::of($e)), __CLASS__);
            }
        }

        return true;
    }

    /**
     * Why $run, which is not active, was canceled, and by whom: the reason
     * and the user of its newest workflow.canceled audit entry. Without such
     * an entry (the cancel has not written it yet, or the run ended
     * otherwise): REASON_WORKFLOW_TEMPLATE_DELETED when its workflow template
     * is deleted, else REASON_OPERATOR, with no user.
     *
     * @return array{reason: string, actor_id: int|null} reason: REASON_OPERATOR
     *     or REASON_WORKFLOW_TEMPLATE_DELETED (of JobCancellationService)
     */
    public function cancelOf(WorkflowJob $run): array
    {
        /** @var AuditLog|null $entry */
        $entry = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_WORKFLOW_CANCELED, 'object_type' => 'workflow_job', 'object_id' => (int)$run->id])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $context = $entry === null ? null : json_decode((string)$entry->metadata, true);
        $reason = is_array($context) ? ($context['reason'] ?? null) : null;
        if ($entry !== null && is_string($reason) && isset(self::REASONS[$reason])) {
            return ['reason' => $reason, 'actor_id' => $entry->user_id === null ? null : (int)$entry->user_id];
        }

        // A template row that is gone counts as deleted (scalar() is false then).
        $deletedAt = WorkflowTemplate::findWithDeleted()
            ->select('deleted_at')
            ->where(['id' => (int)$run->workflow_template_id])
            ->scalar();

        return [
            'reason' => $deletedAt === null ? JobCancellationService::REASON_OPERATOR : JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED,
            'actor_id' => null,
        ];
    }

    /**
     * The error message of the steps that a cancel for $reason ends.
     *
     * @throws \InvalidArgumentException for a reason a run is not canceled for
     */
    public static function stepMessage(string $reason): string
    {
        return self::mapping($reason)['step'];
    }

    /**
     * The error messages of every step that a run cancel ended:
     * STEP_RUN_CANCELED and STEP_TEMPLATE_DELETED. Such a step is stored as
     * failed and reads Canceled (runstate\Display::STATE_CANCELED); a step
     * the maintenance run ended for another reason
     * (StrandedJobSweep::STEP_RUN_ENDED) still reads Failed.
     *
     * @return list<string>
     */
    public static function stepMessages(): array
    {
        return array_values(array_unique(array_column(self::REASONS, 'step')));
    }

    /**
     * The reason the jobs of a run canceled for $reason are canceled for
     * (JobCancellationService::cancelWithinWorkflow()).
     *
     * @throws \InvalidArgumentException for a reason a run is not canceled for
     */
    public static function jobReason(string $reason): string
    {
        return self::mapping($reason)['job'];
    }

    /**
     * @return array{job: string, step: string}
     * @throws \InvalidArgumentException for a reason a run is not canceled for
     */
    private static function mapping(string $reason): array
    {
        if (!isset(self::REASONS[$reason])) {
            throw new \InvalidArgumentException(sprintf(
                JobCancellationService::UNKNOWN_REASON,
                $reason,
                implode(', ', array_keys(self::REASONS))
            ));
        }

        return self::REASONS[$reason];
    }

    /**
     * running -> canceled, with finished_at; whether this call changed it.
     */
    private function claim(WorkflowJob $run): bool
    {
        $now = time();

        return WorkflowJob::updateAll(
            ['status' => WorkflowJob::STATUS_CANCELED, 'finished_at' => $now, 'updated_at' => $now],
            ['id' => $run->id, 'status' => WorkflowJob::STATUS_RUNNING]
        ) === 1;
    }

    /**
     * @return list<WorkflowJobStep>
     */
    private function runningSteps(int $runId): array
    {
        /** @var list<WorkflowJobStep> $steps */
        $steps = WorkflowJobStep::find()
            ->where(['workflow_job_id' => $runId, 'status' => WorkflowJobStep::STATUS_RUNNING])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return $steps;
    }

    /**
     * Fail the step if it is still running: one that its job's completion
     * ended meanwhile keeps that outcome.
     */
    private function failStep(WorkflowJobStep $step, string $message): void
    {
        $now = time();
        WorkflowJobStep::updateAll(
            [
                'status' => WorkflowJobStep::STATUS_FAILED,
                'finished_at' => $now,
                'error_message' => $message,
                'updated_at' => $now,
            ],
            ['id' => $step->id, 'status' => WorkflowJobStep::STATUS_RUNNING]
        );
    }

    /**
     * Cancel the step's job: a placeholder that waits for approval, a job
     * that waits or runs. A pause step has none. A lock conflict (the cancel
     * locks the job's approval request, then the job) is tried once more:
     * the database rolled the attempt back.
     */
    private function cancelStepJob(WorkflowJobStep $step, ?int $actorId, int $runId, string $jobReason): void
    {
        if ($step->job_id === null) {
            return;
        }
        /** @var Job|null $job */
        $job = Job::findOne(['id' => $step->job_id]);
        if ($job === null) {
            return;
        }

        /** @var JobCancellationService $jobs */
        $jobs = \Yii::$app->get('jobCancellationService');
        try {
            $jobs->cancelWithinWorkflow($job, $actorId, $runId, $jobReason);
        } catch (\Throwable $e) {
            if (!DbErrorSummary::isLockConflict($e)) {
                throw $e;
            }
            $jobs->cancelWithinWorkflow($job, $actorId, $runId, $jobReason);
        }
    }
}
