<?php

declare(strict_types=1);

namespace app\tests\unit\controllers;

use app\controllers\traits\TeamScopingTrait;
use PHPUnit\Framework\TestCase;
use yii\base\DynamicModel;
use yii\base\Model;

/**
 * TeamScopingTrait::normalizeSubmittedId() reads a submitted reference ID
 * the way the model stores it: every value Yii's integer rule accepts is
 * the int the integer column makes of it, every other value is left to the
 * model's validation.
 *
 * Regression: the web add-step and schedule forms decided team access on
 * filter_var(), which rejects "0112709" while the integer rule accepts it
 * and the column stores 112709, so such an ID skipped the access check.
 */
class TeamScopingTraitTest extends TestCase
{
    /**
     * Forms the integer rule accepts, with the int the column stores.
     *
     * @return array<string, array{0: int|float|string, 1: int}>
     */
    public static function acceptedProvider(): array
    {
        return [
            'digits' => ['112709', 112709],
            'leading zero' => ['0112709', 112709],
            'leading zeros' => ['0000000000000000000000112709', 112709],
            'plus sign' => ['+112709', 112709],
            'plus sign and leading zero' => ['+0112709', 112709],
            'minus sign' => ['-5', -5],
            'zero' => ['0', 0],
            'zeros' => ['00', 0],
            // PCRE's $ also matches before a final newline.
            'trailing newline' => ["112709\n", 112709],
            'int' => [112709, 112709],
            'whole float' => [5.0, 5],
        ];
    }

    /**
     * @dataProvider acceptedProvider
     */
    public function testAValueTheIntegerRuleAcceptsIsAssignedBackAsTheStoredInt(int|float|string $submitted, int $stored): void
    {
        $model = new DynamicModel(['job_template_id' => $submitted]);

        $this->assertSame($stored, $this->normalize($model));
        $this->assertSame($stored, $model->job_template_id);
    }

    /**
     * Values the integer rule rejects, and empty ones, which it skips.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function rejectedProvider(): array
    {
        return [
            'empty string' => [''],
            'null' => [null],
            'leading space' => [' 112709'],
            'trailing space' => ['112709 '],
            'leading tab' => ["\t112709"],
            'exponent' => ['1e3'],
            'decimal' => ['12.0'],
            'hexadecimal' => ['0x1A'],
            'text' => ['abc'],
            'digits after text' => ['abc112709'],
            'sign only' => ['+'],
            'non-ASCII digits' => ["\u{0661}\u{0662}"],
            'fraction' => [5.5],
            'boolean' => [true],
            'empty array' => [[]],
            'array of an ID' => [['112709']],
        ];
    }

    /**
     * @dataProvider rejectedProvider
     */
    public function testAValueTheIntegerRuleRejectsIsLeftToTheModel(mixed $submitted): void
    {
        $model = new DynamicModel(['job_template_id' => $submitted]);

        $this->assertNull($this->normalize($model));
        $this->assertSame($submitted, $model->job_template_id);
    }

    private function normalize(Model $model): ?int
    {
        $subject = new class () {
            use TeamScopingTrait;

            public function normalize(Model $model): ?int
            {
                return $this->normalizeSubmittedId($model, 'job_template_id');
            }
        };

        return $subject->normalize($model);
    }
}
