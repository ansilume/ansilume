<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\Credential;
use app\services\CredentialService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CredentialService encrypt/decrypt cycle.
 * Does NOT require a database — uses a stub credential model.
 */
class CredentialServiceTest extends TestCase
{
    private CredentialService $service;
    private string $originalKey;

    protected function setUp(): void
    {
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? '';
        $_ENV['APP_SECRET_KEY'] = 'test-secret-key-that-is-32-bytes!';
        $this->service = new CredentialService();
    }

    protected function tearDown(): void
    {
        $_ENV['APP_SECRET_KEY'] = $this->originalKey;
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $credential = $this->makeCredential();
        $secrets    = ['private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----"];

        $this->service->storeSecrets($credential, $secrets);

        $this->assertNotEmpty($credential->secret_data);
        $this->assertStringNotContainsString('BEGIN', $credential->secret_data, 'Raw key must not appear in secret_data');

        $decrypted = $this->service->getSecrets($credential);
        $this->assertSame($secrets['private_key'], $decrypted['private_key']);
    }

    public function testEmptySecretsReturnsEmptyArray(): void
    {
        $credential = $this->makeCredential();
        $this->assertSame([], $this->service->getSecrets($credential));
    }

    public function testRedactReplacesValues(): void
    {
        $secrets  = ['password' => 'supersecret', 'token' => 'abc123'];
        $redacted = $this->service->redact($secrets);

        $this->assertSame(['password' => '***REDACTED***', 'token' => '***REDACTED***'], $redacted);
    }

    public function testDifferentCiphertextForSameInput(): void
    {
        $credential1 = $this->makeCredential();
        $credential2 = $this->makeCredential();

        $secrets = ['password' => 'same'];
        $this->service->storeSecrets($credential1, $secrets);
        $this->service->storeSecrets($credential2, $secrets);

        // IV is random each time → ciphertexts must differ
        $this->assertNotSame($credential1->secret_data, $credential2->secret_data);
    }

    public function testShortKeyThrowsException(): void
    {
        $_ENV['APP_SECRET_KEY'] = 'short';
        $service    = new CredentialService();
        $credential = $this->makeCredential();

        $this->expectException(\yii\base\Exception::class);
        $service->storeSecrets($credential, ['password' => 'x']);
    }

    public function testDecryptRejectsInvalidBase64(): void
    {
        $credential = $this->makeCredential();
        $credential->secret_data = '###not-base64###';
        $this->expectException(\yii\base\Exception::class);
        $this->service->getSecrets($credential);
    }

    public function testDecryptRejectsTooShortCiphertext(): void
    {
        $credential = $this->makeCredential();
        // Valid base64 but shorter than 17 bytes (16 IV + ≥1 byte cipher).
        $credential->secret_data = base64_encode('short');
        $this->expectException(\yii\base\Exception::class);
        $this->service->getSecrets($credential);
    }

    public function testDecryptRejectsTamperedCiphertext(): void
    {
        $credential = $this->makeCredential();
        $this->service->storeSecrets($credential, ['password' => 'abc']);
        // Flip a byte in the cipher portion to force openssl_decrypt() to fail.
        $raw = base64_decode($credential->secret_data);
        $raw[16] = $raw[16] === 'A' ? 'B' : 'A';
        // Keep length aligned to block size by padding garbage bytes.
        $credential->secret_data = base64_encode(substr($raw, 0, 16) . str_repeat('x', 16));
        $this->expectException(\yii\base\Exception::class);
        $this->service->getSecrets($credential);
    }

    public function testIsKeySecureEd25519(): void
    {
        $this->assertTrue($this->service->isKeySecure('ed25519', 256));
    }

    public function testIsKeySecureEd448(): void
    {
        $this->assertTrue($this->service->isKeySecure('ed448', 456));
    }

    public function testIsKeySecureEcdsa(): void
    {
        $this->assertFalse($this->service->isKeySecure('ecdsa', 256));
        $this->assertTrue($this->service->isKeySecure('ecdsa', 384));
        $this->assertTrue($this->service->isKeySecure('ecdsa', 521));
    }

    public function testIsKeySecureRsa(): void
    {
        $this->assertFalse($this->service->isKeySecure('rsa', 2048));
        $this->assertTrue($this->service->isKeySecure('rsa', 4096));
    }

    public function testIsKeySecureDsa(): void
    {
        $this->assertFalse($this->service->isKeySecure('dsa', 1024));
    }

    public function testIsKeySecureUnknownAlgorithmReturnsNull(): void
    {
        $this->assertNull($this->service->isKeySecure('blowfish', 128));
    }

    public function testGenerateSshKeyPairProducesEd25519Pair(): void
    {
        if (!$this->sshKeygenAvailable()) {
            $this->markTestSkipped('ssh-keygen not available in this environment');
        }
        $pair = $this->service->generateSshKeyPair();
        $this->assertArrayHasKey('private_key', $pair);
        $this->assertArrayHasKey('public_key', $pair);
        $this->assertStringContainsString('OPENSSH PRIVATE KEY', $pair['private_key']);
        $this->assertStringStartsWith('ssh-ed25519', $pair['public_key']);
    }

    public function testAnalyzePrivateKeyOnGeneratedKey(): void
    {
        if (!$this->sshKeygenAvailable()) {
            $this->markTestSkipped('ssh-keygen not available in this environment');
        }
        $pair = $this->service->generateSshKeyPair();
        $info = $this->service->analyzePrivateKey($pair['private_key']);
        $this->assertStringStartsWith('ssh-ed25519', $info['public_key']);
        $this->assertSame('ed25519', $info['algorithm']);
        $this->assertSame(256, $info['bits']);
        $this->assertTrue($info['key_secure']);
    }

    public function testAnalyzePrivateKeyHandlesCrlfLineEndings(): void
    {
        if (!$this->sshKeygenAvailable()) {
            $this->markTestSkipped('ssh-keygen not available in this environment');
        }
        $pair = $this->service->generateSshKeyPair();
        $crlf = str_replace("\n", "\r\n", $pair['private_key']);
        $info = $this->service->analyzePrivateKey($crlf);
        $this->assertSame('ed25519', $info['algorithm']);
    }

    public function testAnalyzePrivateKeyReturnsSafeFallbackOnGarbage(): void
    {
        $info = $this->service->analyzePrivateKey('not a real private key');
        $this->assertSame('', $info['public_key']);
        $this->assertSame('unknown', $info['algorithm']);
        $this->assertSame(0, $info['bits']);
        $this->assertNull($info['key_secure']);
    }

    private function sshKeygenAvailable(): bool
    {
        // Walk PATH manually instead of shelling out — avoids shell_exec
        // (flagged by the repo's no-shell-exec audit) and has no side effects.
        $pathEnv = (string)(getenv('PATH') ?: '');
        foreach (explode(PATH_SEPARATOR, $pathEnv) as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = rtrim($dir, '/') . '/ssh-keygen';
            if (is_file($candidate) && is_executable($candidate)) {
                return true;
            }
        }
        return false;
    }

    private function makeCredential(): Credential
    {
        // Partial stub — only secret_data field is used by the service.
        // Pre-populate _attributes so __get/__set work without a DB connection.
        $stub = $this->getMockBuilder(Credential::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->getMock();
        $stub->method('save')->willReturn(true);
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($stub, ['secret_data' => '']);
        return $stub;
    }
}
