<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\SurveySecretException;
use app\components\SurveySecretRowMasker;
use app\components\SurveySecrets;
use app\tests\unit\TraceArguments;
use PHPUnit\Framework\TestCase;

/**
 * SurveySecretRowMasker: what one stored row looks like after the backfill
 * moved its plaintext password answers into the sealed set. Rows are built
 * the way releases up to 2.8.1 stored them: job.extra_vars and the payload's
 * extra vars as the launch encoded them, the command as the claim joined it.
 */
class SurveySecretRowMaskerTest extends TestCase
{
    /** A test value standing for a password answer. */
    private const ANSWER = 'masker-answer-marker-5521';

    private const NAMES = ['db_password', 'api_token'];

    private mixed $originalKey;

    private SurveySecretRowMasker $masker;

    protected function setUp(): void
    {
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = 'row-masker-test-key-0123456789';
        $this->masker = new SurveySecretRowMasker();
    }

    protected function tearDown(): void
    {
        if ($this->originalKey === null) {
            unset($_ENV['APP_SECRET_KEY']);
        } else {
            $_ENV['APP_SECRET_KEY'] = $this->originalKey;
        }
    }

    // -- job rows ----------------------------------------------------------------

    public function testAPlaintextJobRowIsSealedAndMaskedInEveryColumn(): void
    {
        $vars = ['app_env' => 'prod', 'db_password' => self::ANSWER, 'path' => '/opt/app', 'städte' => ['köln', 'bonn']];
        $row = self::legacyJobRow($vars, true);

        $result = $this->masker->maskJob($row, self::NAMES);

        $masked = self::json(['app_env' => 'prod', 'db_password' => SurveySecrets::PLACEHOLDER, 'path' => '/opt/app', 'städte' => ['köln', 'bonn']]);
        $this->assertSame(SurveySecretRowMasker::STATUS_CHANGED, $result['status']);
        $this->assertFalse($result['command_cleared']);
        $this->assertSame(['extra_vars', 'runner_payload', 'execution_command', 'secret_extra_vars'], array_keys($result['columns']));
        $this->assertSame($masked, $result['columns']['extra_vars'], 'encoded as a launch encodes it');
        $this->assertSame(self::legacyPayload($masked), $result['columns']['runner_payload'], 'only the extra vars of the payload change');
        $this->assertSame(self::legacyCommand($masked, true), $result['columns']['execution_command']);
        $this->assertSame(['db_password' => self::ANSWER], SurveySecrets::open($result['columns']['secret_extra_vars']));
        foreach (['extra_vars', 'runner_payload', 'execution_command', 'secret_extra_vars'] as $column) {
            $this->assertStringNotContainsString(self::ANSWER, (string)$result['columns'][$column], $column);
        }
    }

    /**
     * The API and triggers could send any JSON type; the sealed set keeps it.
     */
    public function testAnswersOfEveryJsonTypeAreSealedAsTheyWere(): void
    {
        $answers = [
            'db_password' => ['nested' => ['list' => [1, 2.5, true, null], 'text' => 'ünï ' . self::ANSWER]],
            'api_token' => 9007199254740993,
        ];
        $row = self::legacyJobRow(['region' => 'eu'] + $answers, false);

        $result = $this->masker->maskJob($row, self::NAMES);

        $this->assertSame($answers, SurveySecrets::open($result['columns']['secret_extra_vars']));
        $this->assertSame(
            self::json(['region' => 'eu', 'db_password' => SurveySecrets::PLACEHOLDER, 'api_token' => SurveySecrets::PLACEHOLDER]),
            $result['columns']['extra_vars']
        );
        $this->assertNull($result['columns']['execution_command'], 'an unclaimed job has no command');
    }

    public function testANullAnswerIsSealedToo(): void
    {
        $result = $this->masker->maskJob(self::legacyJobRow(['db_password' => null], false), self::NAMES);

        $this->assertSame(SurveySecretRowMasker::STATUS_CHANGED, $result['status']);
        $this->assertSame(['db_password' => null], SurveySecrets::open($result['columns']['secret_extra_vars']));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function rowsWithoutAPlaintextAnswerProvider(): array
    {
        $done = self::json(['app_env' => 'prod', 'db_password' => SurveySecrets::PLACEHOLDER]);

        return [
            'no password key' => [['extra_vars' => self::json(['app_env' => 'prod', 'other_password' => 'not-a-field']), 'runner_payload' => null]],
            'already masked' => [['extra_vars' => $done, 'runner_payload' => self::legacyPayload($done), 'execution_command' => self::legacyCommand($done, false)]],
            'no extra vars' => [['extra_vars' => null, 'runner_payload' => null, 'execution_command' => null, 'secret_extra_vars' => null]],
            'missing columns' => [[]],
            'extra vars not valid JSON' => [['extra_vars' => '{"db_password": ', 'runner_payload' => '{"extra_vars": 5']],
            'extra vars a list' => [['extra_vars' => '["db_password", "x"]', 'runner_payload' => '"text"']],
            'payload extra vars not text' => [['runner_payload' => json_encode(['extra_vars' => ['db_password' => 'x']])]],
            'empty strings' => [['extra_vars' => '', 'runner_payload' => '', 'execution_command' => '']],
        ];
    }

    /**
     * @dataProvider rowsWithoutAPlaintextAnswerProvider
     * @param array<string, mixed> $row
     */
    public function testARowWithoutAPlaintextAnswerIsLeftAsItIs(array $row): void
    {
        $this->assertSame(
            ['status' => SurveySecretRowMasker::STATUS_UNCHANGED, 'columns' => [], 'command_cleared' => false],
            $this->masker->maskJob($row, self::NAMES)
        );
    }

    /**
     * The runner gets the payload's value, so that is the one sealed.
     */
    public function testThePayloadValueWinsOverTheJobValue(): void
    {
        $row = self::legacyJobRow(['db_password' => 'from-the-job'], true);
        $row['runner_payload'] = self::legacyPayload(self::json(['db_password' => self::ANSWER]));

        $result = $this->masker->maskJob($row, self::NAMES);

        $this->assertSame(['db_password' => self::ANSWER], SurveySecrets::open($result['columns']['secret_extra_vars']));
        $this->assertStringNotContainsString('from-the-job', (string)$result['columns']['extra_vars']);
        $this->assertStringNotContainsString(self::ANSWER, (string)$result['columns']['runner_payload']);
    }

    /**
     * A job whose own extra vars lack the answer keeps them byte for byte.
     */
    public function testOnlyThePayloadHoldsTheAnswer(): void
    {
        $vars = self::json(['db_password' => self::ANSWER, 'x' => '/a']);
        $row = [
            'extra_vars' => "{\n  \"note\": \"kept as written\"\n}",
            'runner_payload' => self::legacyPayload($vars),
            'execution_command' => self::legacyCommand($vars, false),
            'secret_extra_vars' => null,
        ];

        $result = $this->masker->maskJob($row, self::NAMES);

        $masked = self::json(['db_password' => SurveySecrets::PLACEHOLDER, 'x' => '/a']);
        $this->assertSame($row['extra_vars'], $result['columns']['extra_vars']);
        $this->assertSame(self::legacyPayload($masked), $result['columns']['runner_payload']);
        $this->assertSame(self::legacyCommand($masked, false), $result['columns']['execution_command']);
    }

    /**
     * A job whose payload lacks the answer (or is damaged) keeps payload and
     * command as they are.
     */
    public function testOnlyTheJobHoldsTheAnswer(): void
    {
        $row = [
            'extra_vars' => self::json(['db_password' => self::ANSWER]),
            'runner_payload' => '{not json',
            'execution_command' => 'ansible-playbook site.yml',
            'secret_extra_vars' => null,
        ];

        $result = $this->masker->maskJob($row, self::NAMES);

        $this->assertSame(self::json(['db_password' => SurveySecrets::PLACEHOLDER]), $result['columns']['extra_vars']);
        $this->assertSame('{not json', $result['columns']['runner_payload']);
        $this->assertSame('ansible-playbook site.yml', $result['columns']['execution_command']);
        $this->assertFalse($result['command_cleared']);
    }

    /**
     * A job launched by this release (sealed answers) whose template got
     * another password field afterwards: both end up sealed, and an answer
     * that was sealed already wins, as the claim gives the runner that one.
     */
    public function testExistingSealedAnswersAreKeptAndWin(): void
    {
        $row = self::legacyJobRow(['db_password' => 'plaintext-copy', 'api_token' => self::ANSWER], false);
        $row['secret_extra_vars'] = SurveySecrets::seal(['db_password' => 'sealed-before', 'carried_key' => 'carried']);

        $result = $this->masker->maskJob($row, self::NAMES);

        $this->assertSame(
            ['db_password' => 'sealed-before', 'carried_key' => 'carried', 'api_token' => self::ANSWER],
            SurveySecrets::open($result['columns']['secret_extra_vars'])
        );
        $this->assertSame(
            self::json(['db_password' => SurveySecrets::PLACEHOLDER, 'api_token' => SurveySecrets::PLACEHOLDER]),
            $result['columns']['extra_vars']
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function undecryptableProvider(): array
    {
        return [
            'not base64' => ['%%% not sealed %%%'],
            'too short' => [base64_encode('short')],
            'another key' => ['another-key'],
        ];
    }

    /**
     * Regression guard: existing sealed answers are never dropped; the row
     * stays as it is and is counted.
     *
     * @dataProvider undecryptableProvider
     */
    public function testAnUndecryptableSealedSetLeavesTheRowAsItIs(string $sealed): void
    {
        if ($sealed === 'another-key') {
            $_ENV['APP_SECRET_KEY'] = 'the-key-before-it-was-changed-42';
            $sealed = (string)SurveySecrets::seal(['db_password' => 'old']);
            $_ENV['APP_SECRET_KEY'] = 'row-masker-test-key-0123456789';
        }
        $row = self::legacyJobRow(['db_password' => self::ANSWER], true) + ['secret_extra_vars' => null];
        $row['secret_extra_vars'] = $sealed;

        $this->assertSame(
            ['status' => SurveySecretRowMasker::STATUS_UNDECRYPTABLE, 'columns' => [], 'command_cleared' => false],
            $this->masker->maskJob($row, self::NAMES)
        );
        $this->assertSame(
            ['status' => SurveySecretRowMasker::STATUS_UNDECRYPTABLE, 'columns' => [], 'command_cleared' => false],
            $this->masker->maskSchedule(['extra_vars' => self::json(['db_password' => self::ANSWER]), 'secret_extra_vars' => $sealed], self::NAMES)
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unrepairableCommandProvider(): array
    {
        return [
            'seeded placeholder' => ['e2e-artifact-job'],
            'other formatting' => ['ansible-playbook site.yml --extra-vars {"db_password": "' . self::ANSWER . '"}'],
            'quoted argument' => ["ansible-playbook site.yml --extra-vars '" . self::json(['db_password' => self::ANSWER]) . "'"],
        ];
    }

    /**
     * @dataProvider unrepairableCommandProvider
     */
    public function testACommandWithoutTheStoredExtraVarsIsCleared(string $command): void
    {
        $row = self::legacyJobRow(['db_password' => self::ANSWER], false);
        $row['execution_command'] = $command;

        $result = $this->masker->maskJob($row, self::NAMES);

        $this->assertSame(SurveySecretRowMasker::STATUS_CHANGED, $result['status']);
        $this->assertNull($result['columns']['execution_command']);
        $this->assertTrue($result['command_cleared']);
    }

    /**
     * The 2.9 claim puts an environment prefix in front for old runners.
     */
    public function testACommandWithAnEnvironmentPrefixIsRepaired(): void
    {
        $vars = self::json(['db_password' => self::ANSWER]);
        $row = self::legacyJobRow(['db_password' => self::ANSWER], false);
        $row['execution_command'] = '/usr/bin/env ANSIBLE_VERBOSITY=0 ' . self::legacyCommand($vars, false);

        $result = $this->masker->maskJob($row, self::NAMES);

        $masked = self::json(['db_password' => SurveySecrets::PLACEHOLDER]);
        $this->assertSame('/usr/bin/env ANSIBLE_VERBOSITY=0 ' . self::legacyCommand($masked, false), $result['columns']['execution_command']);
        $this->assertFalse($result['command_cleared']);
    }

    public function testAnEmptyCommandStaysEmpty(): void
    {
        $row = self::legacyJobRow(['db_password' => self::ANSWER], false);
        $row['execution_command'] = '';

        $result = $this->masker->maskJob($row, self::NAMES);

        $this->assertSame('', $result['columns']['execution_command']);
        $this->assertFalse($result['command_cleared']);
    }

    /**
     * MariaDB rejects (strict mode) or truncates a value longer than its
     * TEXT column; the placeholder is longer than a short answer.
     *
     * @return array<string, array{0: string}>
     */
    public static function overflowingColumnProvider(): array
    {
        return ['extra_vars' => ['extra_vars'], 'runner_payload' => ['runner_payload'], 'execution_command' => ['execution_command']];
    }

    /**
     * @dataProvider overflowingColumnProvider
     */
    public function testARowWhoseMaskedColumnWouldNotFitIsLeftAsItIs(string $column): void
    {
        $row = self::rowThatGrowsTo(SurveySecretRowMasker::TEXT_MAX_BYTES + 1, $column);

        $this->assertSame(
            ['status' => SurveySecretRowMasker::STATUS_TOO_LONG, 'columns' => [], 'command_cleared' => false],
            $this->masker->maskJob($row, self::NAMES)
        );
    }

    /**
     * @dataProvider overflowingColumnProvider
     */
    public function testAMaskedColumnOfExactlyTheLimitFits(string $column): void
    {
        $row = self::rowThatGrowsTo(SurveySecretRowMasker::TEXT_MAX_BYTES, $column);

        $result = $this->masker->maskJob($row, self::NAMES);

        $this->assertSame(SurveySecretRowMasker::STATUS_CHANGED, $result['status']);
        $this->assertSame(SurveySecretRowMasker::TEXT_MAX_BYTES, strlen((string)$result['columns'][$column]));
    }

    /**
     * JSON allows 1e999; PHP reads it as INF and cannot write it back. The
     * row fails with a fixed message (no value in it or in the trace).
     *
     * Regression (fix round 4): the trace check read only the string form,
     * where the answer never shows: it prints the row as "Array" and the
     * first 15 characters of the JSON texts. So it passed without the marks
     * of maskJob($row), masked($json) and encode($json) too. TraceArguments
     * reads the arguments themselves.
     */
    public function testExtraVarsThatCannotBeEncodedAgainFailTheRow(): void
    {
        $row = ['extra_vars' => '{"db_password":"' . self::ANSWER . '","huge":1e999}', 'runner_payload' => null];

        try {
            $this->masker->maskJob($row, self::NAMES);
            $this->fail('expected an exception');
        } catch (\UnexpectedValueException $e) {
            $this->assertSame('The masked JSON cannot be encoded.', $e->getMessage());
            TraceArguments::assertSecretAbsent(self::ANSWER, $e);
        }
    }

    public function testAnAnswerThatCannotBeSealedFailsTheRow(): void
    {
        $this->expectException(SurveySecretException::class);
        $this->masker->maskJob(['extra_vars' => '{"db_password":1e999}'], self::NAMES);
    }

    // -- schedule rows -----------------------------------------------------------

    public function testAPlaintextScheduleIsSealedAndMaskedAsTheFormWritesJson(): void
    {
        $row = ['extra_vars' => "{\n  \"path\": \"/opt/app\",\n  \"db_password\": \"" . self::ANSWER . "\",\n  \"stadt\": \"köln\"\n}", 'secret_extra_vars' => null];

        $result = $this->masker->maskSchedule($row, self::NAMES);

        $this->assertSame(SurveySecretRowMasker::STATUS_CHANGED, $result['status']);
        $this->assertSame(['extra_vars', 'secret_extra_vars'], array_keys($result['columns']));
        $this->assertSame('{"path":"/opt/app","db_password":"$encrypted$","stadt":"köln"}', $result['columns']['extra_vars']);
        $this->assertSame(['db_password' => self::ANSWER], SurveySecrets::open($result['columns']['secret_extra_vars']));
        $this->assertFalse($result['command_cleared']);
    }

    public function testAScheduleKeepsItsSealedAnswers(): void
    {
        $row = [
            'extra_vars' => self::json(['db_password' => SurveySecrets::PLACEHOLDER, 'api_token' => self::ANSWER]),
            'secret_extra_vars' => SurveySecrets::seal(['db_password' => 'sealed-before']),
        ];

        $result = $this->masker->maskSchedule($row, self::NAMES);

        $this->assertSame(['db_password' => 'sealed-before', 'api_token' => self::ANSWER], SurveySecrets::open($result['columns']['secret_extra_vars']));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unchangedScheduleProvider(): array
    {
        return [
            'masked' => [['extra_vars' => '{"db_password":"$encrypted$"}', 'secret_extra_vars' => null]],
            'no extra vars' => [['extra_vars' => null]],
            'not JSON' => [['extra_vars' => 'db_password=secret']],
        ];
    }

    /**
     * @dataProvider unchangedScheduleProvider
     * @param array<string, mixed> $row
     */
    public function testAScheduleWithoutAPlaintextAnswerIsLeftAsItIs(array $row): void
    {
        $this->assertSame(
            ['status' => SurveySecretRowMasker::STATUS_UNCHANGED, 'columns' => [], 'command_cleared' => false],
            $this->masker->maskSchedule($row, self::NAMES)
        );
    }

    public function testAScheduleWhoseMaskedExtraVarsWouldNotFitIsLeftAsItIs(): void
    {
        $filler = SurveySecretRowMasker::TEXT_MAX_BYTES - strlen('{"pad":"","db_password":"x"}');
        $row = ['extra_vars' => '{"pad":"' . str_repeat('a', $filler) . '","db_password":"x"}'];
        $this->assertSame(SurveySecretRowMasker::TEXT_MAX_BYTES, strlen($row['extra_vars']));

        $this->assertSame(SurveySecretRowMasker::STATUS_TOO_LONG, $this->masker->maskSchedule($row, self::NAMES)['status']);
    }

    // -- a password field named "0" -------------------------------------------------

    /**
     * The answer of a password field named "0" as the only extra var: the
     * masked extra vars, in the job, the payload and the command, stay a
     * JSON object.
     *
     * Regression (task 3 of R2V2-1): json_decode() makes the key 0, and
     * json_encode() writes [0 => ...] as a list, so {"0": ...} became [...].
     */
    public function testAJobWhoseOnlyKeyIsAPasswordFieldNamedZeroStaysAnObject(): void
    {
        $json = '{"0":"' . self::ANSWER . '"}';
        $row = ['extra_vars' => $json, 'runner_payload' => self::legacyPayload($json), 'execution_command' => self::legacyCommand($json, true), 'secret_extra_vars' => null];

        $result = $this->masker->maskJob($row, ['0']);

        $this->assertSame(SurveySecretRowMasker::STATUS_CHANGED, $result['status']);
        $this->assertSame('{"0":"$encrypted$"}', $result['columns']['extra_vars']);
        $this->assertSame(self::legacyPayload('{"0":"$encrypted$"}'), $result['columns']['runner_payload']);
        $this->assertSame(self::legacyCommand('{"0":"$encrypted$"}', true), $result['columns']['execution_command']);
        $this->assertSame(['0' => self::ANSWER], SurveySecrets::open($result['columns']['secret_extra_vars']));
    }

    public function testAScheduleWhoseOnlyKeyIsAPasswordFieldNamedZeroStaysAnObject(): void
    {
        $result = $this->masker->maskSchedule(['extra_vars' => '{"0":"' . self::ANSWER . '"}', 'secret_extra_vars' => null], ['0']);

        $this->assertSame('{"0":"$encrypted$"}', $result['columns']['extra_vars']);
        $this->assertSame(['0' => self::ANSWER], SurveySecrets::open($result['columns']['secret_extra_vars']));
    }

    /**
     * 2.8.1's launch merged the key "0" as 0, so a lone such answer was
     * stored as the list ["..."]: the position is the key, the list stays a
     * list.
     */
    public function testAJobStoredAsAListStaysAList(): void
    {
        $json = '["' . self::ANSWER . '"]';
        $row = ['extra_vars' => $json, 'runner_payload' => self::legacyPayload($json), 'execution_command' => self::legacyCommand($json, false), 'secret_extra_vars' => null];

        $result = $this->masker->maskJob($row, ['0']);

        $this->assertSame('["$encrypted$"]', $result['columns']['extra_vars']);
        $this->assertSame(self::legacyPayload('["$encrypted$"]'), $result['columns']['runner_payload']);
        $this->assertSame(self::legacyCommand('["$encrypted$"]', false), $result['columns']['execution_command']);
    }

    // -- survey definitions -------------------------------------------------------

    public function testPasswordDefaultsAreRemovedAndEverythingElseIsKept(): void
    {
        $survey = json_encode([
            ['name' => 'app_env', 'label' => 'Umgebung', 'type' => 'text', 'default' => 'staging/eu'],
            ['name' => 'db_password', 'type' => 'password', 'default' => self::ANSWER, 'hint' => 'ä'],
            ['name' => 'db[pw]', 'type' => 'password', 'default' => 'unusable-name-default'],
            ['name' => 'count', 'type' => 'password', 'default' => 0],
            ['name' => 'flag', 'type' => 'password', 'default' => false],
            ['name' => 'blob', 'type' => 'password', 'default' => ['x' => 1]],
            ['name' => 'empty', 'type' => 'password', 'default' => ''],
            ['name' => 'none', 'type' => 'password', 'default' => null],
            ['name' => 'missing', 'type' => 'password'],
            ['name' => 'upper', 'type' => 'Password', 'default' => 'not a password field'],
            'not a field',
        ]);

        $cleared = SurveySecretRowMasker::withoutPasswordDefaults((string)$survey);

        $this->assertSame(json_encode([
            ['name' => 'app_env', 'label' => 'Umgebung', 'type' => 'text', 'default' => 'staging/eu'],
            ['name' => 'db_password', 'type' => 'password', 'default' => '', 'hint' => 'ä'],
            ['name' => 'db[pw]', 'type' => 'password', 'default' => ''],
            ['name' => 'count', 'type' => 'password', 'default' => ''],
            ['name' => 'flag', 'type' => 'password', 'default' => ''],
            ['name' => 'blob', 'type' => 'password', 'default' => ''],
            ['name' => 'empty', 'type' => 'password', 'default' => ''],
            ['name' => 'none', 'type' => 'password', 'default' => null],
            ['name' => 'missing', 'type' => 'password'],
            ['name' => 'upper', 'type' => 'Password', 'default' => 'not a password field'],
            'not a field',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $cleared, 'written as the survey editor writes it');
        $this->assertTrue(SurveySecretRowMasker::hasPasswordDefault((string)$survey));
    }

    public function testASurveyGivenAsAMapStaysAMap(): void
    {
        $cleared = SurveySecretRowMasker::withoutPasswordDefaults('{"first": {"name": "pw", "type": "password", "default": "x"}}');

        $this->assertSame('{"first":{"name":"pw","type":"password","default":""}}', $cleared);
    }

    /**
     * A survey map keyed "0" stays a map when a password default goes.
     */
    public function testASurveyMapKeyedByNumbersStaysAMap(): void
    {
        $cleared = SurveySecretRowMasker::withoutPasswordDefaults('{"0": {"name": "pw", "type": "password", "default": "x"}}');

        $this->assertSame('{"0":{"name":"pw","type":"password","default":""}}', $cleared);
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function surveyWithoutPasswordDefaultProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'not JSON' => ['[{"type": "password", "default": "x"'],
            'a scalar' => ['"password"'],
            'no password field' => ['[{"name": "a", "type": "text", "default": "b"}]'],
            'malformed entries' => ['[{"name": ["x"], "type": {"a": 1}, "default": [1]}, 5, null]'],
            'password without default' => ['[{"name": "pw", "type": "password", "default": ""}]'],
        ];
    }

    /**
     * @dataProvider surveyWithoutPasswordDefaultProvider
     */
    public function testASurveyWithoutAPasswordDefaultIsNotRewritten(?string $survey): void
    {
        $this->assertNull(SurveySecretRowMasker::withoutPasswordDefaults($survey));
        $this->assertFalse(SurveySecretRowMasker::hasPasswordDefault($survey));
        $this->assertSame(
            ['status' => SurveySecretRowMasker::STATUS_UNCHANGED, 'columns' => [], 'command_cleared' => false],
            $this->masker->maskSurvey(['survey_fields' => $survey])
        );
    }

    public function testMaskSurveyReturnsTheSurveyWithoutDefaults(): void
    {
        $result = $this->masker->maskSurvey(['survey_fields' => '[{"name":"pw","type":"password","default":"' . self::ANSWER . '"}]']);

        $this->assertSame(
            ['status' => SurveySecretRowMasker::STATUS_CHANGED, 'columns' => ['survey_fields' => '[{"name":"pw","type":"password","default":""}]'], 'command_cleared' => false],
            $result
        );
    }

    /**
     * PHP writes 1e9 back as 1000000000, so a survey near the limit can grow.
     */
    public function testASurveyThatWouldNotFitAfterwardsIsLeftAsItIs(): void
    {
        $options = implode(',', array_fill(0, 16000, '1e9'));
        $survey = '[{"name":"pw","type":"password","default":"x","options":[' . $options . ']}]';
        $this->assertLessThanOrEqual(SurveySecretRowMasker::TEXT_MAX_BYTES, strlen($survey));

        $this->assertSame(
            ['status' => SurveySecretRowMasker::STATUS_TOO_LONG, 'columns' => [], 'command_cleared' => false],
            $this->masker->maskSurvey(['survey_fields' => $survey])
        );
    }

    public function testASurveyThatCannotBeEncodedAgainThrowsAFixedMessage(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('The masked JSON cannot be encoded.');
        SurveySecretRowMasker::withoutPasswordDefaults('[{"name":"pw","type":"password","default":"x","hint":1e999}]');
    }

    // Which parameters carry answers and are marked #[\SensitiveParameter]:
    // tests/unit/SensitiveParametersTest.php, for the whole application.

    // -- helpers -------------------------------------------------------------------

    private static function json(mixed $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * A runner payload as JobLaunchService::buildRunnerPayload() writes it.
     */
    private static function legacyPayload(string $extraVars): string
    {
        return self::json([
            'template_id' => 7,
            'template_name' => 'Deploy – Köln',
            'project_id' => 3,
            'inventory_id' => 4,
            'credential_id' => null,
            'credential_ids' => [],
            'credential_snapshot' => [],
            'playbook' => 'deploy/site.yml',
            'extra_vars' => $extraVars,
            'limit' => null,
            'verbosity' => 2,
            'forks' => 5,
            'become' => false,
            'become_method' => 'sudo',
            'become_user' => 'root',
            'tags' => null,
            'skip_tags' => null,
            'check_mode' => true,
            'timeout_minutes' => 120,
        ]);
    }

    /**
     * The command as JobClaimService stored it: implode(' ', argv).
     */
    private static function legacyCommand(string $extraVars, bool $checkMode): string
    {
        return 'ansible-playbook -i __INVENTORY_TMP__ /var/www/runtime/projects/3/deploy/site.yml -vv --forks 5 --extra-vars ' . $extraVars
            . ($checkMode ? ' --check --diff' : '');
    }

    /**
     * A job row as releases up to 2.8.1 stored it.
     *
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    private static function legacyJobRow(array $vars, bool $claimed): array
    {
        $json = self::json($vars);

        return [
            'id' => 1,
            'extra_vars' => $json,
            'runner_payload' => self::legacyPayload($json),
            'execution_command' => $claimed ? self::legacyCommand($json, true) : null,
            'secret_extra_vars' => null,
        ];
    }

    /**
     * A legacy row whose $column is exactly $bytes long once masked: the
     * other columns stay small.
     *
     * @return array<string, mixed>
     */
    private static function rowThatGrowsTo(int $bytes, string $column): array
    {
        $growth = strlen(SurveySecrets::PLACEHOLDER) - strlen('x');
        $vars = self::json(['db_password' => 'x']);
        $payloadGrowth = strlen(self::legacyPayload(self::json(['db_password' => SurveySecrets::PLACEHOLDER]))) - strlen(self::legacyPayload($vars));
        $row = ['extra_vars' => $vars, 'runner_payload' => self::legacyPayload($vars), 'execution_command' => self::legacyCommand($vars, false), 'secret_extra_vars' => null];
        if ($column === 'extra_vars') {
            $pad = $bytes - $growth - strlen(self::json(['pad' => '', 'db_password' => 'x']));
            $row['extra_vars'] = self::json(['pad' => str_repeat('a', $pad), 'db_password' => 'x']);
        } elseif ($column === 'runner_payload') {
            $padded = json_decode(self::legacyPayload($vars), true);
            $padded['template_name'] = '';
            $pad = $bytes - $payloadGrowth - strlen(self::json($padded));
            $padded['template_name'] = str_repeat('a', $pad);
            $row['runner_payload'] = self::json($padded);
        } else {
            $row['execution_command'] = self::legacyCommand($vars, false) . ' --limit ' . str_repeat('h', $bytes - $growth - strlen(self::legacyCommand($vars, false) . ' --limit '));
        }

        return $row;
    }
}
