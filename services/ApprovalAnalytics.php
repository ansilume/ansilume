<?php

declare(strict_types=1);

namespace app\services;

use app\components\Percent;
use yii\db\Connection;

/**
 * The approval statistics of the analytics reports
 * (AnalyticsService::approvalSummary()): requests by status, the approval
 * rate, and the average time to a decision.
 *
 * Canceled requests (their job or workflow run was canceled, or their
 * template deleted, before anybody decided) are counted on their own and
 * left out of the average decision time: nobody decided them. Timed-out
 * requests count in it with the time until they expired (expires_at), not
 * until the timeout was applied: a backlog applied late (the first
 * maintenance run after an update, an outage of the schedule runner) does
 * not add its delay. A timed-out request without expires_at (left by older
 * versions) counts until it was resolved.
 */
final class ApprovalAnalytics
{
    /** The note of the Avg Decision Time card on the analytics page's Approvals tab. */
    public const AVG_DECISION_NOTE = 'Canceled requests are not counted.';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param list<string> $conditions SQL conditions on approval_request, all of which must hold
     * @param array<string, mixed> $params their bound values
     * @return array{total: int, approved: int, rejected: int, timed_out: int, canceled: int, pending: int, approval_rate: float, avg_decision_seconds: float}
     */
    public function summary(array $conditions, array $params): array
    {
        $row = $this->db->createCommand(
            'SELECT COUNT(*) AS total,'
            . ' SUM(CASE WHEN status = :approved THEN 1 ELSE 0 END) AS approved,'
            . ' SUM(CASE WHEN status = :rejected THEN 1 ELSE 0 END) AS rejected,'
            . ' SUM(CASE WHEN status = :timed_out THEN 1 ELSE 0 END) AS timed_out,'
            . ' SUM(CASE WHEN status = :canceled THEN 1 ELSE 0 END) AS canceled,'
            . ' SUM(CASE WHEN status = :pending THEN 1 ELSE 0 END) AS pending,'
            . ' AVG(CASE WHEN status = :avg_timed_out AND resolved_at IS NOT NULL AND expires_at IS NOT NULL'
            . '   THEN LEAST(resolved_at, expires_at) - requested_at'
            . '   WHEN resolved_at IS NOT NULL AND status <> :not_decided'
            . '   THEN resolved_at - requested_at ELSE NULL END) AS avg_decision'
            . ' FROM {{%approval_request}}'
            . ' WHERE ' . implode(' AND ', $conditions),
            array_merge($params, [
                ':approved' => 'approved',
                ':rejected' => 'rejected',
                ':timed_out' => 'timed_out',
                ':canceled' => 'canceled',
                ':pending' => 'pending',
                ':avg_timed_out' => 'timed_out',
                ':not_decided' => 'canceled',
            ])
        )->queryOne();

        $approved = (int)($row['approved'] ?? 0);
        $rejected = (int)($row['rejected'] ?? 0);
        $decided = $approved + $rejected;

        return [
            'total' => (int)($row['total'] ?? 0),
            'approved' => $approved,
            'rejected' => $rejected,
            'timed_out' => (int)($row['timed_out'] ?? 0),
            'canceled' => (int)($row['canceled'] ?? 0),
            'pending' => (int)($row['pending'] ?? 0),
            'approval_rate' => Percent::rate($approved, $decided),
            'avg_decision_seconds' => round((float)($row['avg_decision'] ?? 0), 1),
        ];
    }
}
