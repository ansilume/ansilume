<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\SecretCipher;
use app\components\SecretCipherException;
use app\tests\unit\TraceArguments;
use PHPUnit\Framework\TestCase;

/**
 * SecretCipher: the AES-256-CBC format credentials and TOTP secrets have
 * always been stored in, now shared with survey password answers.
 */
class SecretCipherTest extends TestCase
{
    private const KEY = 'unit-test-key-with-enough-characters';
    private const MARKER = 'cipher-test-plaintext-marker';

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

    public function testRoundTrip(): void
    {
        $cipher = new SecretCipher();
        $plaintext = "multi\nline ünïcode " . self::MARKER;

        $encoded = $cipher->encrypt($plaintext);

        $this->assertStringNotContainsString(self::MARKER, $encoded);
        $this->assertStringNotContainsString(self::MARKER, (string)base64_decode($encoded, true));
        $this->assertSame($plaintext, $cipher->decrypt($encoded));
    }

    public function testEveryValueGetsItsOwnIv(): void
    {
        $cipher = new SecretCipher();

        $first = $cipher->encrypt('same');
        $second = $cipher->encrypt('same');

        $this->assertNotSame($first, $second);
        $this->assertNotSame(substr((string)base64_decode($first, true), 0, 16), substr((string)base64_decode($second, true), 0, 16));
    }

    /**
     * Values stored before the cipher was shared (CredentialService and
     * TotpService wrote base64(IV . AES-256-CBC(sha256(APP_SECRET_KEY)))
     * themselves) still decrypt, and new values decrypt the old way.
     */
    public function testTheStoredFormatIsUnchanged(): void
    {
        $key = hash('sha256', self::KEY, true);
        $iv = random_bytes(16);
        $legacy = base64_encode($iv . (string)openssl_encrypt('{"password":"legacy"}', 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv));

        $this->assertSame('{"password":"legacy"}', (new SecretCipher())->decrypt($legacy));

        $raw = (string)base64_decode((new SecretCipher())->encrypt('new value'), true);
        $this->assertSame('new value', openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16)));
    }

    public function testAnEmptyValueRoundTrips(): void
    {
        $cipher = new SecretCipher();

        $this->assertSame('', $cipher->decrypt($cipher->encrypt('')));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unusableKeyProvider(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            '15 characters' => [str_repeat('k', 15)],
            'not text' => [['a list']],
        ];
    }

    /**
     * The plaintext stays out of the trace: encrypt() marks it
     * #[\SensitiveParameter].
     *
     * Regression (fix round 4): the trace check read only the string form,
     * which shows the first 15 characters of a text argument, never the
     * whole marker, so it passed without the mark too. TraceArguments reads
     * the arguments themselves.
     *
     * @dataProvider unusableKeyProvider
     */
    public function testAnUnusableKeyFailsBothWaysWithTheSetupMessage(mixed $key): void
    {
        $encoded = (new SecretCipher())->encrypt('x');
        if ($key === null) {
            unset($_ENV['APP_SECRET_KEY']);
        } else {
            $_ENV['APP_SECRET_KEY'] = $key;
        }

        foreach ([fn () => (new SecretCipher())->encrypt(self::MARKER), fn () => (new SecretCipher())->decrypt($encoded)] as $call) {
            try {
                $call();
                $this->fail('expected a SecretCipherException');
            } catch (SecretCipherException $e) {
                $this->assertSame(SecretCipherException::KEY_UNUSABLE, $e->getCode());
                $this->assertSame('APP_SECRET_KEY is not set or too short. Set it in your .env file.', $e->getMessage());
                TraceArguments::assertSecretAbsent(self::MARKER, $e);
            }
        }
    }

    public function testSixteenCharactersAreEnough(): void
    {
        $_ENV['APP_SECRET_KEY'] = str_repeat('k', 16);
        $cipher = new SecretCipher();

        $this->assertSame('ok', $cipher->decrypt($cipher->encrypt('ok')));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidCiphertextProvider(): array
    {
        return [
            'not base64' => ['###not-base64###'],
            'empty' => [''],
            'only an IV' => [base64_encode(str_repeat('i', 16))],
        ];
    }

    /**
     * @dataProvider invalidCiphertextProvider
     */
    public function testAValueThatIsNotIvPlusCiphertextIsRejected(string $encoded): void
    {
        try {
            (new SecretCipher())->decrypt($encoded);
            $this->fail('expected a SecretCipherException');
        } catch (SecretCipherException $e) {
            $this->assertSame(SecretCipherException::INVALID_CIPHERTEXT, $e->getCode());
            $this->assertSame('Decryption failed: invalid ciphertext.', $e->getMessage());
        }
    }

    /**
     * A value whose ciphertext was damaged: its block does not decrypt to
     * valid padding. The key (self::KEY, set in setUp()), the IV and the
     * block are fixed, so the outcome is deterministic: the block decrypts
     * to a last byte of 0x6e, which is no padding.
     *
     * Regression: the test damaged a value that encrypt() had written with a
     * random IV. About 1 in 256 random IVs decrypt the damaged block to a
     * last byte of 0x01, which is valid padding, so the value decrypted and
     * the test failed at random on every PHP version (12 of 3000 runs on
     * PHP 8.5).
     */
    public function testADamagedValueIsRejected(): void
    {
        $iv = str_repeat("\x01", 16);

        $this->expectExceptionObject(new SecretCipherException('Decryption failed.', SecretCipherException::CIPHER_FAILED));
        (new SecretCipher())->decrypt(base64_encode($iv . str_repeat('x', 16)));
    }

    /**
     * A value encrypted with another key: the padding check fails. The IV
     * and plaintext are fixed, so the outcome is deterministic.
     */
    public function testAValueOfAnotherKeyIsRejected(): void
    {
        $iv = str_repeat("\x01", 16);
        $other = hash('sha256', 'the-key-before-rotation', true);
        $encoded = base64_encode($iv . (string)openssl_encrypt('{"token":"rotated"}', 'AES-256-CBC', $other, OPENSSL_RAW_DATA, $iv));

        $this->expectExceptionObject(new SecretCipherException('Decryption failed.', SecretCipherException::CIPHER_FAILED));
        (new SecretCipher())->decrypt($encoded);
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: string}>
     */
    public static function describeProvider(): array
    {
        return [
            'key' => [SecretCipherException::KEY_UNUSABLE, 'decryption', 'APP_SECRET_KEY is not set or too short. Set it in your .env file.'],
            'invalid ciphertext' => [SecretCipherException::INVALID_CIPHERTEXT, 'decryption', 'Credential decryption failed: invalid ciphertext.'],
            'failed decryption' => [SecretCipherException::CIPHER_FAILED, 'decryption', 'Credential decryption failed.'],
            'failed encryption' => [SecretCipherException::CIPHER_FAILED, 'encryption', 'Credential encryption failed.'],
        ];
    }

    /**
     * @dataProvider describeProvider
     */
    public function testDescribeForKeepsTheWordingOfTheCallers(int $code, string $operation, string $expected): void
    {
        $message = $code === SecretCipherException::KEY_UNUSABLE ? 'APP_SECRET_KEY is not set or too short. Set it in your .env file.' : 'cipher detail';

        $this->assertSame($expected, (new SecretCipherException($message, $code))->describeFor('Credential', $operation));
    }
}
