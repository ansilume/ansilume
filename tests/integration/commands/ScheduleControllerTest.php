<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\ScheduleController;
use app\components\CronExpressionRules;
use app\components\PendingMigrations;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Schedule;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\services\RecordingNotificationDispatcher;
use app\tests\integration\TeamScopeFixtures;
use yii\console\ExitCode;

/**
 * schedule/run and schedule/check.
 *
 * Regression: schedule/run launched schedules while migrations were still
 * pending. In the prebuilt setup the schedule runner starts while the app
 * container still migrates, and a bind-mounted checkout runs new code right
 * after `git pull`, so a schedule was claimed, failed against the old
 * schema, and its run was lost.
 */
class ScheduleControllerTest extends DbTestCase
{
    use RestoresComponents;
    use TeamScopeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swapComponent('notificationDispatcher', new RecordingNotificationDispatcher());
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    public function testWhileMigrationsArePendingNoScheduleIsClaimedOrLaunched(): void
    {
        [$template, $schedule] = $this->dueSchedule();
        $nextRunAt = $schedule->next_run_at;
        $ctrl = $this->makeController(2);

        $result = $ctrl->actionRun();

        $this->assertSame(ExitCode::OK, $result, 'the schedule runner stays healthy');
        $this->assertSame(
            'Schedules not run: 2 database migration(s) pending; they run once the update has finished.' . PHP_EOL,
            $ctrl->captured
        );
        $schedule->refresh();
        $this->assertSame($nextRunAt, $schedule->next_run_at, 'not claimed: the run stays due');
        $this->assertNull($schedule->last_run_at);
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $template->id])->count());
    }

    public function testOnceTheMigrationsHaveRunDueSchedulesLaunch(): void
    {
        [$template, $schedule] = $this->dueSchedule();
        $ctrl = $this->makeController(0);

        $result = $ctrl->actionRun();

        $this->assertSame(ExitCode::OK, $result);
        $this->assertStringContainsString('Launched ', $ctrl->captured);
        $this->assertStringNotContainsString('migration', $ctrl->captured);
        $schedule->refresh();
        $this->assertNotNull($schedule->last_run_at);
        $this->assertSame(1, (int)Job::find()->where(['job_template_id' => $template->id])->count());
    }

    /**
     * Review finding: the update notes found the schedules saved before the
     * cron rules with two queries, which missed too large steps and W after
     * the 28th: those keep running at wrong times. schedule/check lists every
     * schedule whose expression a save refuses, with the reason, and fails.
     * A name's control characters, the C1 ones in UTF-8 included (a later
     * review finding), are printed as "?".
     */
    public function testCheckListsTheSchedulesWhoseExpressionASaveRefuses(): void
    {
        Schedule::updateAll(['cron_expression' => '0 3 * * *']);
        [$template] = $this->dueSchedule();
        $step = $this->storedSchedule($template, 'step', '*/90 * * * *', true);
        $weekday = $this->storedSchedule($template, 'weekday', '0 0 31W * *', false);
        $list = $this->storedSchedule($template, "list\e[2J\u{9B}2J\u{9D}0;title\u{9C}\u{A0}", '0 18,6 * * *', true);
        $this->storedSchedule($template, 'fine', '0 6,18 * * *', true);
        $ctrl = $this->makeController(0);

        $result = $ctrl->actionCheck();

        $this->assertSame(ExitCode::DATAERR, $result);
        $this->assertSame(
            sprintf(ScheduleController::CHECK_REFUSED, $step->id, $step->name, '*/90 * * * *', '', sprintf(CronExpressionRules::STEP_TOO_LARGE, '/90', 'minute', 59)) . PHP_EOL
            . sprintf(ScheduleController::CHECK_REFUSED, $weekday->id, $weekday->name, '0 0 31W * *', ScheduleController::CHECK_DISABLED, sprintf(CronExpressionRules::NEAREST_WEEKDAY_LATE, '31W')) . PHP_EOL
            . sprintf(ScheduleController::CHECK_REFUSED, $list->id, str_replace(["\e", "\u{9B}", "\u{9D}", "\u{9C}"], '?', $list->name), '0 18,6 * * *', '', sprintf(CronExpressionRules::LIST_ORDER, '18,6', 'hour', '6,18')) . PHP_EOL
            . sprintf(ScheduleController::CHECK_FAILED, 3, (int)Schedule::find()->count()) . PHP_EOL,
            $ctrl->captured
        );
        foreach (["\e", "\u{9B}", "\u{9D}", "\u{9C}"] as $control) {
            $this->assertStringNotContainsString($control, $ctrl->captured, 'a name cannot steer the terminal');
        }
        $this->assertStringContainsString("\u{A0}", $ctrl->captured, 'other characters stay');
    }

    public function testCheckPassesWhenASaveRefusesNoExpression(): void
    {
        Schedule::updateAll(['cron_expression' => '0 3 * * *']);
        $this->dueSchedule();
        $ctrl = $this->makeController(0);

        $result = $ctrl->actionCheck();

        $this->assertSame(ExitCode::OK, $result);
        $this->assertSame(sprintf(ScheduleController::CHECK_PASSED, (int)Schedule::find()->count()) . PHP_EOL, $ctrl->captured);
    }

    /**
     * It checks the application's migrations.
     */
    public function testItChecksTheApplicationsMigrations(): void
    {
        $method = new \ReflectionMethod(ScheduleController::class, 'pendingMigrations');
        $migrations = $method->invoke(new ScheduleController('schedule', \Yii::$app));
        $files = glob((string)\Yii::getAlias('@app/migrations') . '/m*.php');
        $this->assertNotFalse($files);

        $this->assertInstanceOf(PendingMigrations::class, $migrations);
        $this->assertSame(count($files), $migrations->expectedCount());
    }

    /**
     * @return array{0: JobTemplate, 1: Schedule}
     */
    private function dueSchedule(): array
    {
        $user = $this->createUserWithRole('schedule-run', 'operator');
        $template = $this->createJobTemplate(
            (int)$this->createProject($user->id)->id,
            (int)$this->createInventory($user->id)->id,
            (int)$this->createRunnerGroup($user->id)->id,
            (int)$user->id
        );
        $schedule = new Schedule();
        $schedule->name = 'schedule-run-' . uniqid('', true);
        $schedule->job_template_id = (int)$template->id;
        $schedule->cron_expression = '0 * * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->next_run_at = time() - 60;
        $schedule->created_by = (int)$user->id;
        $schedule->save(false);

        return [$template, $schedule];
    }

    /**
     * A schedule of $template stored with $cron as older versions saved it,
     * without the rules.
     */
    private function storedSchedule(JobTemplate $template, string $name, string $cron, bool $enabled): Schedule
    {
        $schedule = new Schedule();
        $schedule->name = 'schedule-check-' . $name . '-' . uniqid('', true);
        $schedule->job_template_id = (int)$template->id;
        $schedule->cron_expression = $cron;
        $schedule->timezone = 'UTC';
        $schedule->enabled = $enabled;
        $schedule->next_run_at = time() + 3600;
        $schedule->created_by = (int)$template->created_by;
        $schedule->save(false);

        return $schedule;
    }

    /**
     * The command, with a migration state that reports $pending pending
     * migrations, and its output captured.
     */
    private function makeController(int $pending): ScheduleController
    {
        $migrations = new class ($pending) extends PendingMigrations {
            public function __construct(private readonly int $pending)
            {
                parent::__construct();
            }

            public function count(): int
            {
                return $this->pending;
            }
        };

        return new class ('schedule', \Yii::$app, $migrations) extends ScheduleController {
            public string $captured = '';

            /**
             * @param array<string, mixed> $config
             */
            public function __construct($id, $module, private readonly PendingMigrations $migrations, array $config = [])
            {
                parent::__construct($id, $module, $config);
            }

            public function stdout($string): int
            {
                $this->captured .= $string;
                return 0;
            }

            protected function pendingMigrations(): PendingMigrations
            {
                return $this->migrations;
            }
        };
    }
}
