<?php

declare(strict_types=1);

namespace app\tests\unit\components\vault;

use app\components\vault\VaultContentScanner;
use app\components\vault\VaultScanEntry;
use app\components\vault\VaultScanResult;
use PHPUnit\Framework\TestCase;

class VaultContentScannerTest extends TestCase
{
    use TemporaryTree;

    protected function tearDown(): void
    {
        $this->removeTrees();
    }

    public function testListsTheVaultContentOfTheFixtureRepository(): void
    {
        $result = (new VaultContentScanner())->scan(self::fixture('repo'));

        $this->assertFalse($result->truncated);
        $this->assertSame(16, $result->filesScanned);
        $this->assertSame([
            'inline group_vars/all.yml:3 app_secret',
            'file inventories/dev/group_vars/all/vault.yml',
            'file inventories/prod/group_vars/all/vault.yml',
            'inline inventories/prod/host_vars/prod-web1.yml:2 host_secret',
            'file playbooks/deploy-vars.yml',
            'file playbooks/group_vars/web.yml',
            'file vars/secrets.yml',
        ], self::describe($result));
        foreach ($result->entries as $entry) {
            $this->assertNull($entry->error, $entry->path);
            $this->assertNotNull($entry->fingerprint, $entry->path);
        }
    }

    public function testNeverFollowsSymlinks(): void
    {
        $root = $this->newTree();
        $outside = $this->newTree();
        $this->put($outside, 'secret.yml', self::vaultFile());
        $this->put($outside, 'dir/secret.yml', self::vaultFile());
        $this->put($root, 'vault.yml', self::vaultFile());
        symlink($outside . '/secret.yml', $root . '/link-to-file.yml');
        symlink($outside . '/dir', $root . '/link-to-dir');
        symlink($root . '/vault.yml', $root . '/link-inside.yml');
        symlink($root . '/missing.yml', $root . '/dangling.yml');

        $result = (new VaultContentScanner())->scan($root);

        $this->assertSame(['file vault.yml'], self::describe($result));
        $this->assertSame(1, $result->filesScanned);
    }

    public function testSkipsGitAndAnsibleDirectoriesAtAnyDepth(): void
    {
        $root = $this->newTree();
        foreach (['.git/config.yml', '.ansible/collections/x.yml', 'roles/web/.git/y.yml', 'roles/web/.ansible/z.yml'] as $path) {
            $this->put($root, $path, self::vaultFile());
        }
        $this->put($root, '.github/vault.yml', self::vaultFile());
        $this->put($root, 'roles/web/vars/main.yml', self::vaultFile());

        $result = (new VaultContentScanner())->scan($root);

        $this->assertSame(['file .github/vault.yml', 'file roles/web/vars/main.yml'], self::describe($result));
        $this->assertSame(2, $result->filesScanned);
    }

    public function testSkipsBinaryAndOversizedFilesButCountsThem(): void
    {
        $root = $this->newTree();
        $this->put($root, 'binary.yml', self::vaultFile() . "\0");
        $this->put($root, 'late-nul.yml', "db: !vault |\n" . self::indented(self::vaultFile()) . '#' . str_repeat('-', 8200) . "\0\n");
        $this->put($root, 'large.yml', self::vaultFile() . '#' . str_repeat('-', 10000) . "\n");
        $this->put($root, 'small.yml', self::vaultFile());

        $result = (new VaultContentScanner(10.0, 100, 100, 10000))->scan($root);

        $this->assertSame(['inline late-nul.yml:1 db', 'file small.yml'], self::describe($result));
        $this->assertSame(4, $result->filesScanned);
        $this->assertFalse($result->truncated);
    }

    public function testStopsAtTheFileLimit(): void
    {
        $root = $this->newTree();
        foreach (['a.yml', 'b.yml', 'c.yml'] as $name) {
            $this->put($root, $name, self::vaultFile());
        }

        $limited = (new VaultContentScanner(10.0, 2))->scan($root);
        $exact = (new VaultContentScanner(10.0, 3))->scan($root);

        $this->assertSame(['file a.yml', 'file b.yml'], self::describe($limited));
        $this->assertTrue($limited->truncated);
        $this->assertSame(2, $limited->filesScanned);
        $this->assertFalse($exact->truncated, 'nothing was left out');
        $this->assertSame(3, $exact->filesScanned);
    }

    public function testStopsAtTheEntryLimit(): void
    {
        $root = $this->newTree();
        $value = self::indented(self::vaultFile());
        $this->put($root, 'a.yml', "one: !vault |\n{$value}two: !vault |\n{$value}three: !vault |\n{$value}");
        $this->put($root, 'b.yml', self::vaultFile());

        $limited = (new VaultContentScanner(10.0, 100, 2))->scan($root);
        $exact = (new VaultContentScanner(10.0, 100, 4))->scan($root);

        $this->assertSame(['inline a.yml:1 one', 'inline a.yml:8 two'], self::describe($limited));
        $this->assertTrue($limited->truncated);
        $this->assertCount(4, $exact->entries);
        $this->assertFalse($exact->truncated);
    }

    public function testStopsAtTheTimeLimit(): void
    {
        $root = $this->newTree();
        $this->put($root, 'vault.yml', self::vaultFile());

        $result = (new VaultContentScanner(0.0))->scan($root);

        $this->assertSame([], $result->entries);
        $this->assertTrue($result->truncated);
        $this->assertSame(0, $result->filesScanned);
    }

    public function testARootThatIsNoDirectoryGivesAnEmptyResult(): void
    {
        $root = $this->newTree();
        $file = $this->put($root, 'vault.yml', self::vaultFile());
        $scanner = new VaultContentScanner();

        foreach ([$root . '/missing', $file] as $path) {
            $result = $scanner->scan($path);
            $this->assertSame([], $result->entries);
            $this->assertFalse($result->truncated);
            $this->assertSame(0, $result->filesScanned);
        }
    }

    public function testEntriesAreSortedByPathThenLine(): void
    {
        $root = $this->newTree();
        $value = self::indented(self::vaultFile());
        $this->put($root, 'z.yml', "first: !vault |\n{$value}second: !vault |\n{$value}");
        $this->put($root, 'a/b.yml', self::vaultFile());
        $this->put($root, 'a-c.yml', self::vaultFile());
        $this->put($root, 'B.yml', self::vaultFile());

        $result = (new VaultContentScanner())->scan($root . '/');

        // Byte order: '-' sorts before '/', upper case before lower case.
        $this->assertSame(['file B.yml', 'file a-c.yml', 'file a/b.yml', 'inline z.yml:1 first', 'inline z.yml:8 second'], self::describe($result));
    }

    public function testListsMalformedContentWithTheReason(): void
    {
        $root = $this->newTree();
        $this->put($root, 'bom.yml', "\xEF\xBB\xBF" . self::vaultFile());
        $this->put($root, 'trailing.yml', self::fixtureContent('malformed-trailing-space.yml'));

        $result = (new VaultContentScanner())->scan($root);

        $this->assertSame(['file bom.yml', 'file trailing.yml'], self::describe($result));
        $this->assertSame('ansible does not read it as vault data: it starts with a UTF-8 byte order mark', $result->entries[0]->error);
        $this->assertSame('the vault body has spaces or tabs, e.g. trailing whitespace on a line', $result->entries[1]->error);
    }

    public function testDoesNotReadFifos(): void
    {
        if (!function_exists('posix_mkfifo')) {
            $this->markTestSkipped('posix_mkfifo() is not available');
        }
        $root = $this->newTree();
        posix_mkfifo($root . '/pipe.yml', 0644);
        $this->put($root, 'vault.yml', self::vaultFile());

        $result = (new VaultContentScanner())->scan($root);

        $this->assertSame(['file vault.yml'], self::describe($result));
        $this->assertSame(1, $result->filesScanned);
    }

    public function testEveryScanStartsAfresh(): void
    {
        $small = $this->newTree();
        $this->put($small, 'vault.yml', self::vaultFile());
        $large = $this->newTree();
        foreach (['a.yml', 'b.yml', 'c.yml'] as $name) {
            $this->put($large, $name, self::vaultFile());
        }
        $scanner = new VaultContentScanner(10.0, 2);

        $this->assertTrue($scanner->scan($large)->truncated);
        $result = $scanner->scan($small);

        $this->assertSame(['file vault.yml'], self::describe($result));
        $this->assertFalse($result->truncated);
        $this->assertSame(1, $result->filesScanned);
    }

    public function testTheDefaultLimits(): void
    {
        $this->assertSame(20000, VaultContentScanner::MAX_FILES);
        $this->assertSame(5000000, VaultContentScanner::MAX_FILE_BYTES);
        $this->assertSame(2000, VaultContentScanner::MAX_ENTRIES);
    }

    /**
     * @return list<string> "kind path[:line key]" per entry
     */
    private static function describe(VaultScanResult $result): array
    {
        return array_map(
            static fn (VaultScanEntry $entry): string => $entry->kind . ' ' . $entry->path
                . ($entry->line === null ? '' : ':' . $entry->line . ' ' . $entry->key),
            $result->entries
        );
    }

    private static function vaultFile(): string
    {
        return self::fixtureContent('file-1.1.yml');
    }

    private static function indented(string $text): string
    {
        return (string)preg_replace('/^/m', '    ', rtrim($text, "\n")) . "\n";
    }
}
