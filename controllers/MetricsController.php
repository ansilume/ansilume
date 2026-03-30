<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\Job;
use app\models\JobHostSummary;
use app\models\JobTask;
use app\models\Runner;
use app\models\RunnerGroup;
use app\models\Schedule;
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
        $fmt = $format ?? $this->getRequestedFormat();

        return match ($fmt) {
            'json' => $this->formatJson($metrics),
            default => $this->formatPrometheus($metrics),
        };
    }

    protected function getRequestedFormat(): string
    {
        /** @var string $format */
        $format = \Yii::$app->request->get('format', 'prometheus');
        return $format;
    }

    // ── Collectors ──────────────────────────────────────────────────────────

    /**
     * Collect all metrics as a structured array.
     * Public for testability (console app cannot use web response).
     *
     * @return array<string, array<string, mixed>>
     */
    public function collect(): array
    {
        return [
            'health' => $this->collectHealth(),
            'jobs' => $this->collectJobs(),
            'tasks' => $this->collectTasks(),
            'hosts' => $this->collectHosts(),
            'runners' => $this->collectRunners(),
            'schedules' => $this->collectSchedules(),
            'queue' => $this->collectQueue(),
        ];
    }

    /**
     * @return array{database_up: bool, database_latency_ms: float|null, redis_up: bool, redis_latency_ms: float|null}
     */
    private function collectHealth(): array
    {
        $db = $this->probeDatabase();
        $redis = $this->probeRedis();

        return [
            'database_up' => $db['up'],
            'database_latency_ms' => $db['latency_ms'],
            'redis_up' => $redis['up'],
            'redis_latency_ms' => $redis['latency_ms'],
        ];
    }

    /**
     * @return array{up: bool, latency_ms: float|null}
     */
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

    /**
     * @return array{up: bool, latency_ms: float|null}
     */
    private function probeRedis(): array
    {
        try {
            $start = hrtime(true);
            /** @var \yii\caching\CacheInterface $cache */
            $cache = \Yii::$app->cache;
            $cache->set('metrics_probe', 1, 5);
            $ms = (hrtime(true) - $start) / 1_000_000;
            return ['up' => true, 'latency_ms' => round($ms, 2)];
        } catch (\Throwable) {
            return ['up' => false, 'latency_ms' => null];
        }
    }

    /**
     * @return array{total: int, by_status: array<string, int>, avg_duration_1h_sec: float|null}
     */
    private function collectJobs(): array
    {
        $jobStatuses = [
            Job::STATUS_PENDING,
            Job::STATUS_QUEUED,
            Job::STATUS_RUNNING,
            Job::STATUS_SUCCEEDED,
            Job::STATUS_FAILED,
            Job::STATUS_CANCELED,
            Job::STATUS_TIMED_OUT,
        ];
        $counts = array_fill_keys($jobStatuses, 0);

        try {
            foreach ($jobStatuses as $status) {
                $counts[$status] = (int)Job::find()->where(['status' => $status])->count();
            }

            $total = (int)Job::find()->count();

            // Average duration of jobs finished in the last hour
            /** @var string|null $avgDuration */
            $avgDuration = Job::find()
                ->where(['status' => [Job::STATUS_SUCCEEDED, Job::STATUS_FAILED]])
                ->andWhere(['>', 'finished_at', time() - 3600])
                ->andWhere(['not', ['finished_at' => null]])
                ->andWhere(['not', ['started_at' => null]])
                ->average('finished_at - started_at');

            return [
                'total' => $total,
                'by_status' => $counts,
                'avg_duration_1h_sec' => $avgDuration !== null ? round((float)$avgDuration, 1) : null,
            ];
        } catch (\Throwable) {
            return ['total' => 0, 'by_status' => $counts, 'avg_duration_1h_sec' => null];
        }
    }

    /**
     * @return array{total: int, by_status: array<string, int>, last_1h: array<string, int>}
     */
    private function collectTasks(): array
    {
        $taskStatuses = [
            JobTask::STATUS_OK,
            JobTask::STATUS_CHANGED,
            JobTask::STATUS_FAILED,
            JobTask::STATUS_SKIPPED,
            JobTask::STATUS_UNREACHABLE,
        ];
        $counts = array_fill_keys($taskStatuses, 0);
        $recentCounts = array_fill_keys($taskStatuses, 0);

        try {
            foreach ($taskStatuses as $status) {
                $counts[$status] = (int)JobTask::find()->where(['status' => $status])->count();
            }

            $total = (int)JobTask::find()->count();

            // Task results from the last hour only
            $oneHourAgo = time() - 3600;
            foreach ($taskStatuses as $status) {
                $recentCounts[$status] = (int)JobTask::find()
                    ->where(['status' => $status])
                    ->andWhere(['>', 'created_at', $oneHourAgo])
                    ->count();
            }

            return [
                'total' => $total,
                'by_status' => $counts,
                'last_1h' => $recentCounts,
            ];
        } catch (\Throwable) {
            return ['total' => 0, 'by_status' => $counts, 'last_1h' => $recentCounts];
        }
    }

    /**
     * @return array{totals: array<string, int>, unique_hosts: int, hosts_with_changes: int, hosts_with_failures: int, jobs_with_changes: int}
     */
    private function collectHosts(): array
    {
        try {
            // Aggregate all-time host summary totals
            /** @var string|null $ok */
            $ok = JobHostSummary::find()->sum('ok');
            /** @var string|null $changed */
            $changed = JobHostSummary::find()->sum('changed');
            /** @var string|null $failed */
            $failed = JobHostSummary::find()->sum('failed');
            /** @var string|null $skipped */
            $skipped = JobHostSummary::find()->sum('skipped');
            /** @var string|null $unreachable */
            $unreachable = JobHostSummary::find()->sum('unreachable');
            /** @var string|null $rescued */
            $rescued = JobHostSummary::find()->sum('rescued');
            $totals = [
                'ok' => (int)$ok,
                'changed' => (int)$changed,
                'failed' => (int)$failed,
                'skipped' => (int)$skipped,
                'unreachable' => (int)$unreachable,
                'rescued' => (int)$rescued,
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
                'totals' => $totals,
                'unique_hosts' => $uniqueHosts,
                'hosts_with_changes' => $hostsWithChanges,
                'hosts_with_failures' => $hostsWithFailures,
                'jobs_with_changes' => $jobsWithChanges,
            ];
        } catch (\Throwable) {
            return [
                'totals' => ['ok' => 0, 'changed' => 0, 'failed' => 0, 'skipped' => 0, 'unreachable' => 0, 'rescued' => 0],
                'unique_hosts' => 0,
                'hosts_with_changes' => 0,
                'hosts_with_failures' => 0,
                'jobs_with_changes' => 0,
            ];
        }
    }

    /**
     * @return array{total: int, online: int, offline: int}
     */
    private function collectRunners(): array
    {
        try {
            $cutoff = time() - RunnerGroup::STALE_AFTER;
            $total = (int)Runner::find()->count();
            $online = (int)Runner::find()->where(['>=', 'last_seen_at', $cutoff])->count();

            return [
                'total' => $total,
                'online' => $online,
                'offline' => $total - $online,
            ];
        } catch (\Throwable) {
            return ['total' => 0, 'online' => 0, 'offline' => 0];
        }
    }

    /**
     * @return array{total: int, enabled: int, overdue: int}
     */
    private function collectSchedules(): array
    {
        try {
            $total = (int)Schedule::find()->count();
            $enabled = (int)Schedule::find()->where(['enabled' => 1])->count();
            $overdue = (int)Schedule::find()
                ->where(['enabled' => 1])
                ->andWhere(['not', ['next_run_at' => null]])
                ->andWhere(['<', 'next_run_at', time() - 300])
                ->count();

            return [
                'total' => $total,
                'enabled' => $enabled,
                'overdue' => $overdue,
            ];
        } catch (\Throwable) {
            return ['total' => 0, 'enabled' => 0, 'overdue' => 0];
        }
    }

    /**
     * @return array{pending: int, running: int}
     */
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

    /**
     * @param array<string, array<string, mixed>> $metrics
     */
    private function formatJson(array $metrics): Response
    {
        $response = \Yii::$app->response;
        $response->format = Response::FORMAT_JSON;
        $response->data = $metrics;
        return $response;
    }

    /**
     * @param array<string, array<string, mixed>> $metrics
     */
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
     *
     * @param array<string, mixed> $metrics
     */
    public static function renderPrometheus(array $metrics): string
    {
        $lines = [];

        /** @var array{database_up: bool, database_latency_ms: float|null, redis_up: bool, redis_latency_ms: float|null} $health */
        $health = $metrics['health'];
        /** @var array{total: int, by_status: array<string, int>, avg_duration_1h_sec: float|null} $jobs */
        $jobs = $metrics['jobs'];
        /** @var array{total: int, by_status: array<string, int>, last_1h: array<string, int>} $tasks */
        $tasks = $metrics['tasks'];
        /** @var array{totals: array<string, int>, unique_hosts: int, hosts_with_changes: int, hosts_with_failures: int, jobs_with_changes: int} $hosts */
        $hosts = $metrics['hosts'];
        /** @var array{total: int, online: int, offline: int} $runners */
        $runners = $metrics['runners'];
        /** @var array{total: int, enabled: int, overdue: int} $schedules */
        $schedules = $metrics['schedules'];
        /** @var array{pending: int, running: int} $queue */
        $queue = $metrics['queue'];

        self::renderHealthMetrics($lines, $health);
        self::renderJobMetrics($lines, $jobs);
        self::renderTaskMetrics($lines, $tasks);
        self::renderHostMetrics($lines, $hosts);
        self::renderRunnerMetrics($lines, $runners);
        self::renderScheduleMetrics($lines, $schedules);
        self::renderQueueMetrics($lines, $queue);

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param string[] $lines
     * @param array{database_up: bool, database_latency_ms: float|null, redis_up: bool, redis_latency_ms: float|null} $h
     */
    private static function renderHealthMetrics(array &$lines, array $h): void
    {
        self::gauge($lines, 'ansilume_database_up', 'Whether the database is reachable (1=up, 0=down).', $h['database_up'] ? '1' : '0');

        if ($h['database_latency_ms'] !== null) {
            self::gauge($lines, 'ansilume_database_latency_ms', 'Database probe latency in milliseconds.', $h['database_latency_ms']);
        }

        self::gauge($lines, 'ansilume_redis_up', 'Whether Redis is reachable (1=up, 0=down).', $h['redis_up'] ? '1' : '0');

        if ($h['redis_latency_ms'] !== null) {
            self::gauge($lines, 'ansilume_redis_latency_ms', 'Redis probe latency in milliseconds.', $h['redis_latency_ms']);
        }
    }

    /**
     * @param string[] $lines
     * @param array{total: int, by_status: array<string, int>, avg_duration_1h_sec: float|null} $j
     */
    private static function renderJobMetrics(array &$lines, array $j): void
    {
        self::gauge($lines, 'ansilume_jobs_total', 'Total number of jobs.', $j['total']);
        self::gaugeLabeled($lines, 'ansilume_jobs_by_status', 'Number of jobs by status.', 'status', $j['by_status']);

        if ($j['avg_duration_1h_sec'] !== null) {
            self::gauge($lines, 'ansilume_jobs_avg_duration_seconds', 'Average job duration (last hour).', $j['avg_duration_1h_sec']);
        }
    }

    /**
     * @param string[] $lines
     * @param array{total: int, by_status: array<string, int>, last_1h: array<string, int>} $t
     */
    private static function renderTaskMetrics(array &$lines, array $t): void
    {
        self::gauge($lines, 'ansilume_tasks_total', 'Total number of task results recorded.', $t['total']);
        self::gaugeLabeled($lines, 'ansilume_tasks_by_status', 'Task results by status (all time).', 'status', $t['by_status']);
        self::gaugeLabeled($lines, 'ansilume_tasks_last_1h', 'Task results by status (last hour).', 'status', $t['last_1h']);
    }

    /**
     * @param string[] $lines
     * @param array{totals: array<string, int>, unique_hosts: int, hosts_with_changes: int, hosts_with_failures: int, jobs_with_changes: int} $hs
     */
    private static function renderHostMetrics(array &$lines, array $hs): void
    {
        self::gaugeLabeled($lines, 'ansilume_host_results_total', 'Aggregated Ansible PLAY RECAP counters across all jobs.', 'result', $hs['totals']);
        self::gauge($lines, 'ansilume_hosts_unique', 'Number of unique hosts seen across all jobs.', $hs['unique_hosts']);
        self::gauge($lines, 'ansilume_hosts_with_changes', 'Unique hosts that had at least one change.', $hs['hosts_with_changes']);
        self::gauge($lines, 'ansilume_hosts_with_failures', 'Unique hosts that had at least one failure.', $hs['hosts_with_failures']);
        self::gauge($lines, 'ansilume_jobs_with_changes', 'Jobs where at least one task made a change.', $hs['jobs_with_changes']);
    }

    /**
     * @param string[] $lines
     * @param array{total: int, online: int, offline: int} $r
     */
    private static function renderRunnerMetrics(array &$lines, array $r): void
    {
        self::gauge($lines, 'ansilume_runners_total', 'Total number of registered runners.', $r['total']);
        self::gauge($lines, 'ansilume_runners_online', 'Runners that checked in recently.', $r['online']);
        self::gauge($lines, 'ansilume_runners_offline', 'Registered runners that are not responding.', $r['offline']);
    }

    /**
     * @param string[] $lines
     * @param array{total: int, enabled: int, overdue: int} $sc
     */
    private static function renderScheduleMetrics(array &$lines, array $sc): void
    {
        self::gauge($lines, 'ansilume_schedules_total', 'Total number of schedules.', $sc['total']);
        self::gauge($lines, 'ansilume_schedules_enabled', 'Number of enabled schedules.', $sc['enabled']);
        self::gauge($lines, 'ansilume_schedules_overdue', 'Enabled schedules past due by more than 5 minutes.', $sc['overdue']);
    }

    /**
     * @param string[] $lines
     * @param array{pending: int, running: int} $q
     */
    private static function renderQueueMetrics(array &$lines, array $q): void
    {
        self::gauge($lines, 'ansilume_queue_pending', 'Jobs waiting to be picked up.', $q['pending']);
        self::gauge($lines, 'ansilume_queue_running', 'Jobs currently executing.', $q['running']);
    }

    /**
     * @param string[] $lines
     * @param string|int|float $value
     */
    private static function gauge(array &$lines, string $name, string $help, $value): void
    {
        $lines[] = "# HELP {$name} {$help}";
        $lines[] = "# TYPE {$name} gauge";
        $lines[] = "{$name} {$value}";
    }

    /**
     * @param string[] $lines
     * @param array<string, int|float> $values
     */
    private static function gaugeLabeled(array &$lines, string $name, string $help, string $label, array $values): void
    {
        $lines[] = "# HELP {$name} {$help}";
        $lines[] = "# TYPE {$name} gauge";
        foreach ($values as $key => $val) {
            $lines[] = "{$name}{{$label}=\"{$key}\"} {$val}";
        }
    }
}
