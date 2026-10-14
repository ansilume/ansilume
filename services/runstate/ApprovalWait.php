<?php

declare(strict_types=1);

namespace app\services\runstate;

/**
 * The approval request of one job, as a viewer may see it
 * (ApprovalWaits::forJobs() returns only requests the viewer may see). Built
 * eagerly, so reading it runs no query.
 *
 * The approval and rejection counts leave out the votes of the user who
 * launched the job (ApprovalRequest::countedDecisionsOf()). The eligibility
 * fields and the viewer's relation are null unless
 * ApprovalWaits::withEligibility() filled them: only for a pending request,
 * and the relation only for the viewer's own page.
 */
final class ApprovalWait
{
    /**
     * @param int $requestId the request
     * @param int $jobId the job that waits for it: a gated job, or the placeholder of an approval step
     * @param string $status the request's status (ApprovalRequest::STATUS_*)
     * @param int|null $ruleId null once its rule was deleted
     * @param string|null $ruleName null once its rule was deleted
     * @param int|null $requiredApprovals the rule's threshold; null once its rule was deleted
     * @param int $approvalCount the approvals that count
     * @param int $rejectionCount the rejections that count
     * @param int|null $requestedAt when the request was opened
     * @param int|null $expiresAt when its timeout applies; null without a timeout
     * @param int|null $resolvedAt when it was decided, timed out or canceled
     * @param string|null $timeoutAction what the timeout does (ApprovalRule::TIMEOUT_ACTION_REJECT
     *     or TIMEOUT_ACTION_APPROVE); null without a timeout or a rule
     * @param bool $linkable whether the viewer may open the request page (approval.view)
     * @param string|null $relation the viewer's relation to the request (Display::RELATION_*);
     *     null when not computed or none applies
     * @param int|null $eligibleCount the approvers who may decide (ApproverEligibility)
     * @param int|null $openCount the eligible approvers who have not voted yet
     * @param bool|null $approvable whether the approvals that count plus the open approvers
     *     still reach the rule's threshold
     * @param string|null $decisionNote how the request was decided, as the viewer may see it
     *     (Texts::DECIDED_*); filled by ApprovalWaits::forJobs() with decisions, null otherwise
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Immutable DTO; splitting reduces cohesion without benefit.
     */
    public function __construct(
        public readonly int $requestId,
        public readonly int $jobId,
        public readonly string $status,
        public readonly ?int $ruleId,
        public readonly ?string $ruleName,
        public readonly ?int $requiredApprovals,
        public readonly int $approvalCount,
        public readonly int $rejectionCount,
        public readonly ?int $requestedAt,
        public readonly ?int $expiresAt,
        public readonly ?int $resolvedAt,
        public readonly ?string $timeoutAction,
        public readonly bool $linkable,
        public readonly ?string $relation = null,
        public readonly ?int $eligibleCount = null,
        public readonly ?int $openCount = null,
        public readonly ?bool $approvable = null,
        public readonly ?string $decisionNote = null,
    ) {
    }

    /**
     * This wait with the eligibility of its approvers and the viewer's
     * relation; every other field stays as it is.
     */
    public function withEligibility(int $eligibleCount, int $openCount, bool $approvable, ?string $relation): self
    {
        return new self(
            $this->requestId,
            $this->jobId,
            $this->status,
            $this->ruleId,
            $this->ruleName,
            $this->requiredApprovals,
            $this->approvalCount,
            $this->rejectionCount,
            $this->requestedAt,
            $this->expiresAt,
            $this->resolvedAt,
            $this->timeoutAction,
            $this->linkable,
            $relation,
            $eligibleCount,
            $openCount,
            $approvable,
            $this->decisionNote,
        );
    }
}
