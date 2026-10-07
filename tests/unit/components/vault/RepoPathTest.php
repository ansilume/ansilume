<?php

declare(strict_types=1);

namespace app\tests\unit\components\vault;

use app\components\vault\RepoPath;
use PHPUnit\Framework\TestCase;

class RepoPathTest extends TestCase
{
    use TemporaryTree;

    protected function tearDown(): void
    {
        $this->removeTrees();
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function normalizeProvider(): array
    {
        return [
            'plain' => ['inventories/prod/hosts.yml', 'inventories/prod/hosts.yml'],
            'dot segments and duplicate slashes' => ['./inventories//prod/./hosts.yml', 'inventories/prod/hosts.yml'],
            'trailing slash' => ['group_vars/', 'group_vars'],
            'leading slash' => ['/site.yml', 'site.yml'],
            'backslashes' => ['inventories\\prod\\hosts.yml', 'inventories/prod/hosts.yml'],
            'parent inside the root' => ['playbooks/../vars/secrets.yml', 'vars/secrets.yml'],
            'back to the root' => ['playbooks/..', ''],
            'root' => ['', ''],
            'dot' => ['.', ''],
            'parent of the root' => ['..', null],
            'escapes the root' => ['../other/hosts.yml', null],
            'escapes later' => ['a/../../b', null],
            'escapes through backslashes' => ['a\\..\\..\\b', null],
        ];
    }

    /**
     * @dataProvider normalizeProvider
     */
    public function testNormalize(string $path, ?string $expected): void
    {
        $this->assertSame($expected, RepoPath::normalize($path));
    }

    public function testRealRelativeResolvesSymlinksAndStaysInsideTheRoot(): void
    {
        $base = $this->newTree();
        $root = $base . '/repo';
        $this->put($root, 'dir/file.yml', 'x');
        $this->put($base, 'repo2/file.yml', 'x');
        symlink($root . '/dir/file.yml', $root . '/link.yml');
        symlink($base . '/repo2/file.yml', $root . '/escape.yml');

        $this->assertSame('dir/file.yml', RepoPath::realRelative($root, $root . '/dir/file.yml'));
        $this->assertSame('dir', RepoPath::realRelative($root . '/', $root . '/dir/'));
        $this->assertSame('dir/file.yml', RepoPath::realRelative($root, $root . '/link.yml'));
        $this->assertNull(RepoPath::realRelative($root, $root . '/escape.yml'));
        $this->assertNull(RepoPath::realRelative($root, $base . '/repo2/file.yml'), 'a sibling sharing the prefix is outside');
        $this->assertNull(RepoPath::realRelative($root, $root), 'the root itself');
        $this->assertNull(RepoPath::realRelative($root, $root . '/missing.yml'));
        $this->assertNull(RepoPath::realRelative($base . '/missing', $root . '/dir/file.yml'));
    }

    public function testParent(): void
    {
        $this->assertSame('inventories/prod', RepoPath::parent('inventories/prod/hosts.yml'));
        $this->assertSame('', RepoPath::parent('site.yml'));
        $this->assertSame('', RepoPath::parent(''));
    }
}
