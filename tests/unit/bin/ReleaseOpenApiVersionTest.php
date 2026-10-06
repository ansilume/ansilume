<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The OpenAPI spec's info.version tracks the application version and is
 * bumped by bin/release (CLAUDE.md). Regression: bin/release never touched
 * the spec, so it stayed at 1.9.0 through every 2.x release.
 */
class ReleaseOpenApiVersionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    public function testSpecVersionMatchesTheApplicationVersion(): void
    {
        $spec = Yaml::parseFile($this->root . '/web/openapi.yaml');
        $this->assertIsArray($spec);

        $this->assertSame(trim((string)file_get_contents($this->root . '/VERSION')), $spec['info']['version']);
    }

    public function testReleaseBumpsOnlyTheInfoVersion(): void
    {
        $release = (string)file_get_contents($this->root . '/bin/release');
        $this->assertSame(1, preg_match('/^bump_openapi_version\(\) \{.*?\n\}/ms', $release, $m));
        $original = (string)file_get_contents($this->root . '/web/openapi.yaml');
        $copy = tempnam(sys_get_temp_dir(), 'openapi_');
        $this->assertNotFalse($copy);
        file_put_contents($copy, $original);

        try {
            $process = proc_open(
                ['bash', '-c', 'set -euo pipefail' . "\n" . $m[0] . "\n" . 'bump_openapi_version "$1" 9.8.7', 'bash', $copy],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            $this->assertIsResource($process);
            $err = (string)stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $err);
            $bumped = (string)file_get_contents($copy);
        } finally {
            unlink($copy);
        }

        $spec = Yaml::parse($bumped);
        $this->assertIsArray($spec);
        $this->assertSame('9.8.7', $spec['info']['version']);
        $changed = array_diff_assoc(explode("\n", $bumped), explode("\n", $original));
        $this->assertSame(['  version: "9.8.7"'], array_values($changed), 'exactly one line changes');
    }

    public function testReleaseCommitsTheBumpedSpec(): void
    {
        $release = (string)file_get_contents($this->root . '/bin/release');

        $this->assertStringContainsString('bump_openapi_version web/openapi.yaml "${NEW}"', $release);
        $this->assertStringContainsString('git add VERSION web/openapi.yaml', $release);
    }
}
