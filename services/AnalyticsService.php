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
     * High-level summary: total jobs, success rate, avg duration, MTTR.
     *
     * @return array{total_jobs: int, succeeded: int, failed: int, success_rate: float, avg_duration_seconds: float, mttr_seconds: float}
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
            . '   THEN finished_at - started_at ELSE NULL END) AS mttr'
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
            'mttr_seconds' => round((float)($row['mttr'] ?? 0), 1),
        ];
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
