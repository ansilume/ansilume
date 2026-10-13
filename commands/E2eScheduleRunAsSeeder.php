<?php

declare(strict_types=1);

namespace app\commands;

use app\models\Job;
use app\models\JobTemplate;
use app\models\Schedule;
use yii\db\Query;

/**
 * The fixtures of the E2E spec for whom a schedule runs as
 * (tests/e2e/tests/schedules/runs-as.spec.ts): a schedule runs as the user
 * who last saved what or when it runs, or enabled it.
 *
 * - e2e-run-as-template: gated by e2e-approval-rule, whose approvers are
 *   e2e-admin and e2e-operator, on the project, inventory and runner group of
 *   e2e-template, which e2e-operator's team operates. Every seed deletes its
 *   jobs, and their approval requests with them: the spec fires the schedule
 *   and gets a new job every time.
 * - e2e-run-as-schedule: e2e-admin's disabled schedule of that template,
 *   yearly at midnight on 1 January (UTC), without extra vars, so it never
 *   comes due while the specs run. Every seed gives it back to e2e-admin.
 *
 * Restored by name on every seed, a soft-deleted template too.
 */
final class E2eScheduleRunAsSeeder
{
    public const TEMPLATE = 'e2e-run-as-template';
    public const SCHEDULE = 'e2e-run-as-schedule';

    /** Yearly: the schedule never comes due by itself while the specs run. */
    public const CRON = '0 0 1 1 *';

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Restore the template, gated by the rule #$ruleId (e2e-approval-rule),
     * on the parents of job template #$jobTemplateId (e2e-template), without
     * jobs, and the disabled schedule of it that $userId (e2e-admin) owns.
     *
     * @throws \RuntimeException when job template #$jobTemplateId does not exist
     */
    public function seed(int $userId, int $jobTemplateId, int $ruleId): void
    {
        $template = $this->template($userId, $jobTemplateId, $ruleId);
        $this->deleteJobs((int)$template->id);
        $schedule = $this->schedule($userId, (int)$template->id);

        ($this->logger)(sprintf(
            "  Restored %s (ID %d) of %s (ID %d), gated by approval rule #%d: disabled, runs as user #%d.\n",
            self::SCHEDULE,
            $schedule->id,
            self::TEMPLATE,
            $template->id,
            $ruleId,
            $userId
        ));
    }

    /**
     * The gated template, restored by name: a soft-deleted copy comes back
     * instead of a second one.
     *
     * @throws \RuntimeException when job template #$jobTemplateId does not exist
     */
    private function template(int $userId, int $jobTemplateId, int $ruleId): JobTemplate
    {
        $source = JobTemplate::findOne($jobTemplateId);
        if ($source === null) {
            throw new \RuntimeException("Job template #{$jobTemplateId} not found.");
        }
        if (JobTemplate::findOne(['name' => self::TEMPLATE]) === null) {
            /** @var JobTemplate|null $deleted */
            $deleted = JobTemplate::findWithDeleted()->where(['name' => self::TEMPLATE])->orderBy(['id' => SORT_DESC])->one();
            $deleted?->updateAttributes(['deleted_at' => null]);
        }
        $parents = [0 => (int)$source->project_id, 1 => (int)$source->inventory_id, 2 => (int)$source->runner_group_id];
        $template = E2eFixtureHelper::template(self::TEMPLATE, $parents, $userId, null, []);
        $template->description = 'E2E fixture: e2e-run-as-schedule launches it; every launch waits for approval';
        $template->approval_rule_id = $ruleId;
        $template->extra_vars = null;
        $template->survey_fields = null;
        $template->limit = null;
        $template->trigger_token = null;
        $template->trigger_token_created_by = null;
        $template->save(false);

        return $template;
    }

    /**
     * Deletes the jobs of the template: logs, artifacts and approval requests
     * go with them (foreign keys), tasks do not.
     */
    private function deleteJobs(int $templateId): void
    {
        $db = \Yii::$app->db;
        $jobIds = (new Query())->select('id')->from('{{%job}}')->where(['job_template_id' => $templateId])->column($db);
        if ($jobIds !== []) {
            $db->createCommand()->delete('{{%job_task}}', ['job_id' => $jobIds])->execute();
            Job::deleteAll(['id' => $jobIds]);
        }
    }

    /**
     * The schedule, restored by name: disabled, owned by $userId, without
     * extra vars, password answers or runs.
     */
    private function schedule(int $userId, int $templateId): Schedule
    {
        $schedule = Schedule::findOne(['name' => self::SCHEDULE]) ?? new Schedule();
        $schedule->name = self::SCHEDULE;
        $schedule->job_template_id = $templateId;
        $schedule->cron_expression = self::CRON;
        $schedule->timezone = 'UTC';
        $schedule->extra_vars = null;
        $schedule->setSealedSecretExtraVars(null);
        $schedule->enabled = false;
        $schedule->last_run_at = null;
        $schedule->next_run_at = null;
        $schedule->created_by = $userId;
        $schedule->save(false);

        return $schedule;
    }
}
