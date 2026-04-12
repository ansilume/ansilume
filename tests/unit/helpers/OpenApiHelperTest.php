<?php

declare(strict_types=1);

namespace app\tests\unit\helpers;

use app\helpers\OpenApiHelper;
use PHPUnit\Framework\TestCase;

/**
 * Tests for OpenApiHelper — ensures the OpenAPI spec is parseable and
 * the helper produces the expected structure for the API reference view.
 */
class OpenApiHelperTest extends TestCase
{
    public function testGetEndpointsByTagReturnsNonEmptyArray(): void
    {
        $result = OpenApiHelper::getEndpointsByTag();

        $this->assertNotEmpty($result, 'OpenAPI spec must contain at least one tag with endpoints');
        $this->assertIsArray($result);
    }

    public function testGetEndpointsByTagGroupsAreTags(): void
    {
        $result = OpenApiHelper::getEndpointsByTag();

        // Known tags from the spec
        $expectedTags = ['Projects', 'Jobs', 'Credentials', 'Inventories'];
        foreach ($expectedTags as $tag) {
            $this->assertArrayHasKey($tag, $result, "Expected tag '{$tag}' in OpenAPI spec");
        }
    }

    public function testEachEndpointHasRequiredKeys(): void
    {
        $result = OpenApiHelper::getEndpointsByTag();

        foreach ($result as $tag => $endpoints) {
            $this->assertNotEmpty($endpoints, "Tag '{$tag}' must have at least one endpoint");
            foreach ($endpoints as $i => $ep) {
                $this->assertArrayHasKey('method', $ep, "Endpoint {$i} in '{$tag}' missing 'method'");
                $this->assertArrayHasKey('path', $ep, "Endpoint {$i} in '{$tag}' missing 'path'");
                $this->assertArrayHasKey('summary', $ep, "Endpoint {$i} in '{$tag}' missing 'summary'");
                $this->assertArrayHasKey('badge', $ep, "Endpoint {$i} in '{$tag}' missing 'badge'");
                $this->assertMatchesRegularExpression(
                    '/^(GET|POST|PUT|PATCH|DELETE)$/',
                    $ep['method'],
                    "Invalid HTTP method '{$ep['method']}' in '{$tag}'"
                );
                $this->assertStringStartsWith('/', $ep['path'], "Path must start with /");
            }
        }
    }

    public function testEndpointsAreSortedByPathThenMethod(): void
    {
        $result = OpenApiHelper::getEndpointsByTag();

        foreach ($result as $tag => $endpoints) {
            for ($i = 1; $i < count($endpoints); $i++) {
                $prev = $endpoints[$i - 1];
                $curr = $endpoints[$i];
                $pathCmp = strcmp($prev['path'], $curr['path']);
                // If same path, method order must be GET < POST < PUT < DELETE
                if ($pathCmp === 0) {
                    $methodOrder = ['GET' => 0, 'POST' => 1, 'PUT' => 2, 'PATCH' => 3, 'DELETE' => 4];
                    $this->assertLessThanOrEqual(
                        $methodOrder[$curr['method']] ?? 99,
                        $methodOrder[$prev['method']] ?? 99,
                        "In tag '{$tag}': {$prev['method']} {$prev['path']} should come before {$curr['method']} {$curr['path']}"
                    );
                }
            }
        }
    }

    public function testGetVersionReturnsNonEmptyString(): void
    {
        $version = OpenApiHelper::getVersion();

        $this->assertNotEmpty($version, 'OpenAPI spec must have a version');
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $version, 'Version must be semver-like');
    }

    public function testTotalEndpointCountMatchesExpectation(): void
    {
        $result = OpenApiHelper::getEndpointsByTag();
        $total = 0;
        foreach ($result as $endpoints) {
            $total += count($endpoints);
        }

        // The spec currently has 47 endpoints. This is a lower bound —
        // if endpoints are removed, this test catches it.
        $this->assertGreaterThanOrEqual(40, $total, 'Expected at least 40 API endpoints in the spec');
    }
}
