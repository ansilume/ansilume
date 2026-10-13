<?php

declare(strict_types=1);

namespace app\services;

use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\Schedule;

/**
 * Turning schedules on and off. A schedule whose job template was deleted
 * can never launch again, so it is disabled (when its template is deleted,
 * by the maintenance sweep, and by the schedule run that finds it), and
 * enabling it is refused.
 *
 * Enabling locks the job template's row (SELECT ... FOR UPDATE) while it
 * checks the template and enables the schedule, as a template delete locks
 * it before it disables the template's schedules (JobTemplateDeletionService):
 * an enable either commits before the delete, whose disable then finds the
 * schedule, or sees the template deleted.
 *
 * Enabling arms the schedule: it runs as the user who enables it from then
 * on (ScheduleRunAs). Disabling keeps the user it runs as.
 */
class ScheduleEnablement
{
    /** sprintf(): the schedule's name, its job template's ID. */
    public const ENABLE_REFUSED = 'Schedule "%s" was not enabled: its job template #%d was deleted. '
        . 'Edit the schedule to choose another job template, or delete it.';

    /** Audit reason of a schedule disabled because its job template was deleted. */
    public const REASON_TEMPLATE_DELETED = 'template_deleted';

    /** Reads a job template's row and locks it until the transaction ends. */
    private const TEMPLATE_LOCK_SQL = 'SELECT [[id]], [[deleted_at]] FROM {{%job_template}} WHERE [[id]] = :id FOR UPDATE';

    /** @var array{run_as?: array{from: int, to: int}} */
    private array $runAsContext = [];

    /**
     * Disable an enabled schedule, which keeps the user it runs as, or
     * enable a disabled one: its next run is computed, and it runs as
     * $actorId from then on. Enabling one whose job template was deleted is
     * refused and changes nothing.
     *
     * @param int $actorId the user who toggles the schedule
     * @return string|null ENABLE_REFUSED for a schedule of a deleted job
     *     template; null when the schedule was toggled
     */
    public function toggle(Schedule $schedule, int $actorId): ?string
    {
        $this->runAsContext = [];
        if ((bool)$schedule->enabled) {
            $schedule->enabled = false;
            $schedule->next_run_at = null;
            $schedule->save(false, ['enabled', 'next_run_at', 'updated_at']);

            return null;
        }

        /** @var string|null $refusal */
        $refusal = Schedule::getDb()->transaction(fn (): ?string => $this->enableLocked($schedule, $actorId));

        return $refusal;
    }

    /**
     * For the schedule.toggled audit entry of the last toggle(): run_as
     * (ScheduleRunAs::AUDIT_KEY) with the user the schedule ran as and the
     * one who enabled it, or [] when the user it runs as did not change.
     *
     * @return array{run_as?: array{from: int, to: int}}
     */
    public function runAsContext(): array
    {
        return $this->runAsContext;
    }

    /**
     * Disable the enabled schedules of soft-deleted job templates, only
     * those that match $filter when one is given (a condition on schedule
     * columns, such as ['job_template_id' => 5]). Each one is disabled with a
     * conditional update (still enabled), so a schedule another process
     * disabled first is left out.
     *
     * @param array<string, mixed> $filter
     * @return list<array{id: int, name: string, job_template_id: int}> the schedules this call disabled
     */
    public function disableOfDeletedTemplates(array $filter = []): array
    {
        $query = Schedule::find()
            ->select(['id', 'name', 'job_template_id'])
            ->where(['<>', 'enabled', 0])
            ->andWhere(['job_template_id' => JobTemplate::findWithDeleted()->select('id')->where(['not', ['deleted_at' => null]])])
            ->orderBy(['id' => SORT_ASC])
            ->asArray();
        if ($filter !== []) {
            $query->andWhere($filter);
        }

        $disabled = [];
        foreach ($query->all() as $row) {
            /** @var array{id: int|string, name: string, job_template_id: int|string} $row */
            $id = (int)$row['id'];
            $changed = Schedule::updateAll(
                ['enabled' => 0, 'next_run_at' => null, 'updated_at' => time()],
                ['and', ['id' => $id], ['<>', 'enabled', 0]]
            );
            if ($changed === 1) {
                $disabled[] = ['id' => $id, 'name' => (string)$row['name'], 'job_template_id' => (int)$row['job_template_id']];
            }
        }

        return $disabled;
    }

    /**
     * Disable $schedule, whose job template was deleted, for the schedule
     * run that found it due; audited as schedule.toggled by the system.
     *
     * @return bool whether this call disabled it; false when it was not
     *     enabled any more (another process came first) or its job template
     *     is not deleted
     */
    public function disableForDeletedTemplate(Schedule $schedule): bool
    {
        $disabled = $this->disableOfDeletedTemplates(['id' => (int)$schedule->id]);
        $this->auditDisabled($disabled, null);

        return $disabled !== [];
    }

    /**
     * schedule.toggled for each schedule disableOfDeletedTemplates()
     * disabled, with the reason and the job template.
     *
     * @param list<array{id: int, name: string, job_template_id: int}> $disabled
     * @param int|null $actorId the user who deleted the template; null for
     *     the system: the maintenance sweep or the schedule run
     */
    public function auditDisabled(array $disabled, ?int $actorId): void
    {
        /** @var AuditService $audit */
        $audit = \Yii::$app->get('auditService');
        foreach ($disabled as $schedule) {
            $audit->log(AuditLog::ACTION_SCHEDULE_TOGGLED, 'schedule', $schedule['id'], $actorId, [
                'name' => $schedule['name'],
                'enabled' => false,
                'reason' => self::REASON_TEMPLATE_DELETED,
                'job_template_id' => $schedule['job_template_id'],
            ]);
        }
    }

    /**
     * Runs inside the transaction: enable $schedule when its job template's
     * row, locked first, is there and not deleted. The same update makes
     * $actorId the user it runs as.
     */
    private function enableLocked(Schedule $schedule, int $actorId): ?string
    {
        $templateId = (int)$schedule->job_template_id;
        $row = Schedule::getDb()->createCommand(self::TEMPLATE_LOCK_SQL, [':id' => $templateId])->queryOne();
        if (!is_array($row) || $row['deleted_at'] !== null) {
            return sprintf(self::ENABLE_REFUSED, $schedule->name, $templateId);
        }

        $runAs = new ScheduleRunAs($schedule);
        $schedule->enabled = true;
        $schedule->computeNextRunAt();
        $runAs->claim($actorId);
        $schedule->save(false, ['enabled', 'next_run_at', 'updated_at', 'created_by']);
        $this->runAsContext = $runAs->auditContext();

        return null;
    }
}
