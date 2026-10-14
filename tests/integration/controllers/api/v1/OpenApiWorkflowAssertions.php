<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

/**
 * Reads web/openapi.yaml for the contract tests of the workflow step
 * endpoints and of the workflow run states (OpenApiWorkflowStepsContractTest,
 * OpenApiWorkflowRunStateContractTest): texts on one line, the examples of a
 * response, the enums of a property, and the nested objects of an answer
 * (a route, the current step, an approval), which assertMatchesSchema() does
 * not descend into. Use together with OpenApiSchemaAssertions.
 */
trait OpenApiWorkflowAssertions
{
    /**
     * The value at $keys of the spec.
     *
     * @param list<string> $keys
     */
    private function specAt(array $keys): mixed
    {
        return $this->dig($this->spec(), $keys, 'web/openapi.yaml has no ' . implode('.', $keys) . '.');
    }

    /**
     * The text at $keys of the spec, on one line.
     *
     * @param list<string> $keys
     */
    private function specText(array $keys): string
    {
        $value = $this->specAt($keys);
        $this->assertIsString($value, 'web/openapi.yaml: ' . implode('.', $keys) . ' is no text.');

        return trim((string)preg_replace('/\s+/', ' ', $value));
    }

    /**
     * The $code response of $method $path, a named response resolved.
     *
     * @return array<mixed>
     */
    private function responseOf(string $path, string $method, string $code): array
    {
        $response = $this->specAt(['paths', $path, $method, 'responses', $code]);
        $this->assertIsArray($response);
        $ref = $response['$ref'] ?? null;
        if (is_string($ref)) {
            $response = $this->specAt(['components', 'responses', substr($ref, strlen(self::RESPONSE_REF_PREFIX))]);
            $this->assertIsArray($response);
        }

        return $response;
    }

    /**
     * The example bodies of $response: its `example`, or the values of its
     * `examples`.
     *
     * @param array<mixed> $response
     * @return list<array<mixed>>
     */
    private function exampleBodies(array $response): array
    {
        $content = $this->dig($response, ['content', 'application/json'], 'The response has a JSON body.');
        $this->assertIsArray($content);
        $examples = isset($content['example']) ? [['value' => $content['example']]] : ($content['examples'] ?? null);
        $this->assertIsArray($examples, 'The response has examples.');
        $bodies = [];
        foreach ($examples as $example) {
            $this->assertIsArray($example);
            $this->assertIsArray($example['value'] ?? null, 'An example has a value.');
            $bodies[] = $example['value'];
        }

        return $bodies;
    }

    /**
     * The `error` objects of the examples of the $code response of $method
     * $path; examples in the framework's shape are left out.
     *
     * @return list<array<mixed>>
     */
    private function exampleErrors(string $path, string $method, string $code): array
    {
        $errors = [];
        foreach ($this->exampleBodies($this->responseOf($path, $method, $code)) as $body) {
            if (isset($body['error'])) {
                $this->assertIsArray($body['error'], "{$method} {$path} {$code}: an error is an object.");
                $errors[] = $body['error'];
            }
        }

        return $errors;
    }

    /**
     * The messages of exampleErrors().
     *
     * @return list<string>
     */
    private function exampleMessages(string $path, string $method, string $code): array
    {
        return array_map(
            static fn (array $error): string => (string)($error['message'] ?? ''),
            $this->exampleErrors($path, $method, $code)
        );
    }

    /**
     * The enum of $schema.$property without null, which it holds exactly when
     * the property is nullable (OpenAPI 3.0: a nullable enum lists null).
     *
     * @return list<mixed>
     */
    private function enumOf(string $schema, string $property): array
    {
        $definition = $this->properties($schema)[$property] ?? null;
        $this->assertIsArray($definition, "{$schema}.{$property} is documented.");
        $enum = $definition['enum'] ?? null;
        $this->assertIsArray($enum, "{$schema}.{$property} has an enum.");
        $nullable = ($definition['nullable'] ?? false) === true;
        $this->assertSame($nullable, in_array(null, $enum, true), "{$schema}.{$property} lists null exactly when it is nullable.");

        return array_values(array_filter($enum, static fn (mixed $value): bool => $value !== null));
    }

    /**
     * $value of $schema.$property, a property that refers to a schema
     * (`$ref`, or `allOf` with one `$ref`): null only where the property is
     * nullable, else exactly that schema.
     */
    private function assertMatchesNested(string $schema, string $property, mixed $value): void
    {
        $definition = $this->properties($schema)[$property] ?? null;
        $this->assertIsArray($definition, "{$schema}.{$property} is documented.");
        $allOf = $definition['allOf'] ?? [];
        $first = is_array($allOf) ? ($allOf[0] ?? null) : null;
        $ref = $definition['$ref'] ?? (is_array($first) ? ($first['$ref'] ?? null) : null);
        $this->assertIsString($ref, "{$schema}.{$property} refers to a schema.");
        if ($value === null) {
            $this->assertTrue(($definition['nullable'] ?? false) === true, "{$schema}.{$property} is null, so it must be nullable.");
            return;
        }
        $this->assertIsArray($value, "{$schema}.{$property} is an object.");
        $this->assertMatchesSchema(substr($ref, strlen(self::SCHEMA_REF_PREFIX)), $value);
    }
}
