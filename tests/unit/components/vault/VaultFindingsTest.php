<?php

declare(strict_types=1);

namespace app\tests\unit\components\vault;

use app\components\vault\AnsibleCfgVaultReader;
use app\components\vault\AnsibleCfgVaultSettings;
use app\components\vault\VaultContentScanner;
use app\components\vault\VaultEnvelope;
use app\components\vault\VaultFindings;
use app\components\vault\VaultScanEntry;
use app\components\vault\VaultScanResult;
use PHPUnit\Framework\TestCase;

class VaultFindingsTest extends TestCase
{
    use TemporaryTree;

    private const COMMITTED = 'A plaintext vault password is committed to the repository.';
    private const SCRIPT = "This vault password script runs on runners in 'Ansilume and repository' mode.";
    private const ASK = "Jobs fail in 'Ansilume and repository' mode: ansible.cfg asks for a vault password and runners have no prompt.";
    private const ID_MATCH = "vault_id_match is on (any value, even False): Ansible uses a password only for files with the same vault ID, and in 'Ansilume and repository' mode Ansilume's password counts as 'default'.";
    private const SALT = 'vault_encrypt_salt is set: files encrypted with the same password reuse key and IV.';
    private const TRUNCATED = 'The scan stopped at a limit; vault content beyond it is not listed.';

    protected function tearDown(): void
    {
        $this->removeTrees();
    }

    public function testTheFixtureRepositoryHasEverySettingFinding(): void
    {
        $root = self::fixture('repo');

        $findings = VaultFindings::collect($root, AnsibleCfgVaultReader::read($root), (new VaultContentScanner())->scan($root));

        $this->assertSame([
            ['code' => VaultFindings::PASSWORD_FILE_COMMITTED, 'path' => '.vault_pass', 'message' => self::COMMITTED],
            ['code' => VaultFindings::ASK_VAULT_PASS, 'path' => 'ansible.cfg', 'message' => self::ASK],
            ['code' => VaultFindings::VAULT_ID_MATCH, 'path' => 'ansible.cfg', 'message' => self::ID_MATCH],
            ['code' => VaultFindings::ENCRYPT_SALT, 'path' => 'ansible.cfg', 'message' => self::SALT],
        ], $findings);
    }

    public function testACleanCheckoutHasNoFindings(): void
    {
        $root = $this->newTree();
        $this->put($root, 'vault.yml', self::fixtureContent('file-1.1.yml'));

        $this->assertSame([], VaultFindings::collect($root, self::settings(), (new VaultContentScanner())->scan($root)));
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: string}>
     */
    public static function modeProvider(): array
    {
        return [
            'plain file' => [0644, 'password_file_committed', self::COMMITTED],
            'read-only file' => [0400, 'password_file_committed', self::COMMITTED],
            'executable' => [0755, 'password_script', self::SCRIPT],
            'owner execute bit' => [0744, 'password_script', self::SCRIPT],
            'group execute bit' => [0654, 'password_script', self::SCRIPT],
            'other execute bit' => [0645, 'password_script', self::SCRIPT],
        ];
    }

    /**
     * @dataProvider modeProvider
     */
    public function testAConfiguredPasswordFileIsAFileOrAScript(int $mode, string $code, string $message): void
    {
        $root = $this->newTree();
        $this->put($root, 'secrets/vault-pass', "pw\n", $mode);

        $findings = self::passwordFindings($root, self::settings('secrets/vault-pass'));

        $this->assertSame([['code' => $code, 'path' => 'secrets/vault-pass', 'message' => $message]], $findings);
    }

    public function testEveryIdentityListSourceCounts(): void
    {
        $root = $this->newTree();
        $this->put($root, 'vault-dev.txt', "dev\n");
        $this->put($root, 'scripts/prod.sh', "#!/bin/sh\necho prod\n", 0755);
        $this->put($root, 'plain.txt', "test\n");
        $this->put($root, 'prompt', "not a prompt\n");
        $this->put($root, 'vault-pass.txt', "pw\n");

        $findings = self::passwordFindings($root, self::settings('vault-pass.txt', "dev@vault-dev.txt, \"prod@scripts/prod.sh\" ,,\n plain.txt,ask@prompt,label@"));

        $this->assertSame([
            ['vault-pass.txt', VaultFindings::PASSWORD_FILE_COMMITTED],
            ['vault-dev.txt', VaultFindings::PASSWORD_FILE_COMMITTED],
            ['scripts/prod.sh', VaultFindings::PASSWORD_SCRIPT],
            ['plain.txt', VaultFindings::PASSWORD_FILE_COMMITTED],
            ['ansible.cfg', VaultFindings::ASK_VAULT_PASS],
        ], array_map(static fn (array $finding): array => [$finding['path'], $finding['code']], $findings), "a 'prompt' source is the prompt, not the file of that name");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function promptProvider(): array
    {
        return [
            'prompt' => ['dev@prompt'],
            'prompt_ask_vault_pass' => ['prompt_ask_vault_pass'],
            'quoted among others' => ["dev@dev.txt, 'prod@prompt'"],
        ];
    }

    /**
     * @dataProvider promptProvider
     */
    public function testAPromptInTheIdentityListFailsJobsLikeAskVaultPass(string $identityList): void
    {
        $root = $this->newTree();

        $findings = VaultFindings::collect($root, self::settings(null, $identityList), self::emptyScan());

        $this->assertSame([['code' => VaultFindings::ASK_VAULT_PASS, 'path' => 'ansible.cfg', 'message' => self::ASK]], $findings);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function wellKnownNameProvider(): array
    {
        return [
            '.vault_pass' => ['.vault_pass'],
            '.vault_password' => ['.vault_password'],
            'vault_pass.txt' => ['vault_pass.txt'],
            'vault_password.txt' => ['vault_password.txt'],
            '.vault-password' => ['.vault-password'],
        ];
    }

    /**
     * @dataProvider wellKnownNameProvider
     */
    public function testAWellKnownPasswordFileCountsEvenWhenUnused(string $name): void
    {
        $root = $this->newTree();
        $this->put($root, $name, "pw\n");
        $this->put($root, 'sub/' . $name, "pw\n");

        $this->assertSame(
            [['code' => VaultFindings::PASSWORD_FILE_COMMITTED, 'path' => $name, 'message' => self::COMMITTED]],
            self::passwordFindings($root, self::settings())
        );
    }

    public function testAnUnusedScriptWithAWellKnownNameIsNoFinding(): void
    {
        $root = $this->newTree();
        $this->put($root, '.vault_pass', "#!/bin/sh\npass show ansible\n", 0755);

        $this->assertSame([], self::passwordFindings($root, self::settings()));
    }

    public function testOneFindingPerFile(): void
    {
        $committed = $this->newTree();
        $this->put($committed, '.vault_pass', "pw\n");
        $script = $this->newTree();
        $this->put($script, '.vault_pass', "#!/bin/sh\necho pw\n", 0755);

        $this->assertSame(
            [['code' => VaultFindings::PASSWORD_FILE_COMMITTED, 'path' => '.vault_pass', 'message' => self::COMMITTED]],
            self::passwordFindings($committed, self::settings('./sub/../.vault_pass', 'dev@.vault_pass'))
        );
        $this->assertSame(
            [['code' => VaultFindings::PASSWORD_SCRIPT, 'path' => '.vault_pass', 'message' => self::SCRIPT]],
            self::passwordFindings($script, self::settings('.vault_pass'))
        );
    }

    public function testCwdMeansTheCheckoutRootEvenForARelativeRoot(): void
    {
        $root = $this->newTree();
        $this->put($root, 'inside/pass.txt', "pw\n");
        $cwd = (string)getcwd();
        chdir(dirname($root));
        try {
            $findings = self::passwordFindings(basename($root), self::settings('{{CWD}}/inside/pass.txt'));
        } finally {
            chdir($cwd);
        }

        $this->assertSame([['code' => VaultFindings::PASSWORD_FILE_COMMITTED, 'path' => 'inside/pass.txt', 'message' => self::COMMITTED]], $findings);
    }

    public function testOnlySourcesInsideTheCheckoutCount(): void
    {
        $root = $this->newTree();
        $outside = $this->newTree();
        $this->put($outside, 'pass.txt', "pw\n");
        $this->put($root, 'inside/pass.txt', "pw\n");
        mkdir($root . '/a-directory');
        symlink($outside . '/pass.txt', $root . '/link-out.txt');
        symlink($root . '/inside/pass.txt', $root . '/link-in.txt');

        $findings = self::passwordFindings($root, self::settings($outside . '/pass.txt', implode(',', [
            'a@' . $root . '/inside/pass.txt',
            'b@link-out.txt',
            'c@link-in.txt',
            'd@{{CWD}}/inside/pass.txt',
            'e@~/.vault_pass',
            'f@missing.txt',
            'g@a-directory',
            'h@../' . basename($outside) . '/pass.txt',
        ])));

        $this->assertSame(
            [['code' => VaultFindings::PASSWORD_FILE_COMMITTED, 'path' => 'inside/pass.txt', 'message' => self::COMMITTED]],
            $findings,
            'an absolute path, a symlink and {{CWD}} all name the same file inside the checkout'
        );
    }

    /**
     * @return array<string, array{0: AnsibleCfgVaultSettings, 1: list<string>}>
     */
    public static function settingProvider(): array
    {
        return [
            'ask_vault_pass' => [new AnsibleCfgVaultSettings(null, null, true, false, false), [self::ASK]],
            'vault_id_match' => [new AnsibleCfgVaultSettings(null, null, false, true, false), [self::ID_MATCH]],
            'vault_encrypt_salt' => [new AnsibleCfgVaultSettings(null, null, false, false, true), [self::SALT]],
            'all, in order' => [new AnsibleCfgVaultSettings(null, 'dev@prompt', true, true, true), [self::ASK, self::ID_MATCH, self::SALT]],
        ];
    }

    /**
     * @dataProvider settingProvider
     * @param list<string> $messages
     */
    public function testRiskySettingsPointAtAnsibleCfg(AnsibleCfgVaultSettings $settings, array $messages): void
    {
        $findings = VaultFindings::collect($this->newTree(), $settings, self::emptyScan());

        $this->assertSame($messages, array_column($findings, 'message'));
        $this->assertSame(['ansible.cfg'], array_values(array_unique(array_column($findings, 'path'))));
    }

    public function testMalformedEntriesAndTruncationAreReported(): void
    {
        $envelope = VaultEnvelope::parse(self::fixtureContent('file-1.1.yml'));
        $scan = new VaultScanResult([
            new VaultScanEntry('a.yml', VaultScanEntry::KIND_FILE, null, null, null, null, null, 'the vault body has an odd number of hex digits'),
            new VaultScanEntry('b.yml', VaultScanEntry::KIND_FILE, null, null, $envelope->version, $envelope->vaultId, $envelope->fingerprint(), null),
            new VaultScanEntry('c.yml', VaultScanEntry::KIND_INLINE, 4, 'db', null, null, null, 'ansible does not read it as vault data: it is empty'),
        ], true, 3);

        $this->assertSame([
            ['code' => VaultFindings::MALFORMED, 'path' => 'a.yml', 'message' => 'the vault body has an odd number of hex digits'],
            ['code' => VaultFindings::MALFORMED, 'path' => 'c.yml', 'message' => 'ansible does not read it as vault data: it is empty'],
            ['code' => VaultFindings::TRUNCATED, 'path' => null, 'message' => self::TRUNCATED],
        ], VaultFindings::collect($this->newTree(), self::settings(), $scan));
    }

    /**
     * Regression: one finding per malformed entry made the summary too long
     * for its TEXT column; with hundreds of broken values the scan was
     * rolled back and the old overview stayed as if it were current.
     */
    public function testOnlyTheFirstMalformedEntriesAreNamedOneByOne(): void
    {
        $entries = [];
        for ($i = 1; $i <= VaultFindings::MAX_MALFORMED + 5; $i++) {
            $entries[] = new VaultScanEntry(sprintf('broken-%02d.yml', $i), VaultScanEntry::KIND_FILE, null, null, null, null, null, 'broken');
        }

        $findings = VaultFindings::collect($this->newTree(), self::settings(), new VaultScanResult($entries, false, count($entries)));

        $this->assertCount(VaultFindings::MAX_MALFORMED + 1, $findings);
        $this->assertSame('broken-20.yml', $findings[VaultFindings::MAX_MALFORMED - 1]['path']);
        $this->assertSame(
            ['code' => VaultFindings::MALFORMED, 'path' => null, 'message' => '5 more encrypted files or values Ansible cannot read; the entry list names them.'],
            $findings[VaultFindings::MAX_MALFORMED]
        );
    }

    public function testExactlyTheMaximumNeedsNoSummaryFinding(): void
    {
        $entries = [];
        for ($i = 1; $i <= VaultFindings::MAX_MALFORMED; $i++) {
            $entries[] = new VaultScanEntry("broken-{$i}.yml", VaultScanEntry::KIND_FILE, null, null, null, null, null, 'broken');
        }

        $findings = VaultFindings::collect($this->newTree(), self::settings(), new VaultScanResult($entries, false, count($entries)));

        $this->assertCount(VaultFindings::MAX_MALFORMED, $findings);
        $this->assertNotContains(null, array_column($findings, 'path'));
    }

    public function testFindingsComeInAFixedOrder(): void
    {
        $root = $this->newTree();
        $this->put($root, 'vault_pass.txt', "pw\n");
        $scan = new VaultScanResult([new VaultScanEntry('x.yml', VaultScanEntry::KIND_FILE, null, null, null, null, null, 'broken')], true, 1);

        $findings = VaultFindings::collect($root, new AnsibleCfgVaultSettings(null, null, false, true, false), $scan);

        $this->assertSame(['password_file_committed', 'vault_id_match', 'malformed', 'truncated'], array_column($findings, 'code'));
    }

    private static function settings(?string $passwordFile = null, ?string $identityList = null): AnsibleCfgVaultSettings
    {
        return new AnsibleCfgVaultSettings($passwordFile, $identityList, false, false, false);
    }

    private static function emptyScan(): VaultScanResult
    {
        return new VaultScanResult([], false, 0);
    }

    /**
     * @return list<array{code: string, path: string|null, message: string}>
     */
    private static function passwordFindings(string $root, AnsibleCfgVaultSettings $settings): array
    {
        return VaultFindings::collect($root, $settings, self::emptyScan());
    }
}
