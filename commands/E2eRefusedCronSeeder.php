<?php

declare(strict_types=1);

namespace app\commands;

use app\models\Schedule;

/**
 * The fixtures of the E2E spec for schedules whose stored cron expression a
 * save refuses (tests/e2e/tests/schedules/refused-cron.spec.ts): versions up
 * to 2.12.2 saved such expressions, which the schedules list, the
 * schedule's page, its edit form and the API now name with the reason.
 *
 * - e2e-refused-cron-schedule: e2e-admin's disabled schedule of
 *   e2e-template with 0 18,6 * * *, an hour list the scheduler walks out of
 *   order; the specs only look at it.
 * - e2e-refused-cron-fix-schedule: the same; a spec changes its expression
 *   as the reason says.
 *
 * e2e-operator's team operates e2e-template's project, so e2e-operator sees
 * and changes them too. Both are stored without the cron rules, as those
 * versions stored them, and restored by name on every seed: disabled,
 * without a next run, extra vars or password answers, so they never come due
 * while the specs run.
 */
final class E2eRefusedCronSeeder
{
    public const SCHEDULE = 'e2e-refused-cron-schedule';

    public const FIX_SCHEDULE = 'e2e-refused-cron-fix-schedule';

    /** A list of hours the scheduler walks out of order: it ran at 18:00 only. */
    public const CRON = '0 18,6 * * *';

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Restore both schedules of job template #$templateId (e2e-template),
     * running as $userId (e2e-admin).
     */
    public function seed(int $userId, int $templateId): void
    {
        foreach ([self::SCHEDULE, self::FIX_SCHEDULE] as $name) {
            $schedule = $this->restore($name, $userId, $templateId);
            ($this->logger)(sprintf(
                "  Restored %s (ID %d): disabled, with the cron expression %s, which a save refuses.\n",
                $name,
                $schedule->id,
                self::CRON
            ));
        }
    }

    private function restore(string $name, int $userId, int $templateId): Schedule
    {
        $schedule = Schedule::findOne(['name' => $name]) ?? new Schedule();
        $schedule->name = $name;
        $schedule->job_template_id = $templateId;
        $schedule->cron_expression = self::CRON;
        $schedule->timezone = 'UTC';
        $schedule->extra_vars = null;
        $schedule->setSealedSecretExtraVars(null);
        $schedule->enabled = false;
        $schedule->last_run_at = null;
        $schedule->next_run_at = null;
        $schedule->created_by = $userId;
        // Without the cron rules, as versions up to 2.12.2 saved it.
        $schedule->save(false);

        return $schedule;
    }
}
