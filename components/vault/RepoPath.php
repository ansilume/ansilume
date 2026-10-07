<?php

declare(strict_types=1);

namespace app\components\vault;

/**
 * Paths inside a project checkout, the way the vault components compare
 * them: relative to the checkout root, '/' separated, without '.' segments
 * or duplicate separators.
 */
final class RepoPath
{
    /**
     * Normalises a path relative to the root. Backslashes count as
     * separators, '.' segments and duplicate separators are dropped, '..'
     * steps back one segment, and a leading '/' is ignored: Ansilume joins
     * playbook and inventory paths onto the checkout path.
     *
     * @return string|null '' for the root itself, null when '..' leaves the root
     */
    public static function normalize(string $path): ?string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }

    /**
     * Where an existing file or directory is inside the root once every
     * symlink is resolved: its path relative to the real root, or null when
     * it is missing, is the root itself or lies outside it.
     */
    public static function realRelative(string $root, string $path): ?string
    {
        $realRoot = realpath($root);
        $real = realpath($path);
        if ($realRoot === false || $real === false) {
            return null;
        }
        $prefix = rtrim($realRoot, '/') . '/';

        return str_starts_with($real, $prefix) ? substr($real, strlen($prefix)) : null;
    }

    /**
     * The directory of a normalised relative path, '' for the root.
     */
    public static function parent(string $path): string
    {
        $slash = strrpos($path, '/');

        return $slash === false ? '' : substr($path, 0, $slash);
    }
}
