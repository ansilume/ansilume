<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\services\TotpRateLimiter;
use app\services\TotpService;
use app\tests\unit\services\TestableTotpService;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TotpService: secret generation, encryption, code verification,
 * recovery codes, and rate limiting.
 */
class TotpServiceTest extends TestCase
{
    private TestableTotpService $service;

    protected function setUp(): void
    {
        $this->service = new TestableTotpService();
        $this->service->rateLimiter = new TotpRateLimiter();
    }

    // ── Secret generation ────────────────────────────────────────────────────

    public function testGenerateSecretReturnsBase32String(): void
    {
        $secret = $this->service->generateSecret();
        $this->assertNotEmpty($secret);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+=*$/', $secret);
    }

    public function testGenerateSecretIsUnique(): void
    {
        $secrets = [];
        for ($i = 0; $i < 10; $i++) {
            $secrets[] = $this->service->generateSecret();
        }
        $this->assertCount(10, array_unique($secrets));
    }

    // ── Provisioning URI ─────────────────────────────────────────────────────

    public function testBuildProvisioningUriContainsSecret(): void
    {
        $secret = $this->service->generateSecret();
        $user   = $this->createUserStub('testuser', 'test@example.com');

        $uri = $this->service->buildProvisioningUri($secret, $user);

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=' . $secret, $uri);
        $this->assertStringContainsString('test%40example.com', $uri);
    }

    // ── QR code generation ───────────────────────────────────────────────────

    public function testGenerateQrDataUriReturnsSvgDataUri(): void
    {
        $secret = $this->service->generateSecret();
        $user   = $this->createUserStub('testuser', 'test@example.com');
        $uri    = $this->service->buildProvisioningUri($secret, $user);

        $dataUri = $this->service->generateQrDataUri($uri);

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $dataUri);
    }

    // ── Code verification ────────────────────────────────────────────────────

    public function testVerifyCodeAcceptsValidCode(): void
    {
        $secret = $this->service->generateSecret();
        $totp   = TOTP::createFromSecret($secret);
        $code   = $totp->now();

        $this->assertTrue($this->service->verifyCode($secret, $code));
    }

    public function testVerifyCodeRejectsInvalidCode(): void
    {
        $secret = $this->service->generateSecret();
        $this->assertFalse($this->service->verifyCode($secret, '000000'));
    }

    public function testVerifyCodeRejectsNonNumeric(): void
    {
        $secret = $this->service->generateSecret();
        $this->assertFalse($this->service->verifyCode($secret, 'abcdef'));
    }

    public function testVerifyCodeRejectsWrongLength(): void
    {
        $secret = $this->service->generateSecret();
        $this->assertFalse($this->service->verifyCode($secret, '12345'));
        $this->assertFalse($this->service->verifyCode($secret, '1234567'));
    }

    // ── Encryption / Decryption ──────────────────────────────────────────────

    public function testEncryptDecryptRoundtrip(): void
    {
        $secret    = $this->service->generateSecret();
        $encrypted = $this->service->encryptSecret($secret);

        $this->assertNotEquals($secret, $encrypted);

        $decrypted = $this->service->decryptSecret($encrypted);
        $this->assertSame($secret, $decrypted);
    }

    public function testEncryptedOutputIsDifferentEachTime(): void
    {
        $secret = $this->service->generateSecret();
        $enc1   = $this->service->encryptSecret($secret);
        $enc2   = $this->service->encryptSecret($secret);

        // Different IV each time
        $this->assertNotEquals($enc1, $enc2);

        // But both decrypt to the same value
        $this->assertSame($secret, $this->service->decryptSecret($enc1));
        $this->assertSame($secret, $this->service->decryptSecret($enc2));
    }

    public function testDecryptFailsWithGarbage(): void
    {
        $this->expectException(\yii\base\Exception::class);
        $this->service->decryptSecret('not-valid-base64-ciphertext!!!');
    }

    public function testDecryptFailsWithTruncatedData(): void
    {
        $this->expectException(\yii\base\Exception::class);
        $this->service->decryptSecret(base64_encode('short'));
    }

    // ── Recovery codes ───────────────────────────────────────────────────────

    public function testGenerateRecoveryCodesProducesCorrectCount(): void
    {
        $result = $this->service->generateRecoveryCodes();

        $this->assertArrayHasKey('raw', $result);
        $this->assertArrayHasKey('hashed', $result);
        $this->assertCount(10, $result['raw']);
        $this->assertCount(10, $result['hashed']);
    }

    public function testRecoveryCodeFormat(): void
    {
        $result = $this->service->generateRecoveryCodes();
        foreach ($result['raw'] as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code);
        }
    }

    public function testRecoveryCodesAreUnique(): void
    {
        $result = $this->service->generateRecoveryCodes();
        $this->assertCount(10, array_unique($result['raw']));
    }

    public function testVerifyRecoveryCodeMatchesCorrectCode(): void
    {
        $result = $this->service->generateRecoveryCodes();
        $index  = $this->service->verifyRecoveryCode($result['raw'][3], $result['hashed']);
        $this->assertSame(3, $index);
    }

    public function testVerifyRecoveryCodeRejectsInvalidCode(): void
    {
        $result = $this->service->generateRecoveryCodes();
        $index  = $this->service->verifyRecoveryCode('XXXX-YYYY', $result['hashed']);
        $this->assertSame(-1, $index);
    }

    public function testVerifyRecoveryCodeNormalizesInput(): void
    {
        $result = $this->service->generateRecoveryCodes();
        // Should work without dash and in lowercase
        $code        = $result['raw'][0];
        $noDash      = str_replace('-', '', $code);
        $lowerNoDash = strtolower($noDash);

        $this->assertGreaterThanOrEqual(0, $this->service->verifyRecoveryCode($noDash, $result['hashed']));
        $this->assertGreaterThanOrEqual(0, $this->service->verifyRecoveryCode($lowerNoDash, $result['hashed']));
    }

    public function testVerifyRecoveryCodeWithWhitespace(): void
    {
        $result = $this->service->generateRecoveryCodes();
        $code   = '  ' . $result['raw'][0] . '  ';
        $this->assertGreaterThanOrEqual(0, $this->service->verifyRecoveryCode($code, $result['hashed']));
    }

    // ── Recovery code hashing ────────────────────────────────────────────────

    public function testHashedCodesAreBcrypt(): void
    {
        $result = $this->service->generateRecoveryCodes();
        foreach ($result['hashed'] as $hash) {
            $this->assertStringStartsWith('$2y$', $hash);
        }
    }

    // ── Rate limiting ────────────────────────────────────────────────────────

    public function testIsLockedOutReturnsFalseInitially(): void
    {
        $this->assertFalse($this->service->rateLimiter->isLockedOut(99999));
    }

    public function testRecordFailedAttemptDecrementsRemaining(): void
    {
        $userId = 88888;
        $remaining = $this->service->rateLimiter->recordFailedAttempt($userId);
        $this->assertSame(4, $remaining);

        // Clean up
        $this->service->rateLimiter->clearRateLimit($userId);
    }

    public function testLockoutAfterMaxAttempts(): void
    {
        $userId = 77777;
        for ($i = 0; $i < 5; $i++) {
            $this->service->rateLimiter->recordFailedAttempt($userId);
        }
        $this->assertTrue($this->service->rateLimiter->isLockedOut($userId));

        // Clean up
        $this->service->rateLimiter->clearRateLimit($userId);
    }

    public function testClearRateLimitResetsLockout(): void
    {
        $userId = 66666;
        for ($i = 0; $i < 5; $i++) {
            $this->service->rateLimiter->recordFailedAttempt($userId);
        }
        $this->assertTrue($this->service->rateLimiter->isLockedOut($userId));

        $this->service->rateLimiter->clearRateLimit($userId);
        $this->assertFalse($this->service->rateLimiter->isLockedOut($userId));
    }

    // ── Regression: nested array rateLimiter config from config/web.php ──────

    /**
     * Regression for `/profile/security` TypeError: Yii2 Component::__set was
     * assigning the nested `['class' => TotpRateLimiter::class]` array straight
     * to a typed `public TotpRateLimiter $rateLimiter` property. The fix moves
     * the property behind a setter that resolves the config via the DI
     * container. This test constructs TotpService with the *exact* shape used
     * in config/web.php and asserts the resulting property is a real instance.
     */
    public function testRateLimiterNestedArrayConfigIsResolvedToInstance(): void
    {
        /** @var TotpService $service */
        $service = \Yii::createObject([
            'class' => TotpService::class,
            'rateLimiter' => [
                'class' => TotpRateLimiter::class,
            ],
        ]);

        $this->assertInstanceOf(TotpRateLimiter::class, $service->rateLimiter);
        // And it must be fully usable — no half-configured stub.
        $this->assertFalse($service->rateLimiter->isLockedOut(987654));
    }

    public function testRateLimiterDefaultsToRealInstanceWhenNotConfigured(): void
    {
        $service = new TotpService();
        $this->assertInstanceOf(TotpRateLimiter::class, $service->rateLimiter);
    }

    public function testRateLimiterAcceptsDirectInstanceAssignment(): void
    {
        $service = new TotpService();
        $limiter = new TotpRateLimiter();
        $service->rateLimiter = $limiter;
        $this->assertSame($limiter, $service->rateLimiter);
    }

    // ── Custom recovery code count ───────────────────────────────────────────

    public function testCustomRecoveryCodeCount(): void
    {
        $service = new TestableTotpService();
        $service->recoveryCodeCount = 5;
        $result = $service->generateRecoveryCodes();
        $this->assertCount(5, $result['raw']);
        $this->assertCount(5, $result['hashed']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function createUserStub(string $username = 'test', string $email = 'test@example.com'): \app\models\User
    {
        $user = new class extends \app\models\User {
            private array $_data = [];

            public function init(): void
            {
            }

            public static function getTableSchema(): ?\yii\db\TableSchema
            {
                return null;
            }

            public function __set($name, $value)
            {
                $this->_data[$name] = $value;
            }

            public function __get($name)
            {
                return $this->_data[$name] ?? null;
            }

            public function __isset($name)
            {
                return isset($this->_data[$name]);
            }
        };

        $user->username = $username;
        $user->email    = $email;
        return $user;
    }
}
