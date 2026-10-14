<?php

declare(strict_types=1);

namespace app\services\runstate;

use app\helpers\TimeHelper;
use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\services\ApprovalJobState;
use app\services\ApproverEligibility;
use app\services\WorkflowAccessChecker;
use yii\base\Component;
use yii\db\Query;

/**
 * The approval requests of jobs, summarised for a viewer (ApprovalWait):
 * which rule they wait for, how many approvals count, when they expire and,
 * for a request that was decided, how.
 *
 * Only requests the viewer may see are summarised (team scoping,
 * WorkflowAccessChecker::buildApprovalRequestFilter()); a viewer who may see
 * one but not open approval pages (approval.view) gets it unlinkable and
 * without names. Every list is summarised in a constant number of queries.
 * The eligibility of the approvers, which costs a query per check, is added
 * only for one pending request (withEligibility()).
 */
final class ApprovalWaits extends Component
{
    /**
     * The approval requests of $jobIds that the viewer may see, keyed by job
     * ID (a job has at most one); jobs without a request, or with one the
     * viewer may not see, are left out. With $withDecisions, each decided
     * request carries its decision note (Texts::DECIDED_*): who approved or
     * rejected, by name, only for a viewer with approval.view.
     *
     * @param list<int> $jobIds
     * @return array<int, ApprovalWait>
     */
    public function forJobs(array $jobIds, Viewer $viewer, bool $withDecisions = false): array
    {
        $ids = array_filter(array_map('intval', $jobIds), static fn (int $id): bool => $id > 0);
        $rows = $ids === [] ? [] : $this->requestRows(array_values(array_unique($ids)), $viewer);
        if ($rows === []) {
            return [];
        }
        $counts = ApprovalRequest::countedDecisionsOf(array_map(static fn (array $row): int => (int)$row['id'], $rows));
        $notes = $withDecisions ? $this->decisionNotes($rows, $viewer) : [];
        $linkable = $viewer->may('approval.view');
        $waits = [];
        foreach ($rows as $row) {
            $requestId = (int)$row['id'];
            $waits[(int)$row['job_id']] = self::wait($row, $counts[$requestId], $linkable, $notes[$requestId] ?? null);
        }

        return $waits;
    }

    /**
     * $wait with the eligibility of its approvers: how many may decide
     * (ApproverEligibility::eligibleForRequest()), how many of them have not
     * voted yet, and whether the approvals that count plus those open votes
     * still reach the rule's threshold, as a vote settles it
     * (ApprovalSettlement). With $personal also the viewer's relation to the
     * request (Display::RELATION_*), from the same eligibility: it is
     * computed once. A request that is no longer pending, or no longer
     * exists, is returned as it is.
     */
    public function withEligibility(ApprovalWait $wait, Viewer $viewer, bool $personal): ApprovalWait
    {
        /** @var ApprovalRequest|null $request */
        $request = $wait->status === ApprovalRequest::STATUS_PENDING ? ApprovalRequest::findOne($wait->requestId) : null;
        if ($request === null) {
            return $wait;
        }
        $eligible = $this->eligibility()->eligibleForRequest($request);
        /** @var list<int> $voters */
        $voters = array_map('intval', ApprovalDecision::find()
            ->select('user_id')
            ->where(['approval_request_id' => $request->id])
            ->column());
        $open = count(array_diff($eligible, $voters));
        $required = $wait->requiredApprovals;

        return $wait->withEligibility(
            count($eligible),
            $open,
            $required !== null && $wait->approvalCount + $open >= $required,
            $personal ? $this->relationOf($request, $viewer, $eligible, $voters) : null
        );
    }

    /**
     * @param array<string, mixed> $row a row of requestRows()
     * @param array{approved: int, rejected: int} $counts
     */
    private static function wait(array $row, array $counts, bool $linkable, ?string $decisionNote): ApprovalWait
    {
        // The rule's columns are null once the rule was deleted.
        $hasRule = $row['rule_id'] !== null;

        return new ApprovalWait(
            requestId: (int)$row['id'],
            jobId: (int)$row['job_id'],
            status: (string)$row['status'],
            ruleId: $hasRule ? (int)$row['rule_id'] : null,
            ruleName: $hasRule ? (string)$row['rule_name'] : null,
            requiredApprovals: $hasRule ? (int)$row['required_approvals'] : null,
            approvalCount: $counts['approved'],
            rejectionCount: $counts['rejected'],
            requestedAt: self::intOrNull($row['requested_at']),
            expiresAt: self::intOrNull($row['expires_at']),
            resolvedAt: self::intOrNull($row['resolved_at']),
            timeoutAction: self::timeoutAction($row),
            linkable: $linkable,
            decisionNote: $decisionNote,
        );
    }

    /**
     * The requests of $jobIds the viewer may see, with their rule and their
     * job's status and launcher.
     *
     * @param list<int> $jobIds
     * @return list<array<string, mixed>>
     */
    private function requestRows(array $jobIds, Viewer $viewer): array
    {
        $query = (new Query())
            ->select([
                'id' => 'ar.id',
                'job_id' => 'ar.job_id',
                'status' => 'ar.status',
                'requested_at' => 'ar.requested_at',
                'expires_at' => 'ar.expires_at',
                'resolved_at' => 'ar.resolved_at',
                'rule_id' => 'ar_rule.id',
                'rule_name' => 'ar_rule.name',
                'required_approvals' => 'ar_rule.required_approvals',
                'timeout_action' => 'ar_rule.timeout_action',
                'job_status' => 'ar_job.status',
                'launched_by' => 'ar_job.launched_by',
            ])
            ->from(['ar' => ApprovalRequest::tableName()])
            ->leftJoin(['ar_rule' => ApprovalRule::tableName()], '[[ar_rule.id]] = [[ar.approval_rule_id]]')
            ->leftJoin(['ar_job' => Job::tableName()], '[[ar_job.id]] = [[ar.job_id]]')
            ->where(['ar.job_id' => $jobIds])
            ->orderBy(['ar.id' => SORT_ASC]);
        // Nobody (user ID 0) sees no request: a null user is denied everything.
        $filter = $this->access()->buildApprovalRequestFilter($viewer->userId > 0 ? $viewer->userId : null, 'ar.job_id');
        if ($filter !== null) {
            $query->andWhere($filter);
        }

        return array_values($query->all());
    }

    /**
     * The decision note of each decided request of $rows, keyed by request
     * ID: approved by votes (with the approvers' names and the last comment
     * one of them gave, both only with approval.view), rejected (with the
     * names, only with approval.view), or approved by its timeout. A request
     * that its timeout rejected, or that was canceled, has none.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function decisionNotes(array $rows, Viewer $viewer): array
    {
        $votes = $viewer->may('approval.view') ? $this->countedVotes($rows) : [];
        $notes = [];
        foreach ($rows as $row) {
            $requestId = (int)$row['id'];
            $note = self::decisionNote($row, $votes[$requestId] ?? []);
            if ($note !== null) {
                $notes[$requestId] = $note;
            }
        }

        return $notes;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array{decision: string, username: string, comment: string}> $votes the counted votes, oldest first
     */
    private static function decisionNote(array $row, array $votes): ?string
    {
        $resolvedAt = self::intOrNull($row['resolved_at']);
        if ($resolvedAt === null) {
            return null;
        }
        $at = TimeHelper::stamp($resolvedAt);

        return match ((string)$row['status']) {
            ApprovalRequest::STATUS_APPROVED => self::approvedNote($votes, $at),
            ApprovalRequest::STATUS_REJECTED => self::rejectedNote($votes, $at),
            ApprovalRequest::STATUS_TIMED_OUT => $row['job_status'] === Job::STATUS_REJECTED
                ? null
                : sprintf(Texts::DECIDED_TIMEOUT_APPROVED, $at),
            default => null,
        };
    }

    /**
     * @param list<array{decision: string, username: string, comment: string}> $votes
     */
    private static function approvedNote(array $votes, string $at): string
    {
        $approvals = array_values(array_filter(
            $votes,
            static fn (array $vote): bool => $vote['decision'] === ApprovalDecision::DECISION_APPROVED
        ));
        if ($approvals === []) {
            return sprintf(Texts::DECIDED_APPROVED, $at);
        }
        $note = sprintf(Texts::DECIDED_APPROVED_BY, implode(', ', array_column($approvals, 'username')), $at);
        $comments = array_values(array_filter(array_column($approvals, 'comment'), static fn (string $c): bool => $c !== ''));

        return $comments === [] ? $note : $note . ' ' . sprintf(Texts::DECIDED_COMMENT, $comments[count($comments) - 1]);
    }

    /**
     * @param list<array{decision: string, username: string, comment: string}> $votes
     */
    private static function rejectedNote(array $votes, string $at): ?string
    {
        $names = [];
        foreach ($votes as $vote) {
            if ($vote['decision'] === ApprovalDecision::DECISION_REJECTED) {
                $names[] = $vote['username'];
            }
        }

        return $names === [] ? null : sprintf(Texts::DECIDED_REJECTED_BY, implode(', ', $names), $at);
    }

    /**
     * The votes that count on the approved and rejected requests of $rows,
     * oldest first, keyed by request ID: every vote but the launcher's
     * (ApprovalVotes, which lists them all).
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, list<array{decision: string, username: string, comment: string}>>
     */
    private function countedVotes(array $rows): array
    {
        $launchers = [];
        foreach ($rows as $row) {
            if (in_array($row['status'], [ApprovalRequest::STATUS_APPROVED, ApprovalRequest::STATUS_REJECTED], true)) {
                $launchers[(int)$row['id']] = (int)$row['launched_by'];
            }
        }
        $votes = [];
        foreach (ApprovalVotes::of(array_keys($launchers)) as $requestId => $requestVotes) {
            foreach ($requestVotes as $vote) {
                if ($vote['user_id'] !== $launchers[$requestId]) {
                    $votes[$requestId][] = [
                        'decision' => $vote['decision'],
                        'username' => $vote['username'],
                        'comment' => trim((string)$vote['comment']),
                    ];
                }
            }
        }

        return $votes;
    }

    /**
     * The viewer's relation to the pending $request: its launcher (also
     * without approval.view), else, with approval.view and while the
     * request takes votes, whether they may vote now, have voted, are on the
     * rule's list but may not decide, or are not on it. Null when none
     * applies (no approval.view, the request has expired or its job no
     * longer waits) and for nobody.
     *
     * The answer a vote of theirs would get now
     * (ApprovalService::decisionRefusal(), ApprovalSettlement::check(): the
     * request's state, the launcher, eligibility, an earlier vote), read
     * from the eligible approvers and the voters that withEligibility()
     * read, so that the eligibility is not computed a second time for the
     * viewer: they may decide exactly when they are among $eligible
     * (ApproverEligibility::mayDecide() and eligibleForRequest() agree, user
     * by user).
     *
     * @param list<int> $eligible ApproverEligibility::eligibleForRequest() of $request
     * @param list<int> $voters the users who voted on $request
     */
    private function relationOf(ApprovalRequest $request, Viewer $viewer, array $eligible, array $voters): ?string
    {
        $userId = $viewer->userId;
        if ($userId <= 0) {
            return null;
        }
        if ($this->eligibility()->isLauncher($request, $userId)) {
            return Display::RELATION_LAUNCHER;
        }
        if (!$viewer->may('approval.view') || !self::takesVotes($request)) {
            return null;
        }
        if (!in_array($userId, $eligible, true)) {
            return $this->isOnApproverList($request, $userId) ? Display::RELATION_NOT_ELIGIBLE : Display::RELATION_NOT_APPROVER;
        }

        return in_array($userId, $voters, true) ? Display::RELATION_VOTED : Display::RELATION_MAY_DECIDE;
    }

    /**
     * Whether $request takes votes now, as ApprovalSettlement::check() finds
     * it before it looks at the voter: it is pending, has not expired, and
     * its job still waits for it (ApprovalJobState).
     */
    private static function takesVotes(ApprovalRequest $request): bool
    {
        /** @var Job|null $job */
        $job = $request->job;

        return !$request->isResolved() && !$request->hasExpired(time()) && ApprovalJobState::read($job)->waits();
    }

    private function isOnApproverList(ApprovalRequest $request, int $userId): bool
    {
        /** @var ApprovalRule|null $rule */
        $rule = $request->approvalRule;

        return $rule !== null && in_array($userId, $rule->getApproverUserIds(), true);
    }

    /**
     * What the request's timeout does: null without a timeout or a rule,
     * else the rule's action, rejecting unless it approves (as the timeout
     * applies it, ApprovalSettlement::timeOut()).
     *
     * @param array<string, mixed> $row
     */
    private static function timeoutAction(array $row): ?string
    {
        if ($row['expires_at'] === null || $row['rule_id'] === null) {
            return null;
        }

        return $row['timeout_action'] === ApprovalRule::TIMEOUT_ACTION_APPROVE
            ? ApprovalRule::TIMEOUT_ACTION_APPROVE
            : ApprovalRule::TIMEOUT_ACTION_REJECT;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int)$value;
    }

    private function eligibility(): ApproverEligibility
    {
        /** @var ApproverEligibility $eligibility */
        $eligibility = \Yii::$app->get('approverEligibility');

        return $eligibility;
    }

    private function access(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $access */
        $access = \Yii::$app->get('workflowAccessChecker');

        return $access;
    }
}
