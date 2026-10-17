<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\ApiBodyValues;
use PHPUnit\Framework\TestCase;

/**
 * A request body field that holds a list or an object where the endpoint
 * takes a single value is refused (422) before the body reaches the model,
 * naming the first such field in the endpoint's field order.
 */
class ApiBodyValuesTest extends TestCase
{
    private const FIELDS = ['name', 'description', 'inventory_type', 'content', 'source_path', 'project_id', 'approval_rule_id'];

    public function testTheRefusalNamesTheField(): void
    {
        $this->assertSame(
            'project_id must be a single value, not a list or an object.',
            ApiBodyValues::listValueRefusal(['name' => 'web', 'project_id' => [7]], self::FIELDS)
        );
        $this->assertSame(
            'project_id must be a single value, not a list or an object.',
            sprintf(ApiBodyValues::NOT_A_VALUE, 'project_id')
        );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function listOrObjectProvider(): array
    {
        return [
            'a list' => [['a', 'b']],
            'an empty list, or an empty object as JSON decodes it' => [[]],
            'an object as JSON decodes it' => [['key' => 'value']],
            'a nested list' => [[[1]]],
            'an object that a parser kept as an object' => [new \stdClass()],
        ];
    }

    /**
     * @dataProvider listOrObjectProvider
     */
    public function testEveryFieldRefusesAListOrAnObject(mixed $value): void
    {
        foreach (self::FIELDS as $field) {
            $this->assertSame(
                sprintf(ApiBodyValues::NOT_A_VALUE, $field),
                ApiBodyValues::listValueRefusal([$field => $value], self::FIELDS),
                $field
            );
        }
    }

    /**
     * The first field in the endpoint's order is named, whatever the order
     * of the body.
     */
    public function testTheFirstFieldInTheGivenOrderIsNamed(): void
    {
        $body = ['approval_rule_id' => [3], 'content' => ['all' => []], 'name' => 'web'];

        $this->assertSame(sprintf(ApiBodyValues::NOT_A_VALUE, 'content'), ApiBodyValues::listValueRefusal($body, self::FIELDS));
        $this->assertSame(
            sprintf(ApiBodyValues::NOT_A_VALUE, 'approval_rule_id'),
            ApiBodyValues::listValueRefusal($body, ['approval_rule_id', 'content'])
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function acceptedBodyProvider(): array
    {
        return [
            'an empty body' => [[]],
            'strings' => [['name' => 'web', 'content' => "all:\n  hosts:\n    web1: {}\n"]],
            'numbers' => [['project_id' => 7, 'approval_rule_id' => 3.0]],
            'numeric strings' => [['project_id' => '7', 'approval_rule_id' => '3']],
            'booleans' => [['description' => true, 'source_path' => false]],
            'null' => [['project_id' => null, 'approval_rule_id' => null, 'description' => null]],
            'a list under a key no field names' => [['name' => 'web', 'tags' => ['a', 'b'], 'created_by' => [1]]],
        ];
    }

    /**
     * Scalars and null are single values; a key that the endpoint does not
     * take is left to it.
     *
     * @dataProvider acceptedBodyProvider
     * @param array<string, mixed> $body
     */
    public function testScalarsNullAndUnknownKeysPass(array $body): void
    {
        $this->assertNull(ApiBodyValues::listValueRefusal($body, self::FIELDS));
    }

    public function testNoFieldsRefuseNothing(): void
    {
        $this->assertNull(ApiBodyValues::listValueRefusal(['name' => ['a']], []));
    }

    /**
     * The body can carry static inventory content with host passwords: it
     * is marked, so stack traces leave it out, and the refusal never
     * repeats a value.
     */
    public function testTheBodyStaysOutOfTracesAndRefusals(): void
    {
        $parameter = new \ReflectionParameter([ApiBodyValues::class, 'listValueRefusal'], 'body');

        $this->assertNotSame([], $parameter->getAttributes(\SensitiveParameter::class));
        $refusal = ApiBodyValues::listValueRefusal(['content' => ['ansible_password=example-value-1234']], self::FIELDS);
        $this->assertSame(sprintf(ApiBodyValues::NOT_A_VALUE, 'content'), $refusal);
        $this->assertStringNotContainsString('example-value', (string)$refusal);
    }
}
