<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\components\WholeNumberValidator;
use app\models\JobTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use yii\base\DynamicModel;
use yii\validators\NumberValidator;

/**
 * Every rule that Yii builds as a NumberValidator ('integer', 'number',
 * 'double' and NumberValidator::class) is a WholeNumberValidator: the
 * container definition of config/web.php, config/console.php and
 * config/test.php maps the class to it, Validator::createValidator() builds
 * each rule through Yii::createObject(), which asks the container, and no
 * application code (RequestIntCastScan::sourceFilesIn()) creates a
 * NumberValidator with `new`, which the container does not see, or extends
 * one, which inherits its check. WholeNumberValidator itself extends it.
 *
 * Regression: NumberValidator matches its pattern against a float's text,
 * which PHP writes with 14 significant digits, so the integer rule of
 * every model took 2.9999999999999996 (text "3") for a whole number and
 * the column's typecast saved its truncation, 2: PUT /api/v1/users/{id}
 * {"status": 0.999999999999999} deactivated the user. SubmittedInteger and
 * GatedJobSteps created a NumberValidator of their own.
 *
 * What the scan cannot see: a class named in a variable (`new $class()`)
 * and a container definition that code sets later.
 */
class WholeNumberRulesTest extends TestCase
{
    /** The code that may name NumberValidator after `extends`: the rule itself, by path. */
    private const ALLOWED = [
        'components/WholeNumberValidator.php' => ['extends NumberValidator'],
    ];

    /**
     * @return array<string, array{0: string}>
     */
    public static function configProvider(): array
    {
        return [
            'the web application' => ['config/web.php'],
            'the console application' => ['config/console.php'],
            'the tests' => ['config/test.php'],
        ];
    }

    #[DataProvider('configProvider')]
    public function testEachConfigMapsNumberValidatorToWholeNumberValidator(string $file): void
    {
        $config = require self::root() . '/' . $file;
        $this->assertIsArray($config);

        $this->assertSame(
            WholeNumberValidator::class,
            $config['container']['definitions'][NumberValidator::class] ?? null,
            "{$file}: 'container' => ['definitions' => [NumberValidator::class => WholeNumberValidator::class]]"
        );
    }

    /**
     * @return array<string, array{0: string|class-string}>
     */
    public static function ruleProvider(): array
    {
        return [
            'integer' => ['integer'],
            'number' => ['number'],
            'double' => ['double'],
            'the class' => [NumberValidator::class],
        ];
    }

    /**
     * The rules of a model in the application of the tests, and the
     * class asked from the container.
     */
    #[DataProvider('ruleProvider')]
    public function testARuleOfAModelIsAWholeNumberValidator(string $rule): void
    {
        $model = new DynamicModel(['n' => 2.9999999999999996]);
        $model->addRule(['n'], $rule, $rule === 'integer' ? [] : ['integerOnly' => true]);

        $validators = $model->getActiveValidators('n');
        $this->assertCount(1, $validators);
        $this->assertInstanceOf(WholeNumberValidator::class, $validators[0]);
        $this->assertFalse($model->validate(), '2.9999999999999996 is no whole number');
        $this->assertSame(['N must be an integer.'], $model->getErrors('n'));
    }

    public function testTheIntegerRulesOfAModelAndTheContainerGiveWholeNumberValidators(): void
    {
        $this->assertInstanceOf(WholeNumberValidator::class, \Yii::createObject(NumberValidator::class));
        $numberRules = array_filter(
            (new JobTemplate())->getActiveValidators('forks'),
            static fn (object $validator): bool => $validator instanceof NumberValidator
        );
        $this->assertNotSame([], $numberRules, 'precondition: forks has an integer rule');
        foreach ($numberRules as $validator) {
            $this->assertInstanceOf(WholeNumberValidator::class, $validator);
        }
    }

    public function testNoApplicationCodeCreatesOrExtendsANumberValidator(): void
    {
        $root = self::root();
        $sites = [];
        foreach (RequestIntCastScan::sourceFilesIn($root) as $path) {
            $code = file_get_contents($root . '/' . $path);
            $this->assertIsString($code, $path);
            $allowed = self::ALLOWED[$path] ?? [];
            foreach (self::creationsIn($code) as ['line' => $line, 'code' => $text]) {
                if (!in_array($text, $allowed, true)) {
                    $sites[] = "{$path}:{$line}: {$text}";
                }
            }
        }

        $this->assertSame(
            [],
            $sites,
            'Yii\'s NumberValidator reads a float as its text of 14 digits and takes 2.9999999999999996 for 3:'
            . ' create the integer rule as new WholeNumberValidator([\'integerOnly\' => true]), or as a rule of a'
            . ' model, which the container definition of config/*.php makes a WholeNumberValidator'
        );
    }

    /**
     * Each way the scan finds: `new` and `extends` with the class's short,
     * imported, aliased, qualified or fully qualified name, without
     * parentheses and in an anonymous class too; not a string, a comment,
     * the class constant, the container or another class.
     */
    public function testTheScanFindsEachWayToCreateOne(): void
    {
        $code = <<<'PHP'
            <?php
            namespace app\sample;

            use yii\validators\NumberValidator;
            use yii\validators\NumberValidator as Numbers;
            use yii\validators;

            class A extends NumberValidator {}
            class B extends \yii\validators\NumberValidator {}
            $a = new NumberValidator(['integerOnly' => true]);
            $b = new \yii\validators\NumberValidator();
            $c = new Numbers();
            $d = new validators\NumberValidator();
            $e = new NUMBERVALIDATOR;
            $f = new class () extends Numbers {};
            $g = new \app\components\WholeNumberValidator(['integerOnly' => true]);
            $h = NumberValidator::class;
            $i = \Yii::createObject(NumberValidator::class);
            $j = 'new NumberValidator()';
            // new NumberValidator() in a comment
            $k = new class () extends \app\components\WholeNumberValidator {};
            $l = new \app\sample\NumberValidator();
            PHP;

        $this->assertSame(
            [
                ['line' => 8, 'code' => 'extends NumberValidator'],
                ['line' => 9, 'code' => 'extends \yii\validators\NumberValidator'],
                ['line' => 10, 'code' => 'new NumberValidator'],
                ['line' => 11, 'code' => 'new \yii\validators\NumberValidator'],
                ['line' => 12, 'code' => 'new Numbers'],
                ['line' => 13, 'code' => 'new validators\NumberValidator'],
                ['line' => 14, 'code' => 'new NUMBERVALIDATOR'],
                ['line' => 15, 'code' => 'extends Numbers'],
            ],
            self::creationsIn($code)
        );
    }

    /**
     * Where $code names NumberValidator after `new` or `extends`: the line
     * and the two tokens.
     *
     * @return list<array{line: int, code: string}>
     */
    private static function creationsIn(string $code): array
    {
        $php = new PhpCode($code);
        $sites = [];
        foreach ($php->tokens as $i => $token) {
            $name = $php->tokens[$i + 1] ?? null;
            if (
                $token->is([T_NEW, T_EXTENDS])
                && $name !== null
                && $name->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
                && strcasecmp($php->className($name->text), NumberValidator::class) === 0
            ) {
                $sites[] = ['line' => $token->line, 'code' => $token->text . ' ' . $name->text];
            }
        }

        return $sites;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
