<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Durable guard: every request body example in web/openapi.yaml names the
 * required properties of its schema, so the body that Swagger UI's "Try it
 * out" sends is one the API takes as a whole. The schemas are read as the
 * spec writes them: through $ref (to components) and allOf, into nested
 * objects and the items of arrays, and for oneOf or anyOf through the
 * alternatives the value fits. The examples are an operation's `example`
 * and every entry of its `examples` (a $ref to components/examples too); a
 * request body may be a $ref to components/requestBodies.
 *
 * regression: the PUT /api/v1/schedules/{id} example left out name and
 * job_template_id, which ScheduleInput requires.
 */
class OpenApiRequestExamplesTest extends TestCase
{
    /** The operations of a path item. */
    private const OPERATIONS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /** At least this many request body examples: fewer means the spec was not read. */
    private const MIN_EXAMPLES = 40;

    /** A Thing of testTheCheckFindsAMissingPropertyAtEveryLevel() with every property its schema requires, at every level. */
    private const WHOLE = ['x' => 1, 'y' => 2, 'inner' => ['z' => 3], 'list' => [['z' => 4]], 'either' => ['w' => 5], 'other' => 'text'];

    /** @var array<mixed>|null the spec, parsed once per test */
    private ?array $spec = null;

    /**
     * The spec file under test.
     */
    protected function specPath(): string
    {
        return dirname(__DIR__, 5) . '/web/openapi.yaml';
    }

    public function testEveryRequestExampleNamesTheRequiredPropertiesOfItsSchema(): void
    {
        $examples = $this->requestExamples();
        $missing = [];
        foreach ($examples as $where => ['schema' => $schema, 'value' => $value]) {
            foreach ($this->missingIn($schema, $value, '') as $property) {
                $missing[] = "{$where}: {$property}";
            }
        }

        $this->assertGreaterThanOrEqual(self::MIN_EXAMPLES, count($examples), 'the request body examples of the spec');
        $this->assertSame([], $missing, 'request body examples without a property their schema requires');
    }

    /**
     * regression: the PUT /api/v1/schedules/{id} example left out name and
     * job_template_id, which ScheduleInput requires, although an update
     * takes a partial body: "Try it out" sent a body the schema refuses.
     */
    public function testRegressionTheScheduleUpdateExampleNamesNameAndJobTemplateId(): void
    {
        $example = $this->requestExamples()['PUT /api/v1/schedules/{id} application/json example'] ?? null;
        $this->assertIsArray($example);

        $this->assertSame([], $this->missingIn($example['schema'], $example['value'], ''));
        $this->assertIsArray($example['value']);
        $this->assertSame(['name', 'job_template_id', 'cron_expression'], array_values(array_intersect(array_keys($example['value']), ['name', 'job_template_id', 'cron_expression'])));
    }

    /**
     * The check finds a missing property wherever the schema requires it,
     * in a spec of its own: at the top, through allOf and $ref, in a nested
     * object, in an array's items, in the alternative of a oneOf the value
     * fits, in an example of components/examples and in a request body of
     * components/requestBodies. Values that fit no alternative, and bodies
     * without a schema, are not this check's.
     */
    public function testTheCheckFindsAMissingPropertyAtEveryLevel(): void
    {
        $this->spec = [
            'paths' => [
                '/things' => [
                    'parameters' => [['$ref' => '#/components/parameters/IdPath']],
                    'post' => ['requestBody' => ['$ref' => '#/components/requestBodies/ThingBody']],
                    'put' => ['requestBody' => ['content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/Thing'],
                            'examples' => [
                                'whole' => ['value' => self::WHOLE],
                                'shared' => ['$ref' => '#/components/examples/Partial'],
                                'external' => ['externalValue' => 'https://example.com/thing.json'],
                            ],
                        ],
                        'text/plain' => ['example' => 'no schema'],
                    ]]],
                    'get' => ['responses' => []],
                ],
            ],
            'components' => [
                'requestBodies' => ['ThingBody' => ['content' => ['application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/Thing'],
                    'example' => ['inner' => [], 'list' => [['z' => 1], []], 'either' => ['v' => 1], 'other' => 5],
                ]]]],
                'examples' => ['Partial' => ['value' => ['x' => 1, 'y' => 2, 'inner' => ['z' => 1], 'list' => [], 'either' => 'text']]],
                'schemas' => [
                    'Base' => ['type' => 'object', 'required' => ['x'], 'properties' => ['x' => ['type' => 'integer']]],
                    'Inner' => ['type' => 'object', 'required' => ['z'], 'properties' => ['z' => ['type' => 'integer']]],
                    'Thing' => ['allOf' => [
                        ['$ref' => '#/components/schemas/Base'],
                        [
                            'type' => 'object',
                            'required' => ['y'],
                            'properties' => [
                                'inner' => ['$ref' => '#/components/schemas/Inner'],
                                'list' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Inner']],
                                'either' => ['oneOf' => [['type' => 'string'], ['type' => 'object', 'required' => ['w']]]],
                                'other' => ['anyOf' => [['type' => 'string'], ['type' => 'object', 'required' => ['w']]]],
                            ],
                        ],
                    ]],
                ],
            ],
        ];

        $missing = [];
        foreach ($this->requestExamples() as $where => ['schema' => $schema, 'value' => $value]) {
            $missing[$where] = $this->missingIn($schema, $value, '');
        }

        $this->assertSame([
            'PUT /things application/json whole' => [],
            'PUT /things application/json shared' => [],
            'POST /things application/json example' => ['x', 'y', 'inner.z', 'list[1].z', 'either.w'],
        ], $missing);
    }

    // -- Reading the spec ------------------------------------------------------

    /**
     * Every request body example of the spec, by "<VERB> <path> <media
     * type> <example>", with the schema of its media type.
     *
     * @return array<string, array{schema: array<mixed>, value: mixed}>
     */
    private function requestExamples(): array
    {
        $examples = [];
        foreach ($this->arrayIn($this->spec(), 'paths') as $path => $item) {
            $this->assertIsArray($item);
            foreach (self::OPERATIONS as $verb) {
                $operation = $item[$verb] ?? null;
                if (!is_array($operation) || !isset($operation['requestBody'])) {
                    continue;
                }
                foreach ($this->arrayIn($this->resolve($operation['requestBody']), 'content') as $type => $media) {
                    $this->assertIsArray($media);
                    $schema = $media['schema'] ?? null;
                    foreach (is_array($schema) ? $this->examplesOf($media) : [] as $name => $value) {
                        $examples[strtoupper($verb) . " {$path} {$type} {$name}"] = ['schema' => $schema, 'value' => $value];
                    }
                }
            }
        }

        return $examples;
    }

    /**
     * The examples of a media type, by name: `example`, and the value of
     * every entry of `examples` that has one.
     *
     * @param array<mixed> $media
     * @return array<string, mixed>
     */
    private function examplesOf(array $media): array
    {
        $examples = array_key_exists('example', $media) ? ['example' => $media['example']] : [];
        foreach ($this->arrayIn($media, 'examples') as $name => $example) {
            $example = $this->resolve($example);
            if (array_key_exists('value', $example)) {
                $examples[(string)$name] = $example['value'];
            }
        }

        return $examples;
    }

    /**
     * The properties that $schema requires and $value leaves out, as paths
     * below $at ("name", "steps[1].name").
     *
     * @param array<mixed> $schema
     * @return list<string>
     */
    private function missingIn(array $schema, mixed $value, string $at): array
    {
        $schema = $this->merged($schema);
        $alternatives = $schema['oneOf'] ?? $schema['anyOf'] ?? null;
        if (is_array($alternatives)) {
            return $this->missingInAlternatives($alternatives, $value, $at);
        }
        if (!is_array($value)) {
            return [];
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            $missing = [];
            foreach (array_values($value) as $i => $item) {
                $missing = array_merge($missing, $this->missingIn($schema['items'], $item, "{$at}[{$i}]"));
            }

            return $missing;
        }
        $missing = [];
        foreach ($this->arrayIn($schema, 'required') as $name) {
            if (!array_key_exists((string)$name, $value)) {
                $missing[] = self::below($at, (string)$name);
            }
        }
        foreach ($this->arrayIn($schema, 'properties') as $name => $property) {
            if (array_key_exists($name, $value) && is_array($property)) {
                $missing = array_merge($missing, $this->missingIn($property, $value[$name], self::below($at, (string)$name)));
            }
        }

        return $missing;
    }

    /**
     * What a value of oneOf or anyOf leaves out: nothing when one of the
     * alternatives it fits takes it whole, else what the first of them
     * misses; nothing when it fits none.
     *
     * @param array<mixed> $alternatives
     * @return list<string>
     */
    private function missingInAlternatives(array $alternatives, mixed $value, string $at): array
    {
        $found = null;
        foreach ($alternatives as $alternative) {
            $this->assertIsArray($alternative);
            $alternative = $this->merged($alternative);
            if (!self::fits($alternative, $value)) {
                continue;
            }
            $missing = $this->missingIn($alternative, $value, $at);
            if ($missing === []) {
                return [];
            }
            $found ??= $missing;
        }

        return $found ?? [];
    }

    /**
     * Whether $value has the type of $schema (any type when it names none).
     *
     * @param array<mixed> $schema
     */
    private static function fits(array $schema, mixed $value): bool
    {
        $type = $schema['type'] ?? null;

        return match (true) {
            !is_string($type) => true,
            $value === null => ($schema['nullable'] ?? false) === true,
            is_array($value) => $type === 'object' || ($type === 'array' && array_is_list($value)),
            is_int($value) => in_array($type, ['integer', 'number'], true),
            is_float($value) => $type === 'number',
            is_bool($value) => $type === 'boolean',
            default => $type === 'string',
        };
    }

    /**
     * $schema with its $ref followed and the parts of its allOf merged:
     * their required properties and properties, and the first items.
     *
     * @param array<mixed> $schema
     * @return array<mixed>
     */
    private function merged(array $schema): array
    {
        $schema = $this->resolve($schema);
        foreach ($this->arrayIn($schema, 'allOf') as $part) {
            $part = $this->merged($this->resolve($part));
            $schema['required'] = array_merge($this->arrayIn($schema, 'required'), $this->arrayIn($part, 'required'));
            $schema['properties'] = $this->arrayIn($schema, 'properties') + $this->arrayIn($part, 'properties');
            $schema['items'] ??= $part['items'] ?? null;
        }
        unset($schema['allOf']);

        return $schema;
    }

    /**
     * $node, or what its $ref points to in the spec ("#/components/...").
     *
     * @return array<mixed>
     */
    private function resolve(mixed $node): array
    {
        $this->assertIsArray($node);
        $ref = $node['$ref'] ?? null;
        if (!is_string($ref)) {
            return $node;
        }
        $this->assertStringStartsWith('#/', $ref, 'a reference inside the spec');
        $target = $this->spec();
        foreach (explode('/', substr($ref, 2)) as $key) {
            $this->assertIsArray($target, $ref);
            $this->assertArrayHasKey($key, $target, $ref);
            $target = $target[$key];
        }

        return $this->resolve($target);
    }

    /**
     * The array at $key of $node; an empty one when it has none.
     *
     * @param array<mixed> $node
     * @return array<mixed>
     */
    private function arrayIn(array $node, string $key): array
    {
        $value = $node[$key] ?? [];
        $this->assertIsArray($value, $key);

        return $value;
    }

    private static function below(string $at, string $name): string
    {
        return $at === '' ? $name : "{$at}.{$name}";
    }

    /**
     * @return array<mixed>
     */
    private function spec(): array
    {
        if ($this->spec === null) {
            $spec = Yaml::parseFile($this->specPath());
            $this->assertIsArray($spec);
            $this->spec = $spec;
        }

        return $this->spec;
    }
}
