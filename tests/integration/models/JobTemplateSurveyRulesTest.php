<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\components\SurveyDefinition;
use app\components\SurveySecrets;
use app\components\TemplateSurveyValidator;
use app\models\JobTemplate;
use app\models\Schedule;
use app\tests\integration\DbTestCase;
use app\tests\unit\components\UnusablePasswordNames;

/**
 * The survey rules of job templates: checked only when the survey or the
 * extra vars change against the baseline (the stored values, or the cloned
 * template's), so templates saved before the rules keep saving and cloning.
 */
class JobTemplateSurveyRulesTest extends DbTestCase
{
    use UnusablePasswordNames;

    private const NAME_RULE = 'Survey field name %s is not a valid variable name: use letters, digits and underscores, '
        . 'and start with a letter or an underscore.';

    /** A survey saved before the rules: a name with a dash and a password default. */
    private const LEGACY_SURVEY = '[{"name":"db-host","type":"text","default":"db1"},{"name":"db_password","type":"password","default":"legacy-default"}]';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('survey-rules')->id;
    }

    // -- New templates ----------------------------------------------------------

    public function testANewTemplateWithAValidSurveySaves(): void
    {
        $template = $this->newTemplate('[{"name":"env","type":"text"},{"name":"db_password","type":"password","default":""}]');

        $this->assertTrue($template->validate(), (string)json_encode($template->errors));
    }

    public function testANewTemplateIsCheckedAgainstAnEmptySurvey(): void
    {
        $template = $this->newTemplate(self::LEGACY_SURVEY);

        $this->assertFalse($template->validate());
        $this->assertSame(
            [sprintf(self::NAME_RULE, '"db-host"'), SurveyDefinition::PASSWORD_DEFAULT],
            $template->getErrors('survey_fields')
        );
    }

    /**
     * Regression: survey JSON whose names, types or defaults were lists or
     * objects was stored, and reading it failed with "Array to string
     * conversion" (a 500 for the API).
     */
    public function testMalformedEntriesAreValidationErrors(): void
    {
        $template = $this->newTemplate('[{"name":{"a":1},"type":["text"],"default":[1]}]');

        $this->assertFalse($template->validate());
        $this->assertSame([
            sprintf(self::NAME_RULE, '{"a":1}'),
            'Survey field {"a":1} has an unknown type ["text"]; use one of: text, textarea, integer, boolean, select, password.',
            'The default of survey field {"a":1} must be a single value, not a list or an object.',
        ], $template->getErrors('survey_fields'));
    }

    public function testTheValidJsonRuleStillComesFirst(): void
    {
        $template = $this->newTemplate('not json');

        $this->assertFalse($template->validate(['survey_fields']));
        $this->assertSame(['Survey_fields must be valid JSON.'], $template->getErrors('survey_fields'));
    }

    // -- Password field names with padding --------------------------------------

    /**
     * Regression: a password field named "db_password\n" passed the name
     * rule, and padding added to a stored name counted as no change (names
     * were compared trimmed). No caller sends that exact name, so its
     * answers were stored in plaintext.
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
     * @dataProvider paddedNameProvider
     * @dataProvider unicodeNameProvider
     */
    public function testANewTemplateCannotStoreAPasswordNameNoCallerSendsExactly(string $name, string $quoted): void
    {
        $template = $this->newTemplate((string)json_encode([['name' => $name, 'type' => 'password']]));

        $this->assertFalse($template->validate());
        $this->assertSame([sprintf(self::NAME_RULE, $quoted)], $template->getErrors('survey_fields'));
    }

    /**
     * @dataProvider paddedNameProvider
     * @dataProvider unicodeNameProvider
     */
    public function testAnUpdateCannotGiveAStoredPasswordNameSuchACharacter(string $name, string $quoted): void
    {
        $template = $this->storedTemplate('[{"name":"db_password","type":"password"}]');

        $template->survey_fields = (string)json_encode([['name' => $name, 'label' => 'db_password', 'type' => 'password']]);

        $this->assertFalse($template->validate());
        $this->assertSame([sprintf(self::NAME_RULE, $quoted)], $template->getErrors('survey_fields'));
    }

    /**
     * A legacy password name that no caller sends exactly cannot launch.
     * Edits that leave the survey alone keep saving; a changed survey must
     * rename the field (the web editor trims whitespace around names).
     *
     * @dataProvider paddedNameProvider
     * @dataProvider unicodeNameProvider
     */
    public function testALegacyPasswordNameNoCallerSendsExactlyMustGoOnceTheSurveyChanges(string $name, string $quoted): void
    {
        $template = $this->storedTemplate((string)json_encode([['name' => $name, 'type' => 'password']]));

        $template->description = 'edited';
        $this->assertTrue($template->validate(), (string)json_encode($template->errors));

        $template->survey_fields = (string)json_encode([['name' => $name, 'type' => 'password'], ['name' => 'env', 'type' => 'text']]);
        $this->assertFalse($template->validate());
        $this->assertSame([sprintf(self::NAME_RULE, $quoted)], $template->getErrors('survey_fields'));

        $template->survey_fields = '[{"name":"db_password","label":"db_password","type":"password","required":false,"default":"","options":[],"hint":""}]';
        $this->assertTrue($template->validate(), (string)json_encode($template->errors));
        $this->assertSame(['db_password'], $template->passwordFieldNames());
    }

    /**
     * A legacy field of another type keeps such a name, but becoming a
     * password field with it is refused.
     *
     * @dataProvider paddedNameProvider
     * @dataProvider unicodeNameProvider
     */
    public function testALegacyFieldWithSuchANameCannotBeRetypedToPassword(string $name, string $quoted): void
    {
        $template = $this->storedTemplate((string)json_encode([['name' => $name, 'type' => 'text']]));

        $template->survey_fields = (string)json_encode([['name' => $name, 'type' => 'textarea'], ['name' => 'env', 'type' => 'text']]);
        $this->assertTrue($template->validate(), (string)json_encode($template->errors));

        $template->survey_fields = (string)json_encode([['name' => $name, 'type' => 'password']]);
        $this->assertFalse($template->validate());
        $this->assertSame([sprintf(self::NAME_RULE, $quoted)], $template->getErrors('survey_fields'));
        $this->assertSame([], JobTemplate::passwordFieldsFromJson($template->survey_fields)['usable'], 'no name that can carry an answer');
    }

    /**
     * The Extra Vars rule names such a key with its invisible characters
     * escaped, as every message about a field name does.
     *
     * @dataProvider paddedNameProvider
     * @dataProvider unicodeNameProvider
     */
    public function testTheExtraVarsMessageShowsSuchAKeyEscaped(string $name, string $quoted): void
    {
        $template = $this->storedTemplate((string)json_encode([['name' => $name, 'type' => 'password']]), '{}');

        $template->extra_vars = (string)json_encode([$name => 'a-default']);

        $this->assertFalse($template->validate());
        $this->assertSame(
            ["Remove {$quoted} from Extra Vars: a password survey field cannot have a default; enter it at launch."],
            $template->getErrors('extra_vars')
        );
    }

    // -- Updates ----------------------------------------------------------------

    /**
     * An unrelated edit of a legacy template saves: the editor re-serializes
     * the survey on every save (all keys, its own order, the label defaulting
     * to the name), which is the same survey.
     */
    public function testAnUnrelatedEditOfALegacyTemplateSaves(): void
    {
        $template = $this->storedTemplate('[{"name":"db-host","type":"text","default":"db1"}]');

        $template->playbook = 'other.yml';
        $template->survey_fields = '[{"name":"db-host","label":"db-host","type":"text","required":false,"default":"db1","options":[],"hint":""}]';

        $this->assertTrue($template->validate(), (string)json_encode($template->errors));
    }

    public function testAddingAFieldWithABadNameIsRejected(): void
    {
        $template = $this->storedTemplate('[{"name":"db-host","type":"text"}]');

        $template->survey_fields = '[{"name":"db-host","type":"text"},{"name":"new-field","type":"text"}]';

        $this->assertFalse($template->validate());
        $this->assertSame([sprintf(self::NAME_RULE, '"new-field"')], $template->getErrors('survey_fields'));
    }

    public function testAddingAValidFieldToALegacySurveySaves(): void
    {
        $template = $this->storedTemplate('[{"name":"db-host","type":"text"}]');

        $template->survey_fields = '[{"name":"db-host","type":"text"},{"name":"region","type":"select","options":["eu"]}]';

        $this->assertTrue($template->validate(), (string)json_encode($template->errors));
    }

    public function testALegacyPasswordDefaultMustGoOnceTheSurveyChanges(): void
    {
        $template = $this->storedTemplate(self::LEGACY_SURVEY);

        $template->survey_fields = '[{"name":"db-host","type":"text","default":"db2"},{"name":"db_password","type":"password","default":"legacy-default"}]';
        $this->assertFalse($template->validate());
        $this->assertSame([SurveyDefinition::PASSWORD_DEFAULT], $template->getErrors('survey_fields'));

        $template->survey_fields = '[{"name":"db-host","type":"text","default":"db2"},{"name":"db_password","type":"password","default":""}]';
        $this->assertTrue($template->validate(), (string)json_encode($template->errors));
    }

    public function testTheBaselineIsTheStoredSurveyNotAnEarlierAssignment(): void
    {
        $template = $this->storedTemplate('[{"name":"db-host","type":"text"}]');
        $template->survey_fields = '[{"name":"other-legacy","type":"text"}]';

        $this->assertSame(['survey_fields' => '[{"name":"db-host","type":"text"}]', 'extra_vars' => null], $template->getValidationBaseline());
        $this->assertFalse($template->validate(), 'a renamed field needs a valid name');
    }

    // -- Clone ------------------------------------------------------------------

    public function testACloneIsComparedWithItsSource(): void
    {
        $source = $this->storedTemplate(self::LEGACY_SURVEY, '{"db-host":"db1"}');
        $clone = $this->newTemplate((string)$source->survey_fields, $source->extra_vars);

        $clone->setCopiedFrom($source);

        $this->assertSame(['survey_fields' => self::LEGACY_SURVEY, 'extra_vars' => '{"db-host":"db1"}'], $clone->getValidationBaseline());
        $this->assertTrue($clone->validate(), (string)json_encode($clone->errors));
        $clone->setCopiedFrom(null);
        $this->assertFalse($clone->validate(), 'without the source it is a new survey');
    }

    public function testANewRecordWithoutSourceHasNoBaseline(): void
    {
        $this->assertSame(['survey_fields' => null, 'extra_vars' => null], (new JobTemplate())->getValidationBaseline());
    }

    // -- Extra vars -------------------------------------------------------------

    /**
     * Regression: the template's extra vars could hold a value for a password
     * field's key, a default in plaintext on the template page, used by
     * every launch.
     */
    public function testExtraVarsCannotHoldAPasswordAnswer(): void
    {
        $template = $this->newTemplate('[{"name":"db_password","type":"password"}]', '{"env":"prod","db_password":"in-plaintext"}');

        $this->assertFalse($template->validate());
        $this->assertSame(
            ['Remove "db_password" from Extra Vars: a password survey field cannot have a default; enter it at launch.'],
            $template->getErrors('extra_vars')
        );
    }

    public function testALegacyTemplateWithSuchAKeyKeepsSavingUnrelatedEdits(): void
    {
        $template = $this->storedTemplate('[{"name":"db_password","type":"password"}]', '{"db_password":"legacy","env":"prod"}');

        $template->playbook = 'other.yml';
        // A client may reformat the JSON: still the same extra vars.
        $template->extra_vars = "{\n  \"db_password\": \"legacy\",\n  \"env\": \"prod\"\n}";

        $this->assertTrue($template->validate(), (string)json_encode($template->errors));
    }

    public function testChangingTheExtraVarsOfSuchATemplateNeedsTheKeyRemoved(): void
    {
        $template = $this->storedTemplate('[{"name":"db_password","type":"password"}]', '{"db_password":"legacy"}');

        $template->extra_vars = '{"db_password":"legacy","env":"prod"}';
        $this->assertFalse($template->validate());

        $template->extra_vars = '{"env":"prod"}';
        $this->assertTrue($template->validate(), (string)json_encode($template->errors));
    }

    public function testAddingAPasswordFieldNamedLikeAnExtraVarIsRejected(): void
    {
        $template = $this->storedTemplate('[{"name":"env","type":"text"}]', '{"api_token":"abc"}');

        $template->survey_fields = '[{"name":"env","type":"text"},{"name":"api_token","type":"password"}]';

        $this->assertFalse($template->validate());
        $this->assertSame(
            ['Remove "api_token" from Extra Vars: a password survey field cannot have a default; enter it at launch.'],
            $template->getErrors('extra_vars')
        );
    }

    /**
     * A description-only edit that posts the stored values written another
     * way, without a change: the survey editor re-serializes every field
     * from its inputs, and a client that decodes and re-encodes the Extra
     * Vars (the JSON library of an API client, or the form's Extra Vars
     * editor in YAML mode; in JSON mode it posts the stored text unchanged)
     * writes numbers in their shortest form and integer-like keys first.
     *
     * Regression: the extra vars rule ran when the decoded extra vars
     * differed strictly or the survey changed in any way, so such an edit of
     * a legacy template whose Extra Vars hold a password field's key was
     * refused with "Remove "db_password" from Extra Vars".
     *
     * Regression (R2V1-DOC-CMP, R2V2-2): so was such an edit that wrote a
     * whole number beyond 2^53 another way with the same value: an int and
     * a float were the same only within plus or minus 2^53.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function sameValuesWrittenAnotherWayProvider(): array
    {
        $password = '{"name":"db_password","type":"password"}';
        $passwordAsPosted = '{"name":"db_password","label":"db_password","type":"password","required":false,"default":"","options":[],"hint":""}';
        $extraVars = '{"db_password": "legacy-default", "env": "prod"}';
        $extraVarsAsPosted = '{"db_password":"legacy-default","env":"prod"}';

        return [
            '1.0 posted as 1' => [
                "[{$password}]",
                '{"cpu_ratio": 1.0, "db_password": "legacy-default"}',
                "[{$passwordAsPosted}]",
                '{"cpu_ratio":1,"db_password":"legacy-default"}',
            ],
            '1e2 posted as 100' => [
                "[{$password}]",
                '{"retries": 1e2, "db_password": "legacy-default"}',
                "[{$passwordAsPosted}]",
                '{"retries":100,"db_password":"legacy-default"}',
            ],
            'integer-like keys reordered' => [
                "[{$password}]",
                '{"db_password": "legacy-default", "20": "b", "10": "a", "ports": {"web": 80, "22": "ssh"}}',
                "[{$passwordAsPosted}]",
                '{"10":"a","20":"b","db_password":"legacy-default","ports":{"22":"ssh","web":80}}',
            ],
            'a multi-line default posted without its newlines' => [
                "[{$password},{\"name\":\"motd\",\"type\":\"textarea\",\"default\":\"line1\\nline2\"}]",
                $extraVars,
                "[{$passwordAsPosted},{\"name\":\"motd\",\"label\":\"motd\",\"type\":\"textarea\",\"required\":false,\"default\":\"line1line2\",\"options\":[],\"hint\":\"\"}]",
                $extraVarsAsPosted,
            ],
            'an option list split at a comma' => [
                "[{$password},{\"name\":\"tier\",\"type\":\"select\",\"options\":[\"gold, silver\",\"bronze\"],\"default\":\"bronze\"}]",
                $extraVars,
                "[{$passwordAsPosted},{\"name\":\"tier\",\"label\":\"tier\",\"type\":\"select\",\"required\":false,\"default\":\"bronze\",\"options\":[\"gold\",\"silver\",\"bronze\"],\"hint\":\"\"}]",
                $extraVarsAsPosted,
            ],
            'an unknown field type saved as text' => [
                "[{$password},{\"name\":\"region\",\"type\":\"bogus\"}]",
                $extraVars,
                "[{$passwordAsPosted},{\"name\":\"region\",\"label\":\"region\",\"type\":\"text\",\"required\":false,\"default\":\"\",\"options\":[],\"hint\":\"\"}]",
                $extraVarsAsPosted,
            ],
            '-0.0 posted as 0' => [
                "[{$password}]",
                '{"offset": -0.0, "db_password": "legacy-default"}',
                "[{$passwordAsPosted}]",
                '{"offset":0,"db_password":"legacy-default"}',
            ],
            'a whole float of 2^53 posted as an integer' => [
                "[{$password}]",
                '{"max_id": 9007199254740992.0, "db_password": "legacy-default"}',
                "[{$passwordAsPosted}]",
                '{"max_id":9007199254740992,"db_password":"legacy-default"}',
            ],
            'a whole float beyond 2^53 posted as an integer' => [
                "[{$password}]",
                '{"max_id": 9007199254740994.0, "db_password": "legacy-default"}',
                "[{$passwordAsPosted}]",
                '{"max_id":9007199254740994,"db_password":"legacy-default"}',
            ],
            '1e18 posted with all its digits' => [
                "[{$password}]",
                '{"quota": 1e18, "db_password": "legacy-default"}',
                "[{$passwordAsPosted}]",
                '{"quota":1000000000000000000,"db_password":"legacy-default"}',
            ],
            'other float forms' => [
                "[{$password}]",
                '{"ratio": 0.50, "huge": 1.5e300, "db_password": "legacy-default"}',
                "[{$passwordAsPosted}]",
                '{"ratio":0.5,"huge":1.5e+300,"db_password":"legacy-default"}',
            ],
        ];
    }

    /**
     * @dataProvider sameValuesWrittenAnotherWayProvider
     */
    public function testALegacyTemplateWithSuchAKeyKeepsSavingTheSameValuesWrittenAnotherWay(string $survey, string $extraVars, string $postedSurvey, string $postedExtraVars): void
    {
        $template = $this->storedTemplate($survey, $extraVars);

        $template->description = 'only the description changed';
        $template->survey_fields = $postedSurvey;
        $template->extra_vars = $postedExtraVars;

        $this->assertTrue($template->validate(), (string)json_encode($template->errors));
    }

    /**
     * Only a reformat is no change. PHP's == would take true for any
     * non-empty text, so a legacy password default of true could be
     * replaced with a real password unnoticed.
     */
    public function testANewValueForSuchAKeyIsAChangeWhateverItsType(): void
    {
        $template = $this->storedTemplate('[{"name":"db_password","type":"password"}]', '{"db_password": true, "port": 1}');

        $template->extra_vars = '{"db_password":"new-plaintext-default","port":1}';
        $this->assertFalse($template->validate());
        $this->assertSame(
            ['Remove "db_password" from Extra Vars: a password survey field cannot have a default; enter it at launch.'],
            $template->getErrors('extra_vars')
        );

        $template->extra_vars = '{"db_password":true,"port":"1"}';
        $this->assertFalse($template->validate(), 'a number that became text');
    }

    /**
     * Changes of a legacy password key's value that the comparison took for
     * a reformat: a list and an object with the same keys, numbers that
     * differ but decode to the same float, and a whole number beyond the
     * int range that became the same digits as text, or the other way round.
     *
     * Regression (V2-C2): such a change saved unnoticed, although only the
     * same JSON written differently is no change.
     *
     * Regression (R2V1-DOC-CMP, R2V2-2): so did a number beyond the int
     * range that became text, although the launch reads one as a float and
     * the other as text.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function realChangeProvider(): array
    {
        return [
            'a list that became an object' => ['{"db_password": ["a", "b"]}', '{"db_password":{"0":"a","1":"b"}}'],
            'an object that became a list' => ['{"db_password": {"0": "a", "1": "b"}}', '{"db_password":["a","b"]}'],
            'a nested list that became an object' => ['{"db_password": {"keys": ["a"]}}', '{"db_password":{"keys":{"0":"a"}}}'],
            'an integer above 2^53 that became the nearest float' => ['{"db_password": 9007199254740993}', '{"db_password":9007199254740992.0}'],
            'a whole float that became the integer above it' => ['{"db_password": 9007199254740992.0}', '{"db_password":9007199254740993}'],
            'an integer beyond the int range that changed' => ['{"db_password": 12345678901234567890}', '{"db_password":12345678901234567999}'],
            'a negative one that changed' => ['{"db_password": -12345678901234567890}', '{"db_password":-12345678901234567891}'],
            'an integer beyond the int range that became text' => ['{"db_password": 12345678901234567890}', '{"db_password":"12345678901234567890"}'],
            'text that became an integer beyond the int range' => ['{"db_password": "12345678901234567890"}', '{"db_password":12345678901234567890}'],
            'a nested one that became text' => ['{"db_password": {"pin": [12345678901234567890]}}', '{"db_password":{"pin":["12345678901234567890"]}}'],
        ];
    }

    /**
     * @dataProvider realChangeProvider
     */
    public function testARealChangeOfALegacyPasswordKeysValueNeedsTheKeyRemoved(string $stored, string $changed): void
    {
        $template = $this->storedTemplate('[{"name":"db_password","type":"password"}]', $stored);

        $template->extra_vars = $changed;

        $this->assertFalse($template->validate());
        $this->assertSame(
            ['Remove "db_password" from Extra Vars: a password survey field cannot have a default; enter it at launch.'],
            $template->getErrors('extra_vars')
        );
    }

    /**
     * A number written another way. An int and a float are the same value
     * only when they are exactly the same number: the float is whole and
     * lies within the int range (-2^63 up to, not including, 2^63), where a
     * cast to int keeps its value. A whole number beyond the int range is
     * the same only as the same digits: not as a float, and not as text.
     *
     * Regression (R2V1-DOC-CMP, R2V2-2):
     * - a whole number beyond 2^53 written another way with the same value
     *   was a change (an int and a float were the same only within plus or
     *   minus 2^53), so a description-only edit of a legacy template with a
     *   password key was refused with "Remove "db_password" from Extra
     *   Vars": the rows from 'a whole float beyond 2^53 posted as an int' to
     *   'the whole float -2^63 posted as the smallest int';
     * - a whole number beyond the int range was the same as its digits
     *   written as text, although the launch reads one as a float and the
     *   other as text: the rows with text beyond the int range.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function numberPairProvider(): array
    {
        return [
            'a whole float posted as an int' => ['1.0', '1', true],
            'an int posted as a whole float' => ['1', '1.0', true],
            '1e2 posted as 100' => ['1e2', '100', true],
            '-0.0 posted as 0' => ['-0.0', '0', true],
            'the whole float -2^53 posted as an int' => ['-9007199254740992.0', '-9007199254740992', true],
            'a whole float beyond 2^53 posted as an int' => ['9007199254740994.0', '9007199254740994', true],
            'an int beyond 2^53 posted as a whole float' => ['9007199254740994', '9007199254740994.0', true],
            'the whole float 2^60 posted as an int' => ['1152921504606846976.0', '1152921504606846976', true],
            '1e18 posted with all its digits' => ['1e18', '1000000000000000000', true],
            '10^18 posted as 1e18' => ['1000000000000000000', '1e18', true],
            'the whole float -2^63 posted as the smallest int' => ['-9223372036854775808.0', '-9223372036854775808', true],
            'a float with a fraction' => ['1.5', '1', false],
            'an int posted as a float with a fraction' => ['1', '1.5', false],
            'an infinite float' => ['1e999', '1', false],
            'an int beyond 2^53 posted as the nearest float' => ['9007199254740993', '9007199254740992.0', false],
            'an int beyond 2^53 posted with .0, which reads as the float below it' => ['9007199254740993', '9007199254740993.0', false],
            'the largest int posted with .0, which reads as 2^63' => ['9223372036854775807', '9223372036854775807.0', false],
            '0 posted as 2^64, which a cast to int makes 0' => ['0', '18446744073709551616.0', false],
            '0 posted as -2^64, which a cast to int makes 0' => ['0', '-18446744073709551616.0', false],
            'the smallest int posted as 2^63, which a cast to int makes the smallest int' => ['-9223372036854775808', '9223372036854775808.0', false],
            'an int posted as text' => ['1', '"1"', false],
            'the same digits beyond the int range' => ['12345678901234567890', '12345678901234567890', true],
            'digits beyond the int range posted as text' => ['12345678901234567890', '"12345678901234567890"', false],
            'text posted as digits beyond the int range' => ['"12345678901234567890"', '12345678901234567890', false],
            'negative digits beyond the int range posted as text' => ['-12345678901234567890', '"-12345678901234567890"', false],
            'other digits beyond the int range that read as the same float' => ['12345678901234567890', '12345678901234567999', false],
            'digits beyond the int range posted with an exponent' => ['100000000000000000000', '1e20', false],
            '1e20 posted with all its digits' => ['1e20', '100000000000000000000', false],
            '2^63, the first whole number beyond the int range, posted with .0' => ['9223372036854775808', '9223372036854775808.0', false],
            'a float beyond the int range written another way' => ['1e20', '100000000000000000000.0', true],
        ];
    }

    /**
     * @dataProvider numberPairProvider
     */
    public function testAnIntAndAFloatAreTheSameOnlyAsExactlyTheSameNumber(string $stored, string $posted, bool $same): void
    {
        $template = $this->storedTemplate('[{"name":"db_password","type":"password"}]', '{"db_password": "legacy", "n": ' . $stored . '}');

        $template->extra_vars = '{"db_password":"legacy","n":' . $posted . '}';

        $this->assertSame($same, $template->validate(), (string)json_encode($template->errors));
    }

    /**
     * The value of the password field's own key compares by the same rule:
     * a number written another way keeps the key, any other change needs it
     * removed.
     *
     * @dataProvider numberPairProvider
     */
    public function testThePasswordKeysOwnValueComparesByTheSameRule(string $stored, string $posted, bool $same): void
    {
        $template = $this->storedTemplate('[{"name":"db_password","type":"password"}]', '{"db_password": ' . $stored . ', "env": "prod"}');

        $template->description = 'only the description changed';
        $template->extra_vars = '{"env":"prod","db_password":' . $posted . '}';

        $this->assertSame($same, $template->validate(), (string)json_encode($template->errors));
        $this->assertSame(
            $same ? [] : ['Remove "db_password" from Extra Vars: a password survey field cannot have a default; enter it at launch.'],
            $template->getErrors('extra_vars')
        );
    }

    /**
     * Lists compare by position: the same list saves, the same items in
     * another order are a change.
     */
    public function testAListComparesByPosition(): void
    {
        $template = $this->storedTemplate('[{"name":"db_password","type":"password"}]', '{"db_password": ["a", "b"], "hosts": [1, 2.0]}');

        $template->extra_vars = '{"hosts":[1,2],"db_password":["a","b"]}';
        $this->assertTrue($template->validate(), (string)json_encode($template->errors));

        $template->extra_vars = '{"hosts":[1,2],"db_password":["b","a"]}';
        $this->assertFalse($template->validate());
    }

    /**
     * A key written twice keeps its last value, in the comparison as in the
     * launch: only a change of that value is a change.
     */
    public function testOnlyTheLastValueOfADuplicateKeyCounts(): void
    {
        $template = $this->storedTemplate('[{"name":"db_password","type":"password"}]', '{"db_password":"legacy"}');

        $template->extra_vars = '{"db_password":"never-used","db_password":"legacy"}';
        $this->assertTrue($template->validate(), (string)json_encode($template->errors));

        $template->extra_vars = '{"db_password":"legacy","db_password":"new-plaintext-default"}';
        $this->assertFalse($template->validate());
    }

    public function testRenamingAPasswordFieldToSuchAKeyIsRejected(): void
    {
        $template = $this->storedTemplate('[{"name":"old_password","type":"password"}]', '{"db_password":"abc"}');

        $template->survey_fields = '[{"name":"db_password","type":"password"}]';

        $this->assertFalse($template->validate());
        $this->assertSame(
            ['Remove "db_password" from Extra Vars: a password survey field cannot have a default; enter it at launch.'],
            $template->getErrors('extra_vars')
        );
    }

    public function testRetypingAFieldWithSuchAKeyToPasswordIsRejected(): void
    {
        $template = $this->storedTemplate('[{"name":"api_token","type":"text"}]', '{"api_token":"abc"}');

        $template->survey_fields = '[{"name":"api_token","type":"password"}]';

        $this->assertFalse($template->validate());
        $this->assertSame(
            ['Remove "api_token" from Extra Vars: a password survey field cannot have a default; enter it at launch.'],
            $template->getErrors('extra_vars')
        );
    }

    /**
     * Without the password field, the key is an ordinary extra var.
     */
    public function testRemovingThePasswordFieldOfSuchAKeySaves(): void
    {
        $template = $this->storedTemplate('[{"name":"db_password","type":"password"},{"name":"env","type":"text"}]', '{"db_password":"legacy"}');

        $template->survey_fields = '[{"name":"env","type":"text"}]';

        $this->assertTrue($template->validate(), (string)json_encode($template->errors));
    }

    public function testUnusablePasswordNamesCountAsWell(): void
    {
        $template = $this->storedTemplate('[{"name":"123","type":"password"}]', '{}');

        $template->extra_vars = '{"123":"x"}';

        $this->assertFalse($template->validate());
        $this->assertSame(
            ['Remove "123" from Extra Vars: a password survey field cannot have a default; enter it at launch.'],
            $template->getErrors('extra_vars')
        );
    }

    public function testExtraVarsThatAreNoListHaveNoKeysToCheck(): void
    {
        $template = $this->newTemplate('[{"name":"db_password","type":"password"}]', '"db_password"');

        $this->assertTrue($template->validate(['extra_vars']), (string)json_encode($template->errors));
    }

    public function testTheValidatorIgnoresOtherModelsAndAttributes(): void
    {
        $schedule = new Schedule();
        $schedule->extra_vars = '{"db_password":"x"}';
        $validator = new TemplateSurveyValidator();
        $validator->validateAttribute($schedule, 'extra_vars');
        $this->assertFalse($schedule->hasErrors());

        $template = $this->newTemplate('[{"name":"bad-name","type":"password","default":"x"}]');
        $validator->validateAttribute($template, 'playbook');
        $this->assertFalse($template->hasErrors());
    }

    // -- Parser and masking -----------------------------------------------------

    /**
     * Every password field name is a key whose value is a password answer,
     * usable or not; only a usable name can carry an answer at a launch.
     */
    public function testPasswordFieldNamesAreEveryPasswordFieldsNameUsableOrNot(): void
    {
        $template = $this->newTemplate('[{"name":"db_password","type":"password"},{"name":"db[pw]","type":"password"},{"name":"env"}]');

        $this->assertSame(['db_password', 'db[pw]'], $template->passwordFieldNames());
        $this->assertSame(['usable' => ['db_password'], 'unusable' => ['db[pw]']], JobTemplate::passwordFieldsFromJson($template->survey_fields));
    }

    public function testTheTemplatePageMasksPasswordKeysOfLegacyExtraVars(): void
    {
        $template = $this->newTemplate(
            '[{"name":"db_password","type":"password"},{"name":"123","type":"password"}]',
            '{"env":"prod","db_password":"legacy-plaintext","123":"numbered-plaintext","nested":{"db_password":"kept"}}'
        );

        $masked = (string)$template->getMaskedExtraVars();

        $this->assertStringNotContainsString('legacy-plaintext', $masked);
        $this->assertStringNotContainsString('numbered-plaintext', $masked);
        $this->assertSame(
            ['env' => 'prod', 'db_password' => SurveySecrets::PLACEHOLDER, '123' => SurveySecrets::PLACEHOLDER, 'nested' => ['db_password' => 'kept']],
            json_decode($masked, true),
            'only top-level keys are answers'
        );
    }

    public function testExtraVarsWithoutPasswordKeysAreShownAsWritten(): void
    {
        $written = "{\n    \"env\":   \"prod\"\n}";

        $this->assertSame($written, $this->newTemplate('[{"name":"db_password","type":"password"}]', $written)->getMaskedExtraVars());
        $this->assertSame('not json', $this->newTemplate(null, 'not json')->getMaskedExtraVars());
        $this->assertNull($this->newTemplate('[{"name":"db_password","type":"password"}]', null)->getMaskedExtraVars());
        $this->assertSame('["db_password"]', $this->newTemplate('[{"name":"db_password","type":"password"}]', '["db_password"]')->getMaskedExtraVars());
    }

    // -- Helpers ----------------------------------------------------------------

    private function newTemplate(?string $survey, ?string $extraVars = null): JobTemplate
    {
        $template = new JobTemplate();
        $template->name = 'survey-rules-' . uniqid('', true);
        $template->project_id = (int)$this->createProject($this->userId)->id;
        $template->inventory_id = (int)$this->createInventory($this->userId)->id;
        $template->runner_group_id = (int)$this->createRunnerGroup($this->userId)->id;
        $template->playbook = 'site.yml';
        $template->verbosity = 0;
        $template->forks = 5;
        $template->timeout_minutes = 120;
        $template->become = false;
        $template->become_method = 'sudo';
        $template->become_user = 'root';
        $template->created_by = $this->userId;
        $template->survey_fields = $survey;
        $template->extra_vars = $extraVars;

        return $template;
    }

    /**
     * A template as saved before the survey rules: stored without validation.
     */
    private function storedTemplate(string $survey, ?string $extraVars = null): JobTemplate
    {
        $template = $this->newTemplate($survey, $extraVars);
        $this->assertTrue($template->save(false));
        $stored = JobTemplate::findOne($template->id);
        $this->assertNotNull($stored);

        return $stored;
    }
}
