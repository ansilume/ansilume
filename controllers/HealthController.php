<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\WorkerHeartbeat;
use app\models\Job;
use yii\filters\ContentNegotiator;
use yii\web\Controller;
use yii\web\Response;

/**
 * Health check endpoint for load balancers and monitoring.
 *
 * GET /health
 *
 * Returns HTTP 200 with JSON when the system is healthy.
 * Returns HTTP 503 when a critical component is unavailable.
 *
 * No authentication required — this endpoint must be accessible to
 * health probes that cannot carry credentials.
 */
class HealthController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return [
            'contentNegotiator' => [
                'class'   => ContentNegotiator::class,
                'formats' => ['application/json' => Response::FORMAT_JSON],
            ],
        ];
    }

    public function actionIndex(): array
    {
        $checks  = $this->runChecks();
        $healthy = !in_array(false, array_column($checks, 'ok'), true);

        \Yii::$app->response->statusCode = $healthy ? 200 : 503;

        return [
            'status'  => $healthy ? 'ok' : 'degraded',
            'checks'  => $checks,
            'workers' => $this->workerSummary(),
            'queue'   => $this->queueSummary(),
        ];
    }

    private function runChecks(): array
    {
        return [
            'database' => $this->checkDatabase(),
            'redis'    => $this->checkRedis(),
        ];
    }

    private function checkDatabase(): array
    {
        try {
            \Yii::$app->db->createCommand('SELECT 1')->queryScalar();
            return ['ok' => true, 'latency_ms' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'DB unreachable'];
        }
    }

    private function checkRedis(): array
    {
        try {
            \Yii::$app->cache->set('health_probe', 1, 5);
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Redis unreachable'];
        }
    }

    private function workerSummary(): array
    {
        $workers = WorkerHeartbeat::all();
        $now     = time();
        $alive   = array_filter($workers, fn($w) => ($now - ($w['seen_at'] ?? 0)) < WorkerHeartbeat::STALE_AFTER);

        return [
            'count'   => count($alive),
            'workers' => array_values(array_map(fn($w) => [
                'worker_id'  => $w['worker_id'],
                'hostname'   => $w['hostname'],
                'started_at' => $w['started_at'],
                'seen_at'    => $w['seen_at'],
                'age_s'      => $now - ($w['seen_at'] ?? $now),
            ], $alive)),
        ];
    }

    private function queueSummary(): array
    {
        return [
            'pending' => (int)Job::find()->where(['status' => [Job::STATUS_PENDING, Job::STATUS_QUEUED]])->count(),
            'running' => (int)Job::find()->where(['status' => Job::STATUS_RUNNING])->count(),
        ];
    }
}
