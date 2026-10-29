<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\SubmittedId;
use app\components\SubmittedInteger;
use PHPUnit\Framework\TestCase;
use yii\base\DynamicModel;

/**
 * SubmittedInteger reads a submitted whole number as the model's integer
 * rule reads it, without the (int) cast that warns on PHP 8.5 for a float
 * beyond the int range. The tests run under the application's error
 * handler, which turns a warning into an exception, so a warning here fails
 * them.
 *
 * Regression: a JSON body with a number such as 1e30 reached (int) casts
 * before validation, and on PHP 8.5 the request answered 500 instead of 422.
 */
class SubmittedIntegerTest extends TestCase
{
    /**
     * @return array<string, array{0: int|float|string, 1: int}>
     */
    public static function wholeNumberProvider(): array
    {
        return [
            'int' => [7, 7],
            'negative int' => [-3, -3],
            'digits' => ['5', 5],
            'leading zero and plus sign' => ['+0112709', 112709],
            'whole float' => [5.0, 5],
            'negative zero' => [-0.0, 0],
            'digits with a final line break, as the integer rule reads them' => ["5\n", 5],
            'largest int' => [PHP_INT_MAX, PHP_INT_MAX],
            'digits beyond the int range saturate without a warning' => ['99999999999999999999', PHP_INT_MAX],
        ];
    }

    /**
     * @dataProvider wholeNumberProvider
     */
    public function testAWholeNumberIsTheIntItStandsFor(int|float|string $submitted, int $expected): void
    {
        $this->assertSame($expected, SubmittedInteger::of($submitted));
        $this->assertSame($expected, SubmittedInteger::orAsSubmitted($submitted));
        $this->assertSame($expected, SubmittedId::of($submitted), 'SubmittedId reads IDs the same way');
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function noWholeNumberProvider(): array
    {
        return [
            'float beyond the int range' => [1e30],
            'negative float beyond the int range' => [-1e30],
            'float just beyond the largest int' => [9223372036854775808.0],
            'infinity, which JSON gives for 1e999' => [INF],
            'negative infinity' => [-INF],
            'fraction' => [1.5],
            'decimal text' => ['1.5'],
            'exponent text' => ['1e30'],
            'text' => ['abc'],
            'empty string' => [''],
            'null' => [null],
            'boolean' => [true],
            'array' => [[5]],
        ];
    }

    /**
     * @dataProvider noWholeNumberProvider
     */
    public function testAnyOtherValueIsNoWholeNumberAndLeftAsSubmitted(mixed $submitted): void
    {
        $this->assertNull(SubmittedInteger::of($submitted));
        $this->assertSame($submitted, SubmittedInteger::orAsSubmitted($submitted), 'the model reports it');
        $this->assertNull(SubmittedId::of($submitted));
    }

    /**
     * Floats that are no whole number, but whose text at PHP's precision of
     * 14 significant digits reads as one.
     *
     * @return array<string, array{0: float}>
     */
    public static function nearWholeNumberProvider(): array
    {
        return [
            '0.999999999999999, text "1"' => [0.999999999999999],
            '4.999999999999999, text "5"' => [4.999999999999999],
            '2.9999999999999996, text "3"' => [2.9999999999999996],
            '10.00000000000001, text "10"' => [10.00000000000001],
            '9.99999999999999, text "10"' => [9.99999999999999],
            '-2.9999999999999996, text "-3"' => [-2.9999999999999996],
        ];
    }

    /**
     * Regression: Yii's integer rule read such a float as the whole number
     * of its text, and of() cast it to its truncation: 0.999999999999999 was
     * 0 (PUT /api/v1/users/{id} {"status": 0.999999999999999} deactivated the
     * user), 4.999999999999999 was ID 4, 10.00000000000001 was 10. It is no
     * whole number: of() gives null, orAsSubmitted() hands it on as it came,
     * and a model's integer rule refuses it (WholeNumberValidator, the class
     * of the rule through the container definition of config/test.php).
     *
     * @dataProvider nearWholeNumberProvider
     */
    public function testRegressionAFloatWhoseTextReadsAsAWholeNumberIsNone(float $submitted): void
    {
        $this->assertNull(SubmittedInteger::of($submitted));
        $this->assertNull(SubmittedId::of($submitted));
        $this->assertSame($submitted, SubmittedInteger::orAsSubmitted($submitted), 'the model reports it');

        $model = new DynamicModel(['n' => SubmittedInteger::orAsSubmitted($submitted)]);
        $model->addRule(['n'], 'integer');
        $this->assertFalse($model->validate(), 'a model\'s integer rule refuses it');
        $this->assertSame(['N must be an integer.'], $model->getErrors('n'));
    }

    /**
     * NAN is no whole number. The integer rule refuses it without making
     * text of it, for which PHP 8.5 warns (WholeNumberValidator).
     */
    public function testNotANumberIsNoWholeNumberAndMakesNoWarning(): void
    {
        $this->assertNull(SubmittedInteger::of(NAN));
        $this->assertNull(SubmittedId::of(NAN));
        $kept = SubmittedInteger::orAsSubmitted(NAN);
        $this->assertIsFloat($kept);
        $this->assertNan($kept);
    }

    /**
     * What a JSON body gives for such numbers, read as the request parser
     * reads it: none of them makes a warning on the way.
     */
    public function testNumbersOfAJsonBodyBeyondTheIntRangeAreNoWholeNumbers(): void
    {
        $body = json_decode('{"a": 1e30, "b": 9223372036854775808, "c": 1e999, "d": 12}', true);
        $this->assertIsArray($body);

        $this->assertNull(SubmittedInteger::of($body['a']));
        $this->assertNull(SubmittedInteger::of($body['b']));
        $this->assertNull(SubmittedInteger::of($body['c']));
        $this->assertSame(12, SubmittedInteger::of($body['d']));
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function emptyProvider(): array
    {
        return [
            'null' => [null, true],
            'empty string, the empty choice of a form' => ['', true],
            'an empty list, also a JSON {}' => [[], false],
            'a list' => [[5], false],
            'zero' => [0, false],
            'zero as text' => ['0', false],
            'false' => [false, false],
        ];
    }

    /**
     * @dataProvider emptyProvider
     */
    public function testOnlyNullAndEmptyTextAreEmpty(mixed $value, bool $empty): void
    {
        $this->assertSame($empty, SubmittedInteger::isEmpty($value));
    }

    /**
     * Regression: Yii takes an empty list for empty, so an integer rule
     * skipped a JSON [] or {} and the column stored 0 (or its foreign key
     * refused 0: POST /api/v1/projects with "scm_credential_id": [] answered
     * 500). With isEmpty() the rule refuses it as it refuses a list with
     * values, and null and "" stay no value.
     */
    public function testAnIntegerRuleWithIsEmptyRefusesAnEmptyList(): void
    {
        $this->assertTrue($this->integerRule([], false)->validate(), 'Yii\'s own isEmpty() skips the empty list');

        $model = $this->integerRule([], true);
        $this->assertFalse($model->validate());
        $this->assertSame(['N must be an integer.'], $model->getErrors('n'));
        $this->assertSame([[5], [], ['a' => 1]], array_values(array_filter(
            [[5], [], ['a' => 1], null, ''],
            fn (mixed $value): bool => !$this->integerRule($value, true)->validate()
        )), 'every list and object is refused, null and "" pass');
    }

    /**
     * refusesLists() gives each listed attribute whose value is a list or an
     * object, empty or not, the integer rule's error with the attribute's
     * label; it leaves other values, attributes it is not given and the
     * model's attributes alone.
     */
    public function testRefusesListsGivesEachListTheIntegerRulesError(): void
    {
        $model = new DynamicModel(['verbosity' => 2, 'approval_rule_id' => 7, 'forks' => 5, 'limit' => null]);
        $values = ['verbosity' => [], 'approval_rule_id' => ['a' => 1], 'forks' => '5', 'timeout_minutes' => null, 'limit' => ['x']];

        $this->assertTrue(SubmittedInteger::refusesLists($model, $values, ['verbosity', 'approval_rule_id', 'forks', 'timeout_minutes']));

        $this->assertSame(
            ['verbosity' => ['Verbosity must be an integer.'], 'approval_rule_id' => ['Approval Rule Id must be an integer.']],
            $model->getErrors()
        );
        $this->assertSame(['verbosity' => 2, 'approval_rule_id' => 7, 'forks' => 5, 'limit' => null], $model->getAttributes());
    }

    public function testRefusesListsRefusesNothingWithoutAList(): void
    {
        $model = new DynamicModel(['verbosity' => 2, 'forks' => 5]);

        $this->assertFalse(SubmittedInteger::refusesLists($model, ['verbosity' => 3, 'forks' => '', 'other' => []], ['verbosity', 'forks']));
        $this->assertFalse(SubmittedInteger::refusesLists($model, ['verbosity' => [3]], []));
        $this->assertSame([], $model->getErrors());
    }

    /**
     * The values can be a form's posted fields, static inventory content with
     * host passwords among them: they are marked, so stack traces leave them
     * out, and an error never repeats a value.
     */
    public function testTheValuesStayOutOfTracesAndErrors(): void
    {
        $parameter = new \ReflectionParameter([SubmittedInteger::class, 'refusesLists'], 'values');
        $model = new DynamicModel(['forks' => null]);

        $this->assertNotSame([], $parameter->getAttributes(\SensitiveParameter::class));
        $this->assertTrue(SubmittedInteger::refusesLists($model, ['forks' => ['ansible_password=example-value-1234']], ['forks']));
        $this->assertSame(['Forks must be an integer.'], $model->getErrors('forks'));
    }

    /**
     * A model whose attribute n holds $value, with an integer rule that is
     * given SubmittedInteger::isEmpty() when $submittedIsEmpty.
     */
    private function integerRule(mixed $value, bool $submittedIsEmpty): DynamicModel
    {
        $model = new DynamicModel(['n' => $value]);
        $model->addRule(['n'], 'integer', $submittedIsEmpty ? ['isEmpty' => [SubmittedInteger::class, 'isEmpty']] : []);

        return $model;
    }
}
