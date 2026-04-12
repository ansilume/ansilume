<?php

declare(strict_types=1);

namespace app\services;

use app\models\Job;
use app\models\NotificationTemplate;
use app\models\Schedule;
use Cron\CronExpression;
use yii\base\Component;

/**
 * Evaluates due schedules and launches jobs for them.
 *
 * Designed to be called from the `schedule/run` console command,
 * typically every minute by an OS-level cron job:
 *   * * * * * php yii schedule/run
 */
class ScheduleService extends Component
{
    /**
     * Check all enabled schedules and launch jobs for those that are due.
     *
     * @return int Number of jobs launched.
     */
    public function runDue(): int
    {
        $launched = 0;

        $schedules = Schedule::find()
            ->where(['enabled' => true])
            ->with('jobTemplate')
            ->all();

        foreach ($schedules as $schedule) {
            /** @var Schedule $schedule */
            if (!$this->isDue($schedule)) {
                continue;
            }

            // Atomic claim: advance next_run_at only if it hasn't changed since
            // we read it. Prevents duplicate launches when multiple schedule
            // runners are active.
            if (!$this->claimSchedule($schedule)) {
                continue;
            }

            try {
                $this->launchSchedule($schedule);
                $launched++;
            } catch (\Throwable $e) {
                \Yii::error(
                    "Schedule #{$schedule->id} ({$schedule->name}) failed to launch: " . $e->getMessage(),
                    __CLASS__
                );

                /** @var NotificationDispatcher $dispatcher */
                $dispatcher = \Yii::$app->get('notificationDispatcher');
                $dispatcher->dispatch(NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH, [
                    'schedule' => [
                        'id' => (string)$schedule->id,
                        'name' => (string)$schedule->name,
                        'cron' => (string)$schedule->cron_expression,
                        'error' => $e->getMessage(),
                    ],
                ]);
            }
        }

        return $launched;
    }

    /**
     * Returns true if the schedule is due now (next_run_at <= now or not yet set).
     * Uses the cron expression + timezone for evaluation.
     */
    private function isDue(Schedule $schedule): bool
    {
        if ($schedule->next_run_at === null) {
            // next_run_at not yet computed — compute and check.
            try {
                $cron = new CronExpression($schedule->cron_expression);
                return $cron->isDue('now', $schedule->timezone ?: 'UTC');
            } catch (\Exception $e) {
                return false;
            }
        }

        return $schedule->next_run_at <= time();
    }

    private function launchSchedule(Schedule $schedule): void
    {
        if ($schedule->jobTemplate === null) {
            throw new \RuntimeException("Job template not found for schedule #{$schedule->id}");
        }

        // Use system user ID 0 for system-initiated jobs.
        // The audit log entry records it came from schedule.
        /** @var JobLaunchService $launcher */
        $launcher = \Yii::$app->get('jobLaunchService');

        $overrides = [];
        if (!empty($schedule->extra_vars)) {
            $overrides['extra_vars'] = $schedule->extra_vars;
        }

        $job = $launcher->launch($schedule->jobTemplate, $schedule->created_by, $overrides);

        \Yii::info(
            "Schedule #{$schedule->id} ({$schedule->name}) launched job #{$job->id}",
            __CLASS__
        );
    }

    /**
     * Atomically claim a schedule for execution by advancing its next_run_at.
     *
     * Uses UPDATE ... WHERE to ensure only one runner processes a schedule
     * when multiple schedule-runners are active concurrently.
     */
    private function claimSchedule(Schedule $schedule): bool
    {
        $oldNextRunAt = $schedule->next_run_at;
        $schedule->last_run_at = time();
        $schedule->computeNextRunAt();

        // Atomic: only update if next_run_at hasn't changed since we read it
        $condition = ['id' => $schedule->id, 'enabled' => true];
        if ($oldNextRunAt !== null) {
            $condition['next_run_at'] = $oldNextRunAt;
        } else {
            // Schedule with null next_run_at — match on null explicitly
            $condition['next_run_at'] = null;
        }

        $rows = Schedule::updateAll(
            [
                'last_run_at' => $schedule->last_run_at,
                'next_run_at' => $schedule->next_run_at,
                'updated_at' => time(),
            ],
            $condition
        );

        return $rows > 0;
    }
}
