<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\WorkflowStepMapping;
use PHPUnit\Framework\TestCase;

/**
 * WorkflowStepMapping: what a workflow step's variable mapping
 * (extra_vars_template) may be, what counts as none, and when two are the
 * same.
 */
class WorkflowStepMappingTest extends TestCase
{
    /**
     * @return array<string, array{0: string|null}>
     */
    public static function noneProvider(): array
    {
        return [
            'null' => [null],
            'empty text' => [''],
            'spaces' => ["  \n"],
            'JSON null' => ['null'],
            'an empty list, as the API answers none' => ['[]'],
            'an empty object' => ['{}'],
            'with spaces around it' => [" { } \n"],
        ];
    }

    /**
     * @dataProvider noneProvider
     */
    public function testNoneIsNullEmptyTextOrJsonOfNullOrAnEmptyArray(?string $text): void
    {
        $this->assertTrue(WorkflowStepMapping::isNone($text));
        $this->assertTrue(WorkflowStepMapping::isValid($text), 'none is a valid mapping');
        $this->assertTrue(WorkflowStepMapping::same($text, null), 'none is the same as null');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function mappingProvider(): array
    {
        return [
            'one name' => ['{"app_version": "build_version"}'],
            'two names' => ['{"app_version": "build_version", "target": "hosts"}'],
            'a key with a leading zero, which PHP keeps as text' => ['{"01": "build_version"}'],
            'an empty output variable name' => ['{"app_version": ""}'],
        ];
    }

    /**
     * @dataProvider mappingProvider
     */
    public function testAJsonObjectOfNamesIsAValidMapping(string $text): void
    {
        $this->assertFalse(WorkflowStepMapping::isNone($text));
        $this->assertTrue(WorkflowStepMapping::isValid($text));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'a list of names' => ['["build_version"]'],
            'a list of numbers' => ['[1, 2]'],
            'a number' => ['5'],
            'zero' => ['0'],
            'text' => ['"text"'],
            'true' => ['true'],
            'false' => ['false'],
            'an object as a value' => ['{"a": {"b": "c"}}'],
            'a number as a value' => ['{"a": 1}'],
            'null as a value' => ['{"a": null}'],
            'a list as a value' => ['{"a": ["b"]}'],
            'a key PHP reads as a number' => ['{"0": "build_version"}'],
            'a negative number as a key' => ['{"-1": "build_version"}'],
            'an empty key' => ['{"": "build_version"}'],
            'a good entry and a bad one' => ['{"app_version": "build_version", "count": 3}'],
            'text that is no JSON' => ['{nope'],
        ];
    }

    /**
     * @dataProvider invalidProvider
     */
    public function testAnythingElseIsNoValidMapping(string $text): void
    {
        $this->assertFalse(WorkflowStepMapping::isNone($text));
        $this->assertFalse(WorkflowStepMapping::isValid($text));
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null, 2: bool}>
     */
    public static function sameProvider(): array
    {
        return [
            'none and none in other words' => ['[]', '{}', true],
            'empty text and null' => ['', null, true],
            'JSON null and an empty list' => ['null', '[]', true],
            'other spaces' => ['{"app_version": "build_version"}', '{"app_version":"build_version"}', true],
            'other order of keys' => ['{"a": "x", "b": "y"}', '{"b":"y","a":"x"}', true],
            'other order of keys in a nested object' => ['{"a": {"c": 1, "d": 2}}', '{"a": {"d": 2, "c": 1}}', true],
            'the same text that is no JSON' => ['{nope', '{nope', true],
            'another value' => ['{"a": "x"}', '{"a": "y"}', false],
            'another key' => ['{"a": "x"}', '{"b": "x"}', false],
            'a mapping and none' => ['{"a": "x"}', null, false],
            'a list in another order' => ['["a", "b"]', '["b", "a"]', false],
            'a long list keeps its order' => ['[0,1,2,3,4,5,6,7,8,9,10,11]', '[0,1,10,11,2,3,4,5,6,7,8,9]', false],
            'other text that is no JSON' => ['{nope', '{nope ', false],
            'a number and the same number as text' => ['{"a": 1}', '{"a": "1"}', false],
            'text that is no JSON and JSON' => ['{nope', '"{nope"', false],
            'JSON too large to write back and other text' => ['{"a": 1e999}', '{"a": 2e999}', false],
            'JSON too large to write back, the same text' => ['{"a": 1e999}', '{"a": 1e999}', true],
        ];
    }

    /**
     * @dataProvider sameProvider
     */
    public function testTwoMappingsAreTheSameWhenTheyMapTheSame(?string $first, ?string $second, bool $same): void
    {
        $this->assertSame($same, WorkflowStepMapping::same($first, $second));
        $this->assertSame($same, WorkflowStepMapping::same($second, $first), 'either way round');
    }

    public function testAnAttributeValueIsReadAsTextOrNull(): void
    {
        $this->assertSame('{"a": "b"}', WorkflowStepMapping::textOf('{"a": "b"}'));
        $this->assertSame('', WorkflowStepMapping::textOf(''));
        $this->assertNull(WorkflowStepMapping::textOf(null));
        $this->assertNull(WorkflowStepMapping::textOf(5));
        $this->assertNull(WorkflowStepMapping::textOf(['a' => 'b']));
    }

    /**
     * The example the refusal shows is a mapping a step takes.
     */
    public function testTheExampleOfTheRefusalIsAValidMapping(): void
    {
        $this->assertSame(1, preg_match('~\{[^}]*\}~', WorkflowStepMapping::INVALID, $example));
        $this->assertFalse(WorkflowStepMapping::isNone($example[0]));
        $this->assertTrue(WorkflowStepMapping::isValid($example[0]), $example[0]);
    }
}
