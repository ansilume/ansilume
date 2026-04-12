<?php

declare(strict_types=1);

namespace app\commands;

use app\models\NotificationTemplate;
use app\models\Runner;
use app\models\User;
use app\services\NotificationDispatcher;
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

        // RBAC core roles
        $this->checkRbacRoles($healthy);

        // Runtime directories
        $this->checkRuntimeDirs($healthy);

        // Admin user
        $this->checkAdminUser($healthy);

        if (!$healthy) {
            $this->stderr("[health] status: degraded\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("[health] status: ok\n");
        return ExitCode::OK;
    }

    private function checkRbacRoles(bool &$healthy): void
    {
        try {
            /** @var \yii\rbac\ManagerInterface $auth */
            $auth = \Yii::$app->authManager;
            $missing = [];
            foreach (['admin', 'operator', 'viewer'] as $roleName) {
                if ($auth->getRole($roleName) === null) {
                    $missing[] = $roleName;
                }
            }
            if (!empty($missing)) {
                $this->stderr("[health] rbac: missing roles — " . implode(', ', $missing) . "\n");
                $healthy = false;
            } else {
                $this->stdout("[health] rbac: ok\n");
            }
        } catch (\Throwable $e) {
            $this->stderr("[health] rbac: error — " . $e->getMessage() . "\n");
            $healthy = false;
        }
    }

    private function checkRuntimeDirs(bool &$healthy): void
    {
        $dirs = ['/var/www/runtime', '/var/www/runtime/projects', '/var/www/runtime/artifacts', '/var/www/runtime/logs', '/var/www/web/assets'];
        $notWritable = [];
        foreach ($dirs as $dir) {
            if (!is_writable($dir)) {
                $notWritable[] = $dir;
            }
        }
        if (!empty($notWritable)) {
            $this->stderr("[health] dirs: not writable — " . implode(', ', $notWritable) . "\n");
            $healthy = false;
        } else {
            $this->stdout("[health] dirs: ok\n");
        }
    }

    private function checkAdminUser(bool &$healthy): void
    {
        try {
            $userCount = (int)User::find()->count();
            if ($userCount === 0) {
                $this->stderr("[health] users: none — run setup/admin to create the first user\n");
                $healthy = false;
            } else {
                $this->stdout("[health] users: {$userCount} registered\n");
            }
        } catch (\Throwable $e) {
            $this->stderr("[health] users: error — " . $e->getMessage() . "\n");
            $healthy = false;
        }
    }

    /**
     * Detect runner offline/recovered transitions and fire notification
     * events exactly once per transition. Intended to run from cron every
     * minute or two — idempotent across runs.
     */
    public function actionCheckRunners(): int
    {
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');

        /** @var Runner[] $runners */
        $runners = Runner::find()->all();
        $now = time();

        foreach ($runners as $runner) {
            $isOnline = $runner->isOnline();
            $wasNotifiedOffline = $runner->offline_notified_at !== null;

            if (!$isOnline && !$wasNotifiedOffline) {
                $runner->offline_notified_at = $now;
                $runner->save(false);
                $dispatcher->dispatch(NotificationTemplate::EVENT_RUNNER_OFFLINE, [
                    'runner' => [
                        'id' => (string)$runner->id,
                        'name' => (string)$runner->name,
                        'last_seen_at' => (string)($runner->last_seen_at ?? ''),
                    ],
                ]);
                $this->stdout("[health] runner #{$runner->id} ({$runner->name}): OFFLINE\n");
                continue;
            }

            if ($isOnline && $wasNotifiedOffline) {
                $runner->offline_notified_at = null;
                $runner->save(false);
                $dispatcher->dispatch(NotificationTemplate::EVENT_RUNNER_RECOVERED, [
                    'runner' => [
                        'id' => (string)$runner->id,
                        'name' => (string)$runner->name,
                        'last_seen_at' => (string)($runner->last_seen_at ?? ''),
                    ],
                ]);
                $this->stdout("[health] runner #{$runner->id} ({$runner->name}): RECOVERED\n");
            }
        }

        return ExitCode::OK;
    }
}
