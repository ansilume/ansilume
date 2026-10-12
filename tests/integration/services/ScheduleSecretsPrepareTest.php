<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\SurveyField;
use app\components\SurveySecrets;
use app\models\JobTemplate;
use app\models\Schedule;
use app\services\ScheduleSecrets;
use app\tests\integration\DbTestCase;
use app\tests\integration\ScheduleAnswerFixtures;

/**
 * ScheduleSecrets::prepare(): password answers in the extra vars of a
 * schedule about to be saved, from the web form or the API.
 *
 * Regression: schedule extra vars stored the answers to password survey
 * fields in plaintext, shown on the schedule page and in its edit form.
 */
class ScheduleSecretsPrepareTest extends DbTestCase
{
    use ScheduleAnswerFixtures;

    private const ANSWER = 'schedule-answer-marker-3141';
    private const NEW_ANSWER = 'schedule-answer-rotated-2718';

    private int $userId;
    private JobTemplate $template;
    private mixed $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = 'schedule-secrets-test-key-0123456789';
        $this->userId = (int)$this->createUser('schedule-secrets')->id;
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

    // -- New schedules --------------------------------------------------------

    public function testANewScheduleStoresItsAnswersOnlySealed(): void
    {
        $schedule = $this->newSchedule('{"app_env":"prod","db_password":"' . self::ANSWER . '"}');
        $secrets = new ScheduleSecrets();

        $this->assertNull($secrets->prepare($schedule, true));

        $this->assertSame('{"app_env":"prod","db_password":"$encrypted$"}', $schedule->extra_vars);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], SurveySecrets::open($schedule->getSealedSecretExtraVars()));
        $this->assertSame(['job_template_id' => (int)$this->template->id, 'secret_keys_added' => [self::SURVEY_PASSWORD_FIELD]], $secrets->auditContext());
        $this->assertTrue($schedule->save(false));
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], $this->sealedAnswersOf($schedule));
        $this->assertStringNotContainsString(self::ANSWER, (string)$this->reloadSchedule($schedule)->extra_vars);
    }

    /**
     * Answers keep their JSON type.
     */
    public function testAnswersOfAnyJsonTypeAreSealed(): void
    {
        $schedule = $this->newSchedule('{"db_password":{"user":"app","pin":4711}}');

        $this->assertNull((new ScheduleSecrets())->prepare($schedule, true));

        $this->assertSame([self::SURVEY_PASSWORD_FIELD => ['user' => 'app', 'pin' => 4711]], SurveySecrets::open($schedule->getSealedSecretExtraVars()));
    }

    public function testThePlaceholderWithoutAStoredAnswerIsRefused(): void
    {
        $schedule = $this->newSchedule('{"db_password":"$encrypted$"}');
        $secrets = new ScheduleSecrets();

        $this->assertSame('"db_password" has no stored value; enter the real value.', $secrets->prepare($schedule, true));

        $this->assertSame('{"db_password":"$encrypted$"}', $schedule->extra_vars, 'nothing is assigned');
        $this->assertNull($schedule->getSealedSecretExtraVars());
        $this->assertSame([], $secrets->auditContext());
    }

    /**
     * Extra vars without an answer are stored as submitted, formatting
     * included.
     */
    public function testExtraVarsWithoutAnswersAreStoredAsSubmitted(): void
    {
        $submitted = "{\n  \"app_env\": \"prod\",\n  \"note\": \"\$encrypted\$ is just text here\"\n}";
        $schedule = $this->newSchedule($submitted);

        $this->assertNull((new ScheduleSecrets())->prepare($schedule, true));

        $this->assertSame($submitted, $schedule->extra_vars);
        $this->assertNull($schedule->getSealedSecretExtraVars());
    }

    public function testAnApiCreateWithoutExtraVarsStoresNone(): void
    {
        $schedule = $this->newSchedule(null);
        $secrets = new ScheduleSecrets();

        $this->assertNull($secrets->prepare($schedule, false));

        $this->assertNull($schedule->extra_vars);
        $this->assertNull($schedule->getSealedSecretExtraVars());
        $this->assertSame(['job_template_id' => (int)$this->template->id], $secrets->auditContext());
    }

    // -- Updates that keep the job template ------------------------------------

    /**
     * The edit form shows the placeholder; submitted unchanged, it keeps the
     * stored answer.
     */
    public function testThePlaceholderKeepsTheStoredAnswer(): void
    {
        $schedule = $this->storedSchedule();
        $schedule->extra_vars = $this->reloadSchedule($schedule)->extra_vars;
        $secrets = new ScheduleSecrets();

        $this->assertNull($secrets->prepare($schedule, true));
        $schedule->save(false);

        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], $this->sealedAnswersOf($schedule));
        $this->assertSame([self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER], $this->storedExtraVarsOf($schedule));
        $this->assertSame(
            ['job_template_id' => (int)$this->template->id, 'previous_job_template_id' => (int)$this->template->id, 'secret_keys_kept' => [self::SURVEY_PASSWORD_FIELD]],
            $secrets->auditContext()
        );
    }

    public function testANewValueReplacesTheStoredAnswer(): void
    {
        $schedule = $this->storedSchedule();
        $schedule->extra_vars = '{"app_env":"prod","db_password":"' . self::NEW_ANSWER . '"}';
        $secrets = new ScheduleSecrets();

        $this->assertNull($secrets->prepare($schedule, true));
        $schedule->save(false);

        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::NEW_ANSWER], $this->sealedAnswersOf($schedule));
        $this->assertSame(['secret_keys_replaced' => [self::SURVEY_PASSWORD_FIELD]], array_diff_key($secrets->auditContext(), ['job_template_id' => 1, 'previous_job_template_id' => 1]));
        $this->assertStringNotContainsString(self::NEW_ANSWER, (string)json_encode($secrets->auditContext()));
    }

    public function testAnAnswerLeftOutIsDropped(): void
    {
        $schedule = $this->storedSchedule();
        $schedule->extra_vars = '{"app_env":"prod"}';
        $secrets = new ScheduleSecrets();

        $this->assertNull($secrets->prepare($schedule, true));
        $schedule->save(false);

        $this->assertNull($this->reloadSchedule($schedule)->getSealedSecretExtraVars());
        $this->assertSame('{"app_env":"prod"}', $this->reloadSchedule($schedule)->extra_vars);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD], $secrets->auditContext()['secret_keys_dropped'] ?? null);
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function clearingValueProvider(): array
    {
        return ['null' => [null], 'empty' => [''], 'a JSON value without keys' => ['5']];
    }

    /**
     * An explicit null or empty value clears the extra vars and every answer.
     *
     * @dataProvider clearingValueProvider
     */
    public function testExtraVarsWithoutKeysClearEveryAnswer(?string $value): void
    {
        $schedule = $this->storedSchedule();
        $schedule->extra_vars = $value;
        $secrets = new ScheduleSecrets();

        $this->assertNull($secrets->prepare($schedule, true));
        $schedule->save(false);

        $stored = $this->reloadSchedule($schedule);
        $this->assertSame($value, $stored->extra_vars);
        $this->assertNull($stored->getSealedSecretExtraVars());
        $this->assertSame([self::SURVEY_PASSWORD_FIELD], $secrets->auditContext()['secret_keys_dropped'] ?? null);
    }

    /**
     * An API update without extra_vars keeps the extra vars and the answers.
     */
    public function testAnUpdateWithoutExtraVarsKeepsEverything(): void
    {
        $schedule = $this->storedSchedule();
        $sealed = $schedule->getSealedSecretExtraVars();
        $extraVars = $schedule->extra_vars;
        $schedule->enabled = false;
        $secrets = new ScheduleSecrets();

        $this->assertNull($secrets->prepare($schedule, false));

        $this->assertSame($extraVars, $schedule->extra_vars);
        $this->assertSame($sealed, $schedule->getSealedSecretExtraVars());
        $this->assertSame([self::SURVEY_PASSWORD_FIELD], $secrets->auditContext()['secret_keys_kept'] ?? null);
    }

    /**
     * Once secret, always secret: an answer stays secret when the field is
     * no longer a password field of the template.
     */
    public function testAStoredAnswerOfAFieldNoLongerInTheSurveyStaysSecret(): void
    {
        $schedule = $this->createScheduleWithAnswers((int)$this->template->id, $this->userId, ['old_password' => self::ANSWER]);

        $schedule->extra_vars = '{"app_env":"prod","old_password":"$encrypted$"}';
        $this->assertNull((new ScheduleSecrets())->prepare($schedule, true));
        $this->assertSame(['old_password' => self::ANSWER], SurveySecrets::open($schedule->getSealedSecretExtraVars()));

        $schedule->extra_vars = '{"app_env":"prod","old_password":"' . self::NEW_ANSWER . '"}';
        $this->assertNull((new ScheduleSecrets())->prepare($schedule, true));
        $this->assertSame(['old_password' => self::NEW_ANSWER], SurveySecrets::open($schedule->getSealedSecretExtraVars()));
        $this->assertSame('{"app_env":"prod","old_password":"$encrypted$"}', $schedule->extra_vars);
    }

    /**
     * A key that became a password field after the schedule was saved: its
     * plain value counts as the stored answer. The form shows the
     * placeholder for it; submitted unchanged, the value is sealed.
     */
    public function testAPlainAnswerIsKeptAndSealedWhenThePlaceholderIsSubmitted(): void
    {
        $schedule = $this->createPlainSchedule((int)$this->template->id, $this->userId, '{"app_env":"prod","db_password":"' . self::ANSWER . '"}');
        $schedule->extra_vars = (string)ScheduleSecrets::maskedExtraVars($schedule);
        $this->assertStringNotContainsString(self::ANSWER, $schedule->extra_vars);
        $secrets = new ScheduleSecrets();

        $this->assertNull($secrets->prepare($schedule, true));
        $schedule->save(false);

        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], $this->sealedAnswersOf($schedule));
        $this->assertStringNotContainsString(self::ANSWER, (string)$this->reloadSchedule($schedule)->extra_vars);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD], $secrets->auditContext()['secret_keys_kept'] ?? null);
    }

    // -- Updates that change the job template ----------------------------------

    /**
     * Stored answers never follow a schedule to another template, whose
     * playbook may be someone else's.
     */
    public function testAfterATemplateChangeThePlaceholderOfAStoredAnswerIsRefused(): void
    {
        $schedule = $this->storedSchedule();
        $sealed = $schedule->getSealedSecretExtraVars();
        $schedule->job_template_id = (int)$this->otherTemplate()->id;
        $schedule->extra_vars = '{"app_env":"prod","db_password":"$encrypted$"}';

        $this->assertSame('"db_password": the job template changed; enter the real value.', (new ScheduleSecrets())->prepare($schedule, true));
        $this->assertSame($sealed, $schedule->getSealedSecretExtraVars(), 'nothing is assigned');
    }

    public function testAfterATemplateChangeTheAnswersAreEnteredAgain(): void
    {
        $schedule = $this->storedSchedule();
        $other = $this->otherTemplate();
        $schedule->job_template_id = (int)$other->id;
        $schedule->extra_vars = '{"db_password":"' . self::NEW_ANSWER . '","api_token":"token-value-9"}';
        $secrets = new ScheduleSecrets();

        $this->assertNull($secrets->prepare($schedule, true));

        $this->assertSame(['db_password' => self::NEW_ANSWER, 'api_token' => 'token-value-9'], SurveySecrets::open($schedule->getSealedSecretExtraVars()));
        $this->assertSame('{"db_password":"$encrypted$","api_token":"$encrypted$"}', $schedule->extra_vars);
        $this->assertSame([
            'job_template_id' => (int)$other->id,
            'previous_job_template_id' => (int)$this->template->id,
            'secret_keys_replaced' => ['db_password'],
            'secret_keys_added' => ['api_token'],
        ], $secrets->auditContext());
    }

    public function testAfterATemplateChangeTheNewTemplatesPasswordFieldNeedsARealValue(): void
    {
        $schedule = $this->createPlainSchedule((int)$this->plainTemplate()->id, $this->userId, '{"x":1}');
        $schedule->job_template_id = (int)$this->otherTemplate()->id;
        $schedule->extra_vars = '{"api_token":"$encrypted$"}';

        $this->assertSame('"api_token" has no stored value; enter the real value.', (new ScheduleSecrets())->prepare($schedule, true));
    }

    /**
     * An API body that changes the template but leaves out extra_vars
     * cannot keep the answers: they would follow the schedule.
     */
    public function testATemplateChangeWithoutExtraVarsIsRefusedWhenAnswersAreStored(): void
    {
        $schedule = $this->storedSchedule();
        $extraVars = $schedule->extra_vars;
        $schedule->job_template_id = (int)$this->otherTemplate()->id;

        $this->assertSame('The job template changed: send extra_vars with the password answers again.', (new ScheduleSecrets())->prepare($schedule, false));
        $this->assertSame($extraVars, $schedule->extra_vars);
    }

    /**
     * Without stored answers, the stored extra vars are split again for the
     * new template: its password fields' values are sealed.
     */
    public function testATemplateChangeWithoutExtraVarsSealsTheNewTemplatesAnswers(): void
    {
        $schedule = $this->createPlainSchedule((int)$this->plainTemplate()->id, $this->userId, '{"api_token":"token-value-9","x":1}');
        $schedule->job_template_id = (int)$this->otherTemplate()->id;
        $secrets = new ScheduleSecrets();

        $this->assertNull($secrets->prepare($schedule, false));

        $this->assertSame('{"api_token":"$encrypted$","x":1}', $schedule->extra_vars);
        $this->assertSame(['api_token' => 'token-value-9'], SurveySecrets::open($schedule->getSealedSecretExtraVars()));
        $this->assertSame(['api_token'], $secrets->auditContext()['secret_keys_added'] ?? null);
    }

    // -- Failures ---------------------------------------------------------------

    /**
     * Answers sealed with an APP_SECRET_KEY that has been replaced cannot be
     * kept: an error, never a 500 and never a silent drop. Entering every
     * value again works.
     */
    public function testUndecryptableAnswersCannotBeKeptButCanBeReplaced(): void
    {
        $schedule = $this->storedSchedule();
        $_ENV['APP_SECRET_KEY'] = 'a-rotated-key-that-cannot-open-it-0123';

        $schedule->extra_vars = '{"app_env":"prod","db_password":"$encrypted$"}';
        $this->assertSame('The stored password answers cannot be decrypted; enter the real values.', (new ScheduleSecrets())->prepare($schedule, true));

        $schedule->extra_vars = '{"app_env":"prod","db_password":"' . self::NEW_ANSWER . '"}';
        $secrets = new ScheduleSecrets();
        $this->assertNull($secrets->prepare($schedule, true));
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::NEW_ANSWER], SurveySecrets::open($schedule->getSealedSecretExtraVars()));
        $this->assertSame([self::SURVEY_PASSWORD_FIELD], $secrets->auditContext()['secret_keys_replaced'] ?? null, 'named by its placeholder');
    }

    public function testExtraVarsThatCannotBeEncodedAgainAreRefused(): void
    {
        $schedule = $this->newSchedule('{"db_password":"' . self::ANSWER . '","huge":1e999}');

        $this->assertSame(ScheduleSecrets::NOT_ENCODABLE, (new ScheduleSecrets())->prepare($schedule, true));
        $this->assertNull($schedule->getSealedSecretExtraVars());
    }

    /**
     * The placeholder can make extra vars that fit the column longer than it.
     */
    public function testExtraVarsTooLongWithThePlaceholderAreRefused(): void
    {
        $submitted = '{"db_password":"x","pad":"' . str_repeat('a', 65500) . '"}';
        $this->assertLessThanOrEqual(65535, strlen($submitted));
        $schedule = $this->newSchedule($submitted);

        $this->assertSame(ScheduleSecrets::TOO_LONG, (new ScheduleSecrets())->prepare($schedule, true));
        $this->assertNull($schedule->getSealedSecretExtraVars());
    }

    public function testAnswersThatCannotBeEncryptedAreRefused(): void
    {
        $schedule = $this->newSchedule('{"db_password":"' . self::ANSWER . '"}');
        $_ENV['APP_SECRET_KEY'] = 'short';

        $this->assertSame('The password answers cannot be encrypted. Check APP_SECRET_KEY.', (new ScheduleSecrets())->prepare($schedule, true));
        $this->assertSame('{"db_password":"' . self::ANSWER . '"}', $schedule->extra_vars, 'nothing is assigned');
    }

    /**
     * Code running before m000080: schedules without answers save, one with
     * an answer gets an actionable error instead of losing it.
     */
    public function testBeforeTheMigrationOnlyAnswersAreRefused(): void
    {
        $schedule = new class () extends Schedule {
            public function attributes(): array
            {
                return array_values(array_diff(parent::attributes(), ['secret_extra_vars']));
            }
        };
        $schedule->job_template_id = (int)$this->template->id;

        $schedule->extra_vars = '{"app_env":"prod"}';
        $this->assertNull((new ScheduleSecrets())->prepare($schedule, true));

        $schedule->extra_vars = '{"db_password":"' . self::ANSWER . '"}';
        $this->assertSame('The database update has not finished; run the migrations.', (new ScheduleSecrets())->prepare($schedule, true));
    }

    // The parameters of ScheduleSecrets and ScheduleLaunchSecrets that carry
    // answers are #[\SensitiveParameter], at a save or at a launch, in every
    // method: tests/unit/SensitiveParametersTest.php (MARKED, MUST_BE_MARKED).

    // -- Helpers ----------------------------------------------------------------

    private function newSchedule(?string $extraVars): Schedule
    {
        $schedule = new Schedule();
        $schedule->name = 'new-schedule-' . uniqid('', true);
        $schedule->job_template_id = (int)$this->template->id;
        $schedule->cron_expression = '0 2 * * *';
        $schedule->timezone = 'UTC';
        $schedule->extra_vars = $extraVars;
        $schedule->created_by = $this->userId;

        return $schedule;
    }

    /**
     * A saved schedule of the password template with the answer stored.
     */
    private function storedSchedule(): Schedule
    {
        return $this->createScheduleWithAnswers((int)$this->template->id, $this->userId, [self::SURVEY_PASSWORD_FIELD => self::ANSWER]);
    }

    /**
     * A template whose survey has the password fields db_password and api_token.
     */
    private function otherTemplate(): JobTemplate
    {
        $template = $this->plainTemplate();
        $template->survey_fields = (string)json_encode([
            ['name' => 'db_password', 'label' => 'DB', 'type' => SurveyField::TYPE_PASSWORD, 'required' => false, 'default' => '', 'options' => [], 'hint' => ''],
            ['name' => 'api_token', 'label' => 'Token', 'type' => SurveyField::TYPE_PASSWORD, 'required' => false, 'default' => '', 'options' => [], 'hint' => ''],
        ]);
        $template->save(false);

        return $template;
    }

    private function plainTemplate(): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
    }
}
