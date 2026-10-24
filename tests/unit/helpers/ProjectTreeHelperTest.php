<?php

declare(strict_types=1);

namespace app\tests\unit\helpers;

use app\helpers\ProjectTreeHelper;
use PHPUnit\Framework\TestCase;

class ProjectTreeHelperTest extends TestCase
{
    public function testDrawsTheTreeWithDirectoriesLinksAndWhatCouldNotBeRead(): void
    {
        $tree = [
            self::node('playbooks', 'playbooks', 'dir', [
                self::node('locked', 'playbooks/locked', 'dir'),
                self::node('deploy.yml', 'playbooks/deploy.yml', 'file'),
            ]),
            self::node('private', 'private', 'dir'),
            self::node('etc', 'etc', 'link'),
            self::node('secret.yml', 'secret.yml', 'file'),
        ];

        $this->assertSame([
            '├── 📁 playbooks',
            '│   ├── 📁 locked' . ProjectTreeHelper::NOT_READABLE,
            '│   └── deploy.yml',
            '├── 📁 private' . ProjectTreeHelper::NOT_READABLE,
            '├── 🔗 etc',
            '└── secret.yml' . ProjectTreeHelper::NOT_READABLE,
        ], iterator_to_array(ProjectTreeHelper::lines($tree, ['playbooks/locked', 'private', 'secret.yml']), false));
    }

    public function testTheLastEntryOfANestedDirectoryIndentsWithoutALine(): void
    {
        $tree = [self::node('a', 'a', 'dir', [self::node('b', 'a/b', 'dir', [self::node('c.txt', 'a/b/c.txt', 'file')])])];

        $this->assertSame(['└── 📁 a', '    └── 📁 b', '        └── c.txt'], iterator_to_array(ProjectTreeHelper::lines($tree, []), false));
    }

    public function testAnEmptyTreeHasNoLines(): void
    {
        $this->assertSame([], iterator_to_array(ProjectTreeHelper::lines([], ['.']), false));
        $this->assertSame([], iterator_to_array(ProjectTreeHelper::lines(['not a node'], []), false));
    }

    /**
     * @param list<array{name: string, rel: string, type: string, children: array<int, mixed>}> $children
     * @return array{name: string, rel: string, type: string, children: array<int, mixed>}
     */
    private static function node(string $name, string $rel, string $type, array $children = []): array
    {
        return ['name' => $name, 'rel' => $rel, 'type' => $type, 'children' => $children];
    }
}
