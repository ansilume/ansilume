<?php

declare(strict_types=1);

namespace app\services;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;

/**
 * What one settlement of an approval request did (ApprovalSettlement): the
 * vote it recorded, the status it gave the request, and how the job left
 * pending_approval. Its side effects (audit entries, notifications, the
 * workflow advance) follow after the transaction committed, see
 * ApprovalEffects.
 */
final class ApprovalOutcome
{
    /**
     * @param ApprovalRequest $request the request as locked, with the status this settlement gave it
     * @param Job|null $job the request's job as it is after the settlement; null when it no longer exists
     * @param ApprovalRule|null $rule the request's rule; null once it was deleted
     * @param string|null $resolution the status this settlement gave the request (approved, rejected,
     *     timed_out, canceled); null when the request is still pending
     * @param string|null $jobStatus the status this settlement moved the job to from pending_approval:
     *     queued (approved), succeeded (the approved placeholder of a workflow approval step, which never
     *     runs), rejected, or canceled (its workflow step or run had ended, or its job template was
     *     deleted); null when the job did not change
     * @param WorkflowJobStep|null $step the execution of the workflow step the job belongs to (an approval
     *     step's placeholder, or the job of a job step whose template is gated); null for a job outside a
     *     workflow, and for a job that was no longer pending_approval (its step is not read then)
     * @param ApprovalDecision|null $vote the vote this settlement recorded; null for a timeout, or
     *     when the vote found the job no longer waiting and closed the request
     * @param array<string, int|string> $timeout the context of the approval.timed_out audit entry;
     *     [] when the settlement was no timeout
     * @param string|null $closeReason why the request was closed as canceled (the approval.canceled
     *     reason): ApprovalSettlement::REASON_JOB_NOT_WAITING, REASON_WORKFLOW_STEP_ENDED or
     *     REASON_TEMPLATE_DELETED; null when it was not closed
     */
    public function __construct(
        public readonly ApprovalRequest $request,
        public readonly ?Job $job,
        public readonly ?ApprovalRule $rule,
        public readonly ?string $resolution,
        public readonly ?string $jobStatus,
        public readonly ?WorkflowJobStep $step,
        public readonly ?ApprovalDecision $vote = null,
        public readonly array $timeout = [],
        public readonly ?string $closeReason = null,
    ) {
    }

    /**
     * What the workflow engine is told through onApprovalResolved(), if
     * anything: true when the placeholder of an approval step was approved,
     * false when the job of any workflow step was rejected, null otherwise.
     * An approved job of a gated job step is queued instead: its step ends
     * when the job does. A job canceled because its step had ended has
     * nothing to advance; one canceled because its template was deleted
     * ends its step as a canceled job (see canceledForDeletedTemplate()).
     */
    public function workflowDecision(): ?bool
    {
        if ($this->step === null) {
            return null;
        }

        return match ($this->jobStatus) {
            Job::STATUS_REJECTED => false,
            Job::STATUS_SUCCEEDED => true,
            default => null,
        };
    }

    /**
     * The workflow run whose ended step's job this settlement canceled
     * (REASON_WORKFLOW_STEP_ENDED), as read with the step; null when it
     * canceled no such job.
     */
    public function canceledRun(): ?WorkflowJob
    {
        if (
            $this->jobStatus !== Job::STATUS_CANCELED
            || $this->closeReason !== ApprovalSettlement::REASON_WORKFLOW_STEP_ENDED
            || $this->step === null
        ) {
            return null;
        }

        return $this->step->workflowJob;
    }

    /**
     * Whether this settlement canceled the job because its job template was
     * deleted (REASON_TEMPLATE_DELETED). Its step, if it has one, runs in an
     * active run (an ended step comes first, see ApprovalJobState).
     */
    public function canceledForDeletedTemplate(): bool
    {
        return $this->jobStatus === Job::STATUS_CANCELED
            && $this->closeReason === ApprovalSettlement::REASON_TEMPLATE_DELETED;
    }
}
