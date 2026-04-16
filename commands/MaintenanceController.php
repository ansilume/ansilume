<?php

declare(strict_types=1);

namespace app\commands;

use app\services\MaintenanceService;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Periodic maintenance dispatcher.
 *
 * Intended to be invoked once a minute by the schedule-runner container
 * (alongside `schedule/run`). Each individual task has its own cooldown
 * inside {@see MaintenanceService}, so this command is safe to run
 * frequently — it will be a no-op until at least one task is due.
 *
 *   * * * * * /path/to/php /app/yii maintenance/run
 */
class MaintenanceController extends Controller
{
    /**
     * Run any maintenance task whose cooldown has expired.
     */
    public function actionRun(): int
    {
        /** @var MaintenanceService $service */
        $service = \Yii::$app->get('maintenanceService');
        $report = $service->runIfDue();

        // Quiet by default — the schedule-runner pipes stdout to docker logs
        // and we do not want one line per minute when nothing is due. Only
        // emit output when a task actually ran.
        foreach ($report['ran'] as $task) {
            $result = $report['results'][$task] ?? [];
            $details = [];
            foreach ($result as $key => $value) {
                $details[] = "{$key}={$value}";
            }
            $detailLine = $details === [] ? '' : ' (' . implode(', ', $details) . ')';
            $this->stdout("[maintenance] ran: {$task}{$detailLine}\n");
        }

        return ExitCode::OK;
    }
}
