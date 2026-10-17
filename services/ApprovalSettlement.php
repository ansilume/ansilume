<?php

declare(strict_types=1);

namespace app\services;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use yii\base\Component;
use yii\db\IntegrityException;

/**
 * Settles an approval request: records a vote and evaluates the threshold,
 * or applies the timeout of an expired request. Each settlement is one
 * transaction whose first statement locks the request row
 * (SELECT ... FOR UPDATE), then the job row: approval_request before job,
 * as every path that writes both (JobCancellationService too), so they
 * cannot deadlock. Votes and timeouts of one request therefore run one
 * after the other, and each sees what the one before it committed: a
 * request is decided exactly once, by the first vote or timeout that gets
 * the lock. Every write is conditional on the state that was checked
 * (request WHERE status = 'pending', job WHERE status = 'pending_approval').
 *
 * Job templates and inventories, which no settlement touches, are locked
 * job_template first (FOR UPDATE), then the inventories (LOCK IN SHARE MODE,
 * in one statement in ascending ID order, JobTemplateGate::readInventoryRules()):
 * by a launch (JobLaunchService) and a template save (GatedJobTemplateLock).
 * An inventory save locks only its own row and reads job templates without a
 * lock (InventoryWriteService).
 *
 * The two locks are the transaction's first two statements, before any
 * plain (consistent) read. InnoDB takes a transaction's snapshot at its
 * first consistent read, so every later read of the settlement (the rule,
 * the votes, the job's workflow step and run, its template's deleted state,
 * see ApprovalJobState) sees what was committed before the locks were
 * granted. The statement-order tests of votes and timeouts pin this.
 *
 * A job whose workflow step or run has ended, or whose job template was
 * deleted, no longer waits for approval (ApprovalJobState): its request is
 * closed as canceled and the job is canceled with it
 * (REASON_WORKFLOW_STEP_ENDED, REASON_TEMPLATE_DELETED), so an approval can
 * never queue the job of a canceled run, nor a job no runner would take.
 *
 * Nothing but the database is touched here: the audit entries, the
 * notifications and the workflow advance follow after the commit
 * (ApprovalEffects), so no lock is held while they run.
 */
class ApprovalSettlement extends Component
{
    /**
     * How long after expires_at a timeout whose action is "approve" still
     * approves, in seconds. A timeout applied later (a backlog of requests
     * no timeout ever processed, an outage of the schedule runner) rejects
     * instead: nobody reviewed what would run that late.
     */
    public const APPROVE_TIMEOUT_GRACE_SECONDS = 3600;

    /** approval.timed_out context "reason": the grace period had passed, so the timeout rejected. */
    public const REASON_GRACE_PERIOD_EXCEEDED = 'grace_period_exceeded';

    /** approval.canceled context "reason": the request's job no longer waited for approval. */
    public const REASON_JOB_NOT_WAITING = 'job_not_waiting';

    /**
     * approval.canceled context "reason": the request's job was still
     * pending_approval, but its workflow step was no longer running or its
     * run no longer active (a run cancel that failed part-way); the
     * settlement canceled the job as well.
     */
    public const REASON_WORKFLOW_STEP_ENDED = 'workflow_step_ended';

    /**
     * approval.canceled context "reason": the request's job was still
     * pending_approval, but its job template had been deleted (deleting it
     * cancels its waiting jobs after its commit; a vote or timeout came
     * first, or that cancel failed); the settlement canceled the job as
     * well. The same value as the template delete's own cancel writes
     * (JobCancellationService::REASON_TEMPLATE_DELETED), so the entries do
     * not depend on which of them came first.
     */
    public const REASON_TEMPLATE_DELETED = JobCancellationService::REASON_TEMPLATE_DELETED;

    /** The SQLSTATE of an integrity constraint violation, a duplicate key among them. */
    private const INTEGRITY_SQLSTATE = '23000';

    /** MariaDB's (and MySQL's) error code of a duplicate key (ER_DUP_ENTRY). */
    private const DUPLICATE_KEY_ERROR = 1062;

    private const REQUEST_LOCK_SQL = 'SELECT * FROM {{%approval_request}} WHERE [[id]] = :id FOR UPDATE';

    private const JOB_LOCK_SQL = 'SELECT * FROM {{%job}} WHERE [[id]] = :id FOR UPDATE';

    /** The votes, read with a lock: correct whatever snapshot an enclosing transaction holds. */
    private const TALLY_SQL = 'SELECT [[user_id]], [[decision]] FROM {{%approval_decision}}'
        . ' WHERE [[approval_request_id]] = :id LOCK IN SHARE MODE';

    /**
     * Record user #$userId's vote on request #$requestId and settle the
     * request when the vote decides it: approved once enough approvals
     * count, rejected once the approvals still possible cannot reach the
     * threshold. The launcher's votes never count.
     *
     * A request whose job no longer waits for approval is closed as
     * canceled instead (a job whose workflow step or run has ended, or whose
     * job template was deleted, is canceled with it); the outcome then
     * carries no vote.
     *
     * @throws ApprovalDecisionRefusedException when the vote is refused (see check()); nothing is written
     */
    public function vote(int $requestId, int $userId, string $decision, ?string $comment): ApprovalOutcome
    {
        /** @var ApprovalOutcome $outcome */
        $outcome = ApprovalRequest::getDb()->transaction(
            fn (): ApprovalOutcome => $this->voteLocked($requestId, $userId, $decision, $comment)
        );

        return $outcome;
    }

    /**
     * Apply the timeout of request #$requestId, if it is still pending and
     * expired: the request becomes timed_out, and its job is approved or
     * rejected by its rule's timeout action. An approve applies only within
     * APPROVE_TIMEOUT_GRACE_SECONDS after expires_at; later the job is
     * rejected, and the outcome's audit context says why. A request whose
     * job no longer waits is closed as canceled instead, as by a vote.
     *
     * The settlement's time is the later of $now (when the batch selected
     * the request) and the clock once the request's lock is held: a request
     * that waited behind slow ones of its batch, or for the lock, is judged
     * and stamped by the time its timeout is really applied.
     *
     * @return ApprovalOutcome|null null when there was nothing to do: the request is gone,
     *     no longer pending (a vote or another run settled it first) or not expired
     */
    public function timeOut(int $requestId, int $now): ?ApprovalOutcome
    {
        /** @var ApprovalOutcome|null $outcome */
        $outcome = ApprovalRequest::getDb()->transaction(
            fn (): ?ApprovalOutcome => $this->timeOutLocked($requestId, $now)
        );

        return $outcome;
    }

    /**
     * Why user #$userId may not vote on $request (with its $job) at $now,
     * or null when they may. In this order: the request is resolved, it
     * has expired, its job no longer waits (also: the job's workflow step or
     * run has ended, or its job template was deleted), the user launched the
     * job (for the job of a workflow step: started its run), the user is no
     * eligible approver, the user has voted already. A vote runs the same
     * checks on the locked rows; the view uses this to show or hide Approve
     * and Reject.
     */
    public function check(ApprovalRequest $request, ?Job $job, int $userId, int $now): ?ApprovalDecisionRefusedException
    {
        $refusal = $this->stateRefusal($request, $now);
        if ($refusal !== null) {
            return $refusal;
        }
        $state = ApprovalJobState::read($job);
        if (!$state->waits()) {
            return ApprovalDecisionRefusedException::jobNotWaiting($request);
        }

        return $this->voterRefusal($request, $userId, $state->step !== null);
    }

    private function voteLocked(int $requestId, int $userId, string $decision, ?string $comment): ApprovalOutcome
    {
        $request = $this->lockRequest($requestId);
        if ($request === null) {
            throw ApprovalDecisionRefusedException::gone($requestId);
        }
        // The time once the lock is held: a vote that waited for it is checked against expiry as it lands.
        $now = time();
        $job = $this->lockJob($request);
        $refusal = $this->stateRefusal($request, $now);
        if ($refusal !== null) {
            throw $refusal;
        }
        $state = ApprovalJobState::read($job);
        if (!$state->waits()) {
            return $this->close($request, $state, $now);
        }
        $refusal = $this->voterRefusal($request, $userId, $state->step !== null);
        if ($refusal !== null) {
            throw $refusal;
        }

        return $this->settleVote($request, $state, $this->insertVote($request, $userId, $decision, $comment, $now), $now);
    }

    private function timeOutLocked(int $requestId, int $now): ?ApprovalOutcome
    {
        $request = $this->lockRequest($requestId);
        // The time once the lock is held, not the batch's (see timeOut()).
        $now = max($now, time());
        if ($request === null || $request->isResolved() || !$request->hasExpired($now)) {
            return null;
        }
        $state = ApprovalJobState::read($this->lockJob($request));
        if (!$state->waits()) {
            return $this->close($request, $state, $now);
        }

        // The rule's action as it is now (rejecting without a rule); an
        // approve only within the grace period after expires_at.
        /** @var ApprovalRule|null $rule */
        $rule = $request->approvalRule;
        $action = $rule?->timeout_action === ApprovalRule::TIMEOUT_ACTION_APPROVE
            ? ApprovalRule::TIMEOUT_ACTION_APPROVE
            : ApprovalRule::TIMEOUT_ACTION_REJECT;
        $late = $now - (int)$request->expires_at > self::APPROVE_TIMEOUT_GRACE_SECONDS;
        $applied = $late ? ApprovalRule::TIMEOUT_ACTION_REJECT : $action;
        $context = ['job_id' => (int)$request->job_id, 'timeout_action' => $action, 'applied_action' => $applied]
            + ($applied === $action ? [] : ['reason' => self::REASON_GRACE_PERIOD_EXCEEDED]);

        return $this->resolve(
            $request,
            $state,
            ApprovalRequest::STATUS_TIMED_OUT,
            $applied === ApprovalRule::TIMEOUT_ACTION_APPROVE,
            $now,
            null,
            $context
        );
    }

    /**
     * The request's own state: resolved, or expired at $now.
     */
    private function stateRefusal(ApprovalRequest $request, int $now): ?ApprovalDecisionRefusedException
    {
        if ($request->isResolved()) {
            return ApprovalDecisionRefusedException::resolved($request);
        }
        if ($request->hasExpired($now)) {
            return ApprovalDecisionRefusedException::expired($request);
        }

        return null;
    }

    /**
     * The voter: not the launcher, an eligible approver, not voted yet.
     * $workflowRun: the job belongs to a workflow step (ApprovalJobState's
     * step), so its launcher started the run and is told so.
     */
    private function voterRefusal(
        ApprovalRequest $request,
        int $userId,
        bool $workflowRun
    ): ?ApprovalDecisionRefusedException {
        $eligibility = $this->eligibility();
        if ($eligibility->isLauncher($request, $userId)) {
            return ApprovalDecisionRefusedException::launcher($request, $workflowRun);
        }
        if (!$eligibility->mayDecide($request, $userId)) {
            return ApprovalDecisionRefusedException::notEligible($request);
        }
        if ($request->hasDecisionBy($userId)) {
            return ApprovalDecisionRefusedException::alreadyVoted($request);
        }

        return null;
    }

    /**
     * The vote, inserted. The unique index on (request, user) backs up the
     * check for an earlier vote: a duplicate key is refused the same way.
     * It is the only key a vote can duplicate (its id is generated). Any
     * other integrity error, such as a voter deleted meanwhile, is no
     * second vote and is rethrown.
     *
     * @throws ApprovalDecisionRefusedException
     * @throws IntegrityException for an integrity error other than a duplicate key
     */
    private function insertVote(
        ApprovalRequest $request,
        int $userId,
        string $decision,
        ?string $comment,
        int $now
    ): ApprovalDecision {
        $vote = new ApprovalDecision();
        $vote->approval_request_id = (int)$request->id;
        $vote->user_id = $userId;
        $vote->decision = $decision;
        $vote->comment = $comment;
        $vote->created_at = $now;
        if (!$vote->validate()) {
            throw ApprovalDecisionRefusedException::invalid($request, implode(' ', $vote->getFirstErrors()));
        }
        try {
            $vote->save(false);
        } catch (IntegrityException $e) {
            $info = $e->errorInfo;
            $duplicate = ($info[0] ?? null) === self::INTEGRITY_SQLSTATE
                && in_array($info[1] ?? null, [self::DUPLICATE_KEY_ERROR, (string)self::DUPLICATE_KEY_ERROR], true);
            if (!$duplicate) {
                throw $e;
            }
            throw ApprovalDecisionRefusedException::alreadyVoted($request);
        }

        return $vote;
    }

    /**
     * After the vote: approved once the approvals that count reach the
     * rule's threshold; rejected once they cannot reach it with the votes
     * the eligible approvers have not cast yet; otherwise still pending.
     */
    private function settleVote(
        ApprovalRequest $request,
        ApprovalJobState $state,
        ApprovalDecision $vote,
        int $now
    ): ApprovalOutcome {
        /** @var ApprovalRule $rule the voter was found eligible, so the request has its rule */
        $rule = $request->approvalRule;
        $required = (int)$rule->required_approvals;
        $tally = $this->tally($request, $request->launcherId());
        if ($tally['approvals'] >= $required) {
            return $this->resolve($request, $state, ApprovalRequest::STATUS_APPROVED, true, $now, $vote, []);
        }
        $open = count(array_diff($this->eligibility()->eligibleForRequest($request), $tally['voters']));
        if ($tally['approvals'] + $open < $required) {
            return $this->resolve($request, $state, ApprovalRequest::STATUS_REJECTED, false, $now, $vote, []);
        }

        return new ApprovalOutcome($request, $state->job, $rule, null, null, $state->step, $vote);
    }

    /**
     * The approvals that count (every one but the launcher's) and who voted.
     *
     * @return array{approvals: int, voters: list<int>}
     */
    private function tally(ApprovalRequest $request, int $launcherId): array
    {
        /** @var list<array{user_id: int|string, decision: string}> $rows */
        $rows = ApprovalRequest::getDb()->createCommand(self::TALLY_SQL, [':id' => $request->id])->queryAll();
        $approvals = 0;
        $voters = [];
        foreach ($rows as $row) {
            $voterId = intval($row['user_id']);
            $voters[] = $voterId;
            if ($voterId !== $launcherId && $row['decision'] === ApprovalDecision::DECISION_APPROVED) {
                $approvals++;
            }
        }

        return ['approvals' => $approvals, 'voters' => $voters];
    }

    /**
     * Give the request $status and move its job (it waits: $state->waits())
     * out of pending_approval: approved, a workflow approval step's
     * placeholder succeeds (it never runs) and every other job is queued;
     * rejected, the job is rejected. Both writes are conditional; under the
     * locks they always apply.
     *
     * @param array<string, int|string> $timeout
     */
    private function resolve(
        ApprovalRequest $request,
        ApprovalJobState $state,
        string $status,
        bool $approve,
        int $now,
        ?ApprovalDecision $vote,
        array $timeout
    ): ApprovalOutcome {
        ApprovalRequest::updateAll(
            ['status' => $status, 'resolved_at' => $now],
            ['id' => $request->id, 'status' => ApprovalRequest::STATUS_PENDING]
        );
        $request->status = $status;
        $request->resolved_at = $now;

        /** @var Job $job a job that waits exists */
        $job = $state->job;
        if (!$approve) {
            $target = ['status' => Job::STATUS_REJECTED, 'finished_at' => $now];
        } elseif ($state->placeholder()) {
            $target = ['status' => Job::STATUS_SUCCEEDED, 'finished_at' => $now];
        } else {
            $target = ['status' => Job::STATUS_QUEUED, 'queued_at' => $now];
        }
        $changed = Job::updateAll(
            $target + ['updated_at' => $now],
            ['id' => $job->id, 'status' => Job::STATUS_PENDING_APPROVAL]
        ) === 1;
        $job->refresh();

        /** @var ApprovalRule|null $rule */
        $rule = $request->approvalRule;

        return new ApprovalOutcome(
            $request,
            $job,
            $rule,
            $status,
            $changed ? $target['status'] : null,
            $state->step,
            $vote,
            $timeout
        );
    }

    /**
     * A pending request whose job no longer waits for approval (or is
     * gone): nothing can decide it any more, so it is closed as canceled,
     * as canceling the job would have closed it. A job that is still
     * pending_approval, but whose workflow step or run has ended, or whose
     * job template was deleted, is canceled in the same transaction:
     * approving it would queue the playbook of a canceled run, or a job no
     * runner takes. The reason is ApprovalJobState::closeReason().
     */
    private function close(ApprovalRequest $request, ApprovalJobState $state, int $now): ApprovalOutcome
    {
        ApprovalRequest::updateAll(
            ['status' => ApprovalRequest::STATUS_CANCELED, 'resolved_at' => $now],
            ['id' => $request->id, 'status' => ApprovalRequest::STATUS_PENDING]
        );
        $request->status = ApprovalRequest::STATUS_CANCELED;
        $request->resolved_at = $now;
        $jobCanceled = $state->cancelJob($now);

        /** @var ApprovalRule|null $rule */
        $rule = $request->approvalRule;

        return new ApprovalOutcome(
            $request,
            $state->job,
            $rule,
            ApprovalRequest::STATUS_CANCELED,
            $jobCanceled ? Job::STATUS_CANCELED : null,
            $state->step,
            null,
            [],
            $state->closeReason()
        );
    }

    private function lockRequest(int $requestId): ?ApprovalRequest
    {
        $request = ApprovalRequest::findBySql(self::REQUEST_LOCK_SQL, [':id' => $requestId])->one();

        return $request instanceof ApprovalRequest ? $request : null;
    }

    /**
     * The request's job, locked; it also becomes the request's job relation,
     * so the checks read the locked row.
     */
    private function lockJob(ApprovalRequest $request): ?Job
    {
        $job = Job::findBySql(self::JOB_LOCK_SQL, [':id' => $request->job_id])->one();
        $job = $job instanceof Job ? $job : null;
        $request->populateRelation('job', $job);

        return $job;
    }

    private function eligibility(): ApproverEligibility
    {
        /** @var ApproverEligibility $eligibility */
        $eligibility = \Yii::$app->get('approverEligibility');

        return $eligibility;
    }
}
