<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eRefusedCronSeeder;
use app\components\CronExpressionRules;
use app\models\JobTemplate;
use app\models\Schedule;
use app\tests\integration\DbTestCase;

/**
 * The fixtures of the E2E spec for schedules whose stored cron expression a
 * save refuses (E2eRefusedCronSeeder,
 * tests/e2e/tests/schedules/refused-cron.spec.ts). The specs find them by
 * these names; the test database holds no "e2e-" rows outside a test, and
 * the rollback removes them.
 */
class E2eRefusedCronSeederTest extends DbTestCase
{
    private int $userId;

    /** Stands for e2e-template. */
    private JobTemplate $template;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('e2e-refused-cron')->id;
        $this->template = $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
        $this->log = [];
    }

    public function testSeedStoresBothSchedulesWithAnExpressionASaveRefuses(): void
    {
        $this->seeder()->seed($this->userId, (int)$this->template->id);

        foreach ([E2eRefusedCronSeeder::SCHEDULE, E2eRefusedCronSeeder::FIX_SCHEDULE] as $name) {
            $schedule = Schedule::findOne(['name' => $name]);
            $this->assertNotNull($schedule, $name);
            $this->assertSame(E2eRefusedCronSeeder::CRON, $schedule->cron_expression);
            $this->assertSame(sprintf(CronExpressionRules::LIST_ORDER, '18,6', 'hour', '6,18'), $schedule->cronExpressionProblem());
            $this->assertSame((int)$this->template->id, (int)$schedule->job_template_id);
            $this->assertSame($this->userId, (int)$schedule->created_by);
            $this->assertFalse((bool)$schedule->enabled, 'never due while the specs run');
            $this->assertNull($schedule->next_run_at);
            $this->assertSame('UTC', $schedule->timezone);
        }
        $this->assertSame([
            sprintf("  Restored %s (ID %d): disabled, with the cron expression %s, which a save refuses.\n", E2eRefusedCronSeeder::SCHEDULE, $this->idOf(E2eRefusedCronSeeder::SCHEDULE), E2eRefusedCronSeeder::CRON),
            sprintf("  Restored %s (ID %d): disabled, with the cron expression %s, which a save refuses.\n", E2eRefusedCronSeeder::FIX_SCHEDULE, $this->idOf(E2eRefusedCronSeeder::FIX_SCHEDULE), E2eRefusedCronSeeder::CRON),
        ], $this->log);
    }

    /**
     * A spec fixes the expression and saves the schedule; the next seed
     * puts the refused expression back on the same schedule.
     */
    public function testSeedRestoresAChangedScheduleInPlace(): void
    {
        $this->seeder()->seed($this->userId, (int)$this->template->id);
        $id = $this->idOf(E2eRefusedCronSeeder::FIX_SCHEDULE);
        Schedule::updateAll(
            ['cron_expression' => '0 6,18 * * *', 'enabled' => 1, 'next_run_at' => time() + 60, 'extra_vars' => '{"a":1}'],
            ['id' => $id]
        );

        $this->seeder()->seed($this->userId, (int)$this->template->id);

        $schedule = Schedule::findOne($id);
        $this->assertNotNull($schedule);
        $this->assertSame(E2eRefusedCronSeeder::CRON, $schedule->cron_expression);
        $this->assertFalse((bool)$schedule->enabled);
        $this->assertNull($schedule->next_run_at);
        $this->assertNull($schedule->extra_vars);
        $this->assertSame(1, (int)Schedule::find()->where(['name' => E2eRefusedCronSeeder::FIX_SCHEDULE])->count());
    }

    private function seeder(): E2eRefusedCronSeeder
    {
        return new E2eRefusedCronSeeder(function (string $line): void {
            $this->log[] = $line;
        });
    }

    private function idOf(string $name): int
    {
        return (int)Schedule::find()->select('id')->where(['name' => $name])->scalar();
    }
}
