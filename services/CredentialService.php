<?php

declare(strict_types=1);

namespace app\services;

use app\models\Credential;
use yii\base\Component;
use yii\base\Exception;

/**
 * Handles encryption/decryption of credential secret data.
 *
 * Secret data is stored as AES-256-CBC encrypted JSON in the `secret_data` column.
 * The encryption key is derived from the APP_SECRET_KEY environment variable.
 * Raw secrets are never written to logs.
 */
class CredentialService extends Component
{
    /**
     * Encrypt and store the secret fields for a credential.
     *
     * @param Credential $credential The credential model (already validated).
     * @param array      $secrets    Map of field name => raw value, e.g. ['private_key' => '...'].
     */
    public function storeSecrets(Credential $credential, array $secrets): bool
    {
        $credential->secret_data = $this->encrypt(json_encode($secrets, JSON_THROW_ON_ERROR));
        return $credential->save();
    }

    /**
     * Decrypt and return the secret fields for a credential.
     * Returns an empty array if no secrets are stored.
     *
     * @throws Exception on decryption failure.
     */
    public function getSecrets(Credential $credential): array
    {
        if (empty($credential->secret_data)) {
            return [];
        }

        $json = $this->decrypt($credential->secret_data);
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Returns a redacted representation safe for logging or display.
     */
    public function redact(array $secrets): array
    {
        return array_map(fn($v) => '***REDACTED***', $secrets);
    }

    private function encrypt(string $plaintext): string
    {
        $key    = $this->deriveKey();
        $iv     = random_bytes(16);
        $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($cipher === false) {
            throw new Exception('Credential encryption failed.');
        }

        return base64_encode($iv . $cipher);
    }

    private function decrypt(string $ciphertext): string
    {
        $key  = $this->deriveKey();
        $raw  = base64_decode($ciphertext, true);

        if ($raw === false || strlen($raw) < 17) {
            throw new Exception('Credential decryption failed: invalid ciphertext.');
        }

        $iv     = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain  = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($plain === false) {
            throw new Exception('Credential decryption failed.');
        }

        return $plain;
    }

    private function deriveKey(): string
    {
        $secret = $_ENV['APP_SECRET_KEY'] ?? '';
        if (strlen($secret) < 16) {
            throw new Exception('APP_SECRET_KEY is not set or too short. Set it in your .env file.');
        }
        return hash('sha256', $secret, true);
    }
}
