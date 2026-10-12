<?php

declare(strict_types=1);

namespace app\commands;

use app\components\PendingMigrations;
use app\services\ScheduleService;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Runs due scheduled jobs.
 *
 * Add to your system crontab to trigger every minute:
 *   * * * * * /path/to/php /app/yii schedule/run >> /var/log/ansilume-schedule.log 2>&1
 */
class ScheduleController extends Controller
{
    /**
     * Check all enabled schedules and launch any that are due; print how
     * many jobs were launched, if any.
     *
     * While database migrations are pending it launches nothing and claims
     * no schedule, so their runs stay due until the update has finished: in
     * the prebuilt setup the schedule runner starts while the app container
     * still migrates, and a bind-mounted checkout runs new code right after
     * `git pull`. It exits 0 then, so the schedule runner stays healthy.
     */
    public function actionRun(): int
    {
        $pending = $this->pendingMigrations()->count();
        if ($pending > 0) {
            $this->stdout("Schedules not run: {$pending} database migration(s) pending; they run once the update has finished." . PHP_EOL);
            return ExitCode::OK;
        }

        /** @var ScheduleService $service */
        $service = \Yii::$app->get('scheduleService');
        $launched = $service->runDue();

        if ($launched > 0) {
            $this->stdout("Launched {$launched} scheduled job(s)." . PHP_EOL);
        }

        return ExitCode::OK;
    }

    /**
     * The migration state checked before schedules run.
     */
    protected function pendingMigrations(): PendingMigrations
    {
        return new PendingMigrations();
    }
}
