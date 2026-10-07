<?php

declare(strict_types=1);

namespace app\tests\unit\components\vault;

use app\components\vault\AnsibleCfgVaultReader;
use app\components\vault\AnsibleCfgVaultSettings;
use PHPUnit\Framework\TestCase;

class AnsibleCfgVaultReaderTest extends TestCase
{
    use TemporaryTree;

    protected function tearDown(): void
    {
        $this->removeTrees();
    }

    public function testReadsTheFixtureRepository(): void
    {
        $settings = AnsibleCfgVaultReader::read(self::fixture('repo'));

        $this->assertEquals(new AnsibleCfgVaultSettings('.vault_pass', null, true, true, true), $settings);
    }

    public function testNoUsableFileMeansNoSettings(): void
    {
        $none = new AnsibleCfgVaultSettings(null, null, false, false, false);
        $missing = $this->newTree();
        $directory = $this->newTree();
        mkdir($directory . '/ansible.cfg');
        $oversized = $this->newTree();
        $this->put($oversized, 'ansible.cfg', "[defaults]\nask_vault_pass = yes\n#" . str_repeat('-', 1000000) . "\n");

        $this->assertEquals($none, AnsibleCfgVaultReader::read($missing));
        $this->assertEquals($none, AnsibleCfgVaultReader::read($missing . '/nope'));
        $this->assertEquals($none, AnsibleCfgVaultReader::read($directory));
        $this->assertEquals($none, AnsibleCfgVaultReader::read($oversized));
    }

    public function testASymlinkIsReadOnlyWhenItStaysInTheCheckout(): void
    {
        $outside = $this->newTree();
        $target = $this->put($outside, 'ansible.cfg', "[defaults]\nask_vault_pass = yes\n");
        $escaping = $this->newTree();
        symlink($target, $escaping . '/ansible.cfg');
        $inside = $this->newTree();
        $this->put($inside, 'config/ansible.cfg', "[defaults]\nask_vault_pass = yes\n");
        symlink($inside . '/config/ansible.cfg', $inside . '/ansible.cfg');

        $this->assertFalse(AnsibleCfgVaultReader::read($escaping)->askVaultPass);
        $this->assertTrue(AnsibleCfgVaultReader::read($inside)->askVaultPass);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function askVaultPassProvider(): array
    {
        return [
            'True' => ['True', true],
            'yes' => ['yes', true],
            'YES' => ['YES', true],
            'on' => ['on', true],
            '1' => ['1', true],
            't' => ['t', true],
            'y' => ['y', true],
            'False' => ['False', false],
            'no' => ['no', false],
            '0' => ['0', false],
            'empty' => ['', false],
            'quoted True' => ['"True"', false],
            'anything else' => ['2', false],
        ];
    }

    /**
     * @dataProvider askVaultPassProvider
     */
    public function testAskVaultPassIsAnAnsibleBoolean(string $value, bool $expected): void
    {
        $this->assertSame($expected, $this->readCfg("[defaults]\nask_vault_pass = {$value}\n")->askVaultPass);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function untypedValueProvider(): array
    {
        return [
            'True' => ['True', true],
            'False' => ['False', true],
            'no' => ['no', true],
            '0' => ['0', true],
            'empty' => ['', false],
            'empty double quotes' => ['""', false],
            "empty single quotes" => ["''", false],
            'quoted False' => ['"False"', true],
        ];
    }

    /**
     * @dataProvider untypedValueProvider
     */
    public function testVaultIdMatchIsOnForAnyNonEmptyValue(string $value, bool $expected): void
    {
        $this->assertSame($expected, $this->readCfg("[defaults]\nvault_id_match = {$value}\n")->idMatch);
    }

    /**
     * @dataProvider untypedValueProvider
     */
    public function testVaultEncryptSaltCountsWhenNotEmpty(string $value, bool $expected): void
    {
        $this->assertSame($expected, $this->readCfg("[defaults]\nvault_encrypt_salt = {$value}\n")->encryptSalt);
    }

    public function testUnsetOptionsAreOff(): void
    {
        $this->assertEquals(new AnsibleCfgVaultSettings(null, null, false, false, false), $this->readCfg("[defaults]\ninventory = hosts.yml\n"));
    }

    public function testPasswordSourcesAreKeptAsWritten(): void
    {
        $settings = $this->readCfg("[defaults]\nVAULT_PASSWORD_FILE: \"vault pass.txt\" ; quoted\nvault_identity_list = dev@dev.txt,\n    prod@prod.sh\n");

        $this->assertSame('"vault pass.txt"', $settings->passwordFile, 'Ansible keeps quotes in a path');
        $this->assertSame("dev@dev.txt,\nprod@prod.sh", $settings->identityList);
    }

    public function testEmptyPasswordSourcesAreUnset(): void
    {
        $settings = $this->readCfg("[defaults]\nvault_password_file =\nvault_identity_list = ; nothing\n");

        $this->assertNull($settings->passwordFile);
        $this->assertNull($settings->identityList);
    }

    public function testOnlyTheDefaultsSectionCounts(): void
    {
        $text = "[ssh_connection]\nvault_password_file = a\n[Defaults]\nask_vault_pass = yes\n[defaults]\nvault_id_match = 1\n";

        $this->assertEquals(new AnsibleCfgVaultSettings(null, null, false, true, false), $this->readCfg($text));
    }

    public function testDefaultValuesApplyToAnExistingDefaultsSection(): void
    {
        $withDefaults = "[DEFAULT]\nvault_password_file = a\nask_vault_pass = yes\n[defaults]\nvault_password_file = b\n";

        $this->assertEquals(new AnsibleCfgVaultSettings('b', null, true, false, false), $this->readCfg($withDefaults));
        $this->assertEquals(
            new AnsibleCfgVaultSettings(null, null, false, false, false),
            $this->readCfg("[DEFAULT]\nask_vault_pass = yes\n[ssh_connection]\npipelining = True\n"),
            'configparser.get() finds no [defaults] section'
        );
    }

    private function readCfg(string $text): AnsibleCfgVaultSettings
    {
        $root = $this->newTree();
        $this->put($root, 'ansible.cfg', $text);

        return AnsibleCfgVaultReader::read($root);
    }
}
