<?php

declare(strict_types=1);

namespace app\services;

use app\models\ApprovalRequest;

/**
 * A vote on an approval request that was refused. No vote was recorded.
 *
 * The checks run in this order (ApprovalSettlement::check()), under the
 * request's row lock when a vote is cast:
 * - the request is resolved (approved, rejected, timed out or canceled): 409
 * - it has expired (expires_at has passed; the maintenance run applies its
 *   timeout): 409
 * - its job no longer waits for approval, or the job's workflow step or run
 *   has ended, or its job template was deleted (the request is closed as
 *   canceled then, and a job still pending_approval is canceled): 409
 * - the voter launched the job: 403 LAUNCHER_REFUSED; for the job of a
 *   workflow step (the placeholder of an approval step, or the job of a job
 *   step whose template is gated), whose run the voter started:
 *   LAUNCHER_REFUSED_RUN
 * - the voter is not an eligible approver: 403
 * - the voter has already voted: 422
 * - the decision cannot be stored, e.g. a comment that is too long: 422
 * A request that no longer exists at all answers 404.
 *
 * The message is meant for users; HTML escaping is left to whoever renders
 * it. httpStatus() is the answer of the API (the web UI shows 403 and 404 as
 * errors, 409 as a warning and 422 as a danger flash), and requestStatus()
 * the request's status, which the API's 409 body carries.
 */
final class ApprovalDecisionRefusedException extends \RuntimeException
{
    public const REASON_GONE = 'gone';
    public const REASON_RESOLVED = 'resolved';
    public const REASON_EXPIRED = 'expired';
    public const REASON_JOB_NOT_WAITING = 'job_not_waiting';
    public const REASON_LAUNCHER = 'launcher';
    public const REASON_NOT_ELIGIBLE = 'not_eligible';
    public const REASON_ALREADY_VOTED = 'already_voted';
    public const REASON_INVALID = 'invalid';

    /** sprintf(): the request ID. */
    public const GONE = 'Approval request #%d no longer exists.';

    /** sprintf(): the request ID, its status in words (lower case). */
    public const RESOLVED = 'Approval request #%d is already resolved: %s.';

    /**
     * sprintf(): the request ID. The maintenance run applies timeouts, every
     * minute in a stock installation (a batch per run, and only while the
     * run's time budget lasts); own cron setups run approval/check-timeouts.
     */
    public const EXPIRED = 'Approval request #%d has expired and takes no more votes; '
        . 'its timeout is applied by the next maintenance run.';

    /** sprintf(): the request ID, the job ID. */
    public const JOB_NOT_WAITING = 'Approval request #%d can no longer be decided: '
        . 'its job #%d no longer waits for approval.';

    public const LAUNCHER_REFUSED = 'You launched this job; another approver must decide.';

    /**
     * The launcher's refusal for the job of a workflow step: the placeholder
     * of an approval step, or the job of a job step whose template is gated.
     * The voter started the run, and the run created the job.
     */
    public const LAUNCHER_REFUSED_RUN = 'You launched this workflow run; another approver must decide.';

    public const NOT_ELIGIBLE = 'You are not eligible to decide on this request.';

    public const ALREADY_VOTED = 'User has already voted on this request.';

    /** sprintf(): what validation found. */
    public const INVALID_DECISION = 'The decision cannot be recorded: %s';

    private const HTTP_STATUS = [
        self::REASON_GONE => 404,
        self::REASON_RESOLVED => 409,
        self::REASON_EXPIRED => 409,
        self::REASON_JOB_NOT_WAITING => 409,
        self::REASON_LAUNCHER => 403,
        self::REASON_NOT_ELIGIBLE => 403,
        self::REASON_ALREADY_VOTED => 422,
        self::REASON_INVALID => 422,
    ];

    private string $reason;

    private string $requestStatus;

    private function __construct(string $reason, string $message, string $requestStatus)
    {
        parent::__construct($message);
        $this->reason = $reason;
        $this->requestStatus = $requestStatus;
    }

    public static function gone(int $requestId): self
    {
        return new self(self::REASON_GONE, sprintf(self::GONE, $requestId), '');
    }

    public static function resolved(ApprovalRequest $request): self
    {
        $status = (string)$request->status;

        return new self(
            self::REASON_RESOLVED,
            sprintf(self::RESOLVED, (int)$request->id, strtolower(ApprovalRequest::statusLabel($status))),
            $status
        );
    }

    public static function expired(ApprovalRequest $request): self
    {
        return new self(self::REASON_EXPIRED, sprintf(self::EXPIRED, (int)$request->id), (string)$request->status);
    }

    public static function jobNotWaiting(ApprovalRequest $request): self
    {
        return new self(
            self::REASON_JOB_NOT_WAITING,
            sprintf(self::JOB_NOT_WAITING, (int)$request->id, (int)$request->job_id),
            (string)$request->status
        );
    }

    /**
     * The voter launched the request's job: LAUNCHER_REFUSED, or
     * LAUNCHER_REFUSED_RUN when the job belongs to a workflow step
     * ($workflowRun), whose run the voter started.
     */
    public static function launcher(ApprovalRequest $request, bool $workflowRun = false): self
    {
        return new self(
            self::REASON_LAUNCHER,
            $workflowRun ? self::LAUNCHER_REFUSED_RUN : self::LAUNCHER_REFUSED,
            (string)$request->status
        );
    }

    public static function notEligible(ApprovalRequest $request): self
    {
        return new self(self::REASON_NOT_ELIGIBLE, self::NOT_ELIGIBLE, (string)$request->status);
    }

    public static function alreadyVoted(ApprovalRequest $request): self
    {
        return new self(self::REASON_ALREADY_VOTED, self::ALREADY_VOTED, (string)$request->status);
    }

    /**
     * @param string $errors what validation found, e.g. "Comment should contain at most 1,000 characters."
     */
    public static function invalid(ApprovalRequest $request, string $errors): self
    {
        return new self(self::REASON_INVALID, sprintf(self::INVALID_DECISION, $errors), (string)$request->status);
    }

    /**
     * One of the REASON_* constants.
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * 404 (gone), 409 (the request's state), 403 (the voter) or 422 (the vote).
     */
    public function httpStatus(): int
    {
        return self::HTTP_STATUS[$this->reason];
    }

    /**
     * The status of the request when the vote was refused; '' when the
     * request no longer exists.
     */
    public function requestStatus(): string
    {
        return $this->requestStatus;
    }
}
