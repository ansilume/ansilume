<?php

declare(strict_types=1);

namespace app\commands;

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
     * Check all enabled schedules and launch any that are due.
     *
     * Returns the number of jobs launched as exit information.
     */
    public function actionRun(): int
    {
        /** @var ScheduleService $service */
        $service = \Yii::$app->get('scheduleService');
        $launched = $service->runDue();

        if ($launched > 0) {
            $this->stdout("Launched {$launched} scheduled job(s)." . PHP_EOL);
        }

        return ExitCode::OK;
    }
}
