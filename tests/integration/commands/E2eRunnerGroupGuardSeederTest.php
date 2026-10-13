<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eRunnerGroupGuardSeeder;
use app\commands\E2eTeardownHelper;
use app\models\JobTemplate;
use app\models\Runner;
use app\models\RunnerGroup;
use app\services\RunnerGroupDeletionService;
use app\tests\integration\DbTestCase;

/**
 * The fixtures of the E2E specs for deleting a runner group that is in use
 * (E2eRunnerGroupGuardSeeder, tests/e2e/tests/runner-groups/
 * delete-in-use.spec.ts and rbac.spec.ts). The specs find them by these
 * names; the test database holds no "e2e-" rows outside a test, and the
 * rollback removes them.
 */
class E2eRunnerGroupGuardSeederTest extends DbTestCase
{
    private int $adminId;

    /** Stands for e2e-template, whose project and inventory the fixture's template uses. */
    private JobTemplate $parent;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->createUser('admin');
        $admin->username = 'e2e-admin';
        $admin->is_superadmin = true;
        $admin->save(false);
        $this->adminId = (int)$admin->id;
        $this->parent = $this->createJobTemplate(
            (int)$this->createProject($this->adminId)->id,
            (int)$this->createInventory($this->adminId)->id,
            (int)$this->createRunnerGroup($this->adminId)->id,
            $this->adminId
        );
        $this->log = [];
    }

    /**
     * One group without runners that the template uses, one group without
     * templates that the runner belongs to; the runner never connected and
     * nobody holds its token.
     */
    public function testEachGroupIsInUseByExactlyOneTemplateOrRunner(): void
    {
        $this->seed();

        $templateGroup = $this->group(E2eRunnerGroupGuardSeeder::TEMPLATE_GROUP);
        $runnerGroup = $this->group(E2eRunnerGroupGuardSeeder::RUNNER_GROUP);
        $template = $this->template();
        $runner = $this->runner();

        $this->assertSame(0, (int)$templateGroup->getRunners()->count());
        $this->assertSame([(int)$template->id], $this->templateIdsOf($templateGroup));
        $this->assertSame(
            [(int)$this->parent->project_id, (int)$this->parent->inventory_id],
            [(int)$template->project_id, (int)$template->inventory_id]
        );
        $this->assertNull($template->approval_rule_id);
        $this->assertNull($template->trigger_token);
        $this->assertNull($template->deleted_at);

        $this->assertSame([(int)$runner->id], $this->runnerIdsOf($runnerGroup));
        $this->assertSame([], $this->templateIdsOf($runnerGroup));
        $this->assertNull($runner->last_seen_at, 'never connected');
        $this->assertFalse($runner->isOnline());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)$runner->token_hash);

        foreach ([$templateGroup, $runnerGroup] as $group) {
            $this->assertSame($this->adminId, (int)$group->created_by);
        }
        $this->assertSame($this->adminId, (int)$template->created_by);
        $this->assertSame($this->adminId, (int)$runner->created_by);
        $this->assertSame([
            sprintf(
                "  Restored e2e-group-with-template (ID %d), which e2e-group-template (ID %d) uses, and "
                . "e2e-group-with-runner (ID %d), which e2e-group-runner (ID %d) belongs to: neither group may be deleted.\n",
                $templateGroup->id,
                $template->id,
                $runnerGroup->id,
                $runner->id
            ),
        ], $this->log);
    }

    /**
     * What the spec's delete gets: each group is refused, with the texts the
     * spec quotes, and nothing is deleted.
     */
    public function testTheDeleteOfEitherGroupIsRefused(): void
    {
        $this->seed();
        $service = new RunnerGroupDeletionService();

        $this->assertSame(
            'Cannot delete "e2e-group-with-template": 1 job template(s) reference this group.',
            $service->delete($this->group(E2eRunnerGroupGuardSeeder::TEMPLATE_GROUP))
        );
        $this->assertSame(
            'Cannot delete "e2e-group-with-runner": 1 runner(s) still belong to this group.',
            $service->delete($this->group(E2eRunnerGroupGuardSeeder::RUNNER_GROUP))
        );

        $this->assertSame((int)$this->group(E2eRunnerGroupGuardSeeder::TEMPLATE_GROUP)->id, (int)$this->template()->runner_group_id);
        $this->assertSame((int)$this->group(E2eRunnerGroupGuardSeeder::RUNNER_GROUP)->id, (int)$this->runner()->runner_group_id);
    }

    /**
     * A delete without the guard (as before the fix) removes both groups:
     * the template loses its runner group and the runner goes with its group
     * (foreign keys). The next seed brings everything back, the template in
     * place.
     */
    public function testSeedingAgainRestoresWhatAnUnguardedDeleteRemoved(): void
    {
        $this->seed();
        $templateId = (int)$this->template()->id;
        RunnerGroup::deleteAll(['name' => [E2eRunnerGroupGuardSeeder::TEMPLATE_GROUP, E2eRunnerGroupGuardSeeder::RUNNER_GROUP]]);
        $template = $this->template();
        $this->assertNull($template->runner_group_id, 'the foreign key cleared it');
        $this->assertNull(Runner::findOne(['name' => E2eRunnerGroupGuardSeeder::RUNNER]), 'the foreign key deleted it');

        $this->seed();

        $this->assertSame($templateId, (int)$this->template()->id, 'restored in place');
        $this->assertSame([$templateId], $this->templateIdsOf($this->group(E2eRunnerGroupGuardSeeder::TEMPLATE_GROUP)));
        $this->assertSame([(int)$this->runner()->id], $this->runnerIdsOf($this->group(E2eRunnerGroupGuardSeeder::RUNNER_GROUP)));
        $this->assertCount(2, $this->log);
    }

    /**
     * Whatever happened to the fixtures in between, every seed restores them
     * in place: a soft-deleted template on another group, a runner moved to
     * another group with a different description, groups described
     * differently.
     */
    public function testSeedingAgainRestoresChangedFixturesInPlace(): void
    {
        $this->seed();
        $ids = [
            (int)$this->group(E2eRunnerGroupGuardSeeder::TEMPLATE_GROUP)->id,
            (int)$this->group(E2eRunnerGroupGuardSeeder::RUNNER_GROUP)->id,
            (int)$this->template()->id,
            (int)$this->runner()->id,
        ];
        $tokenHash = (string)$this->runner()->token_hash;
        $elsewhere = (int)$this->parent->runner_group_id;
        RunnerGroup::updateAll(['description' => 'changed'], ['id' => [$ids[0], $ids[1]]]);
        JobTemplate::updateAll(['runner_group_id' => $elsewhere, 'deleted_at' => time(), 'trigger_token' => 'stale'], ['id' => $ids[2]]);
        Runner::updateAll(['runner_group_id' => $elsewhere, 'description' => 'changed', 'last_seen_at' => time()], ['id' => $ids[3]]);

        $this->seed();

        $templateGroup = $this->group(E2eRunnerGroupGuardSeeder::TEMPLATE_GROUP);
        $runnerGroup = $this->group(E2eRunnerGroupGuardSeeder::RUNNER_GROUP);
        $template = $this->template();
        $runner = $this->runner();
        $this->assertSame($ids, [(int)$templateGroup->id, (int)$runnerGroup->id, (int)$template->id, (int)$runner->id]);
        $this->assertSame(1, (int)JobTemplate::findWithDeleted()->where(['name' => E2eRunnerGroupGuardSeeder::TEMPLATE])->count());
        $this->assertNull($template->deleted_at);
        $this->assertNull($template->trigger_token);
        $this->assertSame([$ids[2]], $this->templateIdsOf($templateGroup));
        $this->assertSame([$ids[3]], $this->runnerIdsOf($runnerGroup));
        $this->assertNull($runner->last_seen_at);
        $this->assertSame($tokenHash, (string)$runner->token_hash, 'the token hash stays');
        $this->assertSame('E2E fixture: e2e-group-template uses it', $templateGroup->description);
        $this->assertSame('E2E fixture: e2e-group-runner belongs to it', $runnerGroup->description);
        $this->assertSame('E2E fixture: keeps the runner group e2e-group-with-runner in use', $runner->description);
        $other = $this->group($this->groupName($elsewhere));
        $this->assertSame([(int)$this->parent->id], $this->templateIdsOf($other), 'only the parent is left there');
        $this->assertSame([], $this->runnerIdsOf($other));
    }

    /**
     * The specs quote how many runners use each group: a runner that another
     * spec moved into one of them and left there is gone after the next
     * seed. Runners of other groups stay.
     */
    public function testSeedingAgainDeletesOtherRunnersOfBothGroups(): void
    {
        $this->seed();
        $templateGroup = $this->group(E2eRunnerGroupGuardSeeder::TEMPLATE_GROUP);
        $runnerGroup = $this->group(E2eRunnerGroupGuardSeeder::RUNNER_GROUP);
        $strayInTemplateGroup = $this->createRunner((int)$templateGroup->id, $this->adminId);
        $strayInRunnerGroup = $this->createRunner((int)$runnerGroup->id, $this->adminId);
        $elsewhere = $this->createRunner((int)$this->parent->runner_group_id, $this->adminId);

        $this->seed();

        $this->assertNull(Runner::findOne($strayInTemplateGroup->id));
        $this->assertNull(Runner::findOne($strayInRunnerGroup->id));
        $this->assertSame([], $this->runnerIdsOf($templateGroup));
        $this->assertSame([(int)$this->runner()->id], $this->runnerIdsOf($runnerGroup));
        $this->assertNotNull(Runner::findOne($elsewhere->id), 'a runner of another group stays');
    }

    public function testAMissingParentTemplateIsReported(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Job template #2147483647 not found.');

        $this->seeder()->seed($this->adminId, 2147483647);
    }

    /**
     * yii e2e/teardown removes both groups, the template and the runner.
     */
    public function testTheTeardownRemovesBothGroupsTheTemplateAndTheRunner(): void
    {
        $this->seed();

        $problems = (new E2eTeardownHelper('e2e-', static function (string $msg): void {
        }))->teardownDatabase();

        $this->assertSame([], $problems);
        $this->assertNull(RunnerGroup::findOne(['name' => E2eRunnerGroupGuardSeeder::TEMPLATE_GROUP]));
        $this->assertNull(RunnerGroup::findOne(['name' => E2eRunnerGroupGuardSeeder::RUNNER_GROUP]));
        $this->assertSame(0, (int)JobTemplate::findWithDeleted()->where(['name' => E2eRunnerGroupGuardSeeder::TEMPLATE])->count());
        $this->assertNull(Runner::findOne(['name' => E2eRunnerGroupGuardSeeder::RUNNER]));
    }

    private function seed(): void
    {
        $this->seeder()->seed($this->adminId, (int)$this->parent->id);
    }

    private function seeder(): E2eRunnerGroupGuardSeeder
    {
        return new E2eRunnerGroupGuardSeeder(function (string $msg): void {
            $this->log[] = $msg;
        });
    }

    private function group(string $name): RunnerGroup
    {
        $group = RunnerGroup::findOne(['name' => $name]);
        $this->assertInstanceOf(RunnerGroup::class, $group, "runner group {$name}");

        return $group;
    }

    private function groupName(int $id): string
    {
        $group = RunnerGroup::findOne($id);
        $this->assertInstanceOf(RunnerGroup::class, $group, "runner group #{$id}");

        return $group->name;
    }

    private function template(): JobTemplate
    {
        $template = JobTemplate::findWithDeleted()->where(['name' => E2eRunnerGroupGuardSeeder::TEMPLATE])->one();
        $this->assertInstanceOf(JobTemplate::class, $template);

        return $template;
    }

    private function runner(): Runner
    {
        $runner = Runner::findOne(['name' => E2eRunnerGroupGuardSeeder::RUNNER]);
        $this->assertInstanceOf(Runner::class, $runner);

        return $runner;
    }

    /**
     * The live job templates on $group (as the delete guard counts them).
     *
     * @return list<int>
     */
    private function templateIdsOf(RunnerGroup $group): array
    {
        return array_values(array_map('intval', $group->getJobTemplates()->select('id')->orderBy('id')->column()));
    }

    /**
     * @return list<int>
     */
    private function runnerIdsOf(RunnerGroup $group): array
    {
        return array_values(array_map('intval', $group->getRunners()->select('id')->orderBy('id')->column()));
    }
}
