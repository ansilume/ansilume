<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use PHPUnit\Framework\TestCase;

/**
 * OpenApiRequestNumbers, from which SubmittedNumbersApiTest takes the
 * fields it sends numbers: each shape a request body describes a whole
 * number or an ID with, and what is none.
 */
class OpenApiRequestNumbersTest extends TestCase
{
    public function testItFindsTheWholeNumbersAndIdsOfEachShape(): void
    {
        $this->assertSame(['POST /things' => [
            'count',
            'either',
            'or_null',
            'team_id',
            'user_ids',
            'user_ids[]',
            'tags[]',
            'nested.level',
            'steps[].job_template_id',
            'merged.limit',
            'referenced',
        ]], OpenApiRequestNumbers::of(self::spec()));
    }

    public function testTheRealSpecHasFieldsOfEveryShape(): void
    {
        $fields = array_merge(...array_values(OpenApiRequestNumbers::ofTheSpec()));

        foreach (['scm_credential_id', 'credential_ids[]', 'steps[].job_template_id', 'verbosity'] as $field) {
            $this->assertContains($field, $fields);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function spec(): array
    {
        return [
            'paths' => [
                '/things' => [
                    'post' => ['requestBody' => ['$ref' => '#/components/requestBodies/Thing']],
                    'get' => ['responses' => []],
                ],
                '/texts' => [
                    'put' => ['requestBody' => ['content' => ['application/json' => ['schema' => [
                        'type' => 'object',
                        'properties' => ['name' => ['type' => 'string'], 'ratio' => ['type' => 'number']],
                    ]]]]],
                ],
            ],
            'components' => [
                'requestBodies' => [
                    'Thing' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Thing']]]],
                ],
                'schemas' => [
                    'Thing' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer', 'readOnly' => true],
                            'name' => ['type' => 'string'],
                            'count' => ['type' => 'integer'],
                            'either' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]],
                            'or_null' => ['type' => ['integer', 'null']],
                            'team_id' => ['type' => 'string'],
                            'user_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            'tags' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            'labels' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'nested' => ['type' => 'object', 'properties' => ['level' => ['type' => 'integer'], 'note' => ['type' => 'string']]],
                            'steps' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['job_template_id' => ['type' => 'integer']]]],
                            'merged' => ['allOf' => [['$ref' => '#/components/schemas/Limit']]],
                            'referenced' => ['$ref' => '#/components/schemas/Count'],
                        ],
                    ],
                    'Limit' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']]],
                    'Count' => ['type' => 'integer'],
                ],
            ],
        ];
    }
}
