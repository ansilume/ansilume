<?php

declare(strict_types=1);

namespace app\helpers;

use Symfony\Component\Yaml\Yaml;

/**
 * Parses the bundled openapi.yaml and returns a structured endpoint list
 * grouped by tag, suitable for rendering an API reference in the UI.
 *
 * This ensures the profile/tokens API reference stays in sync with the
 * OpenAPI spec — single source of truth, no hardcoded endpoint tables.
 */
class OpenApiHelper
{
    private const SPEC_PATH = __DIR__ . '/../web/openapi.yaml';

    /**
     * HTTP method display order — determines the visual sort within a tag group.
     */
    private const METHOD_ORDER = ['get' => 0, 'post' => 1, 'put' => 2, 'patch' => 3, 'delete' => 4];

    /**
     * Bootstrap badge class per HTTP method.
     *
     * @var array<string, string>
     */
    private const METHOD_BADGE = [
        'get' => 'text-bg-success',
        'post' => 'text-bg-primary',
        'put' => 'text-bg-warning',
        'patch' => 'text-bg-info',
        'delete' => 'text-bg-danger',
    ];

    /**
     * Parse the OpenAPI spec and return endpoints grouped by tag.
     *
     * @return array<string, list<array{method: string, path: string, summary: string, badge: string}>>
     */
    public static function getEndpointsByTag(): array
    {
        $specFile = self::SPEC_PATH;
        if (!file_exists($specFile)) {
            return [];
        }

        /** @var array<string, mixed> $spec */
        $spec = Yaml::parseFile($specFile);
        /** @var array<string, mixed> $paths */
        $paths = $spec['paths'] ?? [];

        $grouped = self::collectEndpoints($paths);
        self::sortEndpoints($grouped);

        return $grouped;
    }

    /**
     * Return the API version string from the spec.
     */
    public static function getVersion(): string
    {
        $specFile = self::SPEC_PATH;
        if (!file_exists($specFile)) {
            return '';
        }

        /** @var array<string, mixed> $spec */
        $spec = Yaml::parseFile($specFile);

        return (string)($spec['info']['version'] ?? '');
    }

    /**
     * @param array<string, mixed> $paths
     * @return array<string, list<array{method: string, path: string, summary: string, badge: string}>>
     */
    private static function collectEndpoints(array $paths): array
    {
        /** @var array<string, list<array{method: string, path: string, summary: string, badge: string}>> $grouped */
        $grouped = [];

        foreach ($paths as $path => $methods) {
            if (!is_array($methods)) {
                continue;
            }
            foreach ($methods as $method => $operation) {
                if (!isset(self::METHOD_ORDER[$method]) || !is_array($operation)) {
                    continue;
                }
                $tags = is_array($operation['tags'] ?? null) ? $operation['tags'] : ['Other'];
                $tag = (string)($tags[0] ?? 'Other');
                $grouped[$tag][] = [
                    'method' => strtoupper($method),
                    'path' => (string)$path,
                    'summary' => (string)($operation['summary'] ?? ''),
                    'badge' => self::METHOD_BADGE[$method] ?? 'text-bg-secondary',
                ];
            }
        }

        return $grouped;
    }

    /**
     * Sort endpoints within each tag group: by path first, then by HTTP method order.
     *
     * @param array<string, list<array{method: string, path: string, summary: string, badge: string}>> &$grouped
     */
    private static function sortEndpoints(array &$grouped): void
    {
        foreach ($grouped as &$endpoints) {
            usort($endpoints, function (array $a, array $b): int {
                $pathCmp = strcmp($a['path'], $b['path']);
                if ($pathCmp !== 0) {
                    return $pathCmp;
                }
                return (self::METHOD_ORDER[strtolower($a['method'])] ?? 99)
                    - (self::METHOD_ORDER[strtolower($b['method'])] ?? 99);
            });
        }
    }
}
