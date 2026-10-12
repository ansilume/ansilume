<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\StoredScheduleAnswers;
use app\components\SurveySecretException;
use app\components\SurveySecrets;
use PHPUnit\Framework\TestCase;

/**
 * The password answers a schedule has stored: sealed ones, and plain ones
 * left in its extra vars from before their key became a password field.
 */
class StoredScheduleAnswersTest extends TestCase
{
    private const NAMES = ['db_password', 'api_pin'];
    private const ANSWER = 'stored-answer-marker-2207';

    private mixed $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = 'stored-schedule-answers-key-0123456789';
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

    public function testAScheduleWithoutAnswersHasNone(): void
    {
        $answers = new StoredScheduleAnswers('{"env":"prod"}', null, self::NAMES);

        $this->assertFalse($answers->exist());
        $this->assertSame([], $answers->names());
        $this->assertSame([], $answers->values());
        $this->assertSame([], $answers->plain());
        $this->assertSame('{"env":"prod"}', $answers->masked());
        $this->assertSame(['env' => 'prod'], $answers->vars());
    }

    public function testSealedAnswersAreReadFromTheSealedSet(): void
    {
        $answers = new StoredScheduleAnswers(
            '{"env":"prod","db_password":"$encrypted$"}',
            SurveySecrets::seal(['db_password' => self::ANSWER, 'old_pin' => 1234]),
            self::NAMES
        );

        $this->assertTrue($answers->exist());
        $this->assertSame(['db_password', 'old_pin'], $answers->names(), 'a sealed key that is no password field any more stays an answer');
        $this->assertSame(['db_password' => self::ANSWER, 'old_pin' => 1234], $answers->values());
        $this->assertSame([], $answers->plain(), 'the placeholder is no plain answer');
        $this->assertSame('{"env":"prod","db_password":"$encrypted$"}', $answers->masked(), 'nothing to mask: unchanged');
    }

    /**
     * A key that became a password field after the schedule was saved holds
     * its value in plain text: a stored answer, masked wherever it is shown.
     */
    public function testAPlainValueOfAPasswordFieldIsAStoredAnswer(): void
    {
        $answers = new StoredScheduleAnswers('{"env":"prod","api_pin":"' . self::ANSWER . '","n":1}', null, self::NAMES);

        $this->assertTrue($answers->exist());
        $this->assertSame(['api_pin' => self::ANSWER], $answers->plain());
        $this->assertSame(['api_pin'], $answers->names());
        $this->assertSame(['api_pin' => self::ANSWER], $answers->values());
        $masked = (string)$answers->masked();
        $this->assertSame(['env' => 'prod', 'api_pin' => SurveySecrets::PLACEHOLDER, 'n' => 1], json_decode($masked, true));
        $this->assertStringNotContainsString(self::ANSWER, $masked);
    }

    public function testSealedAndPlainAnswersCombine(): void
    {
        $answers = new StoredScheduleAnswers(
            '{"db_password":"$encrypted$","api_pin":"4711"}',
            SurveySecrets::seal(['db_password' => self::ANSWER]),
            self::NAMES
        );

        $this->assertSame(['api_pin', 'db_password'], $answers->names());
        $this->assertSame(['db_password' => self::ANSWER, 'api_pin' => '4711'], $answers->values());
    }

    /**
     * Without APP_SECRET_KEY's old value the sealed answers cannot be read:
     * their names still come from the placeholders, their values not at all.
     */
    public function testUndecryptableAnswersKeepTheirNamesButNotTheirValues(): void
    {
        $sealed = SurveySecrets::seal(['db_password' => self::ANSWER]);
        $_ENV['APP_SECRET_KEY'] = 'a-rotated-key-that-cannot-open-it-0123';
        $answers = new StoredScheduleAnswers('{"db_password":"$encrypted$","env":"prod","api_pin":"9"}', $sealed, self::NAMES);

        $this->assertTrue($answers->exist());
        $this->assertSame(['api_pin', 'db_password'], $answers->names());
        $this->expectException(SurveySecretException::class);
        $answers->values();
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function extraVarsWithoutKeysProvider(): array
    {
        return ['null' => [null], 'empty' => [''], 'a JSON number' => ['5'], 'invalid JSON' => ['{not json']];
    }

    /**
     * @dataProvider extraVarsWithoutKeysProvider
     */
    public function testExtraVarsWithoutKeysHoldNoPlainAnswer(?string $extraVars): void
    {
        $answers = new StoredScheduleAnswers($extraVars, null, self::NAMES);

        $this->assertSame([], $answers->vars());
        $this->assertSame([], $answers->plain());
        $this->assertSame($extraVars, $answers->masked());
    }

    /**
     * Masking never fails: a number too large for JSON shows as 0 rather
     * than the extra vars going missing (or being shown unmasked).
     */
    public function testMaskingSurvivesANumberTooLargeForJson(): void
    {
        $answers = new StoredScheduleAnswers('{"api_pin":"' . self::ANSWER . '","huge":1e999}', null, self::NAMES);

        $this->assertSame(['api_pin' => SurveySecrets::PLACEHOLDER, 'huge' => 0], json_decode((string)$answers->masked(), true));
    }

    /**
     * A password field named "0" whose plain answer is the only extra var:
     * the masked extra vars stay a JSON object, and a list stays a list.
     *
     * Regression (task 3 of R2V2-1): json_decode() made the key 0, and
     * json_encode() wrote [0 => ...] as a list, so the form and the API
     * showed [...] for {"0": ...}.
     */
    public function testMaskingKeepsTheTopLevelShape(): void
    {
        $object = new StoredScheduleAnswers('{"0":"' . self::ANSWER . '"}', null, ['0']);
        $list = new StoredScheduleAnswers('["' . self::ANSWER . '"]', null, ['0']);

        $this->assertSame("{\n    \"0\": \"\$encrypted\$\"\n}", $object->masked());
        $this->assertSame("[\n    \"\$encrypted\$\"\n]", $list->masked());
        $this->assertSame(['0' => self::ANSWER], $object->plain());
    }

    // The extra vars are #[\SensitiveParameter]: tests/unit/SensitiveParametersTest.php.
}
