<?php

declare(strict_types=1);

namespace app\services;

use app\models\User;
use OTPHP\TOTP;
use yii\base\Component;
use yii\base\Exception;

/**
 * Manages TOTP two-factor authentication: secret generation, QR URIs,
 * code verification, recovery codes, and encrypted secret storage.
 */
class TotpService extends Component
{
    /** Number of recovery codes to generate. */
    public int $recoveryCodeCount = 10;

    /** Time window tolerance: accept codes ±1 period (30 s). */
    public int $window = 1;

    /** Maximum TOTP verify attempts before lockout. */
    public int $maxAttempts = 5;

    /** Lockout duration in seconds after max attempts. */
    public int $lockoutDuration = 300;

    // ── Secret management ────────────────────────────────────────────────────

    /**
     * Generate a new TOTP shared secret (Base32, 160 bits).
     */
    public function generateSecret(): string
    {
        $totp = TOTP::generate();
        return $totp->getSecret();
    }

    /**
     * Build the otpauth:// provisioning URI for QR code scanning.
     */
    public function buildProvisioningUri(string $secret, User $user): string
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('TOTP secret must not be empty.');
        }
        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel((string)$user->email ?: 'user');
        $issuer = \Yii::$app->name ?: 'Ansilume';
        $totp->setIssuer($issuer);
        return $totp->getProvisioningUri();
    }

    /**
     * Generate a QR code as a data URI (PNG, base64-encoded) for embedding in HTML.
     */
    public function generateQrDataUri(string $provisioningUri): string
    {
        $renderer = new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle(250),
            new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
        );
        $writer = new \BaconQrCode\Writer($renderer);
        $svg = $writer->writeString($provisioningUri);
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Verify a TOTP code against a secret. Accepts ±1 time period.
     */
    public function verifyCode(string $secret, string $code): bool
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        if ($secret === '') {
            return false;
        }

        $totp = TOTP::createFromSecret($secret);
        /** @var non-empty-string $code Validated by preg_match above */
        return $totp->verify($code, null, max(0, $this->window));
    }

    // ── Encrypted secret storage ─────────────────────────────────────────────

    /**
     * Encrypt a TOTP secret for database storage.
     */
    protected function encryptSecret(string $plainSecret): string
    {
        $key = $this->deriveKey();
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plainSecret, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($cipher === false) {
            throw new Exception('TOTP secret encryption failed.');
        }

        return base64_encode($iv . $cipher);
    }

    /**
     * Decrypt a TOTP secret from database storage.
     */
    protected function decryptSecret(string $ciphertext): string
    {
        $key = $this->deriveKey();
        $raw = base64_decode($ciphertext, true);

        if ($raw === false || strlen($raw) < 17) {
            throw new Exception('TOTP secret decryption failed: invalid ciphertext.');
        }

        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($plain === false) {
            throw new Exception('TOTP secret decryption failed.');
        }

        return $plain;
    }

    // ── Recovery codes ───────────────────────────────────────────────────────

    /**
     * Generate a set of single-use recovery codes.
     *
     * @return array{raw: string[], hashed: string[]}
     *   'raw' = plaintext codes to show the user once
     *   'hashed' = bcrypt hashes to store in DB
     */
    protected function generateRecoveryCodes(): array
    {
        $raw = [];
        $hashed = [];
        /** @var \yii\base\Security $security */
        $security = \Yii::$app->security;
        for ($i = 0; $i < $this->recoveryCodeCount; $i++) {
            $code = $this->generateSingleRecoveryCode();
            $raw[] = $code;
            // Hash the normalized form (no dashes, uppercase) so verification works
            // regardless of how the user types the code
            $normalized = strtoupper(str_replace('-', '', $code));
            $hashed[] = $security->generatePasswordHash($normalized);
        }
        return ['raw' => $raw, 'hashed' => $hashed];
    }

    /**
     * Verify a recovery code against the stored hashes.
     * Returns the index of the matched code (for invalidation), or -1 if no match.
     *
     * @param string[] $hashedCodes
     */
    public function verifyRecoveryCode(string $code, array $hashedCodes): int
    {
        $code = strtoupper(str_replace('-', '', trim($code)));
        /** @var \yii\base\Security $security */
        $security = \Yii::$app->security;
        foreach ($hashedCodes as $i => $hash) {
            if ($security->validatePassword($code, $hash)) {
                return $i;
            }
        }
        return -1;
    }

    /**
     * Use a recovery code: verify it and remove from the user's stored codes.
     * Returns true if the code was valid and consumed.
     */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $codes = $this->getStoredRecoveryCodes($user);
        $index = $this->verifyRecoveryCode($code, $codes);
        if ($index < 0) {
            return false;
        }

        // Remove the used code
        array_splice($codes, $index, 1);
        $user->recovery_codes = !empty($codes) ? (json_encode($codes) ?: null) : null;
        $user->save(false, ['recovery_codes']);
        return true;
    }

    /**
     * Get the stored (hashed) recovery codes for a user.
     *
     * @return string[]
     */
    public function getStoredRecoveryCodes(User $user): array
    {
        if (empty($user->recovery_codes)) {
            return [];
        }
        $codes = json_decode($user->recovery_codes, true);
        return is_array($codes) ? $codes : [];
    }

    /**
     * Count remaining recovery codes for a user.
     */
    public function remainingRecoveryCodeCount(User $user): int
    {
        return count($this->getStoredRecoveryCodes($user));
    }

    // ── Rate limiting ────────────────────────────────────────────────────────

    /**
     * Check if the user is locked out from TOTP attempts.
     */
    public function isLockedOut(int $userId): bool
    {
        $key = $this->rateLimitKey($userId);
        /** @var \yii\caching\CacheInterface $cache */
        $cache = \Yii::$app->cache;
        $data = $cache->get($key);
        if ($data === false) {
            return false;
        }
        return $data['attempts'] >= $this->maxAttempts;
    }

    /**
     * Record a failed TOTP attempt. Returns remaining attempts.
     */
    public function recordFailedAttempt(int $userId): int
    {
        $key = $this->rateLimitKey($userId);
        /** @var \yii\caching\CacheInterface $cache */
        $cache = \Yii::$app->cache;
        $data = $cache->get($key);
        if ($data === false) {
            $data = ['attempts' => 0];
        }
        $data['attempts']++;
        $cache->set($key, $data, $this->lockoutDuration);
        return (int)max(0, $this->maxAttempts - $data['attempts']);
    }

    /**
     * Clear the rate limit counter (after successful login).
     */
    public function clearRateLimit(int $userId): void
    {
        /** @var \yii\caching\CacheInterface $cache */
        $cache = \Yii::$app->cache;
        $cache->delete($this->rateLimitKey($userId));
    }

    // ── Enable / Disable ─────────────────────────────────────────────────────

    /**
     * Enable TOTP for a user: encrypt and store the secret, store recovery codes.
     *
     * @return string[] Raw recovery codes to display once.
     */
    public function enable(User $user, string $plainSecret): array
    {
        $recovery = $this->generateRecoveryCodes();

        $user->totp_secret = $this->encryptSecret($plainSecret);
        $user->totp_enabled = true;
        $user->recovery_codes = json_encode($recovery['hashed']) ?: null;
        $user->save(false, ['totp_secret', 'totp_enabled', 'recovery_codes']);

        return $recovery['raw'];
    }

    /**
     * Disable TOTP for a user: clear all TOTP fields.
     */
    public function disable(User $user): void
    {
        $user->totp_secret = null;
        $user->totp_enabled = false;
        $user->recovery_codes = null;
        $user->save(false, ['totp_secret', 'totp_enabled', 'recovery_codes']);
    }

    /**
     * Get the decrypted TOTP secret for a user.
     */
    public function getUserSecret(User $user): ?string
    {
        if (empty($user->totp_secret)) {
            return null;
        }
        return $this->decryptSecret($user->totp_secret);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function deriveKey(): string
    {
        $secret = $_ENV['APP_SECRET_KEY'] ?? '';
        if (strlen($secret) < 16) {
            throw new Exception('APP_SECRET_KEY is not set or too short. Set it in your .env file.');
        }
        return hash('sha256', $secret, true);
    }

    private function generateSingleRecoveryCode(): string
    {
        // 8-character uppercase alphanumeric, e.g. "A3F7-K9X2"
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no I/O/0/1 to avoid confusion
        $code = '';
        $bytes = random_bytes(8);
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[ord($bytes[$i]) % strlen($chars)];
        }
        return substr($code, 0, 4) . '-' . substr($code, 4, 4);
    }

    private function rateLimitKey(int $userId): string
    {
        return 'totp_rate_limit_' . $userId;
    }
}
