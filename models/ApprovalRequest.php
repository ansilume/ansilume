<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\db\Query;

/**
 * @property int         $id
 * @property int         $job_id
 * @property int|null    $approval_rule_id  null once its rule was deleted (resolved requests keep their history)
 * @property string      $status
 * @property int|null    $requested_at
 * @property int|null    $resolved_at
 * @property int|null    $expires_at
 *
 * @property Job $job
 * @property ApprovalRule|null $approvalRule
 * @property ApprovalDecision[] $decisions
 */
class ApprovalRequest extends ActiveRecord
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_TIMED_OUT = 'timed_out';
    /** Closed without a decision: its job or workflow run was canceled, or its template deleted. */
    public const STATUS_CANCELED = 'canceled';

    public static function tableName(): string
    {
        return '{{%approval_request}}';
    }

    public function rules(): array
    {
        return [
            [['job_id', 'approval_rule_id'], 'required'],
            [['job_id', 'approval_rule_id'], 'integer'],
            [['status'], 'in', 'range' => [
                self::STATUS_PENDING,
                self::STATUS_APPROVED,
                self::STATUS_REJECTED,
                self::STATUS_TIMED_OUT,
                self::STATUS_CANCELED,
            ]],
            [['requested_at', 'resolved_at', 'expires_at'], 'integer'],
        ];
    }

    /**
     * @return string[]
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_TIMED_OUT,
            self::STATUS_CANCELED,
        ];
    }

    public function isResolved(): bool
    {
        return $this->status !== self::STATUS_PENDING;
    }

    /**
     * Whether the request's time ran out at $now: expires_at has passed.
     * Votes are refused from then on, and the timeout applies its rule's
     * timeout action (the same comparison on both paths).
     */
    public function hasExpired(int $now): bool
    {
        return $this->expires_at !== null && (int)$this->expires_at <= $now;
    }

    /**
     * Whether user #$userId has voted on this request.
     */
    public function hasDecisionBy(int $userId): bool
    {
        return ApprovalDecision::find()
            ->where(['approval_request_id' => $this->id, 'user_id' => $userId])
            ->exists();
    }

    /**
     * The user who launched the job that waits for this request: for a
     * workflow approval step, the user who started the run. That user never
     * decides on the request. 0 when the job is unknown (an unsaved request).
     */
    public function launcherId(): int
    {
        /** @var Job|null $job */
        $job = $this->job;

        return $job === null ? 0 : (int)$job->launched_by;
    }

    /**
     * Whether $decision was cast by the user who launched the job. Such a
     * decision, recorded before self-approval was refused, stays in the
     * history but counts neither as an approval nor as a rejection.
     */
    public function isLauncherDecision(ApprovalDecision $decision): bool
    {
        return (int)$decision->user_id === $this->launcherId();
    }

    /**
     * Approvals that count toward the rule's threshold: every approval except
     * the launcher's own.
     */
    public function approvalCount(): int
    {
        return $this->countedDecisions(ApprovalDecision::DECISION_APPROVED);
    }

    /**
     * Rejections that count: every rejection except the launcher's own.
     */
    public function rejectionCount(): int
    {
        return $this->countedDecisions(ApprovalDecision::DECISION_REJECTED);
    }

    public function getJob(): ActiveQuery
    {
        return $this->hasOne(Job::class, ['id' => 'job_id']);
    }

    public function getApprovalRule(): ActiveQuery
    {
        return $this->hasOne(ApprovalRule::class, ['id' => 'approval_rule_id']);
    }

    /**
     * The votes on this request in the order cast, as the API answers them
     * (runstate\ApprovalVotes): without an order, MariaDB reads them through
     * the unique index (approval_request_id, user_id), by user ID.
     */
    public function getDecisions(): ActiveQuery
    {
        return $this->hasMany(ApprovalDecision::class, ['approval_request_id' => 'id'])
            ->orderBy(['id' => SORT_ASC]);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_TIMED_OUT => 'Timed Out',
            self::STATUS_CANCELED => 'Canceled',
            default => $status,
        };
    }

    public static function statusCssClass(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'warning',
            self::STATUS_APPROVED => 'success',
            self::STATUS_REJECTED => 'danger',
            self::STATUS_TIMED_OUT => 'secondary',
            // Not "dark": the layout's dark theme draws a dark badge on a background of the same colour.
            self::STATUS_CANCELED => 'secondary',
            default => 'secondary',
        };
    }

    /**
     * The approvals and rejections that count on each request of
     * $requestIds, without the votes of the user who launched its job (as
     * approvalCount() and rejectionCount() count): one query for all of
     * them. Every given ID is a key, with zeros for a request without votes
     * (or one that does not exist).
     *
     * @param list<int> $requestIds
     * @return array<int, array{approved: int, rejected: int}>
     */
    public static function countedDecisionsOf(array $requestIds): array
    {
        $counts = [];
        foreach ($requestIds as $requestId) {
            $counts[(int)$requestId] = ['approved' => 0, 'rejected' => 0];
        }
        if ($counts === []) {
            return [];
        }
        /** @var list<array{request_id: int|string, decision: string, votes: int|string}> $rows */
        $rows = (new Query())
            ->select(['request_id' => 'd.approval_request_id', 'decision' => 'd.decision', 'votes' => 'COUNT(*)'])
            ->from(['d' => ApprovalDecision::tableName()])
            ->innerJoin(['r' => self::tableName()], '[[r.id]] = [[d.approval_request_id]]')
            ->leftJoin(['j' => Job::tableName()], '[[j.id]] = [[r.job_id]]')
            ->where([
                'd.approval_request_id' => array_keys($counts),
                // Compared as approvalCount() compares them, in the column's collation.
                'd.decision' => [ApprovalDecision::DECISION_APPROVED, ApprovalDecision::DECISION_REJECTED],
            ])
            // The launcher's votes never count; a request whose job is gone has no launcher.
            ->andWhere(['or', ['j.launched_by' => null], '[[d.user_id]] <> [[j.launched_by]]'])
            ->groupBy(['d.approval_request_id', 'd.decision'])
            ->all();
        foreach ($rows as $row) {
            $kind = strtolower($row['decision']) === ApprovalDecision::DECISION_APPROVED ? 'approved' : 'rejected';
            $counts[(int)$row['request_id']][$kind] += (int)$row['votes'];
        }

        return $counts;
    }

    /**
     * Decisions of one kind, without the launcher's: one query, the launcher
     * is read in a subquery on the job.
     */
    private function countedDecisions(string $decision): int
    {
        $launcher = (new Query())
            ->select('launched_by')
            ->from(Job::tableName())
            ->where(['id' => $this->job_id]);

        return (int)ApprovalDecision::find()
            ->where(['approval_request_id' => $this->id, 'decision' => $decision])
            ->andWhere(['not in', 'user_id', $launcher])
            ->count();
    }
}
