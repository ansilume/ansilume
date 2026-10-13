<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\JobTemplate;
use app\models\Runner;
use app\models\RunnerGroup;
use app\services\RunnerGroupDeletionService;
use app\tests\integration\DbTestCase;
use yii\log\Logger;

/**
 * A runner group is deleted only while nothing uses it: no runner belongs to
 * it (the foreign key would delete the runners with it) and no job template
 * that is not deleted runs on it (the foreign key would clear its runner
 * group, and no runner would claim its jobs). The web UI and the REST API
 * share this guard; the decision is taken on the group's row, locked as the
 * first statement of the delete's transaction.
 */
class RunnerGroupDeletionServiceTest extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('rgd')->id;
    }

    public function testAGroupNothingUsesIsDeleted(): void
    {
        $group = $this->createRunnerGroup($this->userId);

        $this->assertNull($this->service()->refusal($group));
        $this->assertNull($this->service()->delete($group));

        $this->assertNull(RunnerGroup::findOne($group->id));
    }

    public function testAGroupThatRunnersBelongToIsRefusedAndKeepsThem(): void
    {
        $group = $this->createRunnerGroup($this->userId);
        $runners = [$this->createRunner($group->id, $this->userId), $this->createRunner($group->id, $this->userId)];
        $expected = "Cannot delete \"{$group->name}\": 2 runner(s) still belong to this group.";

        $this->assertSame($expected, $this->service()->refusal($group));
        $this->assertSame($expected, $this->service()->delete($group));
        $this->assertSame(sprintf(RunnerGroupDeletionService::RUNNERS_REFUSED, $group->name, 2), $expected);

        $this->assertNotNull(RunnerGroup::findOne($group->id), 'the group stays');
        foreach ($runners as $runner) {
            $this->assertNotNull(Runner::findOne($runner->id), 'its runners are not deleted with it');
        }
    }

    public function testAGroupThatJobTemplatesRunOnIsRefusedAndTheyKeepIt(): void
    {
        $group = $this->createRunnerGroup($this->userId);
        $template = $this->templateOn($group);
        $expected = "Cannot delete \"{$group->name}\": 1 job template(s) reference this group.";

        $this->assertSame($expected, $this->service()->refusal($group));
        $this->assertSame($expected, $this->service()->delete($group));
        $this->assertSame(sprintf(RunnerGroupDeletionService::TEMPLATES_REFUSED, $group->name, 1), $expected);

        $this->assertNotNull(RunnerGroup::findOne($group->id), 'the group stays');
        $this->assertSame((int)$group->id, (int)JobTemplate::findOne($template->id)?->runner_group_id, 'the template keeps its runner group');
    }

    public function testRunnersAreNamedBeforeTemplates(): void
    {
        $group = $this->createRunnerGroup($this->userId);
        $this->createRunner($group->id, $this->userId);
        $this->templateOn($group);

        $this->assertSame(sprintf(RunnerGroupDeletionService::RUNNERS_REFUSED, $group->name, 1), $this->service()->delete($group));
        $this->assertNotNull(RunnerGroup::findOne($group->id));
    }

    /**
     * A deleted template never runs again: it does not keep the group, and
     * the delete clears its runner group (foreign key ON DELETE SET NULL).
     */
    public function testDeletedTemplatesDoNotKeepTheGroup(): void
    {
        $group = $this->createRunnerGroup($this->userId);
        $template = $this->templateOn($group);
        $this->assertTrue($template->softDelete());

        $this->assertNull($this->service()->delete($group));

        $this->assertNull(RunnerGroup::findOne($group->id));
        $stored = JobTemplate::findWithDeleted()->where(['id' => $template->id])->one();
        $this->assertInstanceOf(JobTemplate::class, $stored);
        $this->assertNull($stored->runner_group_id);
    }

    /**
     * The group's row is locked before anything is counted, as the first
     * statement of the delete's transaction: a runner that registers into
     * the group, or a template moved onto it, meanwhile waits for the lock
     * and then fails on the foreign key, instead of being deleted with the
     * group or losing it.
     */
    public function testTheGroupsRowIsLockedBeforeItIsCountedAndDeleted(): void
    {
        $group = $this->createRunnerGroup($this->userId);

        $statements = $this->statementsOf(fn (): ?string => $this->service()->delete($group));

        $savepoint = $this->indexOf($statements, '~^SAVEPOINT LEVEL\d+$~');
        $lock = $this->indexOf($statements, '~^SELECT `id` FROM `runner_group` WHERE `id` = ' . $group->id . ' FOR UPDATE$~');
        $runners = $this->indexOf($statements, '~^SELECT COUNT\(\*\) FROM `runner` WHERE `runner_group_id`=' . $group->id . '$~');
        $templates = $this->indexOf($statements, '~^SELECT COUNT\(\*\) FROM `job_template` WHERE .*`runner_group_id`=' . $group->id . '~');
        $delete = $this->indexOf($statements, '~^DELETE FROM `runner_group` WHERE `id`=' . $group->id . '$~');
        $this->assertSame($savepoint + 1, $lock, implode("\n", $statements));
        $this->assertGreaterThan($lock, $runners);
        $this->assertGreaterThan($runners, $templates);
        $this->assertGreaterThan($templates, $delete);
    }

    public function testARefusedDeleteDeletesNothing(): void
    {
        $group = $this->createRunnerGroup($this->userId);
        $this->createRunner($group->id, $this->userId);

        $statements = $this->statementsOf(fn (): ?string => $this->service()->delete($group));

        $this->assertSame([], preg_grep('~^DELETE ~', $statements), implode("\n", $statements));
    }

    // -- Helpers ----------------------------------------------------------------------

    private function service(): RunnerGroupDeletionService
    {
        return new RunnerGroupDeletionService();
    }

    private function templateOn(RunnerGroup $group): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$group->id,
            $this->userId
        );
    }

    /**
     * The SQL statements $action runs, in order.
     *
     * @return list<string>
     */
    private function statementsOf(callable $action): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $action();
        } finally {
            \Yii::setLogger($previous);
        }
        $statements = [];
        foreach ($logger->messages as $message) {
            if (
                in_array($message[2], ['yii\db\Command::query', 'yii\db\Command::execute'], true)
                && $message[1] === Logger::LEVEL_INFO
                && is_string($message[0])
            ) {
                $statements[] = $message[0];
            }
        }

        return $statements;
    }

    /**
     * @param list<string> $statements
     */
    private function indexOf(array $statements, string $pattern): int
    {
        foreach ($statements as $index => $statement) {
            if (preg_match($pattern, $statement) === 1) {
                return $index;
            }
        }
        $this->fail("No statement matches {$pattern}:\n" . implode("\n", $statements));
    }
}
