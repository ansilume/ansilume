<?php

declare(strict_types=1);

namespace app\tests\unit\controllers;

use app\controllers\MetricsController;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MetricsController — data collection and Prometheus rendering.
 *
 * The test bootstrap uses a console app, so we test collect() for structure
 * and renderPrometheus() for output format without touching web responses.
 */
class MetricsControllerTest extends TestCase
{
    // ── collect() — JSON structure ──────────────────────────────────────────

    public function testCollectReturnsExpectedTopLevelKeys(): void
    {
        $ctrl = new MetricsController('metrics', \Yii::$app);
        $data = $ctrl->collect();

        $this->assertArrayHasKey('health', $data);
        $this->assertArrayHasKey('jobs', $data);
        $this->assertArrayHasKey('tasks', $data);
        $this->assertArrayHasKey('hosts', $data);
        $this->assertArrayHasKey('workers', $data);
        $this->assertArrayHasKey('runners', $data);
        $this->assertArrayHasKey('queue', $data);
    }

    public function testCollectHealthKeys(): void
    {
        $ctrl = new MetricsController('metrics', \Yii::$app);
        $health = $ctrl->collect()['health'];

        $this->assertArrayHasKey('database_up', $health);
        $this->assertArrayHasKey('database_latency_ms', $health);
        $this->assertArrayHasKey('redis_up', $health);
        $this->assertArrayHasKey('redis_latency_ms', $health);
        $this->assertIsBool($health['database_up']);
        $this->assertIsBool($health['redis_up']);
    }

    public function testCollectJobsKeys(): void
    {
        $ctrl = new MetricsController('metrics', \Yii::$app);
        $jobs = $ctrl->collect()['jobs'];

        $this->assertArrayHasKey('total', $jobs);
        $this->assertArrayHasKey('by_status', $jobs);
        $this->assertArrayHasKey('avg_duration_1h_sec', $jobs);
        $this->assertIsInt($jobs['total']);
        $this->assertIsArray($jobs['by_status']);
    }

    public function testCollectJobsByStatusContainsAllStatuses(): void
    {
        $ctrl = new MetricsController('metrics', \Yii::$app);
        $statuses = $ctrl->collect()['jobs']['by_status'];

        foreach (['pending', 'queued', 'running', 'succeeded', 'failed', 'canceled', 'timed_out'] as $s) {
            $this->assertArrayHasKey($s, $statuses, "Missing status: {$s}");
        }
    }

    public function testCollectWorkersKeys(): void
    {
        $ctrl = new MetricsController('metrics', \Yii::$app);
        $workers = $ctrl->collect()['workers'];

        $this->assertArrayHasKey('alive', $workers);
        $this->assertArrayHasKey('stale', $workers);
        $this->assertIsInt($workers['alive']);
        $this->assertIsInt($workers['stale']);
    }

    public function testCollectQueueKeys(): void
    {
        $ctrl = new MetricsController('metrics', \Yii::$app);
        $queue = $ctrl->collect()['queue'];

        $this->assertArrayHasKey('pending', $queue);
        $this->assertArrayHasKey('running', $queue);
        $this->assertIsInt($queue['pending']);
        $this->assertIsInt($queue['running']);
    }

    // ── renderPrometheus() — output format ──────────────────────────────────

    private function sampleMetrics(): array
    {
        return [
            'health' => [
                'database_up' => true,
                'database_latency_ms' => 1.23,
                'redis_up' => true,
                'redis_latency_ms' => 0.45,
            ],
            'jobs' => [
                'total' => 100,
                'by_status' => [
                    'pending' => 5, 'queued' => 3, 'running' => 2,
                    'succeeded' => 80, 'failed' => 7, 'canceled' => 2, 'timed_out' => 1,
                ],
                'avg_duration_1h_sec' => 42.5,
            ],
            'tasks' => [
                'total' => 500,
                'by_status' => [
                    'ok' => 350, 'changed' => 80, 'failed' => 20, 'skipped' => 40, 'unreachable' => 10,
                ],
                'last_1h' => [
                    'ok' => 30, 'changed' => 8, 'failed' => 2, 'skipped' => 5, 'unreachable' => 0,
                ],
            ],
            'hosts' => [
                'totals' => [
                    'ok' => 1200, 'changed' => 300, 'failed' => 50, 'skipped' => 100, 'unreachable' => 5, 'rescued' => 3,
                ],
                'unique_hosts' => 25,
                'hosts_with_changes' => 18,
                'hosts_with_failures' => 4,
                'jobs_with_changes' => 35,
            ],
            'workers' => ['alive' => 2, 'stale' => 1],
            'runners' => ['total' => 4, 'online' => 2, 'offline' => 2],
            'queue' => ['pending' => 8, 'running' => 2],
        ];
    }

    public function testPrometheusContainsAllMetricNames(): void
    {
        $output = MetricsController::renderPrometheus($this->sampleMetrics());

        $expected = [
            'ansilume_database_up',
            'ansilume_database_latency_ms',
            'ansilume_redis_up',
            'ansilume_redis_latency_ms',
            'ansilume_jobs_total',
            'ansilume_jobs_by_status',
            'ansilume_jobs_avg_duration_seconds',
            'ansilume_tasks_total',
            'ansilume_tasks_by_status',
            'ansilume_tasks_last_1h',
            'ansilume_host_results_total',
            'ansilume_hosts_unique',
            'ansilume_hosts_with_changes',
            'ansilume_hosts_with_failures',
            'ansilume_jobs_with_changes',
            'ansilume_workers_alive',
            'ansilume_workers_stale',
            'ansilume_runners_total',
            'ansilume_runners_online',
            'ansilume_runners_offline',
            'ansilume_queue_pending',
            'ansilume_queue_running',
        ];

        foreach ($expected as $metric) {
            $this->assertStringContainsString($metric, $output);
        }
    }

    public function testPrometheusHasHelpAndTypeAnnotations(): void
    {
        $output = MetricsController::renderPrometheus($this->sampleMetrics());

        $this->assertStringContainsString('# HELP', $output);
        $this->assertStringContainsString('# TYPE', $output);
        // Every TYPE should be gauge
        preg_match_all('/# TYPE .+ (\w+)/', $output, $matches);
        foreach ($matches[1] as $type) {
            $this->assertSame('gauge', $type);
        }
    }

    public function testPrometheusJobStatusLabels(): void
    {
        $output = MetricsController::renderPrometheus($this->sampleMetrics());

        foreach (['pending', 'queued', 'running', 'succeeded', 'failed', 'canceled', 'timed_out'] as $status) {
            $this->assertStringContainsString('status="' . $status . '"', $output);
        }
    }

    public function testPrometheusValues(): void
    {
        $output = MetricsController::renderPrometheus($this->sampleMetrics());

        $this->assertStringContainsString('ansilume_database_up 1', $output);
        $this->assertStringContainsString('ansilume_redis_up 1', $output);
        $this->assertStringContainsString('ansilume_jobs_total 100', $output);
        $this->assertStringContainsString('ansilume_workers_alive 2', $output);
        $this->assertStringContainsString('ansilume_workers_stale 1', $output);
        $this->assertStringContainsString('ansilume_queue_pending 8', $output);
        $this->assertStringContainsString('ansilume_queue_running 2', $output);
        $this->assertStringContainsString('ansilume_jobs_avg_duration_seconds 42.5', $output);
    }

    public function testPrometheusEndsWithNewline(): void
    {
        $output = MetricsController::renderPrometheus($this->sampleMetrics());
        $this->assertStringEndsWith("\n", $output);
    }

    public function testPrometheusOmitsLatencyWhenNull(): void
    {
        $metrics = $this->sampleMetrics();
        $metrics['health']['database_latency_ms'] = null;
        $metrics['health']['redis_latency_ms'] = null;

        $output = MetricsController::renderPrometheus($metrics);

        $this->assertStringNotContainsString('ansilume_database_latency_ms', $output);
        $this->assertStringNotContainsString('ansilume_redis_latency_ms', $output);
    }

    public function testPrometheusOmitsAvgDurationWhenNull(): void
    {
        $metrics = $this->sampleMetrics();
        $metrics['jobs']['avg_duration_1h_sec'] = null;

        $output = MetricsController::renderPrometheus($metrics);

        $this->assertStringNotContainsString('ansilume_jobs_avg_duration_seconds', $output);
    }

    public function testPrometheusDownState(): void
    {
        $metrics = $this->sampleMetrics();
        $metrics['health']['database_up'] = false;
        $metrics['health']['redis_up'] = false;

        $output = MetricsController::renderPrometheus($metrics);

        $this->assertStringContainsString('ansilume_database_up 0', $output);
        $this->assertStringContainsString('ansilume_redis_up 0', $output);
    }

    // ── Tasks ───────────────────────────────────────────────────────────────

    public function testCollectTasksKeys(): void
    {
        $ctrl = new MetricsController('metrics', \Yii::$app);
        $tasks = $ctrl->collect()['tasks'];

        $this->assertArrayHasKey('total', $tasks);
        $this->assertArrayHasKey('by_status', $tasks);
        $this->assertArrayHasKey('last_1h', $tasks);
        $this->assertIsInt($tasks['total']);
    }

    public function testCollectTasksByStatusContainsAllStatuses(): void
    {
        $ctrl = new MetricsController('metrics', \Yii::$app);
        $statuses = $ctrl->collect()['tasks']['by_status'];

        foreach (['ok', 'changed', 'failed', 'skipped', 'unreachable'] as $s) {
            $this->assertArrayHasKey($s, $statuses, "Missing task status: {$s}");
        }
    }

    public function testPrometheusTaskStatusLabels(): void
    {
        $output = MetricsController::renderPrometheus($this->sampleMetrics());

        foreach (['ok', 'changed', 'failed', 'skipped', 'unreachable'] as $status) {
            $this->assertStringContainsString('ansilume_tasks_by_status{status="' . $status . '"}', $output);
            $this->assertStringContainsString('ansilume_tasks_last_1h{status="' . $status . '"}', $output);
        }
    }

    public function testPrometheusTaskValues(): void
    {
        $output = MetricsController::renderPrometheus($this->sampleMetrics());

        $this->assertStringContainsString('ansilume_tasks_total 500', $output);
        $this->assertStringContainsString('ansilume_tasks_by_status{status="ok"} 350', $output);
        $this->assertStringContainsString('ansilume_tasks_by_status{status="changed"} 80', $output);
        $this->assertStringContainsString('ansilume_tasks_last_1h{status="failed"} 2', $output);
    }

    // ── Hosts ───────────────────────────────────────────────────────────────

    public function testCollectHostsKeys(): void
    {
        $ctrl = new MetricsController('metrics', \Yii::$app);
        $hosts = $ctrl->collect()['hosts'];

        $this->assertArrayHasKey('totals', $hosts);
        $this->assertArrayHasKey('unique_hosts', $hosts);
        $this->assertArrayHasKey('hosts_with_changes', $hosts);
        $this->assertArrayHasKey('hosts_with_failures', $hosts);
        $this->assertArrayHasKey('jobs_with_changes', $hosts);
    }

    public function testCollectHostTotalsContainsAllCategories(): void
    {
        $ctrl = new MetricsController('metrics', \Yii::$app);
        $totals = $ctrl->collect()['hosts']['totals'];

        foreach (['ok', 'changed', 'failed', 'skipped', 'unreachable', 'rescued'] as $k) {
            $this->assertArrayHasKey($k, $totals, "Missing host total: {$k}");
        }
    }

    public function testPrometheusHostResultLabels(): void
    {
        $output = MetricsController::renderPrometheus($this->sampleMetrics());

        foreach (['ok', 'changed', 'failed', 'skipped', 'unreachable', 'rescued'] as $result) {
            $this->assertStringContainsString('ansilume_host_results_total{result="' . $result . '"}', $output);
        }
    }

    public function testPrometheusHostValues(): void
    {
        $output = MetricsController::renderPrometheus($this->sampleMetrics());

        $this->assertStringContainsString('ansilume_hosts_unique 25', $output);
        $this->assertStringContainsString('ansilume_hosts_with_changes 18', $output);
        $this->assertStringContainsString('ansilume_hosts_with_failures 4', $output);
        $this->assertStringContainsString('ansilume_jobs_with_changes 35', $output);
        $this->assertStringContainsString('ansilume_host_results_total{result="changed"} 300', $output);
        $this->assertStringContainsString('ansilume_host_results_total{result="rescued"} 3', $output);
    }

    // ── Runners ─────────────────────────────────────────────────────────────

    public function testCollectRunnersKeys(): void
    {
        $ctrl = new MetricsController('metrics', \Yii::$app);
        $runners = $ctrl->collect()['runners'];

        $this->assertArrayHasKey('total', $runners);
        $this->assertArrayHasKey('online', $runners);
        $this->assertArrayHasKey('offline', $runners);
        $this->assertIsInt($runners['total']);
        $this->assertIsInt($runners['online']);
        $this->assertIsInt($runners['offline']);
    }

    public function testPrometheusRunnerValues(): void
    {
        $output = MetricsController::renderPrometheus($this->sampleMetrics());

        $this->assertStringContainsString('ansilume_runners_total 4', $output);
        $this->assertStringContainsString('ansilume_runners_online 2', $output);
        $this->assertStringContainsString('ansilume_runners_offline 2', $output);
    }
}
