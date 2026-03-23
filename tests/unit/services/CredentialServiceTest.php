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
