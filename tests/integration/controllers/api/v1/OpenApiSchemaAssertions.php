<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\models\JobTemplate;
use app\models\User;
use Symfony\Component\Yaml\Yaml;
use yii\base\Module;

/**
 * Compares API responses with the schemas of web/openapi.yaml, for the
 * response contract tests (DbTestCase subclasses): a response must have
 * exactly the documented fields, each of the documented type, and the spec
 * must document which schema an endpoint returns.
 */
trait OpenApiSchemaAssertions
{
    private const SCHEMA_REF_PREFIX = '#/components/schemas/';

    private const RESPONSE_REF_PREFIX = '#/components/responses/';

    /** @var array<mixed>|null the spec, parsed once per test */
    private ?array $spec = null;

    /**
     * The spec file under test.
     */
    protected function specPath(): string
    {
        return dirname(__DIR__, 5) . '/web/openapi.yaml';
    }

    /**
     * $data has exactly the documented properties of $schema except
     * $absent, each of the documented type (null only where the property is
     * nullable). The $absent properties are documented, but not required.
     *
     * @param array<mixed> $data
     * @param list<string> $absent
     */
    private function assertMatchesSchema(string $schema, array $data, array $absent = []): void
    {
        $properties = $this->properties($schema);
        $required = $this->required($schema);
        foreach ($absent as $name) {
            $this->assertArrayHasKey($name, $properties, "Schema {$schema} in web/openapi.yaml must document {$name}.");
            $this->assertNotContains($name, $required, "{$schema}.{$name} is not returned to every caller, so it must not be required.");
            unset($properties[$name]);
        }
        $this->assertMatchesProperties($properties, $data, "Schema {$schema}");
    }

    /**
     * $data has exactly the documented $properties, each of the documented
     * type (null only where the property is nullable). $where names the
     * schema in the messages.
     *
     * @param array<mixed> $properties
     * @param array<mixed> $data
     */
    private function assertMatchesProperties(array $properties, array $data, string $where): void
    {
        $documented = array_map('strval', array_keys($properties));
        $returned = array_map('strval', array_keys($data));
        sort($documented);
        sort($returned);
        $this->assertSame($documented, $returned, "{$where} in web/openapi.yaml must list exactly the fields the API returns.");

        foreach ($properties as $name => $definition) {
            $this->assertIsArray($definition);
            $type = $definition['type'] ?? null;
            if (!is_string($type)) {
                // oneOf or $ref: no single type to compare.
                continue;
            }
            $nullable = ($definition['nullable'] ?? false) === true;
            $this->assertTrue(
                self::hasType($data[$name], $type, $nullable),
                "{$where}: {$name} is documented as {$type}" . ($nullable ? ' or null' : '') . ', the API returned ' . get_debug_type($data[$name]) . '.'
            );
        }
    }

    /**
     * The properties of a schema, with those of the schemas it extends
     * through allOf.
     *
     * @return array<string, array<mixed>>
     */
    private function properties(string $schema): array
    {
        $definition = $this->schema($schema);
        $parts = $definition['allOf'] ?? [$definition];
        $this->assertIsArray($parts);

        $properties = [];
        foreach ($parts as $part) {
            $this->assertIsArray($part);
            $ref = $part['$ref'] ?? null;
            $properties += is_string($ref)
                ? $this->properties(substr($ref, strlen(self::SCHEMA_REF_PREFIX)))
                : $this->ownProperties($part);
        }

        return $properties;
    }

    /**
     * The required properties of a schema, with those of the schemas it
     * extends through allOf.
     *
     * @return list<string>
     */
    private function required(string $schema): array
    {
        $definition = $this->schema($schema);
        $parts = $definition['allOf'] ?? [$definition];
        $this->assertIsArray($parts);

        $required = [];
        foreach ($parts as $part) {
            $this->assertIsArray($part);
            $ref = $part['$ref'] ?? null;
            $own = is_string($ref) ? $this->required(substr($ref, strlen(self::SCHEMA_REF_PREFIX))) : ($part['required'] ?? []);
            $this->assertIsArray($own);
            $required = array_merge($required, array_map('strval', array_values($own)));
        }

        return $required;
    }

    /**
     * @param array<mixed> $definition
     * @return array<string, array<mixed>>
     */
    private function ownProperties(array $definition): array
    {
        $own = $definition['properties'] ?? [];
        $this->assertIsArray($own);

        $properties = [];
        foreach ($own as $name => $property) {
            $this->assertIsArray($property);
            $properties[(string)$name] = $property;
        }

        return $properties;
    }

    /**
     * The spec documents the $status response of $method $path, directly or
     * through a named response, with $schema as its `data`.
     */
    private function assertDocumentedData(string $path, string $method, string $status, string $schema): void
    {
        $operation = strtoupper($method) . " {$path}";
        $response = $this->dig($this->spec(), ['paths', $path, $method, 'responses', $status], "web/openapi.yaml must document {$status} of {$operation}.");
        $this->assertIsArray($response);
        $ref = $response['$ref'] ?? null;
        if (is_string($ref)) {
            $name = substr($ref, strlen(self::RESPONSE_REF_PREFIX));
            $response = $this->dig($this->spec(), ['components', 'responses', $name], "{$operation} refers to the missing response {$ref}.");
        }
        $message = "The {$status} response of {$operation} must document its data as {$schema}.";
        $data = $this->dig($response, ['content', 'application/json', 'schema', 'properties', 'data', '$ref'], $message);
        $this->assertSame(self::SCHEMA_REF_PREFIX . $schema, $data, $message);
    }

    /**
     * The value at $keys under $node, failing with $message where a key is
     * missing.
     *
     * @param list<string> $keys
     */
    private function dig(mixed $node, array $keys, string $message): mixed
    {
        foreach ($keys as $key) {
            $this->assertIsArray($node, $message);
            $this->assertArrayHasKey($key, $node, $message);
            $node = $node[$key];
        }

        return $node;
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

    /**
     * @return array<mixed>
     */
    private function schema(string $name): array
    {
        $components = $this->spec()['components'] ?? null;
        $this->assertIsArray($components);
        $schemas = $components['schemas'] ?? null;
        $this->assertIsArray($schemas);
        $schema = $schemas[$name] ?? null;
        $this->assertIsArray($schema, "web/openapi.yaml has no schema {$name}.");

        return $schema;
    }

    private static function hasType(mixed $value, string $type, bool $nullable): bool
    {
        if ($value === null) {
            return $nullable;
        }

        return match ($type) {
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'string' => is_string($value),
            'array' => is_array($value) && array_is_list($value),
            // What the API encodes as a JSON object: an stdClass, or an array
            // with keys that are not 0..n. An empty array or a list is encoded
            // as a JSON list (regression: approver_config [] passed as object).
            'object' => $value instanceof \stdClass || (is_array($value) && $value !== [] && !array_is_list($value)),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $result
     * @return array<mixed>
     */
    private function data(array $result): array
    {
        $this->assertArrayHasKey('data', $result, (string)json_encode($result));
        $this->assertIsArray($result['data']);

        return $result['data'];
    }

    /**
     * The item with $id from a paginated list response.
     *
     * @param array<string, mixed> $result
     * @return array<mixed>
     */
    private function listItem(array $result, int $id): array
    {
        foreach ($this->data($result) as $item) {
            $this->assertIsArray($item);
            if (($item['id'] ?? null) === $id) {
                return $item;
            }
        }
        $this->fail("Item #{$id} is missing from the list.");
    }

    private function app(): Module
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);

        return $app;
    }

    private function userWithRole(string $suffix, string $role): User
    {
        $user = $this->createUser($suffix);
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $item = $auth->getRole($role);
        $this->assertNotNull($item);
        $auth->assign($item, (string)$user->id);

        return $user;
    }

    /**
     * A job template of a project, inventory and runner group of its own,
     * all created by $owner.
     */
    private function jobTemplateOwnedBy(User $owner): JobTemplate
    {
        $ownerId = (int)$owner->id;

        return $this->createJobTemplate(
            (int)$this->createProject($ownerId)->id,
            (int)$this->createInventory($ownerId)->id,
            (int)$this->createRunnerGroup($ownerId)->id,
            $ownerId
        );
    }
}
