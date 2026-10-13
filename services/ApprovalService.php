<?php

declare(strict_types=1);

namespace app\services;

use app\components\DbErrorSummary;
use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use yii\base\Component;

/**
 * Manages approval workflows: creating requests, recording decisions,
 * checking thresholds, and processing timeouts.
 *
 * Votes and timeouts are settled under the request's row lock
 * (ApprovalSettlement); their audit entries, notifications and the workflow
 * advance follow after the commit (ApprovalEffects). Who may decide is
 * ApproverEligibility's to say; the user who launched the job never may.
 */
class ApprovalService extends Component
{
    /**
     * Logged when the timeout of one request failed and was rolled back:
     * the request stays pending, and the next run tries it again.
     * sprintf(): the request ID, the exception class, the SQLSTATE and the
     * driver's error code ("-" when there is none).
     */
    public const TIMEOUT_FAILED = 'Approval timeout of request #%d failed: %s (SQLSTATE %s, driver error %s).';

    /**
     * Logged when what follows a timeout after its commit failed (the audit
     * entry, the notification or the workflow advance): the request stays
     * settled (timed out, or closed because its job no longer waited), and
     * nothing tries the follow-up again; a workflow run that waited at the
     * step does not move on (cancel the run and launch the workflow again).
     * sprintf(): the request ID, the exception class, the SQLSTATE and the
     * driver's error code ("-" when there is none).
     */
    public const TIMEOUT_EFFECTS_FAILED = 'Approval request #%d was settled by its timeout, but the audit entry, '
        . 'notification or workflow advance after it failed: %s (SQLSTATE %s, driver error %s).';

    /**
     * Logged when what follows a vote after its commit failed (the audit
     * entry, the notification or the workflow advance); what the vote
     * committed stands (the vote, or the close of a request whose job no
     * longer waited). sprintf(): the request ID, the voter's user ID, the
     * exception class, the SQLSTATE and the driver's error code ("-" when
     * there is none).
     */
    public const VOTE_EFFECTS_FAILED = 'Approval request #%d: the audit entry, notification or workflow advance '
        . 'after the vote of user #%d failed: %s (SQLSTATE %s, driver error %s).';

    /** What validation finds in a vote whose comment is neither a string nor null (a list, an object, a number). */
    public const COMMENT_NOT_A_STRING = 'Comment must be a string.';

    /**
     * Create an approval request for a job that waits for it, and announce
     * it (audit entry, notification).
     */
    public function createRequest(Job $job, ApprovalRule $rule): ApprovalRequest
    {
        $request = $this->openRequest($job, $rule);
        $this->announceRequest($request, $job, $rule);

        return $request;
    }

    /**
     * Insert the pending approval request of $job, without announcing it:
     * for a caller that creates the job and its request in one transaction
     * and calls announceRequest() after the commit.
     *
     * @throws \RuntimeException when the request cannot be saved
     */
    public function openRequest(Job $job, ApprovalRule $rule): ApprovalRequest
    {
        $request = new ApprovalRequest();
        $request->job_id = $job->id;
        $request->approval_rule_id = $rule->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();

        if ($rule->timeout_minutes !== null) {
            $request->expires_at = time() + ($rule->timeout_minutes * 60);
        }

        if (!$request->save()) {
            throw new \RuntimeException(
                'Failed to create approval request: ' . json_encode($request->errors)
            );
        }

        return $request;
    }

    /**
     * The approval.requested audit entry and notification of a request
     * openRequest() created, once its transaction committed.
     */
    public function announceRequest(ApprovalRequest $request, Job $job, ApprovalRule $rule): void
    {
        $this->effects()->requested($request, $job, $rule);
    }

    /**
     * Record user #$userId's approval or rejection and settle the request
     * when it decides it. $request is refreshed afterwards.
     *
     * The audit entry, the notification and the workflow advance follow
     * after the commit. A failure among them cannot undo the vote: it is
     * logged (VOTE_EFFECTS_FAILED, without values), and the vote is
     * answered as recorded.
     *
     * @return ApprovalDecision The recorded decision.
     * @throws ApprovalDecisionRefusedException when the vote is refused; nothing is recorded
     *     (a request whose job no longer waits is closed as canceled, though)
     */
    public function recordDecision(
        ApprovalRequest $request,
        int $userId,
        string $decision,
        ?string $comment = null
    ): ApprovalDecision {
        $outcome = $this->settlement()->vote((int)$request->id, $userId, $decision, $comment);
        try {
            $this->effects()->settled($outcome, $userId);
        } catch (\Throwable $e) {
            self::logFailure(self::VOTE_EFFECTS_FAILED, [(int)$request->id, $userId], $e);
        }
        $request->refresh();
        if ($outcome->vote === null) {
            throw ApprovalDecisionRefusedException::jobNotWaiting($request);
        }

        return $outcome->vote;
    }

    /**
     * A vote's comment as a client sent it ($comment): a string, or null
     * for none. Anything else (a list, an object, a number) is refused as
     * an invalid decision before the vote is checked, so it never reaches
     * the vote as text.
     *
     * @throws ApprovalDecisionRefusedException (REASON_INVALID, 422) with COMMENT_NOT_A_STRING
     */
    public static function commentOf(ApprovalRequest $request, mixed $comment): ?string
    {
        if ($comment !== null && !is_string($comment)) {
            throw ApprovalDecisionRefusedException::invalid($request, self::COMMENT_NOT_A_STRING);
        }

        return $comment;
    }

    /**
     * Whether user #$userId may decide on the request now: it is pending,
     * has not expired, its job waits for it, and the user is an eligible
     * approver (not the launcher) who has not voted yet.
     */
    public function canUserApprove(ApprovalRequest $request, int $userId): bool
    {
        return $this->decisionRefusal($request, $userId) === null;
    }

    /**
     * Why a vote of user #$userId on the request would be refused now, or
     * null when it would be recorded (ApprovalSettlement::check()).
     */
    public function decisionRefusal(ApprovalRequest $request, int $userId): ?ApprovalDecisionRefusedException
    {
        /** @var Job|null $job */
        $job = $request->job;

        return $this->settlement()->check($request, $job, $userId, time());
    }

    /**
     * The users who may decide on a request (ApproverEligibility): the
     * rule's approvers who have an active account, may decide (superadmin
     * or approval.decide), may see the request, and did not launch its job.
     *
     * @return list<int>
     */
    public function eligibleApproverIds(ApprovalRequest $request): array
    {
        return $this->eligibility()->eligibleForRequest($request);
    }

    /**
     * Apply the timeouts of expired pending requests, the longest expired
     * first (expires_at, then id); the maintenance run calls this every
     * minute, approval/check-timeouts for own cron setups. Each request is
     * settled on its own: one that fails is logged (without values) and
     * left for the next run, and does not stop the others (see
     * ApprovalTimeouts).
     *
     * @param int $limit the most requests to process; 0 for all
     * @param float|null $deadline no request is started once microtime(true)
     *     reaches it (one already started is finished; the rest wait for the
     *     next run); null for no time bound
     * @return int the number of requests this call resolved (timed out, or
     *     closed because their job no longer waited)
     */
    public function processTimeouts(int $limit = 0, ?float $deadline = null): int
    {
        return (new ApprovalTimeouts())->process($limit, $deadline);
    }

    /**
     * Log $pattern filled with $ids, then the class of $e and, for a
     * database error, its SQLSTATE and the driver's error code ("-" when
     * there is none, DbErrorSummary), in this class's category (also for
     * ApprovalTimeouts). Never the message: a database error's message
     * carries the SQL with its values.
     *
     * @param list<int> $ids
     */
    public static function logFailure(string $pattern, array $ids, \Throwable $e): void
    {
        \Yii::error(sprintf($pattern, ...[...$ids, ...DbErrorSummary::of($e)]), __CLASS__);
    }

    private function settlement(): ApprovalSettlement
    {
        /** @var ApprovalSettlement $settlement */
        $settlement = \Yii::$app->get('approvalSettlement');

        return $settlement;
    }

    private function eligibility(): ApproverEligibility
    {
        /** @var ApproverEligibility $eligibility */
        $eligibility = \Yii::$app->get('approverEligibility');

        return $eligibility;
    }

    private function effects(): ApprovalEffects
    {
        return new ApprovalEffects();
    }
}
