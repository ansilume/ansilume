<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\ScheduleController;
use app\components\PendingMigrations;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Schedule;
use app\tests\integration\DbTestCase;
use app\tests\integration\services\RecordingNotificationDispatcher;
use app\tests\integration\TeamScopeFixtures;
use yii\console\ExitCode;

/**
 * schedule/run.
 *
 * Regression: schedule/run launched schedules while migrations were still
 * pending. In the prebuilt setup the schedule runner starts while the app
 * container still migrates, and a bind-mounted checkout runs new code right
 * after `git pull`, so a schedule was claimed, failed against the old
 * schema, and its run was lost.
 */
class ScheduleControllerTest extends DbTestCase
{
    use TeamScopeFixtures;

    private mixed $originalDispatcher = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDispatcher = \Yii::$app->getComponents(true)['notificationDispatcher'] ?? null;
        \Yii::$app->set('notificationDispatcher', new RecordingNotificationDispatcher());
    }

    protected function tearDown(): void
    {
        \Yii::$app->set('notificationDispatcher', $this->originalDispatcher);
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
