<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\SurveyDefinition;
use PHPUnit\Framework\TestCase;

/**
 * SurveyDefinition: reading stored survey definitions without trusting their
 * shape, finding password fields, and the rules for changed definitions.
 */
class SurveyDefinitionTest extends TestCase
{
    use UnusablePasswordNames;

    private const NAME_RULE = 'Survey field name %s is not a valid variable name: use letters, digits and underscores, '
        . 'and start with a letter or an underscore.';

    // -- entries ----------------------------------------------------------------

    public function testEntriesOfAnEmptyDefinitionAreEmpty(): void
    {
        $this->assertSame([], SurveyDefinition::entries(null));
        $this->assertSame([], SurveyDefinition::entries(''));
        $this->assertSame([], SurveyDefinition::entries('null'));
        $this->assertSame([], SurveyDefinition::entries('[]'));
        $this->assertSame([], SurveyDefinition::entries('{}'));
    }

    public function testEntriesOfAListOrAMapAreTheirValuesInOrder(): void
    {
        $this->assertSame([['name' => 'a'], 'junk'], SurveyDefinition::entries('[{"name":"a"},"junk"]'));
        $this->assertSame([['name' => 'a'], ['name' => 'b']], SurveyDefinition::entries('{"x":{"name":"a"},"y":{"name":"b"}}'));
    }

    public function testEntriesOfSomethingElseAreNull(): void
    {
        $this->assertNull(SurveyDefinition::entries('{not json'));
        $this->assertNull(SurveyDefinition::entries('"text"'));
        $this->assertNull(SurveyDefinition::entries('5'));
        $this->assertNull(SurveyDefinition::entries('true'));
    }

    // -- passwordFields ---------------------------------------------------------

    public function testPasswordFieldsAreThoseOfTypePasswordExactly(): void
    {
        $json = (string)json_encode([
            ['name' => 'db_password', 'type' => 'password'],
            ['name' => 'env', 'type' => 'text'],
            ['name' => 'upper', 'type' => 'Password'],
            ['name' => 'listed', 'type' => ['password']],
            ['name' => 'untyped'],
            'password',
            ['name' => 'api_key', 'type' => 'password'],
        ]);

        $this->assertSame(['usable' => ['db_password', 'api_key'], 'unusable' => []], SurveyDefinition::passwordFields($json));
    }

    public function testPasswordFieldsOfAMapAreFoundToo(): void
    {
        $this->assertSame(
            ['usable' => ['pw'], 'unusable' => []],
            SurveyDefinition::passwordFields('{"first":{"name":"pw","type":"password"}}')
        );
    }

    /**
     * Names PHP would turn into another key in survey[<name>], or into a
     * number that merging renumbers.
     */
    public function testPasswordFieldNamesThatCannotCarryAnAnswerAreUnusable(): void
    {
        $json = (string)json_encode([
            ['name' => 'db[pw]', 'type' => 'password'],
            ['name' => 'x]', 'type' => 'password'],
            ['name' => '[x', 'type' => 'password'],
            ['name' => '', 'type' => 'password'],
            ['type' => 'password'],
            ['name' => '123', 'type' => 'password'],
            ['name' => '-1', 'type' => 'password'],
            ['name' => '1.5', 'type' => 'password'],
            ['name' => 42, 'type' => 'password'],
            ['name' => ['a' => 1], 'type' => 'password'],
            ['name' => true, 'type' => 'password'],
            ['name' => 'fine name', 'type' => 'password'],
        ]);

        $this->assertSame([
            'usable' => ['fine name'],
            'unusable' => ['db[pw]', 'x]', '[x', '', '123', '-1', '1.5', '42', '{"a":1}', '1'],
        ], SurveyDefinition::passwordFields($json));
    }

    public function testPasswordFieldNamesAreListedOnce(): void
    {
        $this->assertSame(
            ['usable' => ['pw'], 'unusable' => ['1']],
            SurveyDefinition::passwordFields('[{"name":"pw","type":"password"},{"name":"pw","type":"password"},{"name":"1","type":"password"},{"name":1,"type":"password"}]')
        );
    }

    public function testADefinitionThatIsNotAListHasNoPasswordFields(): void
    {
        $this->assertSame(['usable' => [], 'unusable' => []], SurveyDefinition::passwordFields('{broken'));
        $this->assertSame(['usable' => [], 'unusable' => []], SurveyDefinition::passwordFields(null));
    }

    /**
     * Every password field name is a secret key, usable or not: usability
     * decides only whether the template can be launched.
     *
     * Regression (R2V2-1): the backfill, the schedules and their masks took
     * only usable names as secret keys, so the answers that 2.8.1 stored
     * under a name such as "contraseña_db" stayed in plaintext and showed.
     */
    public function testEveryPasswordFieldNameIsASecretName(): void
    {
        $json = (string)json_encode([
            ['name' => 'db_password', 'type' => 'password'],
            ['name' => 'contraseña_db', 'type' => 'password'],
            ['name' => 'env', 'type' => 'text'],
            ['name' => "db_password\u{00A0}", 'type' => 'password'],
            ['name' => 'db_password ', 'type' => 'password'],
            ['name' => '0', 'type' => 'password'],
            ['name' => 'db[pw]', 'type' => 'password'],
            ['name' => 'api_key', 'type' => 'password'],
        ]);

        $this->assertSame(
            ['db_password', 'api_key', 'contraseña_db', "db_password\u{00A0}", 'db_password ', '0', 'db[pw]'],
            SurveyDefinition::secretNames($json)
        );
    }

    /**
     * A name that is a JSON object is listed as its JSON, which a text name
     * can be too: such a name is listed once.
     */
    public function testASecretNameIsListedOnce(): void
    {
        $this->assertSame(
            ['{"a":1}', 'pw'],
            SurveyDefinition::secretNames('[{"name":"{\"a\":1}","type":"password"},{"name":"pw","type":"password"},{"name":{"a":1},"type":"password"}]')
        );
    }

    public function testADefinitionThatIsNotAListHasNoSecretNames(): void
    {
        $this->assertSame([], SurveyDefinition::secretNames('{broken'));
        $this->assertSame([], SurveyDefinition::secretNames(null));
        $this->assertSame([], SurveyDefinition::secretNames('[{"name":"env","type":"text"}]'));
    }

    /**
     * Padding and line breaks around a name, the ways an API client can
     * store one (a YAML block scalar, unstripped lines of a file).
     *
     * Regression: a password field named "db_password\n" passed the name
     * rule ($ also matched before the final line break), and padding added
     * to a stored name was no change (names were compared trimmed). The
     * field counted as usable, but no caller sends its exact name: browsers
     * post a line break in a form field name as CRLF, API and trigger
     * callers the variable name. Its answers were stored in plaintext.
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
     * Fails closed: the launch refuses such a field with the rename message,
     * and the backfill and the scrub report it.
     *
     * @dataProvider paddedNameProvider
     * @dataProvider unicodeNameProvider
     */
    public function testAPasswordFieldNameNoCallerSendsExactlyIsUnusable(string $name): void
    {
        $this->assertSame(
            ['usable' => [], 'unusable' => [$name]],
            SurveyDefinition::passwordFields((string)json_encode([['name' => $name, 'type' => 'password']]))
        );
    }

    public function testAPasswordFieldNameWithAControlCharacterIsUnusable(): void
    {
        $names = ["db\0password", "db\x01password", "db\x1Fpassword", "db\x7Fpassword", "db\npassword"];
        $fields = array_map(static fn (string $name): array => ['name' => $name, 'type' => 'password'], $names);

        $this->assertSame(['usable' => [], 'unusable' => $names], SurveyDefinition::passwordFields((string)json_encode($fields)));
    }

    /**
     * Inner spaces survive survey[<name>] and work on the web launch today,
     * and so does the rest of printable ASCII. A non-breaking space is no
     * such space: it is outside ASCII ({@see unicodeNameProvider()}).
     */
    public function testAPasswordFieldNameWithInnerSpacesStaysUsable(): void
    {
        $this->assertSame(
            ['usable' => ['db password', 'db-pass.word~!@#$%^&*(){}'], 'unusable' => []],
            SurveyDefinition::passwordFields('[{"name":"db password","type":"password"},{"name":"db-pass.word~!@#$%^&*(){}","type":"password"}]')
        );
    }

    /**
     * Only printable ASCII without a space at either end can carry an
     * answer, and only without [ or ] and when not numeric or empty.
     *
     * @dataProvider paddedNameProvider
     * @dataProvider unicodeNameProvider
     */
    public function testANameNoCallerSendsExactlyCannotCarryAnAnswer(string $name): void
    {
        $this->assertFalse(SurveyDefinition::isUsablePasswordName($name));
    }

    public function testAPrintableAsciiNameCanCarryAnAnswer(): void
    {
        foreach (['db_password', 'a', '_', 'db password', 'x y z', 'db-pass.word~!@#$%^&*(){}'] as $name) {
            $this->assertTrue(SurveyDefinition::isUsablePasswordName($name), $name);
        }
        foreach (['', '12', '1.5', ' 1', 'db[pw]', 'pw]', '[pw', "db\x7F"] as $name) {
            $this->assertFalse(SurveyDefinition::isUsablePasswordName($name), (string)json_encode($name));
        }
    }

    /**
     * A byte that is not UTF-8 never reaches passwordFields(): no JSON holds
     * one (json_decode() refuses it, and so do the validation of
     * survey_fields and the utf8mb4 column). The name check refuses it all
     * the same, and a message shows it as the replacement character.
     *
     * Regression (V2-C1U): such a name counted as one a caller can send
     * exactly, and a message showed it as an unescaped U+FFFD.
     */
    public function testANameThatIsNotUtf8CannotCarryAnAnswerAndShowsEscaped(): void
    {
        $this->assertSame('"db_password\ufffd"', SurveyDefinition::quote("db_password\xFF"));
        $this->assertFalse(SurveyDefinition::isUsablePasswordName("db_password\xFF"));
    }

    // -- normalize --------------------------------------------------------------

    /**
     * The survey editor saves every field with all keys, in its own order,
     * the label defaulting to the name and text trimmed: that is the same
     * survey as the stored one.
     */
    public function testTheEditorsOutputIsTheSameSurveyAsAStoredDefinition(): void
    {
        $stored = '{"0":{"type":"select","name":"log_level","label":" ","options":["debug"," info ",""],"default":"info","hint":" h "},"1":{"name":"env","default":5,"required":1}}';
        $editor = (string)json_encode([
            ['name' => 'log_level', 'label' => 'log_level', 'type' => 'select', 'required' => false, 'default' => 'info', 'options' => ['debug', 'info'], 'hint' => 'h'],
            ['name' => 'env', 'label' => 'env', 'type' => 'text', 'required' => true, 'default' => '5', 'options' => [], 'hint' => ''],
        ]);

        $this->assertSame(SurveyDefinition::normalize($stored), SurveyDefinition::normalize($editor));
    }

    /**
     * A password answer is stored under its field's exact name, so names are
     * compared as stored: padding is another name. The label still defaults
     * to the trimmed name, as the editor saves it.
     */
    public function testNamesAreComparedAsStored(): void
    {
        $padded = SurveyDefinition::normalize('[{"name":" env\n"}]');

        $this->assertSame(" env\n", $padded[0]['name'] ?? null);
        $this->assertSame('env', $padded[0]['label'] ?? null);
        $this->assertNotSame(SurveyDefinition::normalize('[{"name":"env"}]'), $padded);
    }

    public function testNormalizeKeepsEntriesThatAreNotFieldsAsTheyAre(): void
    {
        $this->assertSame(['junk', 7, null], SurveyDefinition::normalize('["junk",7,null]'));
    }

    public function testNormalizeReadsMalformedValuesAsMissing(): void
    {
        $this->assertSame(
            [['name' => '', 'label' => '', 'type' => 'text', 'required' => false, 'default' => '', 'options' => ['ok'], 'hint' => '']],
            SurveyDefinition::normalize('[{"name":["x"],"label":{"a":1},"type":["password"],"default":[1],"options":["ok",["no"]],"hint":[]}]')
        );
    }

    public function testNormalizeOfSomethingElseIsNull(): void
    {
        $this->assertNull(SurveyDefinition::normalize('"text"'));
        $this->assertSame([], SurveyDefinition::normalize(null));
    }

    // -- problems ---------------------------------------------------------------

    public function testAnUnchangedSurveyHasNoProblemsWhateverItContains(): void
    {
        $legacy = '[{"name":"db-host","type":"bogus"},{"name":"db-host","type":"password","default":"x"},"junk"]';

        $this->assertSame([], SurveyDefinition::problems($legacy, $legacy));
        $this->assertSame([], SurveyDefinition::problems('[]', null));
        $this->assertSame([], SurveyDefinition::problems(null, null));
        $this->assertSame([], SurveyDefinition::problems('"text"', '"text"'), 'a legacy value that is not a list');
    }

    public function testAValidNewSurveyHasNoProblems(): void
    {
        $json = (string)json_encode([
            ['name' => 'env', 'type' => 'select', 'options' => ['a', 'b'], 'default' => 'a'],
            ['name' => '_private2', 'type' => 'password', 'default' => ''],
            ['name' => 'pw_null_default', 'type' => 'password', 'default' => null],
            ['name' => 'Count', 'type' => 'integer', 'default' => 3],
            ['name' => 'untyped'],
        ]);

        $this->assertSame([], SurveyDefinition::problems($json, null));
    }

    /**
     * Regression: a name like "db-host" or "db[pw]" was accepted, and PHP
     * turned survey[db[pw]] into the key "db[pw", so a password answer could
     * not be found again.
     *
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function invalidNameProvider(): array
    {
        return [
            'dash' => ['db-host', '"db-host"'],
            'brackets' => ['db[pw]', '"db[pw]"'],
            'leading digit' => ['1st', '"1st"'],
            'space' => ['my var', '"my var"'],
            'surrounding spaces' => [' env ', '" env "'],
            'final line feed' => ["env\n", '"env\n"'],
            'NUL' => ["env\0", '"env\u0000"'],
            'empty' => ['', '""'],
            'number' => [5, '5'],
            'list' => [['x'], '["x"]'],
            'object' => [['a' => 1], '{"a":1}'],
            'unicode' => ["p\u{00E4}ssword", '"p\u00e4ssword"'],
        ];
    }

    /**
     * @dataProvider invalidNameProvider
     */
    public function testANewFieldNeedsAVariableName(mixed $name, string $quoted): void
    {
        $json = (string)json_encode([['name' => $name, 'type' => 'text']]);

        $this->assertSame(
            ["Survey field name {$quoted} is not a valid variable name: use letters, digits and underscores, and start with a letter or an underscore."],
            SurveyDefinition::problems($json, null)
        );
    }

    public function testAFieldWithoutANameNeedsOne(): void
    {
        $this->assertSame(
            ['Survey field name "" is not a valid variable name: use letters, digits and underscores, and start with a letter or an underscore.'],
            SurveyDefinition::problems('[{"type":"text"}]', null)
        );
    }

    /**
     * Fields kept from the baseline keep their legacy names: an unrelated
     * change of the survey still saves.
     */
    public function testALegacyNameKeptFromTheBaselineIsAccepted(): void
    {
        $baseline = '[{"name":"db-host","type":"text"},{"name":7,"type":"text"}]';
        $changed = '[{"name":"db-host","label":"Database host","type":"text"},{"name":"7","type":"text"},{"name":"region","type":"text"}]';

        $this->assertSame([], SurveyDefinition::problems($changed, $baseline));
    }

    /**
     * @dataProvider paddedNameProvider
     * @dataProvider unicodeNameProvider
     */
    public function testANewPasswordFieldCannotGetSuchAName(string $name, string $quoted): void
    {
        $this->assertSame(
            [sprintf(self::NAME_RULE, $quoted)],
            SurveyDefinition::problems((string)json_encode([['name' => $name, 'type' => 'password']]), null)
        );
    }

    /**
     * Padding or another character added to a stored name makes it a new
     * name, which the name rule checks.
     *
     * @dataProvider paddedNameProvider
     * @dataProvider unicodeNameProvider
     */
    public function testSuchANameInPlaceOfAStoredNameIsANewName(string $name, string $quoted): void
    {
        $this->assertSame(
            [sprintf(self::NAME_RULE, $quoted)],
            SurveyDefinition::problems((string)json_encode([['name' => $name, 'type' => 'password']]), '[{"name":"db_password","type":"password"}]')
        );
        $this->assertSame(
            [sprintf(self::NAME_RULE, $quoted)],
            SurveyDefinition::problems((string)json_encode([['name' => $name, 'type' => 'text']]), '[{"name":"db_password","type":"text"}]'),
            'for every type'
        );
    }

    /**
     * A password field whose stored name no caller sends exactly cannot
     * launch. Its survey keeps saving while it stays as it is; a changed
     * survey must rename the field. The editor does so for whitespace around
     * the name, which it trims; other characters need a new name.
     *
     * @dataProvider paddedNameProvider
     * @dataProvider unicodeNameProvider
     */
    public function testAStoredPasswordNameNoCallerSendsExactlyMustGoOnceTheSurveyChanges(string $name, string $quoted): void
    {
        $stored = (string)json_encode([['name' => $name, 'type' => 'password']]);
        $changed = (string)json_encode([['name' => $name, 'type' => 'password'], ['name' => 'env']]);
        $repaired = (string)json_encode([['name' => 'db_password', 'label' => 'db_password', 'type' => 'password'], ['name' => 'env']]);

        $this->assertSame([], SurveyDefinition::problems($stored, $stored));
        $this->assertSame([sprintf(self::NAME_RULE, $quoted)], SurveyDefinition::problems($changed, $stored));
        $this->assertSame([], SurveyDefinition::problems($repaired, $stored));
    }

    /**
     * Inner spaces and dashes are no padding: such legacy password names
     * keep saving when the survey changes (and "db password" launches).
     */
    public function testAStoredPasswordNameWithInnerSpacesOrDashesIsKept(): void
    {
        $stored = '[{"name":"db password","type":"password"},{"name":"api-key","type":"password"}]';

        $this->assertSame([], SurveyDefinition::problems('[{"name":"db password","type":"password"},{"name":"api-key","type":"password"},{"name":"env"}]', $stored));
    }

    /**
     * A field of another type keeps such a stored name, but cannot become a
     * password field with it.
     *
     * @dataProvider paddedNameProvider
     * @dataProvider unicodeNameProvider
     */
    public function testAStoredFieldWithSuchANameCannotBecomeAPasswordField(string $name, string $quoted): void
    {
        $stored = (string)json_encode([['name' => $name, 'type' => 'text']]);

        $this->assertSame(
            [],
            SurveyDefinition::problems((string)json_encode([['name' => $name, 'type' => 'textarea'], ['name' => 'env']]), $stored),
            'kept as another type'
        );
        $this->assertSame(
            [sprintf(self::NAME_RULE, $quoted)],
            SurveyDefinition::problems((string)json_encode([['name' => $name, 'type' => 'password']]), $stored)
        );
    }

    /**
     * Other legacy names keep working, padding included, unless the field
     * becomes a password field.
     */
    public function testAStoredPaddedNameOfAnotherTypeIsKeptButCannotBecomeAPasswordField(): void
    {
        $stored = '[{"name":"db-host ","type":"text"},{"name":"token\n","type":"text"}]';

        $this->assertSame([], SurveyDefinition::problems('[{"name":"db-host ","type":"textarea"},{"name":"token\n","type":"text"},{"name":"env"}]', $stored));
        $this->assertSame(
            [sprintf(self::NAME_RULE, '"token\n"')],
            SurveyDefinition::problems('[{"name":"db-host ","type":"text"},{"name":"token\n","type":"password"}]', $stored)
        );
    }

    public function testARenamedFieldNeedsAVariableName(): void
    {
        $baseline = '[{"name":"db-host","type":"text"}]';

        $this->assertSame([], SurveyDefinition::problems('[{"name":"db_host","type":"text"}]', $baseline));
        $this->assertSame(
            ['Survey field name "db-hostname" is not a valid variable name: use letters, digits and underscores, and start with a letter or an underscore.'],
            SurveyDefinition::problems('[{"name":"db-hostname","type":"text"}]', $baseline)
        );
    }

    public function testNamesMustBeUnique(): void
    {
        $this->assertSame(
            ['Survey field name "env" is used more than once.'],
            SurveyDefinition::problems('[{"name":"env"},{"name":"other"},{"name":"env","type":"password"},{"name":" env"}]', '[{"name":" env"}]')
        );
    }

    public function testTwoFieldsWithoutANameAreReportedByTheNameRuleOnly(): void
    {
        $message = 'Survey field name "" is not a valid variable name: use letters, digits and underscores, and start with a letter or an underscore.';

        $this->assertSame([$message], SurveyDefinition::problems('[{"type":"text"},{"type":"text"}]', null));
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function unknownTypeProvider(): array
    {
        return [
            'unknown' => ['bogus', '"bogus"'],
            'wrong case' => ['Password', '"Password"'],
            'empty' => ['', '""'],
            'number' => [1, '1'],
            'list' => [['text'], '["text"]'],
            'object' => [['type' => 'text'], '{"type":"text"}'],
        ];
    }

    /**
     * @dataProvider unknownTypeProvider
     */
    public function testTheTypeMustBeASurveyType(mixed $type, string $quoted): void
    {
        $json = (string)json_encode([['name' => 'env', 'type' => $type]]);

        $this->assertSame(
            ["Survey field \"env\" has an unknown type {$quoted}; use one of: text, textarea, integer, boolean, select, password."],
            SurveyDefinition::problems($json, null)
        );
    }

    /**
     * Regression: a password field's default was stored and shown in plaintext
     * in the editor, although no launch ever used it.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function passwordDefaultProvider(): array
    {
        return [
            'text' => ['hunter2'],
            'space' => [' '],
            'zero' => ['0'],
            'number' => [0],
            'false' => [false],
        ];
    }

    /**
     * @dataProvider passwordDefaultProvider
     */
    public function testAPasswordFieldCannotHaveADefault(mixed $default): void
    {
        $json = (string)json_encode([['name' => 'pw', 'type' => 'password', 'default' => $default], ['name' => 'pw2', 'type' => 'password', 'default' => 'x']]);

        $this->assertSame([SurveyDefinition::PASSWORD_DEFAULT], SurveyDefinition::problems($json, null));
        $this->assertSame('A password field cannot have a default value.', SurveyDefinition::PASSWORD_DEFAULT);
    }

    public function testALegacyPasswordDefaultMustGoWhenTheSurveyChanges(): void
    {
        $baseline = '[{"name":"pw","type":"password","default":"legacy"}]';

        $this->assertSame([SurveyDefinition::PASSWORD_DEFAULT], SurveyDefinition::problems('[{"name":"pw","type":"password","default":"legacy","hint":"new"}]', $baseline));
        $this->assertSame([], SurveyDefinition::problems('[{"name":"pw","type":"password","default":""}]', $baseline), 'the editor saves it without');
    }

    /**
     * Regression: a list or object as a value made SurveyField::fromArray()
     * fail with "Array to string conversion", a 500 for the API.
     */
    public function testADefaultMustBeASingleValue(): void
    {
        $this->assertSame(
            [
                'The default of survey field "env" must be a single value, not a list or an object.',
                'The default of survey field "pw" must be a single value, not a list or an object.',
            ],
            SurveyDefinition::problems('[{"name":"env","default":["a"]},{"name":"pw","type":"password","default":{"a":1}}]', null)
        );
    }

    public function testADefinitionThatIsNotAListIsRejected(): void
    {
        foreach (['"text"', '5', 'true'] as $json) {
            $this->assertSame(['Survey fields must be a JSON list of field definitions.'], SurveyDefinition::problems($json, null), $json);
        }
    }

    public function testEveryEntryMustBeAnObject(): void
    {
        $this->assertSame(
            ['Survey field 2 must be a JSON object with a name and a type.', 'Survey field 3 must be a JSON object with a name and a type.'],
            SurveyDefinition::problems('[{"name":"ok"},"junk",null]', null)
        );
    }

    public function testEveryProblemIsReported(): void
    {
        $problems = SurveyDefinition::problems('[{"name":"a-b","type":"nope","default":[1]},{"name":"a-b"}]', null);

        $this->assertSame([
            'Survey field name "a-b" is not a valid variable name: use letters, digits and underscores, and start with a letter or an underscore.',
            'Survey field "a-b" has an unknown type "nope"; use one of: text, textarea, integer, boolean, select, password.',
            'The default of survey field "a-b" must be a single value, not a list or an object.',
            'Survey field name "a-b" is used more than once.',
        ], $problems);
    }

    // -- quote ------------------------------------------------------------------

    /**
     * Regression (V2-C1U): quote() kept every character outside ASCII as it
     * was, so a message about a name with a trailing non-breaking space or
     * a byte order mark read like one about the plain name.
     */
    public function testQuoteIsJsonWithEverythingOutsidePrintableAsciiEscaped(): void
    {
        $this->assertSame('"\u00e4/\u00f6"', SurveyDefinition::quote("\u{00E4}/\u{00F6}"), 'slashes stay');
        $this->assertSame('"say \"hi\"\n"', SurveyDefinition::quote("say \"hi\"\n"));
        $this->assertSame('"bad \ufffd"', SurveyDefinition::quote("bad \xB1"));
        $this->assertSame('{"a":[1]}', SurveyDefinition::quote(['a' => [1]]));
        $this->assertSame('"db\u007fpw\u0000"', SurveyDefinition::quote("db\x7Fpw\0"));
        $this->assertSame('"x\ud83d\ude00"', SurveyDefinition::quote("x\u{1F600}"));
        foreach (self::unicodeNameProvider() as $case => [$name, $quoted]) {
            $this->assertSame($quoted, SurveyDefinition::quote($name), $case);
        }
    }

    /**
     * Whatever a value holds, its quoted form is printable ASCII, so nothing
     * in it is invisible.
     */
    public function testAQuotedValueIsPrintableAscii(): void
    {
        $value = ['k' . "\u{2028}" => "\x00\x1F\x7F\u{0085}\u{00A0}\u{200B}\u{FEFF}\u{3000}\u{1F600}\xC3"];

        $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/D', SurveyDefinition::quote($value));
    }
}
