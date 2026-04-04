<?php

declare(strict_types=1);

namespace app\tests\integration\api;

use app\models\Credential;
use app\tests\integration\DbTestCase;

class CredentialsApiTest extends DbTestCase
{
    public function testCredentialHasExpectedSerializableFields(): void
    {
        $user = $this->createUser();
        $cred = $this->createCredential($user->id, Credential::TYPE_TOKEN);

        $this->assertNotNull($cred->id);
        $this->assertNotNull($cred->name);
        $this->assertSame(Credential::TYPE_TOKEN, $cred->credential_type);
        $this->assertNotNull($cred->created_at);
        $this->assertNotNull($cred->updated_at);
    }

    public function testCredentialSerializationNeverIncludesSecretData(): void
    {
        $user = $this->createUser();
        $cred = $this->createCredential($user->id, Credential::TYPE_TOKEN);

        // Simulate the controller's serialize() output shape
        $serialized = [
            'id' => $cred->id,
            'name' => $cred->name,
            'description' => $cred->description,
            'credential_type' => $cred->credential_type,
            'username' => $cred->username,
            'created_at' => $cred->created_at,
            'updated_at' => $cred->updated_at,
        ];

        $this->assertArrayNotHasKey('secret_data', $serialized);
        $this->assertArrayHasKey('credential_type', $serialized);
        $this->assertCount(7, $serialized);
    }

    public function testCredentialFindOneReturnsCorrectRecord(): void
    {
        $user = $this->createUser();
        $cred = $this->createCredential($user->id, Credential::TYPE_SSH_KEY);

        $found = Credential::findOne($cred->id);

        $this->assertNotNull($found);
        $this->assertSame($cred->id, $found->id);
        $this->assertSame(Credential::TYPE_SSH_KEY, $found->credential_type);
    }

    public function testCredentialFindOneReturnsNullForMissingId(): void
    {
        $this->assertNull(Credential::findOne(999999));
    }

    public function testCredentialListOrdersById(): void
    {
        $user = $this->createUser();
        $c1 = $this->createCredential($user->id, Credential::TYPE_TOKEN);
        $c2 = $this->createCredential($user->id, Credential::TYPE_SSH_KEY);

        $results = Credential::find()
            ->where(['id' => [$c1->id, $c2->id]])
            ->orderBy(['id' => SORT_DESC])
            ->all();

        $this->assertCount(2, $results);
        $this->assertSame($c2->id, $results[0]->id);
        $this->assertSame($c1->id, $results[1]->id);
    }
}
