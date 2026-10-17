<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\SurveyDefinition;
use app\components\SurveySecrets;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\Schedule;
use app\services\ScheduleSecrets;
use app\services\ScheduleService;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\ScheduleAnswerFixtures;
use app\tests\integration\TeamScopeFixtures;

/**
 * Schedule answers under a password field name that cannot carry an answer
 * (ScheduleSecrets, ScheduleLaunchSecrets): every password field name is a
 * secret key, matched exactly as stored. The schedule's page, edit form and
 * API mask such an answer, a save seals it and keeps it through the form's
 * round trip, and a scheduled run seals it before the launch is refused
 * with the reason.
 *
 * Regression (R2V2-1): once every name outside printable ASCII was
 * unusable, ordinary letters included ("contraseña_db"), these paths took
 * only usable names as secret keys. The answer that 2.8.1 stored under such
 * a name showed in plaintext on the page, in the form and in the API, and
 * stayed in plaintext through every save and run.
 */
class ScheduleSecretsEveryNameTest extends DbTestCase
{
    use RestoresComponents;
    use ScheduleAnswerFixtures;
    use TeamScopeFixtures;

    private const ANSWER = 'schedule-every-name-marker-5521';
    private const NEW_ANSWER = 'schedule-every-name-rotated-3390';
    private const REFUSAL = 'Survey field %s has a name that cannot be used for a password answer; rename it in the survey before launching.';

    private int $userId;
    private RecordingNotificationDispatcher $dispatcher;
    private mixed $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = new RecordingNotificationDispatcher();
        $this->swapComponent('notificationDispatcher', $this->dispatcher);
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = 'schedule-every-name-test-key-0123456789';
        $this->userId = (int)$this->createUserWithRole('schedule-every-name', 'operator')->id;
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        if ($this->originalKey === null) {
            unset($_ENV['APP_SECRET_KEY']);
        } else {
            $_ENV['APP_SECRET_KEY'] = $this->originalKey;
        }
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableNameProvider(): array
    {
        return [
            'a letter outside ASCII' => ['contraseña_db'],
            'a trailing space' => ['db_password '],
            'a non-breaking space' => ["db_password\u{00A0}"],
            'a number' => ['123'],
            'zero' => ['0'],
            'brackets' => ['db[pw]'],
        ];
    }

    /**
     * @dataProvider unusableNameProvider
     */
    public function testTheFormAndThePageShowSuchAnAnswerOnlyAsThePlaceholder(string $name): void
    {
        $schedule = $this->legacySchedule($name, ['app_env' => 'prod', $name => self::ANSWER]);

        $masked = (string)ScheduleSecrets::maskedExtraVars($schedule);
        $shown = (string)ScheduleSecrets::displayExtraVars($schedule);

        $expected = ['app_env' => 'prod', $name => SurveySecrets::PLACEHOLDER];
        $this->assertSame($expected, json_decode($masked, true));
        $this->assertSame($expected, json_decode($shown, true));
        $this->assertStringNotContainsString(self::ANSWER, $masked . $shown);
        $this->assertStringContainsString(self::ANSWER, (string)$this->reloadSchedule($schedule)->extra_vars, 'showing seals nothing');
    }

    /**
     * The edit form's round trip: the masked extra vars submitted unchanged
     * keep the answer (sealed now); a new value replaces it; without the
     * key it is dropped.
     *
     * @dataProvider unusableNameProvider
     */
    public function testASaveKeepsReplacesOrDropsSuchAnAnswer(string $name): void
    {
        $schedule = $this->legacySchedule($name, ['app_env' => 'prod', $name => self::ANSWER]);

        $this->save($schedule, (string)ScheduleSecrets::maskedExtraVars($schedule));
        $this->assertSame([$name => self::ANSWER], $this->sealedAnswersOf($schedule), 'kept');
        $this->assertSame(['app_env' => 'prod', $name => SurveySecrets::PLACEHOLDER], $this->storedExtraVarsOf($schedule));

        $this->save($this->reloadSchedule($schedule), self::json(['app_env' => 'prod', $name => self::NEW_ANSWER]));
        $this->assertSame([$name => self::NEW_ANSWER], $this->sealedAnswersOf($schedule), 'replaced');
        $this->assertSame(['app_env' => 'prod', $name => SurveySecrets::PLACEHOLDER], $this->storedExtraVarsOf($schedule));

        $this->save($this->reloadSchedule($schedule), self::json(['app_env' => 'prod']));
        $this->assertSame([], $this->sealedAnswersOf($schedule), 'dropped');
        $this->assertSame(['app_env' => 'prod'], $this->storedExtraVarsOf($schedule));
    }

    /**
     * The placeholder under such a name, with no answer stored: an error
     * naming the field as messages quote it, and nothing is assigned.
     */
    public function testThePlaceholderWithoutAStoredAnswerIsAnError(): void
    {
        $schedule = $this->legacySchedule('contraseña_db', ['app_env' => 'prod']);
        $schedule->extra_vars = self::json(['app_env' => 'prod', 'contraseña_db' => SurveySecrets::PLACEHOLDER]);

        $error = (new ScheduleSecrets())->prepare($schedule, true);

        $this->assertSame('"contrase\u00f1a_db" has no stored value; enter the real value.', $error);
        $this->assertSame(sprintf(ScheduleSecrets::NO_STORED_VALUE, SurveyDefinition::quote('contraseña_db')), $error);
        $this->assertNull($schedule->getSealedSecretExtraVars());
    }

    /**
     * Stored answers never follow a schedule to another job template, also
     * one under such a name: an API update without extra_vars is refused,
     * and the placeholder sent for it is an error.
     */
    public function testATemplateChangeNeedsSuchAnAnswerAgain(): void
    {
        $schedule = $this->legacySchedule('contraseña_db', ['app_env' => 'prod', 'contraseña_db' => self::ANSWER]);
        $masked = (string)ScheduleSecrets::maskedExtraVars($schedule);
        $schedule->job_template_id = (int)$this->templateWithPasswordField('db_password')->id;

        $this->assertSame(ScheduleSecrets::RESEND_AFTER_TEMPLATE_CHANGE, (new ScheduleSecrets())->prepare($schedule, false));
        $schedule->extra_vars = $masked;
        $this->assertSame('"contrase\u00f1a_db": the job template changed; enter the real value.', (new ScheduleSecrets())->prepare($schedule, true));
        $this->assertNull($schedule->getSealedSecretExtraVars(), 'nothing is assigned');
    }

    /**
     * A scheduled run seals the plain answer in the schedule first, and is
     * then refused, like every launch of such a template.
     *
     * @dataProvider unusableNameProvider
     */
    public function testAScheduledRunSealsSuchAnAnswerBeforeTheLaunchIsRefused(string $name): void
    {
        $schedule = $this->legacySchedule($name, ['app_env' => 'prod', $name => self::ANSWER], time() - 60);

        /** @var ScheduleService $service */
        $service = \Yii::$app->get('scheduleService');
        $service->runDue();

        $this->assertSame([$name => self::ANSWER], $this->sealedAnswersOf($schedule));
        $this->assertSame(['app_env' => 'prod', $name => SurveySecrets::PLACEHOLDER], $this->storedExtraVarsOf($schedule));
        $this->assertSame([sprintf(self::REFUSAL, SurveyDefinition::quote($name))], $this->launchErrorsOf($schedule));
        $this->assertFalse(Job::find()->where(['job_template_id' => $schedule->job_template_id])->exists(), 'no job is launched');
        /** @var AuditLog|null $audit */
        $audit = AuditLog::find()->where(['action' => AuditLog::ACTION_SCHEDULE_UPDATED, 'object_id' => $schedule->id])->one();
        $this->assertNotNull($audit);
        $metadata = json_decode((string)$audit->metadata, true);
        $this->assertIsArray($metadata);
        $this->assertSame([$name], $metadata['secret_keys_sealed']);
        $this->assertStringNotContainsString(self::ANSWER, (string)json_encode([$metadata, $this->dispatcher->events]));
    }

    /**
     * A password field named "0" whose answer is the only extra var: the
     * mask, a save and a scheduled run keep the extra vars a JSON object,
     * although json_decode() makes the key 0, which json_encode() writes as
     * a list.
     */
    public function testExtraVarsWhoseOnlyKeyIsAPasswordFieldNamedZeroStayAnObject(): void
    {
        $stored = '{"0":"' . self::ANSWER . '"}';

        $shown = $this->plainScheduleOf('0', $stored);
        $this->assertSame("{\n    \"0\": \"\$encrypted\$\"\n}", ScheduleSecrets::maskedExtraVars($shown));
        $this->assertSame("{\n    \"0\": \"\$encrypted\$\"\n}", ScheduleSecrets::displayExtraVars($shown));

        $saved = $this->plainScheduleOf('0', $stored);
        $this->save($saved, (string)ScheduleSecrets::maskedExtraVars($saved));
        $this->assertSame('{"0":"$encrypted$"}', $this->reloadSchedule($saved)->extra_vars);
        $this->assertSame(['0' => self::ANSWER], $this->sealedAnswersOf($saved));

        $run = $this->plainScheduleOf('0', $stored, time() - 60);
        /** @var ScheduleService $service */
        $service = \Yii::$app->get('scheduleService');
        $service->runDue();
        $this->assertSame('{"0":"$encrypted$"}', $this->reloadSchedule($run)->extra_vars);
        $this->assertSame(['0' => self::ANSWER], $this->sealedAnswersOf($run));
        $this->assertSame([sprintf(self::REFUSAL, '"0"')], $this->launchErrorsOf($run));
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * A disabled schedule (due when $nextRunAt is set) of a template saved
     * before the survey rules, with a text field and a password field named
     * $name, with $vars stored in plaintext, as 2.8.1 stored them.
     *
     * @param array<int|string, mixed> $vars
     */
    private function legacySchedule(string $name, array $vars, ?int $nextRunAt = null): Schedule
    {
        return $this->plainScheduleOf($name, self::json($vars), $nextRunAt);
    }

    private function plainScheduleOf(string $name, string $extraVars, ?int $nextRunAt = null): Schedule
    {
        return $this->createPlainSchedule((int)$this->templateWithPasswordField($name)->id, $this->userId, $extraVars, $nextRunAt);
    }

    private function templateWithPasswordField(string $name): JobTemplate
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $template->survey_fields = self::json([['name' => 'app_env', 'type' => 'text'], ['name' => $name, 'type' => 'password']]);
        $this->assertTrue($template->save(false));

        return $template;
    }

    /**
     * A save of $schedule with the extra vars $extraVars, as the form saves it.
     */
    private function save(Schedule $schedule, string $extraVars): void
    {
        $schedule->extra_vars = $extraVars;
        $this->assertNull((new ScheduleSecrets())->prepare($schedule, true));
        $this->assertTrue($schedule->save(false));
    }

    /**
     * The errors of the failed-to-launch notifications for $schedule.
     *
     * @return list<mixed>
     */
    private function launchErrorsOf(Schedule $schedule): array
    {
        $errors = [];
        foreach ($this->dispatcher->payloadsOf(NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH) as $payload) {
            $about = is_array($payload['schedule'] ?? null) ? $payload['schedule'] : [];
            if (($about['id'] ?? null) === (string)$schedule->id) {
                $errors[] = $about['error'] ?? null;
            }
        }

        return $errors;
    }

    private static function json(mixed $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
