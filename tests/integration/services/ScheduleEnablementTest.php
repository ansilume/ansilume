<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\services\ScheduleEnablement;
use app\services\ScheduleRunAs;
use app\tests\integration\DbTestCase;
use yii\log\Logger;

/**
 * Turning schedules on and off: enabling one whose job template was deleted
 * is refused, and the schedules of deleted templates are disabled. Enabling
 * makes the schedule run as the user who enables it; disabling keeps whom it
 * runs as.
 */
class ScheduleEnablementTest extends DbTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createUser('enablement');
    }

    // -- toggle() -------------------------------------------------------------

    public function testToggleDisablesAnEnabledSchedule(): void
    {
        $schedule = $this->schedule($this->template(), true);

        $this->assertNull((new ScheduleEnablement())->toggle($schedule, (int)$this->user->id));

        $schedule->refresh();
        $this->assertFalse((bool)$schedule->enabled);
        $this->assertNull($schedule->next_run_at);
    }

    public function testToggleEnablesADisabledScheduleOfALiveTemplate(): void
    {
        $schedule = $this->schedule($this->template(), false);

        $enablement = new ScheduleEnablement();
        $this->assertNull($enablement->toggle($schedule, (int)$this->user->id));

        $schedule->refresh();
        $this->assertTrue((bool)$schedule->enabled);
        $this->assertGreaterThan(time(), (int)$schedule->next_run_at, 'the next run is computed');
        $this->assertSame((int)$this->user->id, (int)$schedule->created_by);
        $this->assertSame([], $enablement->runAsContext(), 'it runs as the user who enabled it already');
    }

    // -- toggle(): whom the schedule runs as ------------------------------------

    /**
     * Regression: editing another user's schedule let the editor approve its
     * job. Enabling someone else's disabled schedule decides when it runs, so
     * the user who enables it becomes the user it runs as, its jobs' launcher.
     */
    public function testEnablingAnotherUsersScheduleMakesItRunAsTheUserWhoEnablesIt(): void
    {
        $schedule = $this->schedule($this->template(), false);
        $enabler = $this->createUser('enablement-enabler');

        $enablement = new ScheduleEnablement();
        $this->assertNull($enablement->toggle($schedule, (int)$enabler->id));

        $this->assertSame((int)$enabler->id, (int)$schedule->created_by);
        $stored = Schedule::findOne($schedule->id);
        $this->assertNotNull($stored);
        $this->assertTrue((bool)$stored->enabled);
        $this->assertSame((int)$enabler->id, (int)$stored->created_by);
        $this->assertSame(
            [ScheduleRunAs::AUDIT_KEY => ['from' => (int)$this->user->id, 'to' => (int)$enabler->id]],
            $enablement->runAsContext()
        );
    }

    public function testDisablingAnotherUsersScheduleKeepsWhomItRunsAs(): void
    {
        $schedule = $this->schedule($this->template(), true);
        $other = $this->createUser('enablement-disabler');

        $enablement = new ScheduleEnablement();
        $this->assertNull($enablement->toggle($schedule, (int)$other->id));

        $stored = Schedule::findOne($schedule->id);
        $this->assertNotNull($stored);
        $this->assertFalse((bool)$stored->enabled);
        $this->assertSame((int)$this->user->id, (int)$stored->created_by);
        $this->assertSame([], $enablement->runAsContext());
    }

    /**
     * A refused enable changes nothing, also not whom the schedule runs as,
     * and a run-as change of an earlier toggle is not reported again.
     */
    public function testARefusedEnableKeepsWhomTheScheduleRunsAs(): void
    {
        $other = $this->createUser('enablement-refused');
        $enablement = new ScheduleEnablement();
        $this->assertNull($enablement->toggle($this->schedule($this->template(), false), (int)$other->id));
        $this->assertNotSame([], $enablement->runAsContext());
        $template = $this->template();
        $schedule = $this->schedule($template, false);
        $template->softDelete();

        $this->assertNotNull($enablement->toggle($schedule, (int)$other->id));

        $this->assertSame([], $enablement->runAsContext());
        $stored = Schedule::findOne($schedule->id);
        $this->assertNotNull($stored);
        $this->assertSame((int)$this->user->id, (int)$stored->created_by);
    }

    /**
     * The new user is written by the same update that enables the schedule,
     * under the lock of the template's row.
     */
    public function testEnablingWritesTheNewUserInTheUpdateThatEnablesTheSchedule(): void
    {
        $template = $this->template();
        $schedule = $this->schedule($template, false);
        $enabler = $this->createUser('enablement-statement');

        $statements = $this->statementsOf(fn () => (new ScheduleEnablement())->toggle($schedule, (int)$enabler->id));

        $lock = $this->indexOf($statements, '~^SELECT `id`, `deleted_at` FROM `job_template` WHERE `id` = ' . $template->id . ' FOR UPDATE$~');
        $write = $this->indexOf(
            $statements,
            '~^UPDATE `schedule` SET `enabled`=1(?=[^\n]*`created_by`=' . $enabler->id . '[, ])[^\n]* WHERE `id`=' . $schedule->id . '$~'
        );
        $this->assertLessThan($write, $lock, implode("\n", $statements));
        $updates = array_filter($statements, static fn (string $statement): bool => str_starts_with($statement, 'UPDATE `schedule`'));
        $this->assertCount(1, $updates, 'one update');
    }

    /**
     * Regression: a schedule of a deleted template stayed enabled and could
     * be re-enabled.
     */
    public function testEnablingAScheduleOfADeletedTemplateIsRefusedAndChangesNothing(): void
    {
        $template = $this->template();
        $schedule = $this->schedule($template, false);
        $template->softDelete();
        $updatedAt = (int)$schedule->updated_at;

        $refusal = (new ScheduleEnablement())->toggle($schedule, (int)$this->user->id);

        $this->assertSame(sprintf(ScheduleEnablement::ENABLE_REFUSED, $schedule->name, $template->id), $refusal);
        $stored = Schedule::findOne($schedule->id);
        $this->assertNotNull($stored);
        $this->assertFalse((bool)$stored->enabled);
        $this->assertNull($stored->next_run_at);
        $this->assertSame($updatedAt, (int)$stored->updated_at);
    }

    /**
     * Enabling locks the template's row, as a template delete does before it
     * disables the template's schedules, and writes the schedule under that
     * lock: an enable and a delete cannot cross.
     */
    public function testEnablingLocksTheTemplateRowBeforeItWritesTheSchedule(): void
    {
        $template = $this->template();
        $schedule = $this->schedule($template, false);

        $statements = $this->statementsOf(fn () => (new ScheduleEnablement())->toggle($schedule, (int)$this->user->id));

        $savepoint = $this->indexOf($statements, '~^SAVEPOINT LEVEL\d+$~');
        $lock = $this->indexOf($statements, '~^SELECT `id`, `deleted_at` FROM `job_template` WHERE `id` = ' . $template->id . ' FOR UPDATE$~');
        $write = $this->indexOf($statements, '~^UPDATE `schedule` SET `enabled`=1.* WHERE `id`=' . $schedule->id . '$~s');
        $release = $this->indexOf($statements, '~^RELEASE SAVEPOINT LEVEL\d+$~');
        $this->assertTrue($savepoint < $lock && $lock < $write && $write < $release, implode("\n", $statements));
    }

    // -- disableOfDeletedTemplates() -------------------------------------------

    public function testDisablesOnlyTheEnabledSchedulesOfDeletedTemplates(): void
    {
        $deleted = $this->template();
        $live = $this->template();
        $enabled = $this->schedule($deleted, true);
        $alreadyDisabled = $this->schedule($deleted, false);
        $ofLiveTemplate = $this->schedule($live, true);
        $deleted->softDelete();

        $disabled = (new ScheduleEnablement())->disableOfDeletedTemplates(['job_template_id' => [(int)$deleted->id, (int)$live->id]]);

        $this->assertSame([['id' => (int)$enabled->id, 'name' => $enabled->name, 'job_template_id' => (int)$deleted->id]], $disabled);
        $enabled->refresh();
        $this->assertFalse((bool)$enabled->enabled);
        $this->assertNull($enabled->next_run_at);
        $alreadyDisabled->refresh();
        $this->assertFalse((bool)$alreadyDisabled->enabled);
        $ofLiveTemplate->refresh();
        $this->assertTrue((bool)$ofLiveTemplate->enabled, 'a schedule of a live template stays enabled');
        $this->assertNotNull($ofLiveTemplate->next_run_at);
        $this->assertSame(0, $this->toggleAuditCount((int)$enabled->id), 'auditing is the caller\'s choice');
    }

    public function testWithoutAFilterEveryDeletedTemplateIsCovered(): void
    {
        $first = $this->template();
        $second = $this->template();
        $a = $this->schedule($first, true);
        $b = $this->schedule($second, true);
        $first->softDelete();
        $second->softDelete();

        $ids = array_column((new ScheduleEnablement())->disableOfDeletedTemplates(), 'id');

        $this->assertContains((int)$a->id, $ids);
        $this->assertContains((int)$b->id, $ids);
        $this->assertSame([], (new ScheduleEnablement())->disableOfDeletedTemplates(['id' => [(int)$a->id, (int)$b->id]]), 'a second call finds nothing');
    }

    /**
     * A schedule another process disables after this call read it is left
     * out: its conditional update changes nothing, and it is not reported
     * (so it is not audited twice).
     */
    public function testAScheduleDisabledAfterItWasReadIsLeftOut(): void
    {
        $template = $this->template();
        $first = $this->schedule($template, true);
        $second = $this->schedule($template, true);
        $template->softDelete();
        $logger = new class ((int)$second->id) extends Logger {
            private bool $done = false;

            public function __construct(private readonly int $scheduleId)
            {
                parent::__construct();
                $this->flushInterval = PHP_INT_MAX;
            }

            /**
             * Before the update of the second schedule runs, "another
             * process" disables it.
             *
             * @param array<mixed>|string|\Throwable $message
             * @param int $level
             * @param string $category
             */
            public function log($message, $level, $category = 'application'): void
            {
                parent::log($message, $level, $category);
                if (!$this->done && is_string($message) && str_starts_with($message, 'UPDATE `schedule`') && str_contains($message, '`id`=' . $this->scheduleId . ')')) {
                    $this->done = true;
                    Schedule::updateAll(['enabled' => 0], ['id' => $this->scheduleId]);
                }
            }
        };
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $disabled = (new ScheduleEnablement())->disableOfDeletedTemplates(['job_template_id' => (int)$template->id]);
        } finally {
            \Yii::setLogger($previous);
        }

        $this->assertSame([(int)$first->id], array_column($disabled, 'id'));
        $second->refresh();
        $this->assertFalse((bool)$second->enabled, 'the other process disabled it');
    }

    public function testEachScheduleIsDisabledWithAConditionalUpdate(): void
    {
        $template = $this->template();
        $schedule = $this->schedule($template, true);
        $template->softDelete();

        $statements = $this->statementsOf(fn () => (new ScheduleEnablement())->disableOfDeletedTemplates(['id' => (int)$schedule->id]));

        $update = '~^UPDATE `schedule` SET `enabled`=0, `next_run_at`=NULL, `updated_at`=\d+ WHERE \(`id`=' . $schedule->id . '\) AND \(`enabled` <> 0\)$~';
        $this->assertGreaterThanOrEqual(0, $this->indexOf($statements, $update));
    }

    // -- disableForDeletedTemplate() --------------------------------------------

    public function testTheScheduleRunDisablesTheScheduleOfADeletedTemplateAsTheSystem(): void
    {
        $template = $this->template();
        $schedule = $this->schedule($template, true);
        $template->softDelete();

        $this->assertTrue((new ScheduleEnablement())->disableForDeletedTemplate($schedule));

        $schedule->refresh();
        $this->assertFalse((bool)$schedule->enabled);
        $entry = $this->onlyToggleAudit((int)$schedule->id);
        $this->assertNull($entry->user_id);
        $this->assertSame(
            ['name' => $schedule->name, 'enabled' => false, 'reason' => ScheduleEnablement::REASON_TEMPLATE_DELETED, 'job_template_id' => (int)$template->id],
            json_decode((string)$entry->metadata, true)
        );
    }

    /**
     * A schedule another process disabled after the run loaded it: this run
     * changes nothing and writes no audit entry, so it reports nothing.
     */
    public function testAScheduleDisabledMeanwhileIsLeftAlone(): void
    {
        $template = $this->template();
        $schedule = $this->schedule($template, true);
        $template->softDelete();
        Schedule::updateAll(['enabled' => 0], ['id' => $schedule->id]);

        $this->assertFalse((new ScheduleEnablement())->disableForDeletedTemplate($schedule));

        $this->assertSame(0, $this->toggleAuditCount((int)$schedule->id));
    }

    public function testAScheduleOfALiveTemplateIsNotDisabled(): void
    {
        $schedule = $this->schedule($this->template(), true);

        $this->assertFalse((new ScheduleEnablement())->disableForDeletedTemplate($schedule));

        $schedule->refresh();
        $this->assertTrue((bool)$schedule->enabled);
    }

    // -- auditDisabled() --------------------------------------------------------

    public function testAuditDisabledNamesTheActor(): void
    {
        $actor = $this->createUser('enablement-actor');

        (new ScheduleEnablement())->auditDisabled([['id' => 424242, 'name' => 'nightly', 'job_template_id' => 77]], (int)$actor->id);

        $entry = $this->onlyToggleAudit(424242);
        $this->assertSame((int)$actor->id, (int)$entry->user_id);
        $this->assertSame(
            ['name' => 'nightly', 'enabled' => false, 'reason' => 'template_deleted', 'job_template_id' => 77],
            json_decode((string)$entry->metadata, true)
        );
    }

    // -- helpers ----------------------------------------------------------------

    private function template(): JobTemplate
    {
        $userId = (int)$this->user->id;

        return $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    private function schedule(JobTemplate $template, bool $enabled): Schedule
    {
        $schedule = new Schedule();
        $schedule->name = 'enablement-' . uniqid('', true);
        $schedule->job_template_id = (int)$template->id;
        $schedule->cron_expression = '0 2 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = $enabled;
        $schedule->created_by = (int)$this->user->id;
        $schedule->created_at = time() - 100;
        $schedule->updated_at = time() - 100;
        if ($enabled) {
            $schedule->computeNextRunAt();
        }
        $schedule->save(false);

        return $schedule;
    }

    private function toggleAuditCount(int $scheduleId): int
    {
        return (int)AuditLog::find()->where([
            'action' => AuditLog::ACTION_SCHEDULE_TOGGLED,
            'object_type' => 'schedule',
            'object_id' => $scheduleId,
        ])->count();
    }

    private function onlyToggleAudit(int $scheduleId): AuditLog
    {
        /** @var list<AuditLog> $entries */
        $entries = AuditLog::find()->where([
            'action' => AuditLog::ACTION_SCHEDULE_TOGGLED,
            'object_type' => 'schedule',
            'object_id' => $scheduleId,
        ])->all();
        $this->assertCount(1, $entries);

        return $entries[0];
    }

    /**
     * The SQL statements $action sends, in order.
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
