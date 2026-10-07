<?php

declare(strict_types=1);

namespace app\tests\unit\components\vault;

use app\components\vault\VaultEnvelope;
use app\components\vault\VaultFileInspector;
use app\components\vault\VaultScanEntry;
use PHPUnit\Framework\TestCase;

class VaultFileInspectorTest extends TestCase
{
    use TemporaryTree;

    public function testAWholeEncryptedFileIsOneEntry(): void
    {
        $entries = VaultFileInspector::inspect('group_vars/all/vault.yml', self::fixtureContent('file-1.2-prod.yml'));

        $this->assertCount(1, $entries);
        $entry = $entries[0];
        $this->assertSame('group_vars/all/vault.yml', $entry->path);
        $this->assertSame(VaultScanEntry::KIND_FILE, $entry->kind);
        $this->assertNull($entry->line);
        $this->assertNull($entry->key);
        $this->assertNull($entry->error);
        $envelope = VaultEnvelope::parse(self::fixtureContent('file-1.2-prod.yml'));
        $this->assertSame('1.2', $entry->version);
        $this->assertSame('prod', $entry->vaultId);
        $this->assertSame($envelope->fingerprint(), $entry->fingerprint);
    }

    public function testAnEntryKeepsNoEncryptedContent(): void
    {
        $entry = VaultFileInspector::inspect('vault.yml', self::fixtureContent('file-1.1.yml'))[0];

        // Only the header fields and the fingerprint: a scan of many large
        // vaults must not hold their ciphertext in memory.
        $this->assertSame(
            ['path', 'kind', 'line', 'key', 'version', 'vaultId', 'fingerprint', 'error'],
            array_keys(get_object_vars($entry))
        );
        $this->assertSame('1.1', $entry->version);
        $this->assertNull($entry->vaultId);
        $this->assertSame(64, strlen((string)$entry->fingerprint));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unreadableFileProvider(): array
    {
        $file = self::fixtureContent('file-1.1.yml');

        return [
            'trailing whitespace' => [self::fixtureContent('malformed-trailing-space.yml'), 'the vault body has spaces or tabs, e.g. trailing whitespace on a line'],
            'byte order mark' => ["\xEF\xBB\xBF" . $file, 'ansible does not read it as vault data: it starts with a UTF-8 byte order mark'],
            'blank lines first' => ["\r\n\n" . $file, 'ansible does not read it as vault data: there are blank lines or spaces before $ANSIBLE_VAULT'],
            'byte order mark and blank lines' => ["\xEF\xBB\xBF\n " . $file, 'ansible does not read it as vault data: it starts with a UTF-8 byte order mark'],
        ];
    }

    /**
     * @dataProvider unreadableFileProvider
     */
    public function testAFileAnsibleCannotReadIsAnEntryWithTheReason(string $content, string $error): void
    {
        $entries = VaultFileInspector::inspect('vault.yml', $content);

        $this->assertCount(1, $entries);
        $this->assertSame(VaultScanEntry::KIND_FILE, $entries[0]->kind);
        $this->assertNull($entries[0]->version);
        $this->assertNull($entries[0]->fingerprint);
        $this->assertSame($error, $entries[0]->error);
    }

    public function testEveryInlineValueIsAnEntry(): void
    {
        $entries = VaultFileInspector::inspect('host_vars/web1.yml', self::fixtureContent('inline.yml'));

        $this->assertCount(6, $entries);
        $this->assertSame(['host_vars/web1.yml'], array_values(array_unique(array_map(static fn (VaultScanEntry $entry): string => $entry->path, $entries))));
        $this->assertSame([VaultScanEntry::KIND_INLINE], array_values(array_unique(array_map(static fn (VaultScanEntry $entry): string => $entry->kind, $entries))));
        $this->assertSame([3, 10, 17, 25, 34, 42], array_map(static fn (VaultScanEntry $entry): ?int => $entry->line, $entries));
        $this->assertSame('token', $entries[1]->key);
        $this->assertSame('prod', $entries[1]->vaultId);
        $this->assertSame('1.2', $entries[1]->version);
        $this->assertNull($entries[1]->error);
    }

    public function testAnInlineValueAnsibleCannotReadCarriesTheReason(): void
    {
        $yaml = "ok: !vault |\n" . self::indented(self::fixtureContent('file-1.1.yml'))
            . "broken: !vault |\n" . self::indented(self::fixtureContent('malformed-trailing-space.yml'));

        $entries = VaultFileInspector::inspect('vars.yml', $yaml);

        $this->assertCount(2, $entries);
        $this->assertNull($entries[0]->error);
        $this->assertSame('broken', $entries[1]->key);
        $this->assertNull($entries[1]->fingerprint);
        $this->assertSame('the vault body has spaces or tabs, e.g. trailing whitespace on a line', $entries[1]->error);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function noEntryProvider(): array
    {
        $inline = "db: !vault |\n" . self::indented(self::fixtureContent('file-1.1.yml'));

        return [
            'plain YAML' => ['vars.yml', "db_password: plain\n"],
            'empty file' => ['vars.yml', ''],
            'a mention in a comment' => ['vars.yml', "# encrypt with !vault | values\nkey: 1\n"],
            'header text not at the start' => ['notes.txt', 'Vault files start with $ANSIBLE_VAULT;1.1;AES256'],
            'Markdown example' => ['README.md', $inline],
            'upper-case extension' => ['docs/NOTES.MD', $inline],
            'markdown' => ['guide.markdown', $inline],
            'reStructuredText' => ['docs/index.rst', $inline],
            'AsciiDoc' => ['docs/index.adoc', $inline],
            'Jinja template' => ['templates/vars.yml.j2', $inline],
            'jinja' => ['templates/vars.jinja', $inline],
            'jinja2' => ['templates/vars.jinja2', $inline],
        ];
    }

    /**
     * @dataProvider noEntryProvider
     */
    public function testFindsNothingWithoutVaultValues(string $path, string $content): void
    {
        $this->assertSame([], VaultFileInspector::inspect($path, $content));
    }

    public function testADocumentThatIsAWholeEncryptedFileStillCounts(): void
    {
        $entries = VaultFileInspector::inspect('secrets.md', self::fixtureContent('file-1.1.yml'));

        $this->assertCount(1, $entries);
        $this->assertSame(VaultScanEntry::KIND_FILE, $entries[0]->kind);
    }

    private static function indented(string $text): string
    {
        return (string)preg_replace('/^/m', '    ', rtrim($text, "\n")) . "\n";
    }
}
