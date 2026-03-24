<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\Credential;
use app\services\CredentialService;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

/**
 * Tests for credential encryption key rotation scenarios.
 *
 * Verifies:
 * - Data encrypted with one key cannot be read with a different key
 * - Round-trip re-encryption with a new key works
 * - Different IVs produce different ciphertexts (no deterministic encryption)
 * - isKeySecure() evaluates algorithm/bit combos correctly
 */
class CredentialKeyRotationTest extends TestCase
{
    private string $originalKey;

    protected function setUp(): void
    {
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? '';
        // Ensure a key is set for tests
        if (strlen($this->originalKey) < 16) {
            $_ENV['APP_SECRET_KEY'] = 'test-secret-key-for-rotation-tests';
        }
    }

    protected function tearDown(): void
    {
        // Restore original key
        $_ENV['APP_SECRET_KEY'] = $this->originalKey;
    }

    private function makeCredential(): Credential
    {
        $cred = $this->getMockBuilder(Credential::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $cred->method('attributes')->willReturn(
            ['id', 'name', 'credential_type', 'secret_data', 'created_by', 'created_at', 'updated_at']
        );
        $cred->method('save')->willReturn(true);
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($cred, [
            'id'              => 1,
            'name'            => 'test-cred',
            'credential_type' => 'token',
            'secret_data'     => null,
            'created_by'      => 1,
            'created_at'      => time(),
            'updated_at'      => time(),
        ]);
        return $cred;
    }

    // -------------------------------------------------------------------------
    // Encryption/Decryption round-trip
    // -------------------------------------------------------------------------

    public function testStoreAndRetrieveSecrets(): void
    {
        $service = new CredentialService();
        $cred    = $this->makeCredential();
        $secrets = ['token' => 'my-secret-api-key', 'extra' => 'data'];

        $service->storeSecrets($cred, $secrets);
        $this->assertNotEmpty($cred->secret_data);

        $retrieved = $service->getSecrets($cred);
        $this->assertSame($secrets, $retrieved);
    }

    public function testDifferentIVsProduceDifferentCiphertexts(): void
    {
        $service = new CredentialService();
        $cred1   = $this->makeCredential();
        $cred2   = $this->makeCredential();
        $secrets = ['token' => 'same-value'];

        $service->storeSecrets($cred1, $secrets);
        $service->storeSecrets($cred2, $secrets);

        $this->assertNotSame($cred1->secret_data, $cred2->secret_data,
            'Same plaintext must produce different ciphertexts due to random IVs.');
    }

    // -------------------------------------------------------------------------
    // Key rotation
    // -------------------------------------------------------------------------

    public function testDecryptionFailsWithWrongKey(): void
    {
        $service = new CredentialService();
        $cred    = $this->makeCredential();

        $_ENV['APP_SECRET_KEY'] = 'original-key-for-encryption';
        $service->storeSecrets($cred, ['token' => 'secret']);

        $_ENV['APP_SECRET_KEY'] = 'different-key-after-rotation';

        $this->expectException(\yii\base\Exception::class);
        $service->getSecrets($cred);
    }

    public function testReEncryptionWithNewKey(): void
    {
        $service = new CredentialService();
        $cred    = $this->makeCredential();
        $secrets = ['token' => 'my-api-key'];

        // Encrypt with old key
        $_ENV['APP_SECRET_KEY'] = 'old-encryption-key-here';
        $service->storeSecrets($cred, $secrets);
        $oldCiphertext = $cred->secret_data;

        // Decrypt with old key
        $decrypted = $service->getSecrets($cred);
        $this->assertSame($secrets, $decrypted);

        // Re-encrypt with new key
        $_ENV['APP_SECRET_KEY'] = 'new-encryption-key-here';
        $service->storeSecrets($cred, $decrypted);
        $newCiphertext = $cred->secret_data;

        $this->assertNotSame($oldCiphertext, $newCiphertext);

        // Verify readable with new key
        $afterRotation = $service->getSecrets($cred);
        $this->assertSame($secrets, $afterRotation);
    }

    public function testEmptySecretDataReturnsEmptyArray(): void
    {
        $service = new CredentialService();
        $cred    = $this->makeCredential();

        $this->assertSame([], $service->getSecrets($cred));
    }

    // -------------------------------------------------------------------------
    // Key security assessment
    // -------------------------------------------------------------------------

    /**
     * @dataProvider keySecurityProvider
     */
    public function testIsKeySecure(string $algorithm, int $bits, ?bool $expected): void
    {
        $service = new CredentialService();
        $this->assertSame($expected, $service->isKeySecure($algorithm, $bits));
    }

    public static function keySecurityProvider(): array
    {
        return [
            'ed25519 always secure'      => ['ed25519', 256, true],
            'ed448 always secure'        => ['ed448', 448, true],
            'rsa-4096 secure'            => ['rsa', 4096, true],
            'rsa-2048 insecure'          => ['rsa', 2048, false],
            'ecdsa-384 secure'           => ['ecdsa', 384, true],
            'ecdsa-256 insecure'         => ['ecdsa', 256, false],
            'dsa always insecure'        => ['dsa', 1024, false],
            'unknown returns null'       => ['foobar', 256, null],
        ];
    }

    // -------------------------------------------------------------------------
    // Redaction
    // -------------------------------------------------------------------------

    public function testRedactReplacesAllValues(): void
    {
        $service = new CredentialService();
        $secrets = ['token' => 'secret', 'password' => 'p@ss'];

        $redacted = $service->redact($secrets);

        $this->assertSame('***REDACTED***', $redacted['token']);
        $this->assertSame('***REDACTED***', $redacted['password']);
        $this->assertCount(2, $redacted);
    }
}
