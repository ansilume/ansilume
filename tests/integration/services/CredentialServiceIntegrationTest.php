<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Credential;
use app\services\CredentialService;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for CredentialService encrypt/decrypt round-trip.
 * Requires APP_SECRET_KEY to be set in .env (available in Docker).
 */
class CredentialServiceIntegrationTest extends DbTestCase
{
    private CredentialService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \Yii::$app->get('credentialService');
    }

    public function testStoreAndRetrieveSecretsRoundTrip(): void
    {
        $user       = $this->createUser();
        $credential = $this->createCredential($user->id, Credential::TYPE_SSH_KEY);

        $secrets = ['private_key' => 'FAKEPRIVATEKEY', 'passphrase' => 'secret123'];
        $this->service->storeSecrets($credential, $secrets);

        $retrieved = $this->service->getSecrets($credential);

        $this->assertSame($secrets, $retrieved);
    }

    public function testGetSecretsReturnsEmptyArrayWhenNoSecretData(): void
    {
        $user       = $this->createUser();
        $credential = $this->createCredential($user->id);
        // No secret_data set

        $this->assertSame([], $this->service->getSecrets($credential));
    }

    public function testEncryptedDataIsNotPlaintext(): void
    {
        $user       = $this->createUser();
        $credential = $this->createCredential($user->id);

        $this->service->storeSecrets($credential, ['token' => 'supersecret']);

        $this->assertNotEmpty($credential->secret_data);
        $this->assertStringNotContainsString('supersecret', $credential->secret_data);
    }

    public function testDifferentEncryptionsProduceDifferentCiphertext(): void
    {
        $user = $this->createUser();
        $c1   = $this->createCredential($user->id);
        $c2   = $this->createCredential($user->id);

        $this->service->storeSecrets($c1, ['key' => 'same_value']);
        $this->service->storeSecrets($c2, ['key' => 'same_value']);

        // IV is random so ciphertexts must differ
        $this->assertNotSame($c1->secret_data, $c2->secret_data);
    }

    public function testStoreSecretsOverwritesPreviousSecrets(): void
    {
        $user       = $this->createUser();
        $credential = $this->createCredential($user->id);

        $this->service->storeSecrets($credential, ['key' => 'first']);
        $this->service->storeSecrets($credential, ['key' => 'second']);

        $this->assertSame(['key' => 'second'], $this->service->getSecrets($credential));
    }

    public function testStoreSecretsPersistsToDatabase(): void
    {
        $user       = $this->createUser();
        $credential = $this->createCredential($user->id, Credential::TYPE_SSH_KEY);

        $secrets = ['private_key' => 'MY_KEY_DATA', 'passphrase' => 'pw123'];
        $result  = $this->service->storeSecrets($credential, $secrets);

        $this->assertTrue($result, 'storeSecrets should return true on successful save');

        // Reload from DB to confirm persistence.
        $reloaded = Credential::findOne($credential->id);
        $this->assertNotNull($reloaded);
        $this->assertNotEmpty($reloaded->secret_data);

        $retrieved = $this->service->getSecrets($reloaded);
        $this->assertSame($secrets, $retrieved);
    }

    public function testRedactReplacesAllValues(): void
    {
        $secrets  = ['password' => 'secret123', 'token' => 'tok_abc'];
        $redacted = $this->service->redact($secrets);

        $this->assertSame('***REDACTED***', $redacted['password']);
        $this->assertSame('***REDACTED***', $redacted['token']);
        $this->assertCount(2, $redacted);
    }

    public function testRedactEmptyArrayReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->service->redact([]));
    }

    public function testStoreAndRetrieveMultipleCredentialTypes(): void
    {
        $user = $this->createUser();

        // SSH Key credential
        $sshCred = $this->createCredential($user->id, Credential::TYPE_SSH_KEY);
        $sshSecrets = ['private_key' => 'FAKE_SSH_KEY'];
        $this->service->storeSecrets($sshCred, $sshSecrets);
        $this->assertSame($sshSecrets, $this->service->getSecrets($sshCred));

        // Vault credential
        $vaultCred = $this->createCredential($user->id, Credential::TYPE_VAULT);
        $vaultSecrets = ['vault_password' => 'vault_pw'];
        $this->service->storeSecrets($vaultCred, $vaultSecrets);
        $this->assertSame($vaultSecrets, $this->service->getSecrets($vaultCred));

        // Token credential
        $tokenCred = $this->createCredential($user->id, Credential::TYPE_TOKEN);
        $tokenSecrets = ['token' => 'tok_xyz'];
        $this->service->storeSecrets($tokenCred, $tokenSecrets);
        $this->assertSame($tokenSecrets, $this->service->getSecrets($tokenCred));

        // Username/password credential
        $pwCred = $this->createCredential($user->id, Credential::TYPE_USERNAME_PASSWORD);
        $pwSecrets = ['password' => 'the_password'];
        $this->service->storeSecrets($pwCred, $pwSecrets);
        $this->assertSame($pwSecrets, $this->service->getSecrets($pwCred));
    }

    public function testStoreSecretsWithSpecialCharacters(): void
    {
        $user       = $this->createUser();
        $credential = $this->createCredential($user->id);

        $secrets = [
            'password' => 'p@$$w0rd!#%^&*()',
            'note' => "line1\nline2\ttab",
        ];

        $this->service->storeSecrets($credential, $secrets);
        $this->assertSame($secrets, $this->service->getSecrets($credential));
    }

    public function testStoreSecretsWithUnicodeContent(): void
    {
        $user       = $this->createUser();
        $credential = $this->createCredential($user->id);

        $secrets = ['password' => 'Passwort mit Umlauten'];
        $this->service->storeSecrets($credential, $secrets);
        $this->assertSame($secrets, $this->service->getSecrets($credential));
    }

    public function testStoreSecretsWithEmptyMap(): void
    {
        $user       = $this->createUser();
        $credential = $this->createCredential($user->id);

        $this->service->storeSecrets($credential, []);
        $this->assertSame([], $this->service->getSecrets($credential));
    }

    public function testIsKeySecureIntegration(): void
    {
        // Exercise isKeySecure through the service component instance.
        $this->assertTrue($this->service->isKeySecure('ed25519', 256));
        $this->assertTrue($this->service->isKeySecure('ed448', 456));
        $this->assertFalse($this->service->isKeySecure('rsa', 2048));
        $this->assertTrue($this->service->isKeySecure('rsa', 4096));
        $this->assertFalse($this->service->isKeySecure('dsa', 1024));
        $this->assertFalse($this->service->isKeySecure('ecdsa', 256));
        $this->assertTrue($this->service->isKeySecure('ecdsa', 384));
        $this->assertNull($this->service->isKeySecure('unknown_algo', 128));
    }
}
