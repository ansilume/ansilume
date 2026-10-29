<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use Symfony\Component\Yaml\Yaml;

/**
 * The whole-number fields of the JSON request bodies that web/openapi.yaml
 * describes, by operation ("POST /api/v1/projects", "PUT
 * /api/v1/projects/{id}"), as paths into the body: "scm_credential_id",
 * "steps[].job_template_id" (a field of each object of the list steps),
 * "credential_ids[]" (each entry of the list credential_ids).
 *
 * A field is a property of a request body, also of an object nested in it
 * or in a list of it, whose schema is an integer (a type integer, also
 * among other types or alternatives of oneOf, anyOf or allOf), or whose
 * name says it holds an ID (id, *_id, *_ids), whatever its type; and the
 * entries of a list of integers. $ref, allOf, oneOf and anyOf are followed;
 * readOnly properties are no field, as a request does not send them.
 */
final class OpenApiRequestNumbers
{
    /** How deep the walk follows nested schemas; the spec's go 3 deep. */
    private const MAX_DEPTH = 12;

    /**
     * @param array<array-key, mixed> $spec the parsed spec
     */
    private function __construct(private readonly array $spec)
    {
    }

    /**
     * The fields of web/openapi.yaml, read once.
     *
     * @return array<string, list<string>> operation => fields, in the spec's order
     */
    public static function ofTheSpec(): array
    {
        static $fields = null;
        if ($fields === null) {
            $spec = Yaml::parseFile(dirname(__DIR__, 3) . '/web/openapi.yaml');
            $fields = self::of(is_array($spec) ? $spec : []);
        }

        return $fields;
    }

    /**
     * The fields of the parsed spec $spec; operations without such a field
     * are left out.
     *
     * @param array<array-key, mixed> $spec
     * @return array<string, list<string>> operation => fields
     */
    public static function of(array $spec): array
    {
        $reader = new self($spec);
        $fields = [];
        foreach (self::arrayOf($spec['paths'] ?? null) as $path => $operations) {
            foreach (self::arrayOf($operations) as $method => $operation) {
                $found = $reader->bodyFields(self::arrayOf($operation));
                if ($found !== []) {
                    $fields[strtoupper((string)$method) . ' ' . $path] = $found;
                }
            }
        }

        return $fields;
    }

    /**
     * @param array<array-key, mixed> $operation
     * @return list<string>
     */
    private function bodyFields(array $operation): array
    {
        $body = $this->resolved($operation['requestBody'] ?? null);
        $schema = self::arrayOf(self::arrayOf(self::arrayOf($body['content'] ?? null)['application/json'] ?? null)['schema'] ?? null);
        $fields = [];
        $this->walk($schema, '', $fields, 0);

        return array_map('strval', array_keys($fields));
    }

    /**
     * Collects the fields of the object schema $schema under $prefix.
     *
     * @param array<array-key, mixed> $schema
     * @param array<string, true> $fields
     */
    private function walk(array $schema, string $prefix, array &$fields, int $depth): void
    {
        $schema = $this->resolved($schema);
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        foreach (['allOf', 'oneOf', 'anyOf'] as $combination) {
            foreach (self::arrayOf($schema[$combination] ?? null) as $alternative) {
                $this->walk(self::arrayOf($alternative), $prefix, $fields, $depth + 1);
            }
        }
        foreach (self::arrayOf($schema['properties'] ?? null) as $name => $property) {
            $property = $this->resolved($property);
            if (($property['readOnly'] ?? false) === true) {
                continue;
            }
            $path = $prefix . (string)$name;
            if ($this->isInteger($property, $depth) || preg_match('/(^|_)ids?$/', (string)$name) === 1) {
                $fields[$path] = true;
            }
            $this->walkValue($property, $path, $fields, $depth + 1);
        }
    }

    /**
     * Collects the fields inside the value of the property $path: the
     * entries of a list, the properties of an object.
     *
     * @param array<array-key, mixed> $property
     * @param array<string, true> $fields
     */
    private function walkValue(array $property, string $path, array &$fields, int $depth): void
    {
        foreach ($this->alternativesOf($property, $depth) as $alternative) {
            if (isset($alternative['items'])) {
                $items = $this->resolved($alternative['items']);
                if ($this->isInteger($items, $depth)) {
                    $fields[$path . '[]'] = true;
                }
                $this->walk($items, $path . '[].', $fields, $depth + 1);
            }
            if (isset($alternative['properties'])) {
                $this->walk($alternative, $path . '.', $fields, $depth + 1);
            }
        }
    }

    /**
     * Whether $schema is an integer, or has an integer among its types or
     * alternatives.
     *
     * @param array<array-key, mixed> $schema
     */
    private function isInteger(array $schema, int $depth): bool
    {
        foreach ($this->alternativesOf($schema, $depth) as $alternative) {
            $type = $alternative['type'] ?? null;
            if ($type === 'integer' || (is_array($type) && in_array('integer', $type, true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * $schema and its alternatives (allOf, oneOf, anyOf), resolved.
     *
     * @param array<array-key, mixed> $schema
     * @return list<array<array-key, mixed>>
     */
    private function alternativesOf(array $schema, int $depth): array
    {
        $schema = $this->resolved($schema);
        $alternatives = [$schema];
        if ($depth > self::MAX_DEPTH) {
            return $alternatives;
        }
        foreach (['allOf', 'oneOf', 'anyOf'] as $combination) {
            foreach (self::arrayOf($schema[$combination] ?? null) as $alternative) {
                array_push($alternatives, ...$this->alternativesOf(self::arrayOf($alternative), $depth + 1));
            }
        }

        return $alternatives;
    }

    /**
     * $node with its $ref followed, to the end of a chain of refs.
     *
     * @return array<array-key, mixed>
     */
    private function resolved(mixed $node): array
    {
        $node = self::arrayOf($node);
        for ($hops = 0; isset($node['$ref']) && is_string($node['$ref']) && $hops <= self::MAX_DEPTH; $hops++) {
            $target = $this->spec;
            foreach (explode('/', substr($node['$ref'], 2)) as $part) {
                $target = self::arrayOf($target)[$part] ?? null;
            }
            $node = self::arrayOf($target);
        }

        return $node;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arrayOf(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
