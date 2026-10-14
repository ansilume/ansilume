<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\SubmittedId;
use PHPUnit\Framework\TestCase;
use yii\base\DynamicModel;

/**
 * SubmittedId reads a submitted reference ID as the model's integer rule
 * reads it and the integer column stores it: every value the rule accepts is
 * the int the column makes of it, every other value is no ID and left to the
 * model's validation. The web controllers (TeamScopingTrait) and the step
 * write path check access on this value, so an ID such as "05" is checked as
 * the 5 that is stored.
 */
class SubmittedIdTest extends TestCase
{
    /**
     * @return array<string, array{0: int|float|string, 1: int}>
     */
    public static function idProvider(): array
    {
        return [
            'digits' => ['5', 5],
            'leading zero' => ['05', 5],
            'plus sign' => ['+5', 5],
            'plus sign and leading zero' => ['+0112709', 112709],
            'minus sign' => ['-5', -5],
            'zero' => ['0', 0],
            'int' => [7, 7],
            'whole float' => [5.0, 5],
        ];
    }

    /**
     * @dataProvider idProvider
     */
    public function testAValueTheIntegerRuleAcceptsIsTheIntTheColumnStores(int|float|string $submitted, int $id): void
    {
        $this->assertSame($id, SubmittedId::of($submitted));
        $this->assertTrue($this->integerRuleAccepts($submitted));

        $model = new DynamicModel(['job_template_id' => $submitted]);
        $this->assertSame($id, SubmittedId::normalize($model, 'job_template_id'));
        $this->assertSame($id, $model->job_template_id, 'assigned back, so the save uses the checked value');
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function noIdProvider(): array
    {
        return [
            'empty string' => [''],
            'null' => [null],
            'space' => [' 5'],
            'text' => ['abc'],
            'decimal' => ['5.0'],
            'fraction' => [5.5],
            'boolean' => [true],
            'array' => [['5']],
        ];
    }

    /**
     * @dataProvider noIdProvider
     */
    public function testAnyOtherValueIsNoIdAndLeftAsItIs(mixed $submitted): void
    {
        $this->assertNull(SubmittedId::of($submitted));

        $model = new DynamicModel(['job_template_id' => $submitted]);
        $this->assertNull(SubmittedId::normalize($model, 'job_template_id'));
        $this->assertSame($submitted, $model->job_template_id, 'the model reports it');
    }

    /**
     * Whatever the integer rule accepts, and nothing else, is an ID.
     */
    public function testItAgreesWithTheIntegerRule(): void
    {
        $samples = array_merge(
            array_column(self::idProvider(), 0),
            array_filter(array_column(self::noIdProvider(), 0), static fn (mixed $value): bool => $value !== null && $value !== '')
        );
        foreach ($samples as $sample) {
            $this->assertSame($this->integerRuleAccepts($sample), SubmittedId::of($sample) !== null, (string)json_encode($sample));
        }
    }

    private function integerRuleAccepts(mixed $value): bool
    {
        return !DynamicModel::validateData(['id' => $value], [['id', 'integer']])->hasErrors();
    }
}
