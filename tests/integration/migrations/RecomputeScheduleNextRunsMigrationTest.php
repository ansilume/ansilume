<?php

declare(strict_types=1);

namespace app\tests\integration\migrations;

use app\models\JobTemplate;
use app\models\Schedule;
use app\tests\integration\DbTestCase;

/**
 * m000089_000000_recompute_schedule_next_runs: the next run of every
 * schedule that is not yet due is computed anew as Schedule::nextRunOf()
 * computes it; a due schedule, one without a next run, and one whose next
 * run cannot be computed keep theirs. The updates run inside the test's
 * transaction and are rolled back with it.
 *
 * Regression: releases up to 2.12.2 computed the next run of a schedule at
 * 3:00 in Europe/Berlin for the night the clocks go back as the second 2:00,
 * an hour early, and skipped runs around the clock changes of some
 * timezones (CronRunsTest); an update before such a night kept that next
 * run.
 */
class RecomputeScheduleNextRunsMigrationTest extends DbTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/m000089_000000_recompute_schedule_next_runs.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Start without leftovers, so every count below is this test's own.
        $this->up();
    }

    public function testUpMovesTheNextRunsThatAreNotTheNextRun(): void
    {
        $template = $this->template();
        $next = Schedule::nextRunOf('0 3 * * *', 'Europe/Berlin');
        $nextInUtc = Schedule::nextRunOf('0 3 * * *', 'UTC');
        $this->assertNotNull($next);
        $this->assertNotNull($nextInUtc);
        $late = $this->schedule($template, '0 3 * * *', 'Europe/Berlin', $next + 3600);
        $skipped = $this->schedule($template, '0 3 * * *', 'Europe/Berlin', $next + 86400);
        $withoutZone = $this->schedule($template, '0 3 * * *', '', $nextInUtc + 3600);

        $output = $this->up();

        $this->assertSame($next, $this->nextRunOf($late));
        $this->assertSame($next, $this->nextRunOf($skipped), 'a run the library skipped comes back');
        $this->assertSame($nextInUtc, $this->nextRunOf($withoutZone), 'no timezone is UTC');
        $this->assertSame('    > ' . sprintf(\m000089_000000_recompute_schedule_next_runs::MOVED, 3) . "\n", $output);
    }

    public function testUpKeepsTheNextRunsItCannotOrNeedNotCompute(): void
    {
        $template = $this->template();
        $next = Schedule::nextRunOf('0 3 * * *', 'Europe/Berlin');
        $this->assertNotNull($next);
        $kept = [
            'the next run' => $this->schedule($template, '0 3 * * *', 'Europe/Berlin', $next),
            'due: the scheduler launches it' => $this->schedule($template, '0 3 * * *', 'Europe/Berlin', time() - 120),
            'without a next run' => $this->schedule($template, '0 3 * * *', 'Europe/Berlin', null),
            'an expression the library cannot be handed' => $this->schedule($template, '0-30/-5 * * * *', 'UTC', $next + 3600),
            'an expression the library cannot read' => $this->schedule($template, 'not a cron', 'UTC', $next + 3600),
            'an unknown timezone' => $this->schedule($template, '0 3 * * *', 'Mars/Olympus', $next + 3600),
        ];
        $before = array_map(fn (Schedule $schedule): ?int => $this->nextRunOf($schedule), $kept);

        $output = $this->up();

        $this->assertSame($before, array_map(fn (Schedule $schedule): ?int => $this->nextRunOf($schedule), $kept));
        $this->assertSame('    > ' . sprintf(\m000089_000000_recompute_schedule_next_runs::MOVED, 0) . "\n", $output);
    }

    /**
     * Review finding: the migration selected the schedules not yet due, then
     * computed each next run from the clock again, so a stored run of the
     * minute that ended meanwhile moved past itself and never ran. Both come
     * from one moment now: a run the select saw as not yet due stays. The
     * moment lies in the past, so that a select by the clock would leave out
     * the runs below, and a computation by the clock would move them.
     */
    public function testANextRunTheSelectSawAsNotYetDueStays(): void
    {
        $template = $this->template();
        $now = (new \DateTimeImmutable('2020-03-01 18:17:59', new \DateTimeZone('UTC')))->getTimestamp();
        $everyMinute = $this->schedule($template, '* * * * *', 'UTC', $now + 1);
        $daily = $this->schedule($template, '18 18 * * *', 'UTC', $now + 1);
        $late = $this->schedule($template, '18 18 * * *', 'UTC', $now + 1 + 86400);

        $this->migration()->moveNextRuns($now);

        $this->assertSame($now + 1, $this->nextRunOf($everyMinute), 'its run at 18:18 stays');
        $this->assertSame($now + 1, $this->nextRunOf($daily));
        $this->assertSame($now + 1, $this->nextRunOf($late), 'computed from the minute after the moment');
    }

    public function testRunningItAgainChangesNothing(): void
    {
        $template = $this->template();
        $next = Schedule::nextRunOf('0 3 * * *', 'Europe/Berlin');
        $this->assertNotNull($next);
        $schedule = $this->schedule($template, '0 3 * * *', 'Europe/Berlin', $next + 3600);
        $this->up();
        $once = Schedule::findOne($schedule->id)?->getAttributes();

        $output = $this->up();

        $this->assertSame($once, Schedule::findOne($schedule->id)?->getAttributes());
        $this->assertSame('    > ' . sprintf(\m000089_000000_recompute_schedule_next_runs::MOVED, 0) . "\n", $output);
    }

    /**
     * Down succeeds without touching data: 2.12.2 launches the next runs
     * this migration stored and computes the ones after them itself.
     */
    public function testDownSucceedsAndRestoresNothing(): void
    {
        $template = $this->template();
        $next = Schedule::nextRunOf('0 3 * * *', 'Europe/Berlin');
        $this->assertNotNull($next);
        $schedule = $this->schedule($template, '0 3 * * *', 'Europe/Berlin', $next + 3600);
        $this->up();

        $this->assertTrue($this->migration()->safeDown());

        $this->assertSame($next, $this->nextRunOf($schedule));
    }

    // -- Helpers ----------------------------------------------------------------

    private function migration(): \m000089_000000_recompute_schedule_next_runs
    {
        return new \m000089_000000_recompute_schedule_next_runs(['db' => \Yii::$app->db, 'compact' => true]);
    }

    /**
     * Runs safeUp() and returns what it printed.
     */
    private function up(): string
    {
        ob_start();
        try {
            $this->migration()->safeUp();
        } finally {
            $output = (string)ob_get_clean();
        }

        return $output;
    }

    private function template(): JobTemplate
    {
        $owner = $this->createUser('next_runs');

        return $this->createJobTemplate(
            (int)$this->createProject((int)$owner->id)->id,
            (int)$this->createInventory((int)$owner->id)->id,
            (int)$this->createRunnerGroup((int)$owner->id)->id,
            (int)$owner->id
        );
    }

    /**
     * A schedule as an older release stored it, with $nextRunAt as its next
     * run.
     */
    private function schedule(JobTemplate $template, string $cron, string $timezone, ?int $nextRunAt): Schedule
    {
        $schedule = new Schedule();
        $schedule->name = 'next-runs-' . uniqid('', true);
        $schedule->job_template_id = $template->id;
        $schedule->cron_expression = $cron;
        $schedule->timezone = $timezone;
        $schedule->enabled = true;
        $schedule->next_run_at = $nextRunAt;
        $schedule->created_by = $template->created_by;
        $schedule->save(false);

        return $schedule;
    }

    private function nextRunOf(Schedule $schedule): ?int
    {
        $value = Schedule::find()->select('next_run_at')->where(['id' => $schedule->id])->scalar();

        return $value === null ? null : (int)$value;
    }
}
