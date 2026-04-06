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
                'class' => ContentNegotiator::class,
                'formats' => ['application/json' => Response::FORMAT_JSON],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function actionIndex(): array
    {
        $critical = $this->criticalChecks();
        $advisory = $this->advisoryChecks();

        $healthy = !in_array(false, array_column($critical, 'ok'), true);

        $this->setHttpStatus($healthy ? 200 : 503);

        return [
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => array_merge($critical, $advisory),
            'runners' => $this->runnerSummary(),
            'schedules' => $this->scheduleSummary(),
            'queue' => $this->queueSummary(),
        ];
    }

    /**
     * Checks that determine HTTP 200 vs 503.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function criticalChecks(): array
    {
        return [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'migrations' => $this->checkMigrations(),
        ];
    }

    /**
     * Informational checks — reported but do not cause 503.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function advisoryChecks(): array
    {
        return [
            'runners' => $this->checkRunners(),
            'scheduler' => $this->checkScheduler(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkDatabase(): array
    {
        try {
            \Yii::$app->db->createCommand('SELECT 1')->queryScalar();
            return ['ok' => true, 'latency_ms' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'DB unreachable'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkRedis(): array
    {
        try {
            /** @var \yii\caching\CacheInterface $cache */
            $cache = \Yii::$app->cache;
            $cache->set('health_probe', 1, 5);
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Redis unreachable'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkMigrations(): array
    {
        try {
            $expected = $this->countMigrationFiles();
            $applied = $this->countAppliedMigrations();

            if ($applied < $expected) {
                return [
                    'ok' => false,
                    'error' => ($expected - $applied) . ' pending migration(s)',
                    'applied' => $applied,
                    'expected' => $expected,
                ];
            }

            return ['ok' => true, 'applied' => $applied, 'expected' => $expected];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Migration check failed'];
        }
    }

    protected function countMigrationFiles(): int
    {
        $path = (string)\Yii::getAlias('@app/migrations');
        $files = glob($path . '/m*.php');
        return $files !== false ? count($files) : 0;
    }

    protected function countAppliedMigrations(): int
    {
        // Yii's migration table always has m000000_000000_base; exclude it.
        return (int)\Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM {{%migration}} WHERE version != 'm000000_000000_base'"
        )->queryScalar();
    }

    protected function setHttpStatus(int $code): void
    {
        \Yii::$app->response->statusCode = $code;
    }

    /**
     * @return array<string, mixed>
     */
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
                'ok' => true,
                'online' => $counts['online'],
                'total' => $counts['total'],
            ];
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'Runner check failed'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkScheduler(): array
    {
        try {
            $counts = $this->getScheduleCounts();

            if ($counts['enabled'] === 0) {
                return ['ok' => true, 'enabled' => 0, 'overdue' => 0];
            }

            if ($counts['overdue'] > 0) {
                return [
                    'ok' => false,
                    'error' => $counts['overdue'] . ' overdue schedule(s)',
                    'enabled' => $counts['enabled'],
                    'overdue' => $counts['overdue'],
                ];
            }

            return ['ok' => true, 'enabled' => $counts['enabled'], 'overdue' => 0];
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'Scheduler check failed'];
        }
    }

    /**
     * @return array{enabled: int, overdue: int}
     */
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

    /**
     * @return array{total: int, online: int, offline: int}
     */
    private function runnerSummary(): array
    {
        return $this->getRunnerCounts();
    }

    /**
     * @return array{total: int, online: int, offline: int}
     */
    protected function getRunnerCounts(): array
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
     * @return array{total: int, enabled: int}
     */
    private function scheduleSummary(): array
    {
        try {
            $total = (int)Schedule::find()->count();
            $enabled = (int)Schedule::find()->where(['enabled' => 1])->count();
            return ['total' => $total, 'enabled' => $enabled];
        } catch (\Throwable) {
            return ['total' => 0, 'enabled' => 0];
        }
    }

    /**
     * @return array{pending: int, running: int}
     */
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
