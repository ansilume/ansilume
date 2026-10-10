<?php

declare(strict_types=1);

namespace app\services;

use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\Schedule;
use Cron\CronExpression;
use yii\base\Component;

/**
 * Team scoping and execution of schedules.
 *
 * A schedule launches its job template as the user who created it, and it is
 * scoped like that template: seeing a schedule needs view access to the
 * template's project, changing it or pointing it at a template needs operator
 * access. At launch time the creator must still be an active user with
 * job.launch and operator access to the project; otherwise the schedule does
 * not launch.
 *
 * runDue() is called from the `schedule/run` console command, typically every
 * minute by an OS-level cron job:
 *   * * * * * php yii schedule/run
 */
class ScheduleService extends Component
{
    /** The user may point a schedule at the job template. */
    public const TEMPLATE_USABLE = 'usable';

    /**
     * The job template does not exist, is deleted, or the user cannot see
     * it. All three get the same answer, so a request cannot probe for the
     * templates of other teams.
     */
    public const TEMPLATE_MISSING = 'missing';

    /** The user sees the job template but may not launch it. */
    public const TEMPLATE_FORBIDDEN = 'forbidden';

    public const TEMPLATE_MISSING_MESSAGE = 'The selected job template does not exist.';

    /** Audit reason: the creator is not an active user with job.launch. */
    public const DENIED_NOT_PERMITTED = 'not_permitted';

    /** Audit reason: the creator may not operate the template's project. */
    public const DENIED_NO_PROJECT_ACCESS = 'no_project_access';

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
            if (!$this->claimIfDue($schedule)) {
                continue;
            }

            try {
                if ($this->launchSchedule($schedule)) {
                    $launched++;
                }
            } catch (\Throwable $e) {
                \Yii::error(
                    "Schedule #{$schedule->id} ({$schedule->name}) failed to launch: " . $e->getMessage(),
                    __CLASS__
                );
                $this->notifyFailedLaunch($schedule, $e->getMessage());
            }
        }

        return $launched;
    }

    /**
     * View access to a schedule: view access to its job template's project.
     */
    public function canView(int $userId, Schedule $schedule): bool
    {
        return $this->projects()->canViewChildResource($userId, $this->projectIdOf($schedule));
    }

    /**
     * Change, delete or toggle access to a schedule: operator access to its
     * job template's project.
     */
    public function canOperate(int $userId, Schedule $schedule): bool
    {
        return $this->projects()->canOperateChildResource($userId, $this->projectIdOf($schedule));
    }

    /**
     * Whether $userId may point a schedule at job template $templateId: one
     * of the TEMPLATE_* constants. Callers answer TEMPLATE_MISSING like an
     * unknown template and TEMPLATE_FORBIDDEN with 403.
     */
    public function templateAccess(int $userId, int $templateId): string
    {
        /** @var JobTemplate|null $template */
        $template = JobTemplate::findOne($templateId);
        $projects = $this->projects();
        if ($template === null || !$projects->canViewChildResource($userId, (int)$template->project_id)) {
            return self::TEMPLATE_MISSING;
        }

        return $projects->canOperateChildResource($userId, (int)$template->project_id)
            ? self::TEMPLATE_USABLE
            : self::TEMPLATE_FORBIDDEN;
    }

    /**
     * Whether the schedule is due and this runner claimed it. Atomic claim:
     * advance next_run_at only if it hasn't changed since we read it. Prevents
     * duplicate launches when multiple schedule runners are active. A schedule
     * that then cannot launch has moved on as well, so it is not retried on
     * every tick. A schedule whose stored values make the check fail is
     * logged and skipped, so it cannot stop the schedules after it.
     */
    private function claimIfDue(Schedule $schedule): bool
    {
        try {
            return $this->isDue($schedule) && $this->claimSchedule($schedule);
        } catch (\Throwable $e) {
            \Yii::error(
                "Schedule #{$schedule->id} ({$schedule->name}) skipped: " . $e->getMessage(),
                __CLASS__
            );
            return false;
        }
    }

    /**
     * Returns true if the schedule is due now (next_run_at <= now or not yet set).
     * Uses the cron expression + timezone for evaluation.
     */
    private function isDue(Schedule $schedule): bool
    {
        if ($schedule->next_run_at === null) {
            // next_run_at not yet computed — compute and check. Older versions
            // could store an expression the cron library cannot evaluate; it
            // is never due.
            if (!Schedule::isParsableCron((string)$schedule->cron_expression)) {
                return false;
            }
            try {
                $cron = new CronExpression($schedule->cron_expression);
                return $cron->isDue('now', $schedule->timezone ?: 'UTC');
            } catch (\Exception $e) {
                return false;
            }
        }

        return $schedule->next_run_at <= time();
    }

    /**
     * Launch the schedule's job template as the schedule's creator. Returns
     * false when the creator may no longer launch it; the refusal is audited
     * and reported like a failed launch.
     *
     * @throws \RuntimeException when the template is gone or the launch fails
     */
    private function launchSchedule(Schedule $schedule): bool
    {
        $template = $schedule->jobTemplate;
        if ($template === null) {
            throw new \RuntimeException("Job template not found for schedule #{$schedule->id}");
        }

        $launchedBy = (int)$schedule->created_by;
        $denial = $this->launchDenial($launchedBy, $template);
        if ($denial !== null) {
            $this->refuseLaunch($schedule, $template, $denial);
            return false;
        }

        $overrides = [];
        if (!empty($schedule->extra_vars)) {
            $overrides['extra_vars'] = $schedule->extra_vars;
        }

        /** @var JobLaunchService $launcher */
        $launcher = \Yii::$app->get('jobLaunchService');
        $job = $launcher->launch($template, $launchedBy, $overrides);

        \Yii::info(
            "Schedule #{$schedule->id} ({$schedule->name}) launched job #{$job->id}",
            __CLASS__
        );

        return true;
    }

    /**
     * Why $userId may not launch $template without a session, or null when
     * they may: they must be an active user with job.launch and operator
     * access to the template's project.
     */
    private function launchDenial(int $userId, JobTemplate $template): ?string
    {
        /** @var WorkflowAccessChecker $access */
        $access = \Yii::$app->get('workflowAccessChecker');
        if (!$access->isActiveWithPermission($userId, 'job.launch')) {
            return self::DENIED_NOT_PERMITTED;
        }
        $projectId = (int)$template->project_id;

        return $this->projects()->canOperateChildResource($userId, $projectId) ? null : self::DENIED_NO_PROJECT_ACCESS;
    }

    private function refuseLaunch(Schedule $schedule, JobTemplate $template, string $reason): void
    {
        $launchedBy = (int)$schedule->created_by;
        /** @var AuditService $audit */
        $audit = \Yii::$app->get('auditService');
        $audit->log(
            AuditLog::ACTION_SCHEDULE_LAUNCH_DENIED,
            'schedule',
            (int)$schedule->id,
            $launchedBy,
            [
                'job_template_id' => (int)$template->id,
                'project_id' => (int)$template->project_id,
                'reason' => $reason,
            ]
        );

        $message = sprintf(
            'Schedule #%d (%s) not launched: user #%d, whom it runs as, %s job template #%d.',
            $schedule->id,
            $schedule->name,
            $launchedBy,
            $reason === self::DENIED_NOT_PERMITTED
                ? 'is not an active user allowed to launch'
                : 'has no operator access to the project of',
            $template->id
        );
        \Yii::warning($message, __CLASS__);
        $this->notifyFailedLaunch($schedule, $message);
    }

    private function notifyFailedLaunch(Schedule $schedule, string $error): void
    {
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');
        $dispatcher->dispatch(NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH, [
            'schedule' => [
                'id' => (string)$schedule->id,
                'name' => (string)$schedule->name,
                'cron' => (string)$schedule->cron_expression,
                'error' => $error,
            ],
        ]);
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

    /**
     * The project of the schedule's job template. A soft-deleted template
     * keeps its project, so its schedules stay scoped to that project's
     * teams instead of becoming visible to everyone.
     */
    private function projectIdOf(Schedule $schedule): ?int
    {
        $projectId = JobTemplate::findWithDeleted()
            ->select('project_id')
            ->andWhere(['id' => $schedule->job_template_id])
            ->scalar();

        return is_numeric($projectId) ? (int)$projectId : null;
    }

    private function projects(): ProjectAccessChecker
    {
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');
        return $checker;
    }
}
