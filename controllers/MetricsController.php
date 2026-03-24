<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\WorkerHeartbeat;
use app\models\Job;
use app\models\JobHostSummary;
use app\models\JobTask;
use app\models\Runner;
use app\models\RunnerGroup;
use yii\web\Controller;
use yii\web\Response;

/**
 * Metrics endpoint for monitoring systems.
 *
 * GET /metrics            → Prometheus OpenMetrics (default)
 * GET /metrics?format=json       → JSON
 * GET /metrics?format=prometheus  → Prometheus OpenMetrics
 *
 * No authentication required — monitoring probes must be able to
 * scrape without credentials. Restrict access via network policy
 * or reverse-proxy rules if needed.
 */
class MetricsController extends Controller
{
    public $enableCsrfValidation = false;

    public function actionIndex(?string $format = null): Response
    {
        $metrics = $this->collect();
        $fmt     = $format ?? $this->getRequestedFormat();

        return match ($fmt) {
            'json'  => $this->formatJson($metrics),
            default => $this->formatPrometheus($metrics),
        };
    }

    protected function getRequestedFormat(): string
    {
        return \Yii::$app->request->get('format', 'prometheus');
    }

    // ── Collectors ──────────────────────────────────────────────────────────

    /**
     * Collect all metrics as a structured array.
     * Public for testability (console app cannot use web response).
     */
    public function collect(): array
    {
        return [
            'health'  => $this->collectHealth(),
            'jobs'    => $this->collectJobs(),
            'tasks'   => $this->collectTasks(),
            'hosts'   => $this->collectHosts(),
            'workers' => $this->collectWorkers(),
            'runners' => $this->collectRunners(),
            'queue'   => $this->collectQueue(),
        ];
    }

    private function collectHealth(): array
    {
        $db    = $this->probeDatabase();
        $redis = $this->probeRedis();

        return [
            'database_up'  => $db['up'],
            'database_latency_ms' => $db['latency_ms'],
            'redis_up'     => $redis['up'],
            'redis_latency_ms' => $redis['latency_ms'],
        ];
    }

    private function probeDatabase(): array
    {
        try {
            $start = hrtime(true);
            \Yii::$app->db->createCommand('SELECT 1')->queryScalar();
            $ms = (hrtime(true) - $start) / 1_000_000;
            return ['up' => true, 'latency_ms' => round($ms, 2)];
        } catch (\Throwable) {
            return ['up' => false, 'latency_ms' => null];
        }
    }

    private function probeRedis(): array
    {
        try {
            $start = hrtime(true);
            \Yii::$app->cache->set('metrics_probe', 1, 5);
            $ms = (hrtime(true) - $start) / 1_000_000;
            return ['up' => true, 'latency_ms' => round($ms, 2)];
        } catch (\Throwable) {
            return ['up' => false, 'latency_ms' => null];
        }
    }

    private function collectJobs(): array
    {
        try {
            $counts = [];
            foreach ([
                Job::STATUS_PENDING,
                Job::STATUS_QUEUED,
                Job::STATUS_RUNNING,
                Job::STATUS_SUCCEEDED,
                Job::STATUS_FAILED,
                Job::STATUS_CANCELED,
                Job::STATUS_TIMED_OUT,
            ] as $status) {
                $counts[$status] = (int)Job::find()->where(['status' => $status])->count();
            }

            $total = (int)Job::find()->count();

            // Average duration of jobs finished in the last hour
            $avgDuration = Job::find()
                ->where(['status' => [Job::STATUS_SUCCEEDED, Job::STATUS_FAILED]])
                ->andWhere(['>', 'finished_at', time() - 3600])
                ->andWhere(['not', ['finished_at' => null]])
                ->andWhere(['not', ['started_at' => null]])
                ->average('finished_at - started_at');

            return [
                'total'               => $total,
                'by_status'           => $counts,
                'avg_duration_1h_sec' => $avgDuration !== null ? round((float)$avgDuration, 1) : null,
            ];
        } catch (\Throwable) {
            return ['total' => 0, 'by_status' => [], 'avg_duration_1h_sec' => null];
        }
    }

    private function collectTasks(): array
    {
        try {
            $counts = [];
            foreach ([
                JobTask::STATUS_OK,
                JobTask::STATUS_CHANGED,
                JobTask::STATUS_FAILED,
                JobTask::STATUS_SKIPPED,
                JobTask::STATUS_UNREACHABLE,
            ] as $status) {
                $counts[$status] = (int)JobTask::find()->where(['status' => $status])->count();
            }

            $total = (int)JobTask::find()->count();

            // Task results from the last hour only
            $recentCounts = [];
            $oneHourAgo = time() - 3600;
            foreach ([
                JobTask::STATUS_OK,
                JobTask::STATUS_CHANGED,
                JobTask::STATUS_FAILED,
                JobTask::STATUS_SKIPPED,
                JobTask::STATUS_UNREACHABLE,
            ] as $status) {
                $recentCounts[$status] = (int)JobTask::find()
                    ->where(['status' => $status])
                    ->andWhere(['>', 'created_at', $oneHourAgo])
                    ->count();
            }

            return [
                'total'     => $total,
                'by_status' => $counts,
                'last_1h'   => $recentCounts,
            ];
        } catch (\Throwable) {
            return ['total' => 0, 'by_status' => [], 'last_1h' => []];
        }
    }

    private function collectHosts(): array
    {
        try {
            // Aggregate all-time host summary totals
            $totals = [
                'ok'          => (int)JobHostSummary::find()->sum('ok'),
                'changed'     => (int)JobHostSummary::find()->sum('changed'),
                'failed'      => (int)JobHostSummary::find()->sum('failed'),
                'skipped'     => (int)JobHostSummary::find()->sum('skipped'),
                'unreachable' => (int)JobHostSummary::find()->sum('unreachable'),
                'rescued'     => (int)JobHostSummary::find()->sum('rescued'),
            ];

            // Unique hosts seen
            $uniqueHosts = (int)JobHostSummary::find()
                ->select('host')
                ->distinct()
                ->count();

            // Hosts with changes (at least one changed > 0)
            $hostsWithChanges = (int)JobHostSummary::find()
                ->select('host')
                ->where(['>', 'changed', 0])
                ->distinct()
                ->count();

            // Hosts with failures
            $hostsWithFailures = (int)JobHostSummary::find()
                ->select('host')
                ->where(['>', 'failed', 0])
                ->distinct()
                ->count();

            // Jobs with changes
            $jobsWithChanges = (int)Job::find()
                ->where(['has_changes' => 1])
                ->count();

            return [
                'totals'              => $totals,
                'unique_hosts'        => $uniqueHosts,
                'hosts_with_changes'  => $hostsWithChanges,
                'hosts_with_failures' => $hostsWithFailures,
                'jobs_with_changes'   => $jobsWithChanges,
            ];
        } catch (\Throwable) {
            return [
                'totals'              => ['ok' => 0, 'changed' => 0, 'failed' => 0, 'skipped' => 0, 'unreachable' => 0, 'rescued' => 0],
                'unique_hosts'        => 0,
                'hosts_with_changes'  => 0,
                'hosts_with_failures' => 0,
                'jobs_with_changes'   => 0,
            ];
        }
    }

    private function collectWorkers(): array
    {
        try {
            $workers = WorkerHeartbeat::all();
            $now     = time();
            $alive   = array_filter($workers, fn($w) => ($now - ($w['seen_at'] ?? 0)) < WorkerHeartbeat::STALE_AFTER);
            $stale   = count($workers) - count($alive);

            return [
                'alive' => count($alive),
                'stale' => $stale,
            ];
        } catch (\Throwable) {
            return ['alive' => 0, 'stale' => 0];
        }
    }

    private function collectRunners(): array
    {
        try {
            $cutoff = time() - RunnerGroup::STALE_AFTER;
            $total  = (int)Runner::find()->count();
            $online = (int)Runner::find()->where(['>=', 'last_seen_at', $cutoff])->count();

            return [
                'total'   => $total,
                'online'  => $online,
                'offline' => $total - $online,
            ];
        } catch (\Throwable) {
            return ['total' => 0, 'online' => 0, 'offline' => 0];
        }
    }

    private function collectQueue(): array
    {
        try {
            return [
                'pending' => (int)Job::find()->where(['status' => [Job::STATUS_PENDING, Job::STATUS_QUEUED]])->count(),
                'running' => (int)Job::find()->where(['status' => Job::STATUS_RUNNING])->count(),
            ];
        } catch (\Throwable) {
            return ['pending' => 0, 'running' => 0];
        }
    }

    // ── Formatters ──────────────────────────────────────────────────────────

    private function formatJson(array $metrics): Response
    {
        $response = \Yii::$app->response;
        $response->format = Response::FORMAT_JSON;
        $response->data = $metrics;
        return $response;
    }

    private function formatPrometheus(array $metrics): Response
    {
        $response = \Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
        $response->data = self::renderPrometheus($metrics);
        return $response;
    }

    /**
     * Render metrics as Prometheus OpenMetrics text.
     * Static and pure so it can be tested without a web response.
     */
    public static function renderPrometheus(array $metrics): string
    {
        $lines = [];

        // Health
        $h = $metrics['health'];
        $lines[] = '# HELP ansilume_database_up Whether the database is reachable (1=up, 0=down).';
        $lines[] = '# TYPE ansilume_database_up gauge';
        $lines[] = 'ansilume_database_up ' . ($h['database_up'] ? '1' : '0');

        if ($h['database_latency_ms'] !== null) {
            $lines[] = '# HELP ansilume_database_latency_ms Database probe latency in milliseconds.';
            $lines[] = '# TYPE ansilume_database_latency_ms gauge';
            $lines[] = 'ansilume_database_latency_ms ' . $h['database_latency_ms'];
        }

        $lines[] = '# HELP ansilume_redis_up Whether Redis is reachable (1=up, 0=down).';
        $lines[] = '# TYPE ansilume_redis_up gauge';
        $lines[] = 'ansilume_redis_up ' . ($h['redis_up'] ? '1' : '0');

        if ($h['redis_latency_ms'] !== null) {
            $lines[] = '# HELP ansilume_redis_latency_ms Redis probe latency in milliseconds.';
            $lines[] = '# TYPE ansilume_redis_latency_ms gauge';
            $lines[] = 'ansilume_redis_latency_ms ' . $h['redis_latency_ms'];
        }

        // Jobs
        $j = $metrics['jobs'];
        $lines[] = '# HELP ansilume_jobs_total Total number of jobs.';
        $lines[] = '# TYPE ansilume_jobs_total gauge';
        $lines[] = 'ansilume_jobs_total ' . $j['total'];

        $lines[] = '# HELP ansilume_jobs_by_status Number of jobs by status.';
        $lines[] = '# TYPE ansilume_jobs_by_status gauge';
        foreach ($j['by_status'] as $status => $count) {
            $lines[] = 'ansilume_jobs_by_status{status="' . $status . '"} ' . $count;
        }

        if ($j['avg_duration_1h_sec'] !== null) {
            $lines[] = '# HELP ansilume_jobs_avg_duration_seconds Average job duration (last hour).';
            $lines[] = '# TYPE ansilume_jobs_avg_duration_seconds gauge';
            $lines[] = 'ansilume_jobs_avg_duration_seconds ' . $j['avg_duration_1h_sec'];
        }

        // Tasks
        $t = $metrics['tasks'];
        $lines[] = '# HELP ansilume_tasks_total Total number of task results recorded.';
        $lines[] = '# TYPE ansilume_tasks_total gauge';
        $lines[] = 'ansilume_tasks_total ' . $t['total'];

        $lines[] = '# HELP ansilume_tasks_by_status Task results by status (all time).';
        $lines[] = '# TYPE ansilume_tasks_by_status gauge';
        foreach ($t['by_status'] as $status => $count) {
            $lines[] = 'ansilume_tasks_by_status{status="' . $status . '"} ' . $count;
        }

        $lines[] = '# HELP ansilume_tasks_last_1h Task results by status (last hour).';
        $lines[] = '# TYPE ansilume_tasks_last_1h gauge';
        foreach ($t['last_1h'] as $status => $count) {
            $lines[] = 'ansilume_tasks_last_1h{status="' . $status . '"} ' . $count;
        }

        // Hosts
        $hs = $metrics['hosts'];
        $lines[] = '# HELP ansilume_host_results_total Aggregated Ansible PLAY RECAP counters across all jobs.';
        $lines[] = '# TYPE ansilume_host_results_total gauge';
        foreach ($hs['totals'] as $key => $val) {
            $lines[] = 'ansilume_host_results_total{result="' . $key . '"} ' . $val;
        }

        $lines[] = '# HELP ansilume_hosts_unique Number of unique hosts seen across all jobs.';
        $lines[] = '# TYPE ansilume_hosts_unique gauge';
        $lines[] = 'ansilume_hosts_unique ' . $hs['unique_hosts'];

        $lines[] = '# HELP ansilume_hosts_with_changes Unique hosts that had at least one change.';
        $lines[] = '# TYPE ansilume_hosts_with_changes gauge';
        $lines[] = 'ansilume_hosts_with_changes ' . $hs['hosts_with_changes'];

        $lines[] = '# HELP ansilume_hosts_with_failures Unique hosts that had at least one failure.';
        $lines[] = '# TYPE ansilume_hosts_with_failures gauge';
        $lines[] = 'ansilume_hosts_with_failures ' . $hs['hosts_with_failures'];

        $lines[] = '# HELP ansilume_jobs_with_changes Jobs where at least one task made a change.';
        $lines[] = '# TYPE ansilume_jobs_with_changes gauge';
        $lines[] = 'ansilume_jobs_with_changes ' . $hs['jobs_with_changes'];

        // Workers
        $w = $metrics['workers'];
        $lines[] = '# HELP ansilume_workers_alive Number of alive worker processes.';
        $lines[] = '# TYPE ansilume_workers_alive gauge';
        $lines[] = 'ansilume_workers_alive ' . $w['alive'];

        $lines[] = '# HELP ansilume_workers_stale Number of stale worker processes.';
        $lines[] = '# TYPE ansilume_workers_stale gauge';
        $lines[] = 'ansilume_workers_stale ' . $w['stale'];

        // Runners
        $r = $metrics['runners'];
        $lines[] = '# HELP ansilume_runners_total Total number of registered runners.';
        $lines[] = '# TYPE ansilume_runners_total gauge';
        $lines[] = 'ansilume_runners_total ' . $r['total'];

        $lines[] = '# HELP ansilume_runners_online Runners that checked in recently.';
        $lines[] = '# TYPE ansilume_runners_online gauge';
        $lines[] = 'ansilume_runners_online ' . $r['online'];

        $lines[] = '# HELP ansilume_runners_offline Registered runners that are not responding.';
        $lines[] = '# TYPE ansilume_runners_offline gauge';
        $lines[] = 'ansilume_runners_offline ' . $r['offline'];

        // Queue
        $q = $metrics['queue'];
        $lines[] = '# HELP ansilume_queue_pending Jobs waiting to be picked up.';
        $lines[] = '# TYPE ansilume_queue_pending gauge';
        $lines[] = 'ansilume_queue_pending ' . $q['pending'];

        $lines[] = '# HELP ansilume_queue_running Jobs currently executing.';
        $lines[] = '# TYPE ansilume_queue_running gauge';
        $lines[] = 'ansilume_queue_running ' . $q['running'];

        return implode("\n", $lines) . "\n";
    }
}
