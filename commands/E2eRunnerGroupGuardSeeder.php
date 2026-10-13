<?php

declare(strict_types=1);

namespace app\commands;

use app\models\JobTemplate;
use app\models\Runner;
use app\models\RunnerGroup;

/**
 * The fixtures of the E2E specs for deleting a runner group that is still in
 * use (tests/e2e/tests/runner-groups/delete-in-use.spec.ts and rbac.spec.ts):
 * the web UI refuses the delete as the API does, and the group stays.
 *
 * - e2e-group-with-template: a runner group without runners, which the job
 *   template e2e-group-template uses (on the project and inventory of
 *   e2e-template; no runner ever claims its jobs, and no spec launches it).
 * - e2e-group-with-runner: a runner group without job templates, which the
 *   runner e2e-group-runner belongs to. The runner never connects.
 *
 * Restored by name on every seed, a soft-deleted template too. A delete
 * without the guard removes a group, which clears the runner group of the
 * template and deletes the runner (foreign keys): the next seed brings both
 * groups back with the template and the runner in them. The specs quote the
 * counts, so other runners in either group are deleted: one that a spec
 * moved there and left (runners/move.spec.ts moves its runner into the first
 * other group, which is e2e-group-with-runner in a stack without the
 * "default" group).
 */
final class E2eRunnerGroupGuardSeeder
{
    public const TEMPLATE_GROUP = 'e2e-group-with-template';

    public const TEMPLATE = 'e2e-group-template';

    public const RUNNER_GROUP = 'e2e-group-with-runner';

    public const RUNNER = 'e2e-group-runner';

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Restore both groups, the template and the runner, which $userId
     * (e2e-admin) created; the template on the project and inventory of job
     * template #$jobTemplateId (e2e-template).
     *
     * @throws \RuntimeException when job template #$jobTemplateId does not exist
     */
    public function seed(int $userId, int $jobTemplateId): void
    {
        $source = JobTemplate::findOne($jobTemplateId);
        if ($source === null) {
            throw new \RuntimeException("Job template #{$jobTemplateId} not found.");
        }
        $templateGroup = $this->group(self::TEMPLATE_GROUP, 'E2E fixture: ' . self::TEMPLATE . ' uses it', $userId);
        $template = $this->template($userId, $source, (int)$templateGroup->id);
        $runnerGroup = $this->group(self::RUNNER_GROUP, 'E2E fixture: ' . self::RUNNER . ' belongs to it', $userId);
        $runner = $this->runner($userId, (int)$runnerGroup->id);
        Runner::deleteAll([
            'and',
            ['runner_group_id' => [(int)$templateGroup->id, (int)$runnerGroup->id]],
            ['not', ['id' => (int)$runner->id]],
        ]);

        ($this->logger)(sprintf(
            "  Restored %s (ID %d), which %s (ID %d) uses, and %s (ID %d), which %s (ID %d) belongs to: "
            . "neither group may be deleted.\n",
            self::TEMPLATE_GROUP,
            $templateGroup->id,
            self::TEMPLATE,
            $template->id,
            self::RUNNER_GROUP,
            $runnerGroup->id,
            self::RUNNER,
            $runner->id
        ));
    }

    private function group(string $name, string $description, int $userId): RunnerGroup
    {
        $group = RunnerGroup::findOne(['name' => $name]) ?? new RunnerGroup();
        $group->name = $name;
        $group->description = $description;
        $group->created_by = $userId;
        $group->save(false);

        return $group;
    }

    /**
     * The template, restored in place: a soft-deleted copy comes back under
     * its name instead of a second one.
     */
    private function template(int $userId, JobTemplate $source, int $groupId): JobTemplate
    {
        if (JobTemplate::findOne(['name' => self::TEMPLATE]) === null) {
            /** @var JobTemplate|null $previous */
            $previous = JobTemplate::findWithDeleted()->where(['name' => self::TEMPLATE])->orderBy(['id' => SORT_DESC])->one();
            $previous?->updateAttributes(['deleted_at' => null]);
        }
        $parents = [0 => (int)$source->project_id, 1 => (int)$source->inventory_id, 2 => $groupId];
        $template = E2eFixtureHelper::template(self::TEMPLATE, $parents, $userId, null, []);
        $template->description = 'E2E fixture: keeps the runner group ' . self::TEMPLATE_GROUP . ' in use';
        $template->approval_rule_id = null;
        $template->extra_vars = null;
        $template->survey_fields = null;
        $template->trigger_token = null;
        $template->trigger_token_created_by = null;
        $template->save(false);

        return $template;
    }

    /**
     * The runner, moved back into its group when it is elsewhere; it never
     * connected.
     */
    private function runner(int $userId, int $groupId): Runner
    {
        $runner = Runner::findOne(['name' => self::RUNNER]) ?? new Runner();
        $runner->runner_group_id = $groupId;
        $runner->name = self::RUNNER;
        $runner->description = 'E2E fixture: keeps the runner group ' . self::RUNNER_GROUP . ' in use';
        if ($runner->isNewRecord) {
            // Nobody gets the raw token: the runner never connects.
            $runner->token_hash = Runner::generateToken()['hash'];
        }
        $runner->last_seen_at = null;
        $runner->created_by = $userId;
        $runner->save(false);

        return $runner;
    }
}
