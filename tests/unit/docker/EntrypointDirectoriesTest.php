<?php

declare(strict_types=1);

namespace app\tests\unit\docker;

use PHPUnit\Framework\TestCase;

/**
 * Regression test for issue #13: runner selftest fails because
 * entrypoint-prod.sh does not create the same runtime directories
 * as the dev entrypoint. Both entrypoints must create all required
 * directories so that ProjectService, ArtifactService, and the
 * selftest playbook work out of the box.
 */
class EntrypointDirectoriesTest extends TestCase
{
    /**
     * The dev entrypoint is the reference — it lists every directory
     * the application needs. The prod entrypoint must create the
     * same set (or a superset).
     */
    public function testProdEntrypointCreatesAllRequiredDirectories(): void
    {
        $devDirs = $this->extractMkdirPaths($this->devEntrypointPath());
        $prodDirs = $this->extractMkdirPaths($this->prodEntrypointPath());

        $this->assertNotEmpty($devDirs, 'Dev entrypoint should create at least one directory');
        $this->assertNotEmpty($prodDirs, 'Prod entrypoint should create at least one directory');

        $missing = array_diff($devDirs, $prodDirs);

        $this->assertEmpty(
            $missing,
            'Prod entrypoint is missing directories that the dev entrypoint creates: '
            . implode(', ', $missing)
            . "\nDev creates: " . implode(', ', $devDirs)
            . "\nProd creates: " . implode(', ', $prodDirs)
        );
    }

    /**
     * Ensure the critical runtime subdirectories are explicitly listed.
     * These are required by ProjectService, ArtifactService, and the
     * selftest playbook — if someone refactors the dev entrypoint and
     * drops them, this test catches it.
     */
    public function testRequiredDirectoriesArePresent(): void
    {
        $required = [
            '/var/www/runtime',
            '/var/www/runtime/projects',
            '/var/www/runtime/artifacts',
            '/var/www/runtime/logs',
            '/var/www/web/assets',
        ];

        $devDirs = $this->extractMkdirPaths($this->devEntrypointPath());
        $prodDirs = $this->extractMkdirPaths($this->prodEntrypointPath());

        foreach ($required as $dir) {
            $this->assertContains(
                $dir,
                $devDirs,
                "Dev entrypoint must create {$dir}"
            );
            $this->assertContains(
                $dir,
                $prodDirs,
                "Prod entrypoint must create {$dir}"
            );
        }
    }

    /**
     * Extract directory paths from the `for dir in ... ; do` loop
     * that both entrypoints use for mkdir.
     *
     * @return string[]
     */
    private function extractMkdirPaths(string $filePath): array
    {
        $content = file_get_contents($filePath);
        $this->assertNotFalse($content, "Could not read {$filePath}");

        // Match: for dir in /path/a /path/b /path/c; do
        if (!preg_match('/for\s+dir\s+in\s+(.+?);\s*do/s', $content, $m)) {
            return [];
        }

        $dirs = preg_split('/\s+/', trim($m[1]));
        return is_array($dirs) ? array_values(array_filter($dirs)) : [];
    }

    private function devEntrypointPath(): string
    {
        return dirname(__DIR__, 3) . '/docker/php/entrypoint.sh';
    }

    private function prodEntrypointPath(): string
    {
        return dirname(__DIR__, 3) . '/docker/php/entrypoint-prod.sh';
    }
}
