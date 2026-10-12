<?php

declare(strict_types=1);

namespace app\components;

/**
 * The AES-256-CBC encryption of secrets at rest: credential secrets, TOTP
 * secrets and survey password answers.
 *
 * The format is the one CredentialService and TotpService have always used,
 * so every stored value still decrypts:
 * - key: the raw SHA-256 of APP_SECRET_KEY, which needs at least 16
 *   characters;
 * - a random 16-byte IV per value;
 * - stored as base64(IV . ciphertext).
 *
 * The key is read on every call: tests and maintenance commands change it.
 */
final class SecretCipher
{
    private const METHOD = 'AES-256-CBC';
    private const IV_BYTES = 16;
    private const MIN_KEY_LENGTH = 16;

    /**
     * @throws SecretCipherException
     */
    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $key = $this->key();
        $iv = random_bytes(self::IV_BYTES);
        $cipher = self::orFail(
            openssl_encrypt($plaintext, self::METHOD, $key, OPENSSL_RAW_DATA, $iv),
            'Encryption failed.'
        );

        return base64_encode($iv . $cipher);
    }

    /**
     * @throws SecretCipherException
     */
    public function decrypt(string $encoded): string
    {
        $key = $this->key();
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= self::IV_BYTES) {
            throw new SecretCipherException('Decryption failed: invalid ciphertext.', SecretCipherException::INVALID_CIPHERTEXT);
        }

        return self::orFail(
            openssl_decrypt(substr($raw, self::IV_BYTES), self::METHOD, $key, OPENSSL_RAW_DATA, substr($raw, 0, self::IV_BYTES)),
            'Decryption failed.'
        );
    }

    private function key(): string
    {
        $secret = $_ENV['APP_SECRET_KEY'] ?? '';
        if (!is_string($secret) || strlen($secret) < self::MIN_KEY_LENGTH) {
            throw new SecretCipherException(
                'APP_SECRET_KEY is not set or too short. Set it in your .env file.',
                SecretCipherException::KEY_UNUSABLE
            );
        }

        return hash('sha256', $secret, true);
    }

    private static function orFail(#[\SensitiveParameter] string|false $result, string $message): string
    {
        if ($result === false) {
            throw new SecretCipherException($message, SecretCipherException::CIPHER_FAILED);
        }

        return $result;
    }
}
