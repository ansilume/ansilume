<?php

declare(strict_types=1);

namespace app\tests\unit\components\vault;

/**
 * Temporary directory trees for the vault component tests, and access to the
 * committed fixtures in tests/fixtures/vault. Call removeTrees() in tearDown().
 */
trait TemporaryTree
{
    /** @var list<string> */
    private array $trees = [];

    private function newTree(): string
    {
        $root = sys_get_temp_dir() . '/ansilume_vault_test_' . bin2hex(random_bytes(6));
        mkdir($root, 0755);

        return $this->trees[] = $root;
    }

    /**
     * Writes a file below the root, creating its directories.
     */
    private function put(string $root, string $relative, string $content, int $mode = 0644): string
    {
        $path = $root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $content);
        chmod($path, $mode);

        return $path;
    }

    private function removeTrees(): void
    {
        foreach ($this->trees as $root) {
            self::removeTree($root);
        }
        $this->trees = [];
    }

    /**
     * Never follows symlinks: a link is removed, not the tree it points at.
     */
    private static function removeTree(string $path): void
    {
        if (is_link($path) || (file_exists($path) && !is_dir($path))) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
            self::removeTree($path . '/' . $name);
        }
        rmdir($path);
    }

    private static function fixture(string $relative = ''): string
    {
        return rtrim(dirname(__DIR__, 3) . '/fixtures/vault/' . $relative, '/');
    }

    private static function fixtureContent(string $relative): string
    {
        return (string)file_get_contents(self::fixture($relative));
    }
}
