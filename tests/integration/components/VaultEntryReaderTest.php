<?php

declare(strict_types=1);

namespace app\tests\integration\components;

use app\components\vault\InlineVaultExtractor;
use app\components\vault\VaultContentScanner;
use app\components\vault\VaultEnvelope;
use app\components\VaultEntryReader;
use app\models\ProjectVaultEntry;
use app\tests\integration\DbTestCase;
use app\tests\unit\components\vault\TemporaryTree;

/**
 * VaultEntryReader reads scanned entries from the checkout as it is now, one
 * file at a time, and gives an envelope only while the checkout still
 * matches the scan.
 */
class VaultEntryReaderTest extends DbTestCase
{
    use TemporaryTree;

    private const DEV_PASSWORD = 'ansilume-test-dummy-dev';
    private const PROD_PASSWORD = 'ansilume-test-dummy-prod';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = $this->newTree();
    }

    protected function tearDown(): void
    {
        $this->removeTrees();
        parent::tearDown();
    }

    private static function fileEntry(string $path, string $vault): ProjectVaultEntry
    {
        return new ProjectVaultEntry([
            'path' => $path,
            'kind' => ProjectVaultEntry::KIND_FILE,
            'format_version' => '1.1',
            'fingerprint' => VaultEnvelope::parse($vault)->fingerprint(),
        ]);
    }

    /**
     * The entry of the inline value whose tag is on $line of the fixture
     * inline.yml, as the scan stores it.
     */
    private static function inlineEntry(string $path, int $line): ProjectVaultEntry
    {
        $values = array_column(InlineVaultExtractor::extract(self::fixtureContent('inline.yml')), null, 'line');

        return new ProjectVaultEntry([
            'path' => $path,
            'kind' => ProjectVaultEntry::KIND_INLINE,
            'line' => $line,
            'var_key' => $values[$line]['key'],
            'fingerprint' => VaultEnvelope::parse($values[$line]['envelope'])->fingerprint(),
        ]);
    }

    /**
     * The envelope of one entry, read on its own.
     */
    private static function envelopeOf(string $root, ProjectVaultEntry $entry): ?VaultEnvelope
    {
        return VaultEntryReader::envelopes($root, $entry->path, [$entry])[0];
    }

    public function testAFileEntryGivesTheEnvelopeOfTheFile(): void
    {
        $vault = self::fixtureContent('file-1.1.yml');
        $this->put($this->root, 'group_vars/all/vault.yml', $vault);
        $entry = self::fileEntry('group_vars/all/vault.yml', $vault);

        $envelope = self::envelopeOf($this->root, $entry);

        $this->assertNotNull($envelope);
        $this->assertSame($entry->fingerprint, $envelope->fingerprint());
        $this->assertSame('1.1', $envelope->version);
        $this->assertTrue($envelope->opens(self::DEV_PASSWORD));
    }

    public function testARootWithATrailingSlashFindsTheSameFile(): void
    {
        $vault = self::fixtureContent('file-1.2-prod.yml');
        $this->put($this->root, 'vars/prod.yml', $vault);

        $envelope = self::envelopeOf($this->root . '/', self::fileEntry('vars/prod.yml', $vault));

        $this->assertNotNull($envelope);
        $this->assertSame('prod', $envelope->vaultId);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function inlineFixtureProvider(): array
    {
        return [
            'LF line endings' => ['inline.yml'],
            'CRLF line endings' => ['inline-crlf.yml'],
        ];
    }

    /**
     * @dataProvider inlineFixtureProvider
     */
    public function testAnInlineEntryGivesTheValueOnItsLine(string $fixture): void
    {
        $this->put($this->root, 'group_vars/all.yml', self::fixtureContent($fixture));

        $token = self::envelopeOf($this->root, self::inlineEntry('group_vars/all.yml', 10));
        $database = self::envelopeOf($this->root, self::inlineEntry('group_vars/all.yml', 3));

        $this->assertNotNull($token);
        $this->assertSame('prod', $token->vaultId);
        $this->assertTrue($token->opens(self::PROD_PASSWORD));
        $this->assertNotNull($database);
        $this->assertNull($database->vaultId);
        $this->assertTrue($database->opens(self::DEV_PASSWORD));
    }

    public function testTheEntriesOfAFileComeInTheirOrderFromOneRead(): void
    {
        $this->put($this->root, 'group_vars/all.yml', self::fixtureContent('inline.yml'));
        $moved = self::inlineEntry('group_vars/all.yml', 3);
        $moved->line = 4;

        $envelopes = VaultEntryReader::envelopes($this->root, 'group_vars/all.yml', [
            self::inlineEntry('group_vars/all.yml', 42),
            $moved,
            self::inlineEntry('group_vars/all.yml', 10),
            self::fileEntry('group_vars/all.yml', self::fixtureContent('file-1.1.yml')),
        ]);

        $this->assertCount(4, $envelopes);
        $this->assertSame(self::inlineEntry('group_vars/all.yml', 42)->fingerprint, $envelopes[0]?->fingerprint());
        $this->assertNull($envelopes[1], 'no value on line 4');
        $this->assertSame('prod', $envelopes[2]?->vaultId);
        $this->assertNull($envelopes[3], 'the file as a whole is no vault');
        $this->assertSame([], VaultEntryReader::envelopes($this->root, 'group_vars/all.yml', []));
    }

    public function testEveryEntryOfAMissingFileGivesNoEnvelope(): void
    {
        $this->assertSame([null, null], VaultEntryReader::envelopes($this->root, 'gone.yml', [
            self::inlineEntry('gone.yml', 3),
            self::fileEntry('gone.yml', self::fixtureContent('file-1.1.yml')),
        ]));
    }

    public function testAFileWithOtherEncryptedContentGivesNoEnvelope(): void
    {
        $this->put($this->root, 'vault.yml', self::fixtureContent('file-1.2-prod.yml'));

        $this->assertNull(self::envelopeOf($this->root, self::fileEntry('vault.yml', self::fixtureContent('file-1.1.yml'))));
    }

    public function testAFileThatNoLongerParsesGivesNoEnvelope(): void
    {
        $vault = self::fixtureContent('file-1.1.yml');
        $this->put($this->root, 'vault.yml', "db_password: now in plain text\n");
        $this->put($this->root, 'broken.yml', self::fixtureContent('malformed-trailing-space.yml'));

        $this->assertNull(self::envelopeOf($this->root, self::fileEntry('vault.yml', $vault)));
        $this->assertNull(self::envelopeOf($this->root, self::fileEntry('broken.yml', $vault)));
    }

    /**
     * The scan skips files over the limit; one that grew since the scan is
     * not read into memory in full.
     */
    public function testAFileThatGrewOverTheScanLimitGivesNoEnvelope(): void
    {
        $vault = self::fixtureContent('file-1.1.yml');
        $this->put($this->root, 'big.yml', $vault);
        $entry = self::fileEntry('big.yml', $vault);
        $this->assertNotNull(self::envelopeOf($this->root, $entry), 'readable before it grew');

        $handle = fopen($this->root . '/big.yml', 'ab');
        $this->assertNotFalse($handle);
        ftruncate($handle, VaultContentScanner::MAX_FILE_BYTES + 1);
        fclose($handle);

        $this->assertNull(self::envelopeOf($this->root, $entry));
    }

    public function testAMissingFileGivesNoEnvelope(): void
    {
        $this->assertNull(self::envelopeOf($this->root, self::fileEntry('gone.yml', self::fixtureContent('file-1.1.yml'))));
    }

    public function testADirectoryAtTheEntryPathGivesNoEnvelope(): void
    {
        mkdir($this->root . '/vault.yml');

        $this->assertNull(self::envelopeOf($this->root, self::fileEntry('vault.yml', self::fixtureContent('file-1.1.yml'))));
    }

    /**
     * Lines added above an inline value move it: the scanned line no longer
     * holds it, and a check must not compare another value.
     */
    public function testAnInlineValueThatMovedGivesNoEnvelope(): void
    {
        $this->put($this->root, 'group_vars/all.yml', "# a new first line\n" . self::fixtureContent('inline.yml'));

        $this->assertNull(self::envelopeOf($this->root, self::inlineEntry('group_vars/all.yml', 3)));
    }

    public function testAnInlineValueThatNoLongerParsesGivesNoEnvelope(): void
    {
        $yaml = str_replace('$ANSIBLE_VAULT;1.1;AES256', '$ANSIBLE_VAULT;1.1;AES128', self::fixtureContent('inline.yml'));
        $this->put($this->root, 'group_vars/all.yml', $yaml);

        $this->assertNull(self::envelopeOf($this->root, self::inlineEntry('group_vars/all.yml', 3)));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function symlinkProvider(): array
    {
        return [
            'the file is a symlink inside the checkout' => ['file'],
            'a directory on the path is a symlink inside the checkout' => ['directory'],
            'a directory on the path is a symlink out of the checkout' => ['outside'],
        ];
    }

    /**
     * The scan never follows symlinks, so an entry behind one cannot be what
     * the scan saw: Ansible would read another file than the scanned one.
     *
     * @dataProvider symlinkProvider
     */
    public function testASymlinkAnywhereOnThePathGivesNoEnvelope(string $link): void
    {
        $vault = self::fixtureContent('file-1.1.yml');
        if ($link === 'file') {
            $this->put($this->root, 'group_vars/all/real.yml', $vault);
            symlink('real.yml', $this->root . '/group_vars/all/vault.yml');
        } elseif ($link === 'directory') {
            $this->put($this->root, 'group_vars/real/vault.yml', $vault);
            symlink('real', $this->root . '/group_vars/all');
        } else {
            $outside = $this->newTree();
            $this->put($outside, 'all/vault.yml', $vault);
            mkdir($this->root . '/group_vars');
            symlink($outside . '/all', $this->root . '/group_vars/all');
        }
        $this->assertFileExists($this->root . '/group_vars/all/vault.yml', 'the link resolves');

        $this->assertNull(self::envelopeOf($this->root, self::fileEntry('group_vars/all/vault.yml', $vault)));
    }

    public function testAPathThatLeavesTheCheckoutGivesNoEnvelope(): void
    {
        $vault = self::fixtureContent('file-1.1.yml');
        $outside = $this->newTree();
        $this->put($outside, 'vault.yml', $vault);
        $escaping = '../' . basename($outside) . '/vault.yml';
        $this->assertFileExists($this->root . '/' . $escaping);

        $this->assertNull(self::envelopeOf($this->root, self::fileEntry($escaping, $vault)));
    }
}
