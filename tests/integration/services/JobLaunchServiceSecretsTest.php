<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\SurveySecretException;
use app\components\SurveySecrets;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\services\JobLaunchRejectedException;
use app\services\JobLaunchService;
use app\services\LaunchContext;
use app\tests\integration\DbTestCase;
use app\tests\unit\components\UnusablePasswordNames;
use app\tests\unit\TraceArguments;
use yii\log\Logger;

/**
 * Password answers at launch: a value is secret when its top-level key is a
 * password survey field of the template, whatever its source. It is stored
 * only sealed (job.secret_extra_vars); job.extra_vars and the runner payload
 * show the placeholder.
 *
 * Regression: survey password answers were stored in plaintext in
 * job.extra_vars and the runner payload, readable by every job viewer.
 */
class JobLaunchServiceSecretsTest extends DbTestCase
{
    use UnusablePasswordNames;

    private const ANSWER = 'launch-answer-marker-8812';
    private const PLACEHOLDER_REFUSAL = 'The value of "db_password" is the placeholder $encrypted$; send the real value.';

    private JobLaunchService $service;
    private int $userId;
    private mixed $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new JobLaunchService();
        $this->userId = (int)$this->createUser('launch-secrets')->id;
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
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

    // -- Where a password answer ends up ----------------------------------------

    public function testASurveyAnswerIsStoredOnlySealed(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);

        $job = $this->service->launch($template, $this->userId, [
            'survey' => [self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => self::ANSWER],
        ]);

        $stored = $this->reload($job);
        $public = [self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER];
        $this->assertSame($public, json_decode((string)$stored->extra_vars, true), 'other answers stay visible');
        $this->assertSame($stored->extra_vars, $stored->decodedRunnerPayload()['extra_vars']);
        $this->assertStringNotContainsString(self::ANSWER, (string)$stored->extra_vars . (string)$stored->runner_payload);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], SurveySecrets::open($stored->getSealedSecretExtraVars()));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function sourceProvider(): array
    {
        return [
            'explicit extra vars as JSON (API, trigger, Advanced Overrides)' => [['extra_vars' => '{"db_password":"launch-answer-marker-8812"}']],
            'explicit extra vars as a list of fields' => [['extra_vars' => ['db_password' => 'launch-answer-marker-8812']]],
            'explicit extra vars over a survey answer' => [['survey' => ['db_password' => 'survey'], 'extra_vars' => '{"db_password":"launch-answer-marker-8812"}']],
        ];
    }

    /**
     * @dataProvider sourceProvider
     * @param array<string, mixed> $overrides
     */
    public function testAPasswordKeyIsSecretWhateverItsSource(array $overrides): void
    {
        $job = $this->service->launch($this->createPasswordSurveyTemplate($this->userId), $this->userId, $overrides);

        $stored = $this->reload($job);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER], json_decode((string)$stored->extra_vars, true));
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], SurveySecrets::open($stored->getSealedSecretExtraVars()));
    }

    public function testAValueFromTheTemplatesOwnExtraVarsIsSecretToo(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $template->extra_vars = '{"db_password":"' . self::ANSWER . '","env":"prod"}';

        $job = $this->service->launch($template, $this->userId);

        $stored = $this->reload($job);
        $this->assertSame(['db_password' => SurveySecrets::PLACEHOLDER, 'env' => 'prod'], json_decode((string)$stored->extra_vars, true));
        $this->assertStringNotContainsString(self::ANSWER, (string)$stored->runner_payload);
        $this->assertSame(['db_password' => self::ANSWER], SurveySecrets::open($stored->getSealedSecretExtraVars()));
    }

    public function testAnswersKeepTheirJsonType(): void
    {
        $answer = ['user' => 'svc', 'pin' => 4711, 'keys' => ['a', 'b'], 'enabled' => true, 'ratio' => 0.5];

        $job = $this->service->launch($this->createPasswordSurveyTemplate($this->userId), $this->userId, [
            'extra_vars' => (string)json_encode([self::SURVEY_PASSWORD_FIELD => $answer]),
        ]);

        $this->assertSame([self::SURVEY_PASSWORD_FIELD => $answer], SurveySecrets::open($this->reload($job)->getSealedSecretExtraVars()));
    }

    public function testAnUnansweredPasswordFieldStoresNothingSecret(): void
    {
        $job = $this->service->launch($this->createPasswordSurveyTemplate($this->userId), $this->userId, [
            'survey' => [self::SURVEY_TEXT_FIELD => 'prod'],
        ]);

        $stored = $this->reload($job);
        $this->assertSame([self::SURVEY_TEXT_FIELD => 'prod'], json_decode((string)$stored->extra_vars, true));
        $this->assertNull($stored->getSealedSecretExtraVars());
    }

    public function testATemplateWithoutPasswordFieldsLaunchesAsBefore(): void
    {
        $template = $this->plainTemplate();
        $template->extra_vars = '{"env":"dev"}';

        $job = $this->service->launch($template, $this->userId, ['extra_vars' => '{"db_password":"visible","n":1}']);

        $stored = $this->reload($job);
        $this->assertSame('{"env":"dev","db_password":"visible","n":1}', $stored->extra_vars);
        $this->assertNull($stored->getSealedSecretExtraVars());
    }

    // -- Refused launches -------------------------------------------------------

    /**
     * Regression guard: a client that copies a job's extra vars (GET
     * /api/v1/jobs/{id}) into a new launch would send the placeholder as the
     * password.
     */
    public function testThePlaceholderAsAnAnswerIsRefused(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $before = (int)Job::find()->count();

        foreach ([['survey' => [self::SURVEY_PASSWORD_FIELD => '$encrypted$']], ['extra_vars' => '{"db_password":"$encrypted$"}']] as $overrides) {
            try {
                $this->service->launch($template, $this->userId, $overrides);
                $this->fail('expected a refusal');
            } catch (JobLaunchRejectedException $e) {
                $this->assertSame(self::PLACEHOLDER_REFUSAL, $e->getMessage());
            }
        }
        $this->assertSame($before, (int)Job::find()->count(), 'nothing is saved');
    }

    public function testThePlaceholderAsTheValueOfAnotherKeyIsJustText(): void
    {
        $job = $this->service->launch($this->createPasswordSurveyTemplate($this->userId), $this->userId, [
            'extra_vars' => '{"note":"$encrypted$"}',
        ]);

        $this->assertSame(['note' => '$encrypted$'], json_decode((string)$this->reload($job)->extra_vars, true));
    }

    /**
     * Regression guard: PHP turns survey[db[pw]] into the key "db[pw", so the
     * answer of such a field could not be kept secret.
     *
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function unusableNameProvider(): array
    {
        return [
            'brackets' => ['db[pw]', '"db[pw]"'],
            'numeric' => ['123', '"123"'],
            'empty' => ['', '""'],
            'not text' => [['a' => 1], '"{\"a\":1}"'],
        ];
    }

    /**
     * @dataProvider unusableNameProvider
     */
    public function testATemplateWithAnUnusablePasswordFieldNameIsRefused(mixed $name, string $quoted): void
    {
        $template = $this->plainTemplate();
        $template->survey_fields = (string)json_encode([['name' => 'env', 'type' => 'text'], ['name' => $name, 'type' => 'password']]);
        $before = (int)Job::find()->count();

        try {
            $this->service->launch($template, $this->userId, ['survey' => ['env' => 'prod']]);
            $this->fail('expected a refusal');
        } catch (JobLaunchRejectedException $e) {
            $this->assertSame(
                "Survey field {$quoted} has a name that cannot be used for a password answer; rename it in the survey before launching.",
                $e->getMessage()
            );
        }
        $this->assertSame($before, (int)Job::find()->count());
    }

    /**
     * Regression: a password field named "db_password\n", stored through the
     * API, counted as usable. API and trigger callers send the answer as
     * "db_password", a browser as "db_password\r\n", so it was stored in
     * plaintext. Such a name is unusable now: the launch is refused before
     * anything is stored.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function paddedNameProvider(): array
    {
        return [
            'line feed' => ["db_password\n", '"db_password\n"'],
            'CRLF' => ["db_password\r\n", '"db_password\r\n"'],
            'leading space' => [' db_password', '" db_password"'],
            'trailing space' => ['db_password ', '"db_password "'],
            'tab' => ["db_password\t", '"db_password\t"'],
        ];
    }

    /**
     * The same for a character outside ASCII ({@see unicodeNameProvider()}),
     * which the message shows escaped: the template is refused whether the
     * answer comes under the visible variable name (API, triggers,
     * schedules) or under the field's exact name (the web form).
     *
     * @dataProvider paddedNameProvider
     * @dataProvider unicodeNameProvider
     */
    public function testATemplateWithAPasswordFieldNameNoCallerSendsExactlyIsRefused(string $name, string $quoted): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $template->survey_fields = (string)json_encode([['name' => 'app_env', 'type' => 'text'], ['name' => $name, 'type' => 'password']]);
        $this->assertTrue($template->save(false));
        $before = (int)Job::find()->count();

        $sent = [
            'the variable name' => ['extra_vars' => '{"app_env":"prod","db_password":"' . self::ANSWER . '"}'],
            // A form posts every line break in a field name as CRLF.
            'the name as a browser posts it' => ['survey' => [(string)preg_replace('/\r?\n/', "\r\n", $name) => self::ANSWER]],
        ];
        foreach ($sent as $how => $overrides) {
            try {
                $this->service->launch($template, $this->userId, $overrides);
                $this->fail("expected a refusal for {$how}");
            } catch (JobLaunchRejectedException $e) {
                $this->assertSame(
                    "Survey field {$quoted} has a name that cannot be used for a password answer; rename it in the survey before launching.",
                    $e->getMessage()
                );
                TraceArguments::assertSecretAbsent(self::ANSWER, $e);
            }
        }
        $this->assertSame($before, (int)Job::find()->count(), 'nothing is stored');
    }

    public function testExtraVarsThatAreNotUtf8AreRefused(): void
    {
        $this->expectExceptionObject(new JobLaunchRejectedException('The extra vars cannot be stored: they contain characters that are not valid UTF-8.'));
        $this->service->launch($this->createPasswordSurveyTemplate($this->userId), $this->userId, ['survey' => ['note' => "bad \xB1"]]);
    }

    /**
     * Regression: an answer that is not UTF-8 (a web form can post one)
     * failed the launch with a SurveySecretException, which the API and
     * triggers answer with 500.
     */
    public function testAnAnswerThatIsNotUtf8CannotBeSealed(): void
    {
        $before = (int)Job::find()->count();
        try {
            $this->service->launch($this->createPasswordSurveyTemplate($this->userId), $this->userId, [
                'survey' => [self::SURVEY_PASSWORD_FIELD => "bad \xB1 " . self::ANSWER],
            ]);
            $this->fail('expected a refusal');
        } catch (JobLaunchRejectedException $e) {
            $this->assertSame('The password answers cannot be stored: they contain characters that are not valid UTF-8.', $e->getMessage());
            TraceArguments::assertSecretAbsent(self::ANSWER, $e);
        }
        $this->assertSame($before, (int)Job::find()->count());
    }

    /**
     * JSON allows 1e999; PHP reads it as INF and cannot write it back.
     *
     * Regression: such a number was refused as "not valid UTF-8", and under
     * a password field's key the launch failed with a SurveySecretException
     * (a 500 for the API and triggers) instead of a refusal.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function numberTooLargeProvider(): array
    {
        $extraVars = 'The extra vars cannot be stored: they contain a number too large for JSON.';
        $answers = 'The password answers cannot be stored: they contain a number too large for JSON.';

        return [
            'another key, as JSON (API)' => [['extra_vars' => '{"timeout_s": 1e999}'], $extraVars],
            'another key, decoded (trigger)' => [['extra_vars' => ['timeout_s' => INF]], $extraVars],
            'a password answer, as JSON (API)' => [['extra_vars' => '{"db_password": 1e999}'], $answers],
            'a password answer, decoded (trigger)' => [['extra_vars' => ['db_password' => -INF]], $answers],
            'a password answer in a list' => [['extra_vars' => '{"db_password": [1, 1e999]}'], $answers],
        ];
    }

    /**
     * @dataProvider numberTooLargeProvider
     * @param array<string, mixed> $overrides
     */
    public function testANumberTooLargeForJsonIsRefused(array $overrides, string $message): void
    {
        $before = (int)Job::find()->count();

        try {
            $this->service->launch($this->createPasswordSurveyTemplate($this->userId), $this->userId, $overrides);
            $this->fail('expected a refusal');
        } catch (JobLaunchRejectedException $e) {
            $this->assertSame($message, $e->getMessage());
        }
        $this->assertSame($before, (int)Job::find()->count(), 'nothing is stored');
    }

    public function testWithoutAUsableKeyAnswersCannotBeStoredButOtherLaunchesWork(): void
    {
        $_ENV['APP_SECRET_KEY'] = 'short';
        $template = $this->createPasswordSurveyTemplate($this->userId);

        try {
            $this->service->launch($template, $this->userId, ['survey' => [self::SURVEY_PASSWORD_FIELD => self::ANSWER]]);
            $this->fail('expected a SurveySecretException');
        } catch (SurveySecretException $e) {
            $this->assertSame('The password answers cannot be encrypted. Check APP_SECRET_KEY.', $e->getMessage());
            TraceArguments::assertSecretAbsent(self::ANSWER, $e);
        }

        $job = $this->service->launch($template, $this->userId, ['survey' => [self::SURVEY_TEXT_FIELD => 'prod']]);
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
    }

    // -- Carried answers --------------------------------------------------------

    public function testARelaunchCarriesTheAnswersAndAuditsTheirNamesOnly(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $source = $this->createJobWithSealedSecrets($template, $this->userId);

        $job = $this->service->launch($template, $this->userId, [
            // The original's public extra vars, without the carried keys.
            'extra_vars' => '{"app_env":"staging"}',
            LaunchContext::KEY => LaunchContext::forRelaunch((int)$source->id, [self::SURVEY_PASSWORD_FIELD => self::ANSWER]),
        ]);

        $stored = $this->reload($job);
        $this->assertSame([self::SURVEY_TEXT_FIELD => 'staging', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER], json_decode((string)$stored->extra_vars, true));
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], SurveySecrets::open($stored->getSealedSecretExtraVars()));
        $metadata = $this->launchAudit($job);
        $this->assertSame((int)$source->id, $metadata['relaunch_of']);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD], $metadata['carried_secret_keys']);
        $this->assertSame((int)$template->id, $metadata['template_id']);
        $this->assertStringNotContainsString(self::ANSWER, (string)json_encode($metadata));
    }

    /**
     * Once secret, always secret: an answer carried from a schedule stays
     * sealed although the template's survey no longer has that field, it
     * wins over the other sources, and it may be any value.
     */
    public function testCarriedAnswersStaySecretAndWin(): void
    {
        $template = $this->plainTemplate();
        $template->extra_vars = '{"old_password":"from-template"}';

        $job = $this->service->launch($template, $this->userId, [
            'extra_vars' => '{"old_password":"$encrypted$"}',
            LaunchContext::KEY => LaunchContext::forSchedule(77, ['old_password' => self::ANSWER]),
        ]);

        $stored = $this->reload($job);
        $this->assertSame(['old_password' => SurveySecrets::PLACEHOLDER], json_decode((string)$stored->extra_vars, true));
        $this->assertSame(['old_password' => self::ANSWER], SurveySecrets::open($stored->getSealedSecretExtraVars()));
        $this->assertSame(77, $this->launchAudit($job)['schedule_id']);
    }

    /**
     * A relaunch or a scheduled run carries the answer of a password field
     * while the extra vars it passes on may still hold the placeholder: the
     * carried answer wins, and the placeholder is no refusal for that key.
     * (RelaunchService and ScheduleSecrets strip carried keys first; this
     * pins the exemption itself.)
     */
    public function testACarriedAnswerOfAPasswordFieldWinsOverAPlaceholder(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);

        $job = $this->service->launch($template, $this->userId, [
            'extra_vars' => '{"app_env":"prod","db_password":"$encrypted$"}',
            LaunchContext::KEY => LaunchContext::forRelaunch(1, [self::SURVEY_PASSWORD_FIELD => self::ANSWER]),
        ]);

        $stored = $this->reload($job);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], SurveySecrets::open($stored->getSealedSecretExtraVars()));
        $this->assertSame([self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER], json_decode((string)$stored->extra_vars, true));
        $this->assertStringNotContainsString(self::ANSWER, (string)$stored->runner_payload);
    }

    public function testAuditContextAddsToTheLaunchEntryButCannotReplaceItsKeys(): void
    {
        $template = $this->plainTemplate();

        $job = $this->service->launch($template, $this->userId, [
            LaunchContext::KEY => LaunchContext::withAudit(['trigger_transport' => 'header', 'template_id' => 999]),
        ]);

        $metadata = $this->launchAudit($job);
        $this->assertSame('header', $metadata['trigger_transport']);
        $this->assertSame((int)$template->id, $metadata['template_id']);
        $this->assertNull($this->reload($job)->getSealedSecretExtraVars());
    }

    /**
     * Regression: the web launch passed posted overrides to the service.
     * Request data cannot produce an object, so whatever arrives under the
     * context key, or under the key the first design used, is ignored.
     */
    public function testOnlyAnInstanceCountsAsLaunchContext(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);

        $job = $this->service->launch($template, $this->userId, [
            'extra_vars' => '{"note":"visible"}',
            LaunchContext::KEY => ['carriedSecrets' => ['note' => 'x'], 'auditContext' => ['relaunch_of' => 1]],
            'secret_extra_vars' => ['note' => 'x'],
        ]);

        $stored = $this->reload($job);
        $this->assertSame(['note' => 'visible'], json_decode((string)$stored->extra_vars, true));
        $this->assertNull($stored->getSealedSecretExtraVars());
        $this->assertArrayNotHasKey('relaunch_of', $this->launchAudit($job));
    }

    // -- Failures while saving --------------------------------------------------

    /**
     * Regression: a database error put the SQL with every value (survey
     * answers included) into the exception message, which callers flashed,
     * logged and sent in schedule-failed notifications.
     */
    public function testADatabaseErrorNeitherPassesOnNorLogsTheSql(): void
    {
        $service = new class () extends JobLaunchService {
            protected function newJobRecord(): Job
            {
                return new class () extends Job {
                    public function save($runValidation = true, $attributeNames = null): bool
                    {
                        throw new \yii\db\Exception(
                            "SQLSTATE[22001]: String data, right truncated: 1406 Data too long\nThe SQL being executed was: INSERT INTO `job` (`extra_vars`) VALUES ('launch-answer-marker-8812')",
                            ['22001', 1406, "Data too long for column 'extra_vars' at row 1"]
                        );
                    }
                };
            }
        };
        $logger = new Logger();
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);

        try {
            $service->launch($this->plainTemplate(), $this->userId, ['extra_vars' => '{"note":"' . self::ANSWER . '"}']);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('Job launch failed: the job could not be saved.', $e->getMessage());
            $this->assertNull($e->getPrevious(), 'the database exception carries the SQL');
            TraceArguments::assertSecretAbsent(self::ANSWER, $e);
        } finally {
            \Yii::setLogger($previous);
        }

        $own = array_values(array_filter($logger->messages, static fn (array $m): bool => $m[2] === JobLaunchService::class));
        $this->assertCount(1, $own);
        $this->assertSame(Logger::LEVEL_ERROR, $own[0][1]);
        $this->assertSame('Job launch failed: the job could not be saved (SQLSTATE 22001, driver error 1406).', $own[0][0]);
    }

    /**
     * The same with a real database error: extra vars of 30000 characters
     * pass the rule (characters) but not the TEXT column (bytes).
     */
    public function testARealDatabaseErrorKeepsTheValuesOut(): void
    {
        $logger = new Logger();
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        $before = (int)Job::find()->count();

        try {
            $this->service->launch($this->plainTemplate(), $this->userId, ['extra_vars' => ['note' => self::ANSWER . str_repeat('€', 30000)]]);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('Job launch failed: the job could not be saved.', $e->getMessage());
            TraceArguments::assertSecretAbsent(self::ANSWER, $e);
        } finally {
            \Yii::setLogger($previous);
        }

        $this->assertSame($before, (int)Job::find()->count());
        $own = array_filter($logger->messages, static fn (array $m): bool => $m[2] === JobLaunchService::class);
        $this->assertStringNotContainsString(self::ANSWER, (string)json_encode(array_column($own, 0)));
        // 1406 "Data too long", or 1366 when the cut would split a character.
        $this->assertMatchesRegularExpression('/^Job launch failed: the job could not be saved \\(SQLSTATE 22\\d{3}, driver error \\d+\\)\\.$/', (string)current($own)[0]);
    }

    public function testOtherErrorsWhileSavingKeepTheirMessage(): void
    {
        $template = $this->plainTemplate();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Job launch failed: Failed to save job record: \{"verbosity"/');
        $this->service->launch($template, $this->userId, ['verbosity' => 9]);
    }

    /**
     * Code running before m000080 (a schedule runner that starts while the
     * app container migrates): launches without answers work, and one with
     * an answer fails with an actionable message instead of losing it.
     */
    public function testBeforeTheMigrationOnlyLaunchesWithAnswersFail(): void
    {
        $service = new class () extends JobLaunchService {
            protected function newJobRecord(): Job
            {
                return new class () extends Job {
                    public function attributes(): array
                    {
                        return array_values(array_diff(parent::attributes(), ['secret_extra_vars']));
                    }
                };
            }
        };
        $template = $this->createPasswordSurveyTemplate($this->userId);

        $job = $service->launch($template, $this->userId, ['survey' => [self::SURVEY_TEXT_FIELD => 'prod']]);
        $this->assertSame(Job::STATUS_QUEUED, $this->reload($job)->status);

        $before = (int)Job::find()->count();
        try {
            $service->launch($template, $this->userId, ['survey' => [self::SURVEY_PASSWORD_FIELD => self::ANSWER]]);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('The database update has not finished; run the migrations.', $e->getMessage());
        }
        $this->assertSame($before, (int)Job::find()->count());
    }

    // -- Helpers ----------------------------------------------------------------

    private function plainTemplate(): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
    }

    private function reload(Job $job): Job
    {
        $stored = Job::findOne($job->id);
        $this->assertNotNull($stored);

        return $stored;
    }

    /**
     * @return array<string, mixed>
     */
    private function launchAudit(Job $job): array
    {
        /** @var AuditLog|null $entry */
        $entry = AuditLog::find()->where(['action' => AuditLog::ACTION_JOB_LAUNCHED, 'object_type' => 'job', 'object_id' => $job->id])->one();
        $this->assertNotNull($entry);
        $metadata = json_decode((string)$entry->metadata, true);
        $this->assertIsArray($metadata);

        return $metadata;
    }
}
