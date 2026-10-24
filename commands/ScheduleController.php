<?php

declare(strict_types=1);

namespace app\commands;

use app\components\PendingMigrations;
use app\models\Schedule;
use app\services\ScheduleService;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Runs due scheduled jobs, and lists the schedules whose cron expression a
 * save refuses.
 *
 * Add to your system crontab to trigger every minute:
 *   * * * * * /path/to/php /app/yii schedule/run >> /var/log/ansilume-schedule.log 2>&1
 */
class ScheduleController extends Controller
{
    /** sprintf(): the ID, the name, the cron expression, CHECK_DISABLED or "", then why a save refuses it. */
    public const CHECK_REFUSED = 'Schedule #%d "%s" (%s%s): %s';

    /** CHECK_REFUSED after the cron expression of a disabled schedule. */
    public const CHECK_DISABLED = ', disabled';

    /** sprintf(): how many schedules there are. */
    public const CHECK_PASSED = 'The cron expressions of all %d schedule(s) meet the cron rules.';

    /** sprintf(): how many of them a save refuses, then how many schedules there are. */
    public const CHECK_FAILED = 'A save refuses the cron expression of %d of %d schedule(s). Each runs as before '
        . 'until its expression is changed as the message says.';

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
     * List the schedules whose cron expression a save refuses
     * (Schedule::cronExpressionProblem()), with the reason: versions up to
     * 2.12.2 saved such expressions, and the schedule keeps running as
     * before, skipped and misplaced runs included, until its expression is
     * changed. Exits with 65 (DATAERR) when it lists one.
     */
    public function actionCheck(): int
    {
        /** @var Schedule[] $schedules */
        $schedules = Schedule::find()->orderBy(['id' => SORT_ASC])->all();
        $refused = 0;
        foreach ($schedules as $schedule) {
            $problem = $schedule->cronExpressionProblem();
            if ($problem === null) {
                continue;
            }
            $refused++;
            $this->stdout(sprintf(
                self::CHECK_REFUSED,
                (int)$schedule->id,
                self::printable((string)$schedule->name),
                self::printable((string)$schedule->cron_expression),
                $schedule->enabled ? '' : self::CHECK_DISABLED,
                $problem
            ) . PHP_EOL);
        }
        if ($refused === 0) {
            $this->stdout(sprintf(self::CHECK_PASSED, count($schedules)) . PHP_EOL);
            return ExitCode::OK;
        }
        $this->stdout(sprintf(self::CHECK_FAILED, $refused, count($schedules)) . PHP_EOL);

        return ExitCode::DATAERR;
    }

    /**
     * $text with its control characters as "?", the C1 ones in UTF-8 too
     * (CSI, OSC), so that a name cannot steer the terminal.
     */
    private static function printable(string $text): string
    {
        return (string)preg_replace('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/', '?', $text);
    }

    /**
     * The migration state checked before schedules run.
     */
    protected function pendingMigrations(): PendingMigrations
    {
        return new PendingMigrations();
    }
}
