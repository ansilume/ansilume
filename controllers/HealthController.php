<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\Job;
use app\models\Runner;
use app\models\RunnerGroup;
use app\models\Schedule;
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

        $this->setHttpStatus($healthy ? 200 : 503);

        return [
            'status'    => $healthy ? 'ok' : 'degraded',
            'checks'    => $checks,
            'runners'   => $this->runnerSummary(),
            'schedules' => $this->scheduleSummary(),
            'queue'     => $this->queueSummary(),
        ];
    }

    protected function runChecks(): array
    {
        return [
            'database'  => $this->checkDatabase(),
            'redis'     => $this->checkRedis(),
            'runners'   => $this->checkRunners(),
            'scheduler' => $this->checkScheduler(),
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

    protected function setHttpStatus(int $code): void
    {
        \Yii::$app->response->statusCode = $code;
    }

    protected function checkRunners(): array
    {
        try {
            $counts = $this->getRunnerCounts();

            if ($counts['total'] === 0) {
                return ['ok' => false, 'error' => 'No runners registered', 'online' => 0, 'total' => 0];
            }

            if ($counts['online'] === 0) {
                return ['ok' => false, 'error' => 'All runners offline', 'online' => 0, 'total' => $counts['total']];
            }

            return [
                'ok'     => true,
                'online' => $counts['online'],
                'total'  => $counts['total'],
            ];
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'Runner check failed'];
        }
    }

    protected function checkScheduler(): array
    {
        try {
            $counts = $this->getScheduleCounts();

            if ($counts['enabled'] === 0) {
                return ['ok' => true, 'enabled' => 0, 'overdue' => 0];
            }

            if ($counts['overdue'] > 0) {
                return [
                    'ok'      => false,
                    'error'   => $counts['overdue'] . ' overdue schedule(s)',
                    'enabled' => $counts['enabled'],
                    'overdue' => $counts['overdue'],
                ];
            }

            return ['ok' => true, 'enabled' => $counts['enabled'], 'overdue' => 0];
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'Scheduler check failed'];
        }
    }

    protected function getScheduleCounts(): array
    {
        $enabled = (int)Schedule::find()->where(['enabled' => 1])->count();
        // Overdue = enabled + next_run_at more than 5 minutes in the past
        $overdue = (int)Schedule::find()
            ->where(['enabled' => 1])
            ->andWhere(['not', ['next_run_at' => null]])
            ->andWhere(['<', 'next_run_at', time() - 300])
            ->count();

        return ['enabled' => $enabled, 'overdue' => $overdue];
    }

    private function runnerSummary(): array
    {
        return $this->getRunnerCounts();
    }

    protected function getRunnerCounts(): array
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

    private function scheduleSummary(): array
    {
        try {
            $total   = (int)Schedule::find()->count();
            $enabled = (int)Schedule::find()->where(['enabled' => 1])->count();
            return ['total' => $total, 'enabled' => $enabled];
        } catch (\Throwable) {
            return ['total' => 0, 'enabled' => 0];
        }
    }

    private function queueSummary(): array
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
}
