<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\ErrorText;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\Schedule;
use app\services\JobLaunchService;
use app\services\ScheduleService;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use yii\log\Logger;

/**
 * Regression: a database error of a schedule's claim or launch was logged
 * with its message, which quotes the SQL statement with its values, and a
 * failed launch also sent that message in its schedule.failed_to_launch
 * notification. Now both name the error's class, SQLSTATE and driver error
 * code, in the one format every text uses for a database error
 * (ErrorText::DATABASE_ERROR); other errors keep their message.
 */
class ScheduleServiceDatabaseErrorTest extends DbTestCase
{
    use RestoresComponents;

    /** In the statements ScheduleFailingCommand quotes in its error message. */
    private const STATEMENT_MARK = 'UPDATE `schedule`';

    private JobTemplate $template;
    private int $userId;
    private RecordingNotificationDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = new RecordingNotificationDispatcher();
        $this->swapComponent('notificationDispatcher', $this->dispatcher);
        $this->userId = (int)$this->createUser('schedule-db-error')->id;
        $this->template = $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    /**
     * Regression: the claim's database error was logged with its statement.
     */
    public function testADatabaseErrorOfTheClaimIsLoggedWithoutItsStatement(): void
    {
        $schedule = $this->dueSchedule();

        $errors = $this->errorsOfRunDue('~^UPDATE `schedule` SET `last_run_at`~', false, $schedule);

        $this->assertCount(1, $errors);
        $this->assertStringNotContainsString(self::STATEMENT_MARK, $errors[0], 'the statement is not logged');
        $this->assertStringNotContainsString('SQLSTATE[', $errors[0], 'nor the driver\'s message');
        $this->assertStringContainsString('(SQLSTATE HY000, driver error 2006)', $errors[0]);
        $this->assertSame(
            sprintf(ScheduleService::SKIPPED, $schedule->id, $schedule->name, sprintf(ErrorText::DATABASE_ERROR, \yii\db\Exception::class, 'HY000', '2006')),
            $errors[0]
        );
        $this->assertSame(
            "Schedule #{$schedule->id} ({$schedule->name}) skipped: yii\\db\\Exception (SQLSTATE HY000, driver error 2006)",
            $errors[0],
            'the format of ErrorText, as every other text that names a database error'
        );
        $this->assertNull($this->reload($schedule)->last_run_at, 'not claimed');
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $this->template->id])->count());
        $this->assertSame([], $this->dispatcher->payloadsOf(NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH));
    }

    /**
     * Regression: a failed launch's database error was logged and sent in
     * the failed-to-launch notification with its statement. Here the
     * schedule of a deleted template fails to disable itself.
     */
    public function testADatabaseErrorOfALaunchIsLoggedAndReportedWithoutItsStatement(): void
    {
        $schedule = $this->dueSchedule();
        $this->template->softDelete();

        $errors = $this->errorsOfRunDue('~^UPDATE `schedule` SET `enabled`=0~', false, $schedule);

        $failures = $this->dispatcher->payloadsOf(NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH);
        $this->assertCount(1, $errors);
        $this->assertCount(1, $failures);
        $sent = (string)json_encode($failures[0]);
        $this->assertStringNotContainsString(self::STATEMENT_MARK, $errors[0], 'the statement is not logged');
        $this->assertStringNotContainsString('UPDATE', $sent, 'nor sent');
        $error = sprintf(ErrorText::DATABASE_ERROR, \yii\db\Exception::class, 'HY000', '2006');
        $this->assertSame(sprintf(ScheduleService::LAUNCH_FAILED, $schedule->id, $schedule->name, $error), $errors[0]);
        $this->assertSame(
            ['id' => (string)$schedule->id, 'name' => $schedule->name, 'cron' => $schedule->cron_expression, 'error' => $error],
            $failures[0]['schedule'] ?? null
        );
        $this->assertTrue((bool)$this->reload($schedule)->enabled, 'the failed disable changed nothing');
    }

    /**
     * Regression: a launch that failed in JobLaunchService was logged and
     * sent as "failed to launch: Job launch failed: the job could not be
     * saved." Now the reason follows the schedule's own wording once, as for
     * every other caller (JobLaunchService::failureReason()).
     */
    public function testAFailedJobInsertIsReportedWithoutADoubledPrefix(): void
    {
        // A user who may launch the template, so the launch reaches the job insert.
        $launcher = $this->createUser('schedule-db-error-admin');
        $launcher->is_superadmin = true;
        $launcher->save(false);
        $schedule = $this->dueSchedule();
        $schedule->created_by = (int)$launcher->id;
        $schedule->save(false);

        $errors = $this->errorsOfRunDue('~^INSERT INTO `job` ~', false, $schedule);

        $failures = $this->dispatcher->payloadsOf(NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH);
        $this->assertSame(
            ["Schedule #{$schedule->id} ({$schedule->name}) failed to launch: the job could not be saved."],
            $errors
        );
        $this->assertCount(1, $failures);
        $this->assertSame(JobLaunchService::SAVE_FAILED, $failures[0]['schedule']['error'] ?? null);
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $this->template->id])->count());
    }

    /**
     * A PDOException that escaped Yii carries the driver's message, which can
     * quote a value: it is a database error too.
     */
    public function testAPdoExceptionIsADatabaseErrorToo(): void
    {
        $schedule = $this->dueSchedule();

        $errors = $this->errorsOfRunDue('~^UPDATE `schedule` SET `last_run_at`~', true, $schedule);

        $this->assertCount(1, $errors);
        $this->assertStringNotContainsString('Duplicate entry', $errors[0], 'the driver\'s message is not logged');
        $this->assertSame(
            sprintf(ScheduleService::SKIPPED, $schedule->id, $schedule->name, sprintf(ErrorText::DATABASE_ERROR, \PDOException::class, '-', '-')),
            $errors[0]
        );
    }

    /**
     * Any other error keeps its message, which carries no SQL, as before:
     * here the ValueError of a stored timezone with a NUL byte.
     */
    public function testAnErrorThatIsNoDatabaseErrorKeepsItsMessage(): void
    {
        $schedule = $this->dueSchedule();
        $schedule->next_run_at = null;
        $schedule->cron_expression = '* * * * *';
        $schedule->timezone = "UTC\0x";
        $schedule->save(false);
        $timezone = $this->reload($schedule)->timezone;
        $message = '';
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
        }
        $this->assertNotSame('', $message, 'DateTimeZone refuses a NUL byte');

        $errors = $this->errorsOfRunDue('', false, $schedule);

        $this->assertSame(["Schedule #{$schedule->id} ({$schedule->name}) skipped: {$message}"], $errors);
    }

    // -- Helpers ------------------------------------------------------------------

    private function dueSchedule(): Schedule
    {
        $schedule = new Schedule();
        $schedule->name = 'schedule-db-error-' . uniqid('', true);
        $schedule->job_template_id = (int)$this->template->id;
        $schedule->cron_expression = '0 2 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->next_run_at = time() - 60;
        $schedule->created_by = $this->userId;
        $schedule->save(false);

        return $this->reload($schedule);
    }

    private function reload(Schedule $schedule): Schedule
    {
        $stored = Schedule::findOne($schedule->id);
        $this->assertNotNull($stored);

        return $stored;
    }

    /**
     * Run the due schedules while every statement matching $failingPattern
     * fails ('' for none); the errors ScheduleService logged meanwhile about
     * $schedule.
     *
     * @return list<string>
     */
    private function errorsOfRunDue(string $failingPattern, bool $asPdoException, Schedule $schedule): array
    {
        $db = \Yii::$app->db;
        $driver = $db->getDriverName();
        $previousCommand = $db->commandMap[$driver] ?? null;
        $db->commandMap[$driver] = [
            'class' => ScheduleFailingCommand::class,
            'failingPattern' => $failingPattern,
            'asPdoException' => $asPdoException,
        ];
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previousLogger = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            /** @var ScheduleService $service */
            $service = \Yii::$app->get('scheduleService');
            $service->runDue();
        } finally {
            \Yii::setLogger($previousLogger);
            if ($previousCommand === null) {
                unset($db->commandMap[$driver]);
            } else {
                $db->commandMap[$driver] = $previousCommand;
            }
        }

        $errors = [];
        foreach ($logger->messages as $message) {
            if (
                $message[1] === Logger::LEVEL_ERROR
                && $message[2] === ScheduleService::class
                && is_string($message[0])
                && str_starts_with($message[0], "Schedule #{$schedule->id} ")
            ) {
                $errors[] = $message[0];
            }
        }

        return $errors;
    }
}
