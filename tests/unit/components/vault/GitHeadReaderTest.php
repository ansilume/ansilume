<?php

declare(strict_types=1);

namespace app\tests\unit\components\vault;

use app\components\vault\GitHeadReader;
use PHPUnit\Framework\TestCase;

/**
 * The layouts are the ones git writes; the reader never runs git.
 */
class GitHeadReaderTest extends TestCase
{
    use TemporaryTree;

    private const SHA1 = 'c59644280db0b2ea2792d572f8ba1843ff8c625f';
    private const SHA256 = '0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c4b5a69788796a5b4c3d2e1f0';

    protected function tearDown(): void
    {
        $this->removeTrees();
    }

    public function testReadsALooseBranchRef(): void
    {
        $root = $this->repository("ref: refs/heads/main\n", ['refs/heads/main' => self::SHA1 . "\n"]);

        $this->assertSame(self::SHA1, GitHeadReader::sha($root));
    }

    public function testReadsAPackedBranchRef(): void
    {
        $root = $this->repository("ref: refs/heads/release/2.7\n", ['packed-refs' => implode("\n", [
            '# pack-refs with: peeled fully-peeled sorted ',
            '45d92790e58519bae3bc9b3910d5079126d67500 refs/remotes/origin/release/2.7',
            self::SHA1 . ' refs/heads/release/2.7',
            '^45d92790e58519bae3bc9b3910d5079126d67500',
            '',
        ])]);

        $this->assertSame(self::SHA1, GitHeadReader::sha($root));
    }

    public function testALooseRefWinsOverPackedRefs(): void
    {
        $root = $this->repository("ref: refs/heads/main\n", [
            'refs/heads/main' => self::SHA1 . "\n",
            'packed-refs' => "45d92790e58519bae3bc9b3910d5079126d67500 refs/heads/main\n",
        ]);

        $this->assertSame(self::SHA1, GitHeadReader::sha($root));
    }

    public function testReadsADetachedHead(): void
    {
        $this->assertSame(self::SHA1, GitHeadReader::sha($this->repository(self::SHA1 . "\n")));
        $this->assertSame(self::SHA256, GitHeadReader::sha($this->repository(self::SHA256)), 'SHA-256 repositories');
        $this->assertSame(self::SHA1, GitHeadReader::sha($this->repository(strtoupper(self::SHA1))));
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function unreadableProvider(): array
    {
        return [
            'garbage in HEAD' => ["hello\n", []],
            'short commit id' => ["c59644280db0\n", []],
            'id with a non-hex digit' => ['g' . substr(self::SHA1, 1), []],
            'ref without a commit' => ["ref: refs/heads/main\n", []],
            'ref missing from packed-refs' => ["ref: refs/heads/main\n", ['packed-refs' => self::SHA1 . " refs/heads/other\n"]],
            'garbage in packed-refs' => ["ref: refs/heads/main\n", ['packed-refs' => "nonsense refs/heads/main\n"]],
            'garbage in the loose ref' => ["ref: refs/heads/main\n", ['refs/heads/main' => "broken\n", 'packed-refs' => self::SHA1 . " refs/heads/main\n"]],
            'symbolic ref to a symbolic ref' => ["ref: refs/heads/main\n", ['refs/heads/main' => "ref: refs/heads/other\n", 'refs/heads/other' => self::SHA1]],
            'ref outside refs/' => ["ref: HEAD2\n", ['HEAD2' => self::SHA1]],
            'ref escaping .git' => ["ref: refs/../../secret\n", []],
            'ref with a space' => ["ref: refs/heads/my branch\n", ['refs/heads/my branch' => self::SHA1]],
            'ref with a backslash' => ["ref: refs/heads/a\\b\n", ['refs/heads/a\\b' => self::SHA1]],
            'oversized HEAD' => [self::SHA1 . str_repeat("\n", 5000), []],
        ];
    }

    /**
     * @dataProvider unreadableProvider
     * @param array<string, string> $files
     */
    public function testAnythingElseGivesNoCommit(string $head, array $files): void
    {
        $this->assertNull(GitHeadReader::sha($this->repository($head, $files)));
    }

    public function testNoGitDirectoryGivesNoCommit(): void
    {
        $plain = $this->newTree();
        $gitFile = $this->newTree();
        $this->put($gitFile, '.git', "gitdir: /elsewhere/.git/worktrees/x\n");
        $noHead = $this->newTree();
        mkdir($noHead . '/.git');

        $this->assertNull(GitHeadReader::sha($plain));
        $this->assertNull(GitHeadReader::sha($plain . '/missing'));
        $this->assertNull(GitHeadReader::sha($gitFile), 'worktrees and submodules are not supported');
        $this->assertNull(GitHeadReader::sha($noHead));
    }

    /**
     * @param array<string, string> $files paths below .git
     */
    private function repository(string $head, array $files = []): string
    {
        $root = $this->newTree();
        $this->put($root, '.git/HEAD', $head);
        foreach ($files as $path => $content) {
            $this->put($root, '.git/' . $path, $content);
        }

        return $root;
    }
}
