<?php

declare(strict_types=1);

namespace app\components;

/**
 * SecretCipher could not encrypt or decrypt. The message never contains the
 * plaintext, the ciphertext or the key; the code says what went wrong, so
 * callers can keep their own wording ({@see describeFor()}).
 */
final class SecretCipherException extends \RuntimeException
{
    /** APP_SECRET_KEY is missing or shorter than 16 characters. */
    public const KEY_UNUSABLE = 1;
    /** The stored value is not base64 of an IV plus ciphertext. */
    public const INVALID_CIPHERTEXT = 2;
    /** OpenSSL refused: a wrong key, a damaged value. */
    public const CIPHER_FAILED = 3;

    /**
     * The message for $subject ("Credential", "TOTP secret") and $operation
     * ("encryption", "decryption"), worded as CredentialService and
     * TotpService worded their errors before they shared this cipher.
     */
    public function describeFor(string $subject, string $operation): string
    {
        return match ($this->getCode()) {
            self::KEY_UNUSABLE => $this->getMessage(),
            self::INVALID_CIPHERTEXT => "{$subject} {$operation} failed: invalid ciphertext.",
            default => "{$subject} {$operation} failed.",
        };
    }
}
