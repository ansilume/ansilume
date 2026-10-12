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
 *
 * `maintenance/reset-survey-secret-scrub` is for operators: it makes the
 * encryption of stored survey password answers start over.
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

    /**
     * Make the survey password scrub start over at the next maintenance run.
     *
     * The scrub encrypts the survey password answers that finished jobs
     * stored in plaintext before the update. Its done mark and position are
     * kept in the cache, so a database restore does not reset them. Run this
     * after restoring a database backup taken after the update started: the
     * restored migration table already lists m000081, so the migration does
     * not reset the scrub either, and the restored jobs would keep their
     * plaintext answers. Safe to run at any time: rows that are done are
     * skipped.
     */
    public function actionResetSurveySecretScrub(): int
    {
        /** @var MaintenanceService $service */
        $service = \Yii::$app->get('maintenanceService');
        $service->resetSurveySecretScrub();
        $this->stdout("The survey password scrub starts over at the next maintenance run.\n");

        return ExitCode::OK;
    }
}
