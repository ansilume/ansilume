<?php

declare(strict_types=1);

namespace app\commands;

use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Application health check for Docker healthchecks and monitoring.
 *
 * Usage:
 *   php yii health/check   — exits 0 (healthy) or 1 (degraded)
 */
class HealthController extends Controller
{
    public function actionCheck(): int
    {
        $healthy = true;

        // Database
        try {
            \Yii::$app->db->createCommand('SELECT 1')->queryScalar();
            $this->stdout("[health] db: ok\n");
        } catch (\Throwable $e) {
            $this->stderr("[health] db: error — " . $e->getMessage() . "\n");
            $healthy = false;
        }

        // Redis
        try {
            /** @var \yii\caching\CacheInterface $cache */
            $cache = \Yii::$app->cache;
            $cache->set('health_probe', 1, 5);
            $this->stdout("[health] redis: ok\n");
        } catch (\Throwable $e) {
            $this->stderr("[health] redis: error — " . $e->getMessage() . "\n");
            $healthy = false;
        }

        // Migrations
        try {
            $path = (string)\Yii::getAlias('@app/migrations');
            $files = glob($path . '/m*.php');
            $expected = $files !== false ? count($files) : 0;
            $applied = (int)\Yii::$app->db->createCommand(
                "SELECT COUNT(*) FROM {{%migration}} WHERE version != 'm000000_000000_base'"
            )->queryScalar();

            if ($applied < $expected) {
                $pending = $expected - $applied;
                $this->stderr("[health] migrations: {$pending} pending ({$applied}/{$expected})\n");
                $healthy = false;
            } else {
                $this->stdout("[health] migrations: ok ({$applied}/{$expected})\n");
            }
        } catch (\Throwable $e) {
            $this->stderr("[health] migrations: error — " . $e->getMessage() . "\n");
            $healthy = false;
        }

        if (!$healthy) {
            $this->stderr("[health] status: degraded\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("[health] status: ok\n");
        return ExitCode::OK;
    }
}
