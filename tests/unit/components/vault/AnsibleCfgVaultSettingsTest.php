<?php

declare(strict_types=1);

namespace app\tests\unit\components\vault;

use app\components\vault\AnsibleCfgVaultSettings;
use PHPUnit\Framework\TestCase;

class AnsibleCfgVaultSettingsTest extends TestCase
{
    public function testAPasswordFileOrAnIdentityListIsAPasswordSource(): void
    {
        $this->assertTrue((new AnsibleCfgVaultSettings('.vault_pass', null, false, false, false))->definesPasswordSource());
        $this->assertTrue((new AnsibleCfgVaultSettings(null, 'prod@prod.sh', false, false, false))->definesPasswordSource());
        $this->assertTrue((new AnsibleCfgVaultSettings('a', 'b@c', false, false, false))->definesPasswordSource());
        $this->assertFalse((new AnsibleCfgVaultSettings(null, null, true, true, true))->definesPasswordSource(), 'prompts and flags bring no password');
    }

    public function testToArrayAndBack(): void
    {
        $settings = new AnsibleCfgVaultSettings('.vault_pass', 'dev@a,prod@b', true, false, true);

        $array = $settings->toArray();

        $this->assertSame([
            'vault_password_file' => '.vault_pass',
            'vault_identity_list' => 'dev@a,prod@b',
            'ask_vault_pass' => true,
            'vault_id_match' => false,
            'vault_encrypt_salt' => true,
        ], $array);
        $this->assertEquals($settings, AnsibleCfgVaultSettings::fromArray($array));
        $this->assertEquals($settings, AnsibleCfgVaultSettings::fromArray((array)json_decode((string)json_encode($array), true)));
    }

    public function testFromArrayTreatsMissingAndMistypedValuesAsUnset(): void
    {
        $this->assertEquals(new AnsibleCfgVaultSettings(null, null, false, false, false), AnsibleCfgVaultSettings::fromArray([]));
        $this->assertEquals(new AnsibleCfgVaultSettings(null, null, false, false, false), AnsibleCfgVaultSettings::fromArray([
            'vault_password_file' => '',
            'vault_identity_list' => ['dev@a'],
            'ask_vault_pass' => 'yes',
            'vault_id_match' => 1,
            'vault_encrypt_salt' => 'true',
        ]));
        $this->assertEquals(new AnsibleCfgVaultSettings(null, 'x', false, true, false), AnsibleCfgVaultSettings::fromArray([
            'vault_identity_list' => 'x',
            'vault_id_match' => true,
        ]));
    }
}
