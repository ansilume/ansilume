<?php

declare(strict_types=1);

namespace app\commands;

use app\components\ErrorText;
use app\components\PendingMigrations;
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
    /**
     * The line of a check that failed with an error. sprintf(): the check
     * (db, redis, migrations, rbac, users), then what may be said about the
     * error (ErrorText): its message, but for a database error only its
     * class, SQLSTATE and driver error code, never its message, which
     * carries the SQL statement. The redis check prints the Redis error's
     * message. Docker keeps this output in the container's health log.
     */
    public const CHECK_ERROR = "[health] %s: error — %s\n";

    public function actionCheck(): int
    {
        $healthy = true;

        // Database
        try {
            \Yii::$app->db->createCommand('SELECT 1')->queryScalar();
            $this->stdout("[health] db: ok\n");
        } catch (\Throwable $e) {
            $this->stderr(sprintf(self::CHECK_ERROR, 'db', ErrorText::of($e)));
            $healthy = false;
        }

        // Redis
        try {
            /** @var \yii\caching\CacheInterface $cache */
            $cache = \Yii::$app->cache;
            $cache->set('health_probe', 1, 5);
            $this->stdout("[health] redis: ok\n");
        } catch (\Throwable $e) {
            // yii2-redis reports Redis errors as yii\db\Exception, which
            // ErrorText would reduce to its class; the message names the
            // cause (RedactingRedisConnection keeps credentials out of it).
            $this->stderr(sprintf(self::CHECK_ERROR, 'redis', $e->getMessage()));
            $healthy = false;
        }

        // Migrations
        try {
            $migrations = $this->pendingMigrations();
            $expected = $migrations->expectedCount();
            $applied = $migrations->appliedCount();

            if ($applied < $expected) {
                $pending = $expected - $applied;
                $this->stderr("[health] migrations: {$pending} pending ({$applied}/{$expected})\n");
                $healthy = false;
            } else {
                $this->stdout("[health] migrations: ok ({$applied}/{$expected})\n");
            }
        } catch (\Throwable $e) {
            $this->stderr(sprintf(self::CHECK_ERROR, 'migrations', ErrorText::of($e)));
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

    /**
     * The migration state the check reports.
     */
    protected function pendingMigrations(): PendingMigrations
    {
        return new PendingMigrations();
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
            $this->stderr(sprintf(self::CHECK_ERROR, 'rbac', ErrorText::of($e)));
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
                // No users is expected during initial setup (before setup/admin runs).
                // Warn but do not mark unhealthy — the app is functional without users.
                $this->stderr("[health] users: none — run setup/admin to create the first user\n");
            } else {
                $this->stdout("[health] users: {$userCount} registered\n");
            }
        } catch (\Throwable $e) {
            $this->stderr(sprintf(self::CHECK_ERROR, 'users', ErrorText::of($e)));
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
