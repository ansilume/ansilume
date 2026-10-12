<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\SurveySecrets;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\Schedule;
use app\services\LaunchContext;
use app\services\ScheduleLaunchSecrets;
use app\tests\integration\DbTestCase;
use app\tests\integration\ScheduleAnswerFixtures;
use yii\base\Event;
use yii\db\BaseActiveRecord;
use yii\log\Logger;

/**
 * ScheduleLaunchSecrets: a schedule's password answers at its scheduled
 * launch travel in the LaunchContext, never in the extra vars. Edits that
 * land while a launch seals plain answers: ScheduleSecretsConcurrentEditTest.
 */
class ScheduleLaunchSecretsTest extends DbTestCase
{
    use ScheduleAnswerFixtures;

    private const ANSWER = 'schedule-launch-marker-1618';

    private int $userId;
    private JobTemplate $template;
    private mixed $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = 'schedule-launch-test-key-0123456789';
        $this->userId = (int)$this->createUser('schedule-launch')->id;
        $this->template = $this->createPasswordSurveyTemplate($this->userId);
    }

    protected function tearDown(): void
    {
        if ($this->originalKey === null) {
            unset($_ENV['APP_SECRET_KEY']);
        } else {
            $_ENV['APP_SECRET_KEY'] = $this->originalKey;
        }
        parent::tearDown();
    }

    // -- launchOverrides() --------------------------------------------------------

    public function testTheStoredAnswersTravelInTheLaunchContext(): void
    {
        $schedule = $this->createScheduleWithAnswers((int)$this->template->id, $this->userId, [self::SURVEY_PASSWORD_FIELD => self::ANSWER]);

        $overrides = (new ScheduleLaunchSecrets())->launchOverrides($schedule, $this->template);

        $this->assertSame([self::SURVEY_TEXT_FIELD => 'prod'], $overrides['extra_vars']);
        $context = $overrides[LaunchContext::KEY];
        $this->assertInstanceOf(LaunchContext::class, $context);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], $context->carriedSecrets);
        $this->assertSame(['schedule_id' => (int)$schedule->id, 'carried_secret_keys' => [self::SURVEY_PASSWORD_FIELD]], $context->auditContext);
    }

    public function testExtraVarsWithoutAnswersAreLaunchedAsStored(): void
    {
        $schedule = $this->createPlainSchedule((int)$this->template->id, $this->userId, '{"app_env": "prod"}');

        $overrides = (new ScheduleLaunchSecrets())->launchOverrides($schedule, $this->template);

        $this->assertSame('{"app_env": "prod"}', $overrides['extra_vars']);
        $this->assertInstanceOf(LaunchContext::class, $overrides[LaunchContext::KEY]);
        $this->assertSame([], $overrides[LaunchContext::KEY]->carriedSecrets);
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function emptyExtraVarsProvider(): array
    {
        return ['none' => [null], 'empty' => ['']];
    }

    /**
     * @dataProvider emptyExtraVarsProvider
     */
    public function testWithoutExtraVarsNoneAreLaunched(?string $extraVars): void
    {
        $schedule = $this->createPlainSchedule((int)$this->template->id, $this->userId, $extraVars);

        $this->assertArrayNotHasKey('extra_vars', (new ScheduleLaunchSecrets())->launchOverrides($schedule, $this->template));
    }

    public function testExtraVarsOfAnswersOnlyAreLaunchedAsAnswersOnly(): void
    {
        $schedule = $this->createScheduleWithAnswers((int)$this->template->id, $this->userId, [self::SURVEY_PASSWORD_FIELD => self::ANSWER], []);

        $overrides = (new ScheduleLaunchSecrets())->launchOverrides($schedule, $this->template);

        $this->assertArrayNotHasKey('extra_vars', $overrides);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], $overrides[LaunchContext::KEY]->carriedSecrets);
    }

    /**
     * A key that became a password field since the schedule was saved: its
     * plain value is sealed and the schedule saved before the launch, with
     * an audit entry that names it.
     */
    public function testPlainAnswersAreSealedBeforeTheLaunch(): void
    {
        $schedule = $this->createScheduleWithAnswers((int)$this->template->id, $this->userId, ['old_password' => 'old-answer'], [
            self::SURVEY_TEXT_FIELD => 'prod',
            self::SURVEY_PASSWORD_FIELD => self::ANSWER,
        ]);

        $overrides = (new ScheduleLaunchSecrets())->launchOverrides($schedule, $this->template);

        $this->assertSame(['old_password' => 'old-answer', self::SURVEY_PASSWORD_FIELD => self::ANSWER], $overrides[LaunchContext::KEY]->carriedSecrets);
        $this->assertSame([self::SURVEY_TEXT_FIELD => 'prod'], $overrides['extra_vars']);
        $this->assertSame(['old_password' => 'old-answer', self::SURVEY_PASSWORD_FIELD => self::ANSWER], $this->sealedAnswersOf($schedule));
        $this->assertSame(
            [self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER, 'old_password' => SurveySecrets::PLACEHOLDER],
            $this->storedExtraVarsOf($schedule)
        );
        $audits = $this->updateAudits($schedule);
        $this->assertCount(1, $audits);
        $this->assertNull($audits[0]->user_id, 'done by the scheduler, not a user');
        $this->assertSame([
            'name' => $schedule->name,
            'source' => 'console:schedule/run',
            'job_template_id' => (int)$this->template->id,
            'secret_keys_sealed' => [self::SURVEY_PASSWORD_FIELD],
        ], json_decode((string)$audits[0]->metadata, true));
    }

    /**
     * The plain answers are sealed in the row as it is now, not as the
     * scheduler read it: an edit in between is not overwritten.
     */
    public function testPlainAnswersAreSealedInTheCurrentRow(): void
    {
        $schedule = $this->createPlainSchedule((int)$this->template->id, $this->userId, '{"db_password":"' . self::ANSWER . '"}');
        $edited = $this->reloadSchedule($schedule);
        $edited->extra_vars = '{"db_password":"$encrypted$","app_env":"edited"}';
        $edited->setSealedSecretExtraVars(SurveySecrets::seal([self::SURVEY_PASSWORD_FIELD => 'edited-answer']));
        $edited->save(false);

        $overrides = (new ScheduleLaunchSecrets())->launchOverrides($schedule, $this->template);

        $this->assertSame([self::SURVEY_PASSWORD_FIELD => 'edited-answer'], $overrides[LaunchContext::KEY]->carriedSecrets);
        $this->assertSame(['app_env' => 'edited'], $overrides['extra_vars']);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => 'edited-answer'], $this->sealedAnswersOf($schedule));
        $this->assertSame([], $this->updateAudits($schedule), 'nothing left to seal');
    }

    /**
     * Answers sealed with an APP_SECRET_KEY that has been replaced: the
     * launch fails with a message that says what to do.
     */
    public function testUndecryptableAnswersFailTheLaunch(): void
    {
        $schedule = $this->createScheduleWithAnswers((int)$this->template->id, $this->userId, [self::SURVEY_PASSWORD_FIELD => self::ANSWER]);
        $_ENV['APP_SECRET_KEY'] = 'a-rotated-key-that-cannot-open-it-0123';

        try {
            (new ScheduleLaunchSecrets())->launchOverrides($schedule, $this->template);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame(
                'Its stored password answers cannot be decrypted (was APP_SECRET_KEY changed?); edit the schedule and enter them again.',
                $e->getMessage()
            );
        }
    }

    public function testPlainAnswersThatCannotBeEncodedFailTheLaunch(): void
    {
        $schedule = $this->createPlainSchedule((int)$this->template->id, $this->userId, '{"db_password":"' . self::ANSWER . '","huge":1e999}');

        $this->expectExceptionObject(new \RuntimeException(ScheduleLaunchSecrets::LAUNCH_SEAL_FAILED));
        (new ScheduleLaunchSecrets())->launchOverrides($schedule, $this->template);
    }

    /**
     * A database error's message carries the SQL with the values: neither
     * passed on nor logged. The sealing's transaction is rolled back.
     */
    public function testADatabaseErrorWhileSealingKeepsTheSqlOut(): void
    {
        $schedule = $this->createPlainSchedule((int)$this->template->id, $this->userId, '{"db_password":"' . self::ANSWER . '"}');
        $failingSave = static function (): void {
            throw new \yii\db\Exception(
                "SQLSTATE[22001]: String data, right truncated: 1406 Data too long\nThe SQL being executed was: UPDATE `schedule` SET `extra_vars`='schedule-launch-marker-1618'",
                ['22001', 1406, 'Data too long']
            );
        };
        Event::on(Schedule::class, BaseActiveRecord::EVENT_BEFORE_UPDATE, $failingSave);
        $db = Schedule::getDb();
        $level = (int)$db->getTransaction()?->getLevel();
        $logger = new Logger();
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);

        try {
            (new ScheduleLaunchSecrets())->launchOverrides($schedule, $this->template);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame(ScheduleLaunchSecrets::LAUNCH_SEAL_FAILED, $e->getMessage());
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString(self::ANSWER, (string)$e);
        } finally {
            \Yii::setLogger($previous);
            Event::off(Schedule::class, BaseActiveRecord::EVENT_BEFORE_UPDATE, $failingSave);
        }

        $own = array_values(array_filter($logger->messages, static fn (array $m): bool => $m[2] === ScheduleLaunchSecrets::class));
        $this->assertCount(1, $own);
        $this->assertSame(
            "Schedule #{$schedule->id}: its plain password answers could not be sealed (SQLSTATE 22001, driver error 1406).",
            $own[0][0]
        );
        $this->assertSame([], $this->updateAudits($schedule));
        $this->assertSame($level, (int)$db->getTransaction()?->getLevel(), 'the transaction has ended');
        $this->assertStringContainsString(self::ANSWER, (string)$this->reloadSchedule($schedule)->extra_vars, 'nothing was saved');
    }

    public function testASealThatIsNotSavedFailsTheLaunch(): void
    {
        $schedule = $this->createPlainSchedule((int)$this->template->id, $this->userId, '{"db_password":"' . self::ANSWER . '"}');
        $veto = static function (\yii\base\ModelEvent $event): void {
            $event->isValid = false;
        };
        Event::on(Schedule::class, BaseActiveRecord::EVENT_BEFORE_UPDATE, $veto);

        try {
            (new ScheduleLaunchSecrets())->launchOverrides($schedule, $this->template);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame(ScheduleLaunchSecrets::LAUNCH_SEAL_FAILED, $e->getMessage());
        } finally {
            Event::off(Schedule::class, BaseActiveRecord::EVENT_BEFORE_UPDATE, $veto);
        }
        $this->assertSame([], $this->updateAudits($schedule));
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * @return list<AuditLog>
     */
    private function updateAudits(Schedule $schedule): array
    {
        /** @var list<AuditLog> $entries */
        $entries = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_SCHEDULE_UPDATED, 'object_type' => 'schedule', 'object_id' => $schedule->id])
            ->all();

        return $entries;
    }
}
