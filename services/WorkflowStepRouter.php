<?php

declare(strict_types=1);

namespace app\services;

use app\models\ApprovalRequest;
use app\models\Job;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use yii\base\Component;

/**
 * Where a workflow run goes after a step ended.
 *
 * - A step that succeeded follows its Always branch, else its On Success
 *   branch; one that failed its Always branch, else its On Failure branch.
 * - A branch is the end of the workflow (END_WORKFLOW), a step of the same
 *   workflow, or empty: then the step with the next higher step order runs,
 *   and after the last step the run ends with the outcome of that step.
 * - A gate that did not open fails closed: an approval step that was
 *   rejected, timed out (rejecting) or canceled, and a job step whose job
 *   never ran because its approval was rejected, timed out or canceled while
 *   it waited, also when its approval request is gone (2.9.0 and older
 *   deleted the requests of a deleted rule). Only an explicit On Failure
 *   branch is followed; an empty one ends the run as failed instead of going
 *   on with the next step, and the Always branch is never taken (approval
 *   steps cannot have one any more; rows saved before that may).
 * - A branch to a step that no longer exists fails the run with
 *   ROUTE_TARGET_MISSING instead of ending it as if the workflow were done.
 *
 * Routes are read from the live steps: steps cannot change while a run of
 * their workflow is active.
 */
class WorkflowStepRouter extends Component
{
    /** The error of an approval step whose request was rejected: the request ID. */
    public const APPROVAL_REJECTED = 'Approval request #%d was rejected.';

    /** The error of an approval step whose request timed out and rejected: the request ID. */
    public const APPROVAL_TIMED_OUT = 'Approval request #%d timed out.';

    /** The error of an approval step whose request was canceled with its job: the request ID. */
    public const APPROVAL_CANCELED = 'Approval request #%d was canceled.';

    /** The error of a job step whose job's approval request was rejected: the request ID. */
    public const JOB_APPROVAL_REJECTED = 'Not run: approval request #%d was rejected.';

    /** The error of a job step whose job's approval request timed out and rejected: the request ID. */
    public const JOB_APPROVAL_TIMED_OUT = 'Not run: approval request #%d timed out.';

    /** The error of a job step whose job was canceled while it waited for approval: the request ID. */
    public const JOB_APPROVAL_CANCELED = 'Not run: approval request #%d was canceled.';

    /**
     * The error of an approval step whose placeholder was canceled while its
     * approval request was gone (2.9.0 and older deleted the requests of a
     * deleted rule; the maintenance run cancels such placeholders).
     */
    public const APPROVAL_MISSING = 'Not approved: the approval request of this step no longer exists.';

    /**
     * The error of a job step whose job was canceled while it waited for an
     * approval request that was gone (see APPROVAL_MISSING).
     */
    public const JOB_APPROVAL_MISSING = 'Not run: the approval request of its job no longer exists.';

    /** The run failed at a branch to a step that no longer exists: the step ID. */
    public const ROUTE_TARGET_MISSING = 'The next step #%d of this workflow no longer exists.';

    /**
     * The next step after $step, or why the run ends failed.
     *
     * @param bool $succeeded whether the step succeeded
     * @param bool $gateClosed whether its gate did not open (see the class description)
     * @return array{next: WorkflowStep|null, error: string|null} next null: the run ends;
     *     error: the run ends failed for this reason
     */
    public function route(WorkflowStep $step, bool $succeeded, bool $gateClosed): array
    {
        if ($gateClosed) {
            $onFailure = $step->on_failure_step_id;

            return $onFailure === null ? self::end() : $this->resolve($step, (int)$onFailure);
        }
        if ($step->on_always_step_id !== null) {
            return $this->resolve($step, (int)$step->on_always_step_id);
        }

        return $this->resolve($step, $succeeded ? $step->on_success_step_id : $step->on_failure_step_id);
    }

    /**
     * Why the gate of $execution did not open, or null when it opened or the
     * execution has none: its job was rejected while it waited for approval
     * (a rejection, or a timeout that rejects), or canceled then, which
     * canceled its request in the same transaction. A job canceled without
     * any approval request that was never queued waited for a request that
     * is gone: its gate never opened either. A job that was approved and
     * then failed or was canceled is no closed gate, nor is one canceled
     * while it was queued or ran.
     */
    public function closedGate(WorkflowJobStep $execution, Job $job): ?string
    {
        $request = ApprovalRequest::findOne(['job_id' => $job->id]);
        $closed = $job->status === Job::STATUS_REJECTED || ($job->status === Job::STATUS_CANCELED && (
            $request === null ? $job->queued_at === null : $request->status === ApprovalRequest::STATUS_CANCELED
        ));

        return $closed ? $this->gateMessage($execution, $request) : null;
    }

    /**
     * The error of a step whose gate did not open, by the status of its
     * approval request: canceled, timed out, else rejected; or that the
     * request is gone.
     */
    public function gateMessage(WorkflowJobStep $execution, ?ApprovalRequest $request): string
    {
        $placeholder = $execution->isApprovalPlaceholder();
        if ($request === null) {
            return $placeholder ? self::APPROVAL_MISSING : self::JOB_APPROVAL_MISSING;
        }
        $format = match ($request->status) {
            ApprovalRequest::STATUS_CANCELED => $placeholder ? self::APPROVAL_CANCELED : self::JOB_APPROVAL_CANCELED,
            ApprovalRequest::STATUS_TIMED_OUT => $placeholder ? self::APPROVAL_TIMED_OUT : self::JOB_APPROVAL_TIMED_OUT,
            default => $placeholder ? self::APPROVAL_REJECTED : self::JOB_APPROVAL_REJECTED,
        };

        return sprintf($format, (int)$request->id);
    }

    /**
     * A branch: END_WORKFLOW, a step ID, or null for the next step by order.
     *
     * @return array{next: WorkflowStep|null, error: string|null}
     */
    private function resolve(WorkflowStep $current, ?int $stepId): array
    {
        if ($stepId === WorkflowStep::END_WORKFLOW) {
            return self::end();
        }
        if ($stepId !== null) {
            /** @var WorkflowStep|null $target */
            $target = WorkflowStep::findOne(['id' => $stepId, 'workflow_template_id' => $current->workflow_template_id]);

            return $target === null
                ? ['next' => null, 'error' => sprintf(self::ROUTE_TARGET_MISSING, $stepId)]
                : ['next' => $target, 'error' => null];
        }

        /** @var WorkflowStep|null $next */
        $next = WorkflowStep::find()
            ->where(['workflow_template_id' => $current->workflow_template_id])
            ->andWhere(['>', 'step_order', $current->step_order])
            ->orderBy(['step_order' => SORT_ASC])
            ->one();

        return ['next' => $next, 'error' => null];
    }

    /**
     * @return array{next: null, error: null}
     */
    private static function end(): array
    {
        return ['next' => null, 'error' => null];
    }
}
