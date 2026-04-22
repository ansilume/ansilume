<?php

declare(strict_types=1);

namespace app\services;

use app\models\AnalyticsQuery;
use yii\base\Component;
use yii\db\Connection;

/**
 * Provides analytics and reporting queries over existing job data.
 *
 * All methods accept an AnalyticsQuery that has already been validated
 * and had defaults applied. Results are returned as plain arrays suitable
 * for JSON serialization or CSV export.
 */
class AnalyticsService extends Component
{
    /**
     * High-level summary: total jobs, success rate, avg duration,
     * avg failure-run duration, and MTTR (mean time to recovery).
     *
     * The two duration fields answer different questions:
     *   - avg_failure_duration_seconds: on average, how long did a
     *     FAILED job run before it finally gave up? (Fast syntax
     *     errors → low value. Slow post-play failures → high value.)
     *     This was labelled "MTTR" before v2.3.2; it never was MTTR.
     *   - mttr_seconds: mean time from a failure finishing until the
     *     next successful run on the SAME template — the DORA-style
     *     recovery time. Failures with no subsequent success in the
     *     window are excluded from the average.
     *
     * @return array{total_jobs: int, succeeded: int, failed: int, success_rate: float, avg_duration_seconds: float, avg_failure_duration_seconds: float, mttr_seconds: float}
     */
    public function summary(AnalyticsQuery $query): array
    {
        $db = $this->getDb();
        $where = $this->buildWhere($query);

        $row = $db->createCommand(
            'SELECT'
            . ' COUNT(*) AS total_jobs,'
            . ' SUM(CASE WHEN status = :succeeded THEN 1 ELSE 0 END) AS succeeded,'
            . ' SUM(CASE WHEN status IN (:failed, :error, :timed_out) THEN 1 ELSE 0 END) AS failed,'
            . ' AVG(CASE WHEN finished_at IS NOT NULL AND started_at IS NOT NULL'
            . '   THEN finished_at - started_at ELSE NULL END) AS avg_duration,'
            . ' AVG(CASE WHEN status IN (:failed2, :error2, :timed_out2)'
            . '   AND finished_at IS NOT NULL AND started_at IS NOT NULL'
            . '   THEN finished_at - started_at ELSE NULL END) AS avg_failure_duration'
            . ' FROM {{%job}}'
            . ' WHERE ' . $where['sql'],
            array_merge($where['params'], [
                ':succeeded' => 'succeeded',
                ':failed' => 'failed',
                ':error' => 'error',
                ':timed_out' => 'timed_out',
                ':failed2' => 'failed',
                ':error2' => 'error',
                ':timed_out2' => 'timed_out',
            ])
        )->queryOne();

        $total = (int)($row['total_jobs'] ?? 0);
        $succeeded = (int)($row['succeeded'] ?? 0);

        return [
            'total_jobs' => $total,
            'succeeded' => $succeeded,
            'failed' => (int)($row['failed'] ?? 0),
            'success_rate' => $total > 0 ? round($succeeded / $total * 100, 2) : 0.0,
            'avg_duration_seconds' => round((float)($row['avg_duration'] ?? 0), 1),
            'avg_failure_duration_seconds' => round((float)($row['avg_failure_duration'] ?? 0), 1),
            'mttr_seconds' => $this->computeMttr($query),
        ];
    }

    /**
     * Mean Time To Recovery: for every failed job in the window, find
     * the next succeeded job for the same template and take the delta
     * between the failure's finished_at and the recovery's started_at.
     * Average those deltas. Failures that never recovered within the
     * observable data are excluded (they can't contribute a finite value).
     *
     * Returned in seconds, rounded to one decimal. Zero when no failure
     * in the window has a subsequent success.
     */
    private function computeMttr(AnalyticsQuery $query): float
    {
        $db = $this->getDb();
        $where = $this->buildWhere($query, 'f');

        $row = $db->createCommand(
            'SELECT AVG(recovery_seconds) AS mttr FROM ('
            . '  SELECT'
            . '    (SELECT MIN(s.started_at) FROM {{%job}} s'
            . '       WHERE s.job_template_id = f.job_template_id'
            . '         AND s.status = :succ'
            . '         AND s.started_at > f.finished_at'
            . '    ) - f.finished_at AS recovery_seconds'
            . '  FROM {{%job}} f'
            . '  WHERE f.status IN (:failed, :error, :timed_out)'
            . '    AND f.finished_at IS NOT NULL'
            . '    AND f.job_template_id IS NOT NULL'
            . '    AND ' . $where['sql']
            . ') AS r'
            . ' WHERE r.recovery_seconds IS NOT NULL',
            array_merge($where['params'], [
                ':succ' => 'succeeded',
                ':failed' => 'failed',
                ':error' => 'error',
                ':timed_out' => 'timed_out',
            ])
        )->queryOne();

        return round((float)($row['mttr'] ?? 0), 1);
    }

    /**
     * Per-template reliability: success rate, avg duration, job count.
     *
     * @return array<int, array{template_id: int, template_name: string, total: int, succeeded: int, failed: int, success_rate: float, avg_duration_seconds: float}>
     */
    public function templateReliability(AnalyticsQuery $query): array
    {
        $db = $this->getDb();
        $where = $this->buildWhere($query, 'j');

        $rows = $db->createCommand(
            'SELECT j.job_template_id AS template_id,'
            . ' jt.name AS template_name,'
            . ' COUNT(*) AS total,'
            . ' SUM(CASE WHEN j.status = :succeeded THEN 1 ELSE 0 END) AS succeeded,'
            . ' SUM(CASE WHEN j.status IN (:failed, :error, :timed_out) THEN 1 ELSE 0 END) AS failed,'
            . ' AVG(CASE WHEN j.finished_at IS NOT NULL AND j.started_at IS NOT NULL'
            . '   THEN j.finished_at - j.started_at ELSE NULL END) AS avg_duration'
            . ' FROM {{%job}} j'
            . ' INNER JOIN {{%job_template}} jt ON jt.id = j.job_template_id'
            . ' WHERE ' . $where['sql']
            . ' GROUP BY j.job_template_id, jt.name'
            . ' ORDER BY total DESC',
            array_merge($where['params'], [
                ':succeeded' => 'succeeded',
                ':failed' => 'failed',
                ':error' => 'error',
                ':timed_out' => 'timed_out',
            ])
        )->queryAll();

        return array_map(function (array $r): array {
            $total = (int)$r['total'];
            $succeeded = (int)$r['succeeded'];
            return [
                'template_id' => (int)$r['template_id'],
                'template_name' => (string)$r['template_name'],
                'total' => $total,
                'succeeded' => $succeeded,
                'failed' => (int)$r['failed'],
                'success_rate' => $total > 0 ? round($succeeded / $total * 100, 2) : 0.0,
                'avg_duration_seconds' => round((float)($r['avg_duration'] ?? 0), 1),
            ];
        }, $rows);
    }

    /**
     * Per-project activity: job counts grouped by project.
     *
     * @return array<int, array{project_id: int, project_name: string, total: int, succeeded: int, failed: int}>
     */
    public function projectActivity(AnalyticsQuery $query): array
    {
        $db = $this->getDb();
        $where = $this->buildWhere($query, 'j');

        $rows = $db->createCommand(
            'SELECT p.id AS project_id,'
            . ' p.name AS project_name,'
            . ' COUNT(*) AS total,'
            . ' SUM(CASE WHEN j.status = :succeeded THEN 1 ELSE 0 END) AS succeeded,'
            . ' SUM(CASE WHEN j.status IN (:failed, :error, :timed_out) THEN 1 ELSE 0 END) AS failed'
            . ' FROM {{%job}} j'
            . ' INNER JOIN {{%job_template}} jt ON jt.id = j.job_template_id'
            . ' INNER JOIN {{%project}} p ON p.id = jt.project_id'
            . ' WHERE ' . $where['sql']
            . ' GROUP BY p.id, p.name'
            . ' ORDER BY total DESC',
            array_merge($where['params'], [
                ':succeeded' => 'succeeded',
                ':failed' => 'failed',
                ':error' => 'error',
                ':timed_out' => 'timed_out',
            ])
        )->queryAll();

        return array_map(function (array $r): array {
            return [
                'project_id' => (int)$r['project_id'],
                'project_name' => (string)$r['project_name'],
                'total' => (int)$r['total'],
                'succeeded' => (int)$r['succeeded'],
                'failed' => (int)$r['failed'],
            ];
        }, $rows);
    }

    /**
     * Per-user activity: launch counts and success rates.
     *
     * @return array<int, array{user_id: int, username: string, total: int, succeeded: int, failed: int, success_rate: float}>
     */
    public function userActivity(AnalyticsQuery $query): array
    {
        $db = $this->getDb();
        $where = $this->buildWhere($query, 'j');

        $rows = $db->createCommand(
            'SELECT j.launched_by AS user_id,'
            . ' u.username,'
            . ' COUNT(*) AS total,'
            . ' SUM(CASE WHEN j.status = :succeeded THEN 1 ELSE 0 END) AS succeeded,'
            . ' SUM(CASE WHEN j.status IN (:failed, :error, :timed_out) THEN 1 ELSE 0 END) AS failed'
            . ' FROM {{%job}} j'
            . ' INNER JOIN {{%user}} u ON u.id = j.launched_by'
            . ' WHERE ' . $where['sql']
            . ' GROUP BY j.launched_by, u.username'
            . ' ORDER BY total DESC',
            array_merge($where['params'], [
                ':succeeded' => 'succeeded',
                ':failed' => 'failed',
                ':error' => 'error',
                ':timed_out' => 'timed_out',
            ])
        )->queryAll();

        return array_map(function (array $r): array {
            $total = (int)$r['total'];
            $succeeded = (int)$r['succeeded'];
            return [
                'user_id' => (int)$r['user_id'],
                'username' => (string)$r['username'],
                'total' => $total,
                'succeeded' => $succeeded,
                'failed' => (int)$r['failed'],
                'success_rate' => $total > 0 ? round($succeeded / $total * 100, 2) : 0.0,
            ];
        }, $rows);
    }

    /**
     * Per-host health: aggregated ok/failed/unreachable from job_host_summary.
     *
     * @return array<int, array{host: string, ok: int, changed: int, failed: int, unreachable: int, skipped: int}>
     */
    public function hostHealth(AnalyticsQuery $query): array
    {
        $db = $this->getDb();
        $where = $this->buildWhere($query, 'j');

        $rows = $db->createCommand(
            'SELECT hs.host,'
            . ' SUM(hs.ok) AS ok,'
            . ' SUM(hs.changed) AS changed,'
            . ' SUM(hs.failed) AS failed,'
            . ' SUM(hs.unreachable) AS unreachable,'
            . ' SUM(hs.skipped) AS skipped'
            . ' FROM {{%job_host_summary}} hs'
            . ' INNER JOIN {{%job}} j ON j.id = hs.job_id'
            . ' WHERE ' . $where['sql']
            . ' GROUP BY hs.host'
            . ' ORDER BY failed DESC, unreachable DESC',
            $where['params']
        )->queryAll();

        return array_map(function (array $r): array {
            return [
                'host' => (string)$r['host'],
                'ok' => (int)$r['ok'],
                'changed' => (int)$r['changed'],
                'failed' => (int)$r['failed'],
                'unreachable' => (int)$r['unreachable'],
                'skipped' => (int)$r['skipped'],
            ];
        }, $rows);
    }

    /**
     * Job trend: daily or weekly counts by status.
     *
     * @return array<int, array{period: string, total: int, succeeded: int, failed: int}>
     */
    public function jobTrend(AnalyticsQuery $query): array
    {
        $db = $this->getDb();
        $where = $this->buildWhere($query, 'j');

        $dateExpr = $query->granularity === AnalyticsQuery::GRANULARITY_WEEKLY
            ? "DATE_FORMAT(FROM_UNIXTIME(j.created_at), '%x-W%v')"
            : "DATE(FROM_UNIXTIME(j.created_at))";

        $rows = $db->createCommand(
            'SELECT ' . $dateExpr . ' AS period,'
            . ' COUNT(*) AS total,'
            . ' SUM(CASE WHEN j.status = :succeeded THEN 1 ELSE 0 END) AS succeeded,'
            . ' SUM(CASE WHEN j.status IN (:failed, :error, :timed_out) THEN 1 ELSE 0 END) AS failed'
            . ' FROM {{%job}} j'
            . ' WHERE ' . $where['sql']
            . ' GROUP BY period'
            . ' ORDER BY period ASC',
            array_merge($where['params'], [
                ':succeeded' => 'succeeded',
                ':failed' => 'failed',
                ':error' => 'error',
                ':timed_out' => 'timed_out',
            ])
        )->queryAll();

        return array_map(function (array $r): array {
            return [
                'period' => (string)$r['period'],
                'total' => (int)$r['total'],
                'succeeded' => (int)$r['succeeded'],
                'failed' => (int)$r['failed'],
            ];
        }, $rows);
    }

    /**
     * Workflow execution summary: totals, success rate, avg duration.
     *
     * Workflows are not subject to project/template filters — only the
     * date range applies, since workflows span multiple templates.
     *
     * @return array{total: int, succeeded: int, failed: int, canceled: int, running: int, success_rate: float, avg_duration_seconds: float}
     */
    public function workflowSummary(AnalyticsQuery $query): array
    {
        $db = $this->getDb();
        $params = [];
        $conditions = ['1=1'];
        if ($query->date_from !== null) {
            $conditions[] = 'created_at >= :date_from';
            $params[':date_from'] = $query->dateFromTimestamp;
        }
        if ($query->date_to !== null) {
            $conditions[] = 'created_at <= :date_to';
            $params[':date_to'] = $query->dateToTimestamp;
        }
        if ($query->user_id !== null) {
            $conditions[] = 'launched_by = :user_id';
            $params[':user_id'] = $query->user_id;
        }

        $row = $db->createCommand(
            'SELECT COUNT(*) AS total,'
            . ' SUM(CASE WHEN status = :succeeded THEN 1 ELSE 0 END) AS succeeded,'
            . ' SUM(CASE WHEN status = :failed THEN 1 ELSE 0 END) AS failed,'
            . ' SUM(CASE WHEN status = :canceled THEN 1 ELSE 0 END) AS canceled,'
            . ' SUM(CASE WHEN status = :running THEN 1 ELSE 0 END) AS running,'
            . ' AVG(CASE WHEN finished_at IS NOT NULL AND started_at IS NOT NULL'
            . '   THEN finished_at - started_at ELSE NULL END) AS avg_duration'
            . ' FROM {{%workflow_job}}'
            . ' WHERE ' . implode(' AND ', $conditions),
            array_merge($params, [
                ':succeeded' => 'succeeded',
                ':failed' => 'failed',
                ':canceled' => 'canceled',
                ':running' => 'running',
            ])
        )->queryOne();

        $total = (int)($row['total'] ?? 0);
        $finished = $total - (int)($row['running'] ?? 0);
        $succeeded = (int)($row['succeeded'] ?? 0);

        return [
            'total' => $total,
            'succeeded' => $succeeded,
            'failed' => (int)($row['failed'] ?? 0),
            'canceled' => (int)($row['canceled'] ?? 0),
            'running' => (int)($row['running'] ?? 0),
            'success_rate' => $finished > 0 ? round($succeeded / $finished * 100, 2) : 0.0,
            'avg_duration_seconds' => round((float)($row['avg_duration'] ?? 0), 1),
        ];
    }

    /**
     * Per-workflow-template reliability.
     *
     * @return array<int, array{template_id: int, template_name: string, total: int, succeeded: int, failed: int, success_rate: float, avg_duration_seconds: float}>
     */
    public function workflowActivity(AnalyticsQuery $query): array
    {
        $db = $this->getDb();
        $params = [];
        $conditions = ['1=1'];
        if ($query->date_from !== null) {
            $conditions[] = 'wj.created_at >= :date_from';
            $params[':date_from'] = $query->dateFromTimestamp;
        }
        if ($query->date_to !== null) {
            $conditions[] = 'wj.created_at <= :date_to';
            $params[':date_to'] = $query->dateToTimestamp;
        }

        $rows = $db->createCommand(
            'SELECT wj.workflow_template_id AS template_id,'
            . ' wt.name AS template_name,'
            . ' COUNT(*) AS total,'
            . ' SUM(CASE WHEN wj.status = :succeeded THEN 1 ELSE 0 END) AS succeeded,'
            . ' SUM(CASE WHEN wj.status = :failed THEN 1 ELSE 0 END) AS failed,'
            . ' AVG(CASE WHEN wj.finished_at IS NOT NULL AND wj.started_at IS NOT NULL'
            . '   THEN wj.finished_at - wj.started_at ELSE NULL END) AS avg_duration'
            . ' FROM {{%workflow_job}} wj'
            . ' INNER JOIN {{%workflow_template}} wt ON wt.id = wj.workflow_template_id'
            . ' WHERE ' . implode(' AND ', $conditions)
            . ' GROUP BY wj.workflow_template_id, wt.name'
            . ' ORDER BY total DESC',
            array_merge($params, [
                ':succeeded' => 'succeeded',
                ':failed' => 'failed',
            ])
        )->queryAll();

        return array_map(function (array $r): array {
            $total = (int)$r['total'];
            $succeeded = (int)$r['succeeded'];
            $failed = (int)$r['failed'];
            $finished = $succeeded + $failed;
            return [
                'template_id' => (int)$r['template_id'],
                'template_name' => (string)$r['template_name'],
                'total' => $total,
                'succeeded' => $succeeded,
                'failed' => $failed,
                'success_rate' => $finished > 0 ? round($succeeded / $finished * 100, 2) : 0.0,
                'avg_duration_seconds' => round((float)($r['avg_duration'] ?? 0), 1),
            ];
        }, $rows);
    }

    /**
     * Approval request stats: counts by outcome and median time-to-decision.
     *
     * @return array{total: int, approved: int, rejected: int, timed_out: int, pending: int, approval_rate: float, avg_decision_seconds: float}
     */
    public function approvalSummary(AnalyticsQuery $query): array
    {
        $db = $this->getDb();
        $params = [];
        $conditions = ['1=1'];
        if ($query->date_from !== null) {
            $conditions[] = 'requested_at >= :date_from';
            $params[':date_from'] = $query->dateFromTimestamp;
        }
        if ($query->date_to !== null) {
            $conditions[] = 'requested_at <= :date_to';
            $params[':date_to'] = $query->dateToTimestamp;
        }

        $row = $db->createCommand(
            'SELECT COUNT(*) AS total,'
            . ' SUM(CASE WHEN status = :approved THEN 1 ELSE 0 END) AS approved,'
            . ' SUM(CASE WHEN status = :rejected THEN 1 ELSE 0 END) AS rejected,'
            . ' SUM(CASE WHEN status = :timed_out THEN 1 ELSE 0 END) AS timed_out,'
            . ' SUM(CASE WHEN status = :pending THEN 1 ELSE 0 END) AS pending,'
            . ' AVG(CASE WHEN resolved_at IS NOT NULL'
            . '   THEN resolved_at - requested_at ELSE NULL END) AS avg_decision'
            . ' FROM {{%approval_request}}'
            . ' WHERE ' . implode(' AND ', $conditions),
            array_merge($params, [
                ':approved' => 'approved',
                ':rejected' => 'rejected',
                ':timed_out' => 'timed_out',
                ':pending' => 'pending',
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
            'pending' => (int)($row['pending'] ?? 0),
            'approval_rate' => $decided > 0 ? round($approved / $decided * 100, 2) : 0.0,
            'avg_decision_seconds' => round((float)($row['avg_decision'] ?? 0), 1),
        ];
    }

    /**
     * Per-runner activity: jobs executed, success rate, utilization.
     *
     * @return array<int, array{runner_id: int, runner_name: string, runner_group: string, total: int, succeeded: int, failed: int, success_rate: float, avg_duration_seconds: float, last_seen_at: int|null}>
     */
    public function runnerActivity(AnalyticsQuery $query): array
    {
        $db = $this->getDb();
        $where = $this->buildWhere($query, 'j');

        $rows = $db->createCommand(
            'SELECT r.id AS runner_id,'
            . ' r.name AS runner_name,'
            . ' rg.name AS runner_group,'
            . ' r.last_seen_at AS last_seen_at,'
            . ' COUNT(j.id) AS total,'
            . ' SUM(CASE WHEN j.status = :succeeded THEN 1 ELSE 0 END) AS succeeded,'
            . ' SUM(CASE WHEN j.status IN (:failed, :error, :timed_out) THEN 1 ELSE 0 END) AS failed,'
            . ' AVG(CASE WHEN j.finished_at IS NOT NULL AND j.started_at IS NOT NULL'
            . '   THEN j.finished_at - j.started_at ELSE NULL END) AS avg_duration'
            . ' FROM {{%runner}} r'
            . ' LEFT JOIN {{%runner_group}} rg ON rg.id = r.runner_group_id'
            . ' LEFT JOIN {{%job}} j ON j.runner_id = r.id AND (' . $where['sql'] . ')'
            . ' GROUP BY r.id, r.name, rg.name, r.last_seen_at'
            . ' ORDER BY total DESC, r.name ASC',
            array_merge($where['params'], [
                ':succeeded' => 'succeeded',
                ':failed' => 'failed',
                ':error' => 'error',
                ':timed_out' => 'timed_out',
            ])
        )->queryAll();

        return array_map(function (array $r): array {
            $total = (int)$r['total'];
            $succeeded = (int)$r['succeeded'];
            return [
                'runner_id' => (int)$r['runner_id'],
                'runner_name' => (string)$r['runner_name'],
                'runner_group' => (string)($r['runner_group'] ?? ''),
                'total' => $total,
                'succeeded' => $succeeded,
                'failed' => (int)$r['failed'],
                'success_rate' => $total > 0 ? round($succeeded / $total * 100, 2) : 0.0,
                'avg_duration_seconds' => round((float)($r['avg_duration'] ?? 0), 1),
                'last_seen_at' => $r['last_seen_at'] !== null ? (int)$r['last_seen_at'] : null,
            ];
        }, $rows);
    }

    /**
     * Build WHERE clause and params from an AnalyticsQuery.
     *
     * @return array{sql: string, params: array<string, mixed>}
     */
    private function buildWhere(AnalyticsQuery $query, string $jobAlias = ''): array
    {
        $prefix = $jobAlias !== '' ? $jobAlias . '.' : '';
        $conditions = ['1=1'];
        $params = [];

        if ($query->date_from !== null) {
            $conditions[] = $prefix . 'created_at >= :date_from';
            $params[':date_from'] = $query->dateFromTimestamp;
        }
        if ($query->date_to !== null) {
            $conditions[] = $prefix . 'created_at <= :date_to';
            $params[':date_to'] = $query->dateToTimestamp;
        }
        if ($query->project_id !== null) {
            $conditions[] = $prefix . 'job_template_id IN ('
                . 'SELECT id FROM {{%job_template}} WHERE project_id = :project_id)';
            $params[':project_id'] = $query->project_id;
        }
        if ($query->template_id !== null) {
            $conditions[] = $prefix . 'job_template_id = :template_id';
            $params[':template_id'] = $query->template_id;
        }
        if ($query->user_id !== null) {
            $conditions[] = $prefix . 'launched_by = :user_id';
            $params[':user_id'] = $query->user_id;
        }
        if ($query->runner_group_id !== null) {
            $conditions[] = $prefix . 'job_template_id IN ('
                . 'SELECT id FROM {{%job_template}} WHERE runner_group_id = :runner_group_id)';
            $params[':runner_group_id'] = $query->runner_group_id;
        }

        // Exclude jobs that are still in progress
        $conditions[] = $prefix . "status NOT IN ('pending', 'queued', 'running')";

        return [
            'sql' => implode(' AND ', $conditions),
            'params' => $params,
        ];
    }

    private function getDb(): Connection
    {
        /** @var Connection $db */
        $db = \Yii::$app->db;
        return $db;
    }
}
