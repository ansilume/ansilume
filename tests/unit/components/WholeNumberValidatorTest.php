<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\WholeNumberValidator;
use PHPUnit\Framework\TestCase;
use yii\base\DynamicModel;
use yii\validators\NumberValidator;

/**
 * WholeNumberValidator is Yii's NumberValidator, the class of every
 * 'integer', 'number' and 'double' rule (config/web.php, config/console.php
 * and config/test.php map NumberValidator to it), except that with
 * integerOnly it refuses a float that is no whole number, however close to
 * one, and in every mode NAN. Such a float gets the rule's message, as 12.5
 * does, and the min and max errors after it as the parent adds them; every
 * other value gets the parent's answer, the same errors in the same order
 * and number, through validateAttribute() and through validate(). The tests
 * run under the application's error handler, which turns a warning into an
 * exception.
 *
 * Regression: NumberValidator matches its pattern against the float's text,
 * which PHP writes with 14 significant digits (the precision setting), so
 * the integer rule took 0.999999999999999 (text "1"), 4.999999999999999
 * ("5"), 2.9999999999999996 ("3") and 10.00000000000001 ("10") for whole
 * numbers, and the (int) cast of the column's typecast and of
 * SubmittedInteger::of() then truncated them to 0, 4, 2 and 10:
 * PUT /api/v1/users/{id} {"status": 0.999999999999999} answered 200 and
 * deactivated the user. And it made text of NAN, for which PHP 8.5 warns
 * ("unexpected NAN value was coerced to string").
 */
class WholeNumberValidatorTest extends TestCase
{
    /** The rules every value is checked with, by name: the configuration of the validator. */
    private const RULES = [
        'integer' => ['integerOnly' => true],
        'integer from 1 to 10' => ['integerOnly' => true, 'min' => 1, 'max' => 10],
        'number' => [],
        'number from 1 to 10' => ['min' => 1, 'max' => 10],
    ];

    /** The message of the integer rule for the attribute n of errorsOf(). */
    private const NO_INTEGER = 'N must be an integer.';

    private const TOO_SMALL = 'N must be no less than 1.';

    private const TOO_BIG = 'N must be no greater than 10.';

    /**
     * Floats that are no whole number but whose text at 14 significant
     * digits reads as one, by name: the parent's integer rule takes them.
     *
     * @return array<string, float>
     */
    private static function nearWholeFloats(): array
    {
        return [
            '0.999999999999999, text "1"' => 0.999999999999999,
            '4.999999999999999, text "5"' => 4.999999999999999,
            '2.9999999999999996, text "3"' => 2.9999999999999996,
            '10.00000000000001, text "10"' => 10.00000000000001,
            '9.99999999999999, text "10"' => 9.99999999999999,
            '-0.999999999999999, text "-1"' => -0.999999999999999,
            '-2.9999999999999996, text "-3"' => -2.9999999999999996,
            '12345678901234.4, text "12345678901234"' => 12345678901234.4,
        ];
    }

    /**
     * Values that every rule answers as the parent does, by name.
     *
     * @return array<string, mixed>
     */
    private static function sameValues(): array
    {
        return [
            'int' => 5,
            'negative int' => -3,
            'zero' => 0,
            'int above 10' => 11,
            'whole float' => 5.0,
            'negative zero' => -0.0,
            'whole float of 14 digits' => 99999999999999.0,
            'whole float of 15 digits, text "1.0E+14"' => 100000000000000.0,
            '1e15, text "1.0E+15"' => 1e15,
            'fraction' => 12.5,
            'negative fraction' => -12.5,
            'fraction below 1' => 0.5,
            'tiny fraction, text "1.0E-20"' => 1e-20,
            'float beyond the int range' => 1e30,
            'negative float beyond the int range' => -1e30,
            'float just beyond the largest int' => 9223372036854775808.0,
            'infinity' => INF,
            'negative infinity' => -INF,
            'digits' => '5',
            'digits with a plus sign' => '+5',
            'digits with a minus sign' => '-5',
            'digits with a leading zero' => '05',
            'digits with a final line break' => "5\n",
            'digits after a space' => ' 5',
            'digits before a space' => '5 ',
            'decimal text of a whole number' => '5.0',
            'decimal text' => '5.5',
            'exponent text' => '1e3',
            'text' => 'abc',
            'empty text' => '',
            'null' => null,
            'true' => true,
            'false' => false,
            'a list' => [5],
            'an empty list' => [],
        ];
    }

    /**
     * Each value of sameValues() with each rule, and the near-whole floats
     * with the number rules, which take them as numbers.
     *
     * @return array<string, array{0: array<string, mixed>, 1: mixed}>
     */
    public static function sameAnswerProvider(): array
    {
        $cases = [];
        foreach (self::RULES as $rule => $config) {
            foreach (self::sameValues() as $name => $value) {
                $cases["{$rule}: {$name}"] = [$config, $value];
            }
        }
        foreach (['number', 'number from 1 to 10'] as $rule) {
            foreach (self::nearWholeFloats() as $name => $value) {
                $cases["{$rule}: {$name}"] = [self::RULES[$rule], $value];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider sameAnswerProvider
     * @param array<string, mixed> $config
     */
    public function testEveryOtherValueGetsTheParentsAnswer(array $config, mixed $value): void
    {
        $parent = new NumberValidator($config);
        $rule = new WholeNumberValidator($config);

        $this->assertSame(self::errorsOf($parent, $value), self::errorsOf($rule, $value), 'validateAttribute()');
        $this->assertSame(self::answerOf($parent, $value), self::answerOf($rule, $value), 'validate()');
    }

    /**
     * Lists of an allowArray rule: each entry is checked as the parent
     * checks it, a list or an object among them stops the check.
     *
     * @return array<string, array{0: array<string, mixed>, 1: list<mixed>}>
     */
    public static function sameListProvider(): array
    {
        $lists = [
            'whole numbers' => [5, 7],
            'an empty list' => [],
            'a fraction and a number above 10' => [5, 12.5, 20],
            'text and a whole number' => ['abc', 3],
            'a list in the list, then a fraction' => [5, [1], 12.5],
            'a boolean first' => [true, 12.5],
        ];
        $cases = [];
        foreach (['integer', 'integer from 1 to 10'] as $rule) {
            foreach ($lists as $name => $list) {
                $cases["{$rule}: {$name}"] = [['allowArray' => true] + self::RULES[$rule], $list];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider sameListProvider
     * @param array<string, mixed> $config
     * @param list<mixed> $list
     */
    public function testAListGetsTheParentsAnswer(array $config, array $list): void
    {
        $parent = new NumberValidator($config);
        $rule = new WholeNumberValidator($config);

        $this->assertSame(self::errorsOf($parent, $list), self::errorsOf($rule, $list), 'validateAttribute()');
        $this->assertSame(self::answerOf($parent, $list), self::answerOf($rule, $list), 'validate()');
    }

    /**
     * A value of no type a number has is refused at once, as the parent
     * refuses it: an object without __toString() and a resource, also in a
     * list. An object with one is read as its text.
     */
    public function testAValueOfAnotherTypeGetsTheParentsAnswer(): void
    {
        $stream = fopen('php://memory', 'r');
        $this->assertIsResource($stream);
        $digits = new class () {
            public function __toString(): string
            {
                return '5';
            }
        };
        $values = ['an object' => new \stdClass(), 'a resource' => $stream, 'an object with the text "5"' => $digits];

        foreach (['integer', 'number'] as $name) {
            foreach ($values as $what => $value) {
                $parent = new NumberValidator(self::RULES[$name]);
                $rule = new WholeNumberValidator(self::RULES[$name]);
                $this->assertSame(self::errorsOf($parent, $value), self::errorsOf($rule, $value), "{$name}: {$what}");
                $this->assertSame(self::answerOf($parent, $value), self::answerOf($rule, $value), "{$name}: {$what}, validate()");
            }
        }
        $config = ['integerOnly' => true, 'allowArray' => true, 'max' => 10];
        $list = [5.5, $stream, 20];
        $this->assertSame([self::NO_INTEGER, self::NO_INTEGER], self::errorsOf(new WholeNumberValidator($config), $list), 'a resource ends the check of a list: 20 is not checked');
        $this->assertSame(self::errorsOf(new NumberValidator($config), $list), self::errorsOf(new WholeNumberValidator($config), $list), 'as the parent');
        fclose($stream);
    }

    /**
     * Each near-whole float with each integer rule: the rule's message, then
     * the min and max errors.
     *
     * @return array<string, array{0: array<string, mixed>, 1: float, 2: list<string>}>
     */
    public static function nowRefusedProvider(): array
    {
        $cases = [];
        foreach (self::nearWholeFloats() as $name => $value) {
            $cases["integer: {$name}"] = [self::RULES['integer'], $value, [self::NO_INTEGER]];
            $limits = [self::NO_INTEGER];
            if ($value < 1) {
                $limits[] = self::TOO_SMALL;
            }
            if ($value > 10) {
                $limits[] = self::TOO_BIG;
            }
            $cases["integer from 1 to 10: {$name}"] = [self::RULES['integer from 1 to 10'], $value, $limits];
        }

        return $cases;
    }

    /**
     * Regression: the integer rule took a float whose text reads as a whole
     * number for one, and the save truncated it.
     *
     * @dataProvider nowRefusedProvider
     * @param array<string, mixed> $config
     * @param list<string> $errors
     */
    public function testRegressionAFloatWhoseTextReadsAsAWholeNumberIsRefused(array $config, float $value, array $errors): void
    {
        $this->assertNotContains(self::NO_INTEGER, self::errorsOf(new NumberValidator($config), $value), 'precondition: the parent takes it for a whole number');

        $this->assertSame($errors, self::errorsOf(new WholeNumberValidator($config), $value), 'validateAttribute()');
        $this->assertSame('the input value must be an integer.', self::answerOf(new WholeNumberValidator($config), $value), 'validate()');
    }

    /**
     * Regression: in a list of an allowArray rule, such a float is refused
     * where it stands, and the entries after it are checked as before.
     */
    public function testRegressionSuchAFloatInAListIsRefusedWhereItStands(): void
    {
        $config = ['integerOnly' => true, 'allowArray' => true, 'max' => 10];
        $list = [5, 4.999999999999999, 20];
        $this->assertSame([self::TOO_BIG], self::errorsOf(new NumberValidator($config), $list), 'precondition: the parent refuses only 20');
        $this->assertSame('the input value must be no greater than 10.', self::answerOf(new NumberValidator($config), $list), 'precondition');

        $this->assertSame([self::NO_INTEGER, self::TOO_BIG], self::errorsOf(new WholeNumberValidator($config), $list));
        $this->assertSame('the input value must be an integer.', self::answerOf(new WholeNumberValidator($config), $list));
        $this->assertSame([self::TOO_BIG, self::NO_INTEGER], self::errorsOf(new WholeNumberValidator($config), [5, 20, -2.9999999999999996]));
        $this->assertSame('the input value must be no greater than 10.', self::answerOf(new WholeNumberValidator($config), [5, 20, -2.9999999999999996]), 'validate() gives the first error');
    }

    /**
     * NAN is no number: every rule refuses it with its message, alone and
     * in a list, without the warning PHP 8.5 gives when it makes text of
     * NAN, and NAN is neither below min nor above max.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: list<mixed>, 3: list<string>}>
     */
    public static function notANumberProvider(): array
    {
        $number = 'N must be a number.';

        return [
            'integer' => [self::RULES['integer'], self::NO_INTEGER, [NAN, 'abc'], [self::NO_INTEGER, self::NO_INTEGER]],
            'integer from 1 to 10' => [self::RULES['integer from 1 to 10'], self::NO_INTEGER, [NAN, 20], [self::NO_INTEGER, self::TOO_BIG]],
            'number' => [self::RULES['number'], $number, [NAN, 'abc'], [$number, $number]],
            'number from 1 to 10' => [self::RULES['number from 1 to 10'], $number, [NAN, 20], [$number, self::TOO_BIG]],
        ];
    }

    /**
     * @dataProvider notANumberProvider
     * @param array<string, mixed> $config
     * @param list<mixed> $list
     * @param list<string> $listErrors
     */
    public function testNotANumberIsRefusedWithoutAWarning(array $config, string $message, array $list, array $listErrors): void
    {
        $rule = new WholeNumberValidator($config);

        $this->assertSame([$message], self::errorsOf($rule, NAN));
        $this->assertSame(str_replace('N ', 'the input value ', $message), self::answerOf($rule, NAN));
        $this->assertSame($listErrors, self::errorsOf(new WholeNumberValidator(['allowArray' => true] + $config), $list), 'in a list, the next entry is checked too');
    }

    /**
     * The client-side check is the parent's: WholeNumberValidator declares
     * neither clientValidateAttribute() nor getClientOptions(), and the
     * options are the same, so a form renders the same script.
     */
    public function testTheClientSideCheckIsTheParents(): void
    {
        foreach (['clientValidateAttribute', 'getClientOptions'] as $method) {
            $this->assertSame(NumberValidator::class, (new \ReflectionMethod(WholeNumberValidator::class, $method))->getDeclaringClass()->getName(), $method);
        }
        foreach (self::RULES as $name => $config) {
            $model = new DynamicModel(['n' => 5]);
            $this->assertEquals(
                (new NumberValidator($config))->getClientOptions($model, 'n'),
                (new WholeNumberValidator($config))->getClientOptions($model, 'n'),
                $name
            );
        }
    }

    /**
     * The errors that $rule gives the attribute n of a model that holds
     * $value, through validateAttribute().
     *
     * @return list<string>
     */
    private static function errorsOf(NumberValidator $rule, mixed $value): array
    {
        $model = new DynamicModel(['n' => $value]);
        $rule->validateAttribute($model, 'n');

        return array_values($model->getErrors('n'));
    }

    /** The error that $rule's validate() gives $value; null when it takes it. */
    private static function answerOf(NumberValidator $rule, mixed $value): ?string
    {
        return $rule->validate($value, $error) ? null : (is_string($error) ? $error : '');
    }
}
