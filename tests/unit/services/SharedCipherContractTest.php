<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\components\SecretCipher;
use app\components\SecretCipherException;
use app\models\Credential;
use app\services\CredentialService;
use app\tests\unit\TraceArguments;
use PHPUnit\Framework\TestCase;
use yii\base\Exception;

/**
 * CredentialService and TotpService delegate to SecretCipher and keep their
 * contracts: they throw yii\base\Exception with the messages they always
 * threw, and they read what the other writes (one format).
 */
class SharedCipherContractTest extends TestCase
{
    private const KEY = 'contract-test-key-with-enough-characters';

    private mixed $originalKey;

    protected function setUp(): void
    {
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = self::KEY;
    }

    protected function tearDown(): void
    {
        if ($this->originalKey === null) {
            unset($_ENV['APP_SECRET_KEY']);
        } else {
            $_ENV['APP_SECRET_KEY'] = $this->originalKey;
        }
    }

    public function testCredentialSecretsAreSharedCipherValues(): void
    {
        $encrypted = (new CredentialService())->encryptSecrets(['token' => 'tok-value']);

        $this->assertSame('{"token":"tok-value"}', (new SecretCipher())->decrypt($encrypted));
        $credential = $this->credentialWith((new SecretCipher())->encrypt('{"password":"pw-value"}'));
        $this->assertSame(['password' => 'pw-value'], (new CredentialService())->getSecrets($credential));
    }

    public function testTotpSecretsAreSharedCipherValues(): void
    {
        $totp = new TestableTotpService();

        $this->assertSame('JBSWY3DPEHPK3PXP', (new SecretCipher())->decrypt($totp->encryptSecret('JBSWY3DPEHPK3PXP')));
        $this->assertSame('JBSWY3DPEHPK3PXP', $totp->decryptSecret((new SecretCipher())->encrypt('JBSWY3DPEHPK3PXP')));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function credentialFailureProvider(): array
    {
        return [
            'invalid ciphertext' => ['###not-base64###', 'Credential decryption failed: invalid ciphertext.'],
            'damaged value' => [base64_encode(str_repeat('i', 16) . str_repeat('x', 16)), 'Credential decryption failed.'],
        ];
    }

    /**
     * @dataProvider credentialFailureProvider
     */
    public function testCredentialDecryptionErrorsKeepTheirClassAndMessage(string $stored, string $message): void
    {
        try {
            (new CredentialService())->getSecrets($this->credentialWith($stored));
            $this->fail('expected an exception');
        } catch (Exception $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertInstanceOf(SecretCipherException::class, $e->getPrevious());
        }
    }

    public function testCredentialServiceReportsAnUnusableKey(): void
    {
        $_ENV['APP_SECRET_KEY'] = 'short';

        try {
            (new CredentialService())->encryptSecrets(['password' => 'credential-plaintext-marker']);
            $this->fail('expected an exception');
        } catch (Exception $e) {
            $this->assertSame('APP_SECRET_KEY is not set or too short. Set it in your .env file.', $e->getMessage());
            TraceArguments::assertSecretAbsent('credential-plaintext-marker', $e);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function totpFailureProvider(): array
    {
        return [
            'invalid ciphertext' => [base64_encode('short'), 'TOTP secret decryption failed: invalid ciphertext.'],
            'damaged value' => [base64_encode(str_repeat('i', 16) . str_repeat('x', 16)), 'TOTP secret decryption failed.'],
        ];
    }

    /**
     * @dataProvider totpFailureProvider
     */
    public function testTotpDecryptionErrorsKeepTheirClassAndMessage(string $stored, string $message): void
    {
        $this->expectExceptionObject(new Exception($message));
        (new TestableTotpService())->decryptSecret($stored);
    }

    public function testTotpServiceReportsAnUnusableKey(): void
    {
        unset($_ENV['APP_SECRET_KEY']);

        try {
            (new TestableTotpService())->encryptSecret('TOTPPLAINTEXTMARKER');
            $this->fail('expected an exception');
        } catch (Exception $e) {
            $this->assertSame('APP_SECRET_KEY is not set or too short. Set it in your .env file.', $e->getMessage());
            TraceArguments::assertSecretAbsent('TOTPPLAINTEXTMARKER', $e);
        }
    }

    private function credentialWith(string $secretData): Credential
    {
        $credential = $this->getMockBuilder(Credential::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setValue($credential, ['secret_data' => $secretData]);

        return $credential;
    }
}
