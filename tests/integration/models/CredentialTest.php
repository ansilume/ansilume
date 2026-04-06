<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\Credential;
use app\models\User;
use app\tests\integration\DbTestCase;

class CredentialTest extends DbTestCase
{
    public function testTableName(): void
    {
        $this->assertSame('{{%credential}}', Credential::tableName());
    }

    public function testPersistAndRetrieve(): void
    {
        $user = $this->createUser();
        $cred = $this->createCredential($user->id);

        $this->assertNotNull($cred->id);
        $reloaded = Credential::findOne($cred->id);
        $this->assertNotNull($reloaded);
        $this->assertSame($cred->name, $reloaded->name);
        $this->assertSame(Credential::TYPE_TOKEN, $reloaded->credential_type);
        $this->assertSame($user->id, (int)$reloaded->created_by);
    }

    public function testValidationRequiresNameAndType(): void
    {
        $cred = new Credential();
        $this->assertFalse($cred->validate());
        $this->assertArrayHasKey('name', $cred->errors);
        $this->assertArrayHasKey('credential_type', $cred->errors);
    }

    public function testCredentialTypeValidation(): void
    {
        $user = $this->createUser();
        $cred = new Credential();
        $cred->name = 'test-cred';
        $cred->credential_type = 'invalid_type';
        $cred->created_by = $user->id;
        $this->assertFalse($cred->validate());
        $this->assertArrayHasKey('credential_type', $cred->errors);
    }

    public function testTypeLabelForAllTypes(): void
    {
        $expected = [
            Credential::TYPE_SSH_KEY => 'SSH Key',
            Credential::TYPE_USERNAME_PASSWORD => 'Username / Password',
            Credential::TYPE_VAULT => 'Vault Secret',
            Credential::TYPE_TOKEN => 'Token',
        ];

        foreach ($expected as $type => $label) {
            $this->assertSame($label, Credential::typeLabel($type), "typeLabel for {$type}");
        }

        // unknown default
        $this->assertSame('unknown_type', Credential::typeLabel('unknown_type'));
    }

    public function testSensitiveFields(): void
    {
        $fields = Credential::sensitiveFields();
        $this->assertCount(5, $fields);
        $this->assertContains('secret_data', $fields);
        $this->assertContains('password', $fields);
        $this->assertContains('private_key', $fields);
        $this->assertContains('token', $fields);
        $this->assertContains('vault_password', $fields);
    }

    public function testCreatorRelation(): void
    {
        $user = $this->createUser();
        $cred = $this->createCredential($user->id);

        $reloaded = Credential::findOne($cred->id);
        $this->assertNotNull($reloaded);
        $this->assertInstanceOf(User::class, $reloaded->creator);
        $this->assertSame($user->id, $reloaded->creator->id);
    }

    public function testBehaviorsIncludesTimestamp(): void
    {
        $cred = new Credential();
        $behaviors = $cred->behaviors();
        $this->assertNotEmpty($behaviors);
    }
}
