<?php

declare(strict_types=1);

namespace app\helpers;

/**
 * The lines of the repository structure on the project page, drawn from
 * ProjectFilesystemScanner::buildTree() as `tree` draws them: a directory
 * marked 📁, a symlink 🔗 (shown, never followed), and a path the web server
 * could not read (ProjectFilesystemScanner::unreadable()) NOT_READABLE.
 */
class ProjectTreeHelper
{
    /** Follows the name of a path the web server could not read. */
    public const NOT_READABLE = ' (not readable)';

    private const ICONS = ['dir' => '📁 ', 'link' => '🔗 '];

    /**
     * The lines one at a time, so that a large tree is never held as one
     * list or string.
     *
     * @param array<int, mixed> $nodes ProjectFilesystemScanner::buildTree()
     * @param list<string> $unreadable ProjectFilesystemScanner::unreadable()
     * @return \Generator<int, string> the lines, not encoded
     */
    public static function lines(array $nodes, array $unreadable): \Generator
    {
        yield from self::draw($nodes, array_flip($unreadable), '');
    }

    /**
     * @param array<int, mixed> $nodes
     * @param array<string, int> $unreadable the paths as keys
     * @return \Generator<int, string>
     */
    private static function draw(array $nodes, array $unreadable, string $prefix): \Generator
    {
        $nodes = array_values(array_filter($nodes, 'is_array'));
        foreach ($nodes as $i => $node) {
            $isLast = $i === count($nodes) - 1;
            $rel = (string)($node['rel'] ?? '');
            yield $prefix . ($isLast ? '└── ' : '├── ') . (self::ICONS[$node['type'] ?? ''] ?? '')
                . (string)($node['name'] ?? '') . (isset($unreadable[$rel]) ? self::NOT_READABLE : '');
            $children = $node['children'] ?? [];
            if (is_array($children) && $children !== []) {
                yield from self::draw($children, $unreadable, $prefix . ($isLast ? '    ' : '│   '));
            }
        }
    }
}
