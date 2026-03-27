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
        return array_fill_keys(array_keys($secrets), '***REDACTED***');
    }

    /**
     * Generate a new Ed25519 SSH key pair.
     * Returns ['private_key' => '...', 'public_key' => '...'].
     *
     * @throws \RuntimeException on failure.
     */
    public function generateSshKeyPair(): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ansilume_gen_');
        unlink($tmp); // ssh-keygen creates its own file

        $cmd = ['ssh-keygen', '-t', 'ed25519', '-N', '', '-C', 'ansilume-generated', '-f', $tmp];
        $this->runCommand($cmd);

        try {
            if (!file_exists($tmp) || !file_exists($tmp . '.pub')) {
                throw new \RuntimeException('ssh-keygen did not produce expected key files.');
            }
            return [
                'private_key' => file_get_contents($tmp),
                'public_key' => trim(file_get_contents($tmp . '.pub')),
            ];
        } finally {
            \app\helpers\FileHelper::safeUnlink($tmp);
            \app\helpers\FileHelper::safeUnlink($tmp . '.pub');
        }
    }

    /**
     * Analyse a private key and extract its public key and algorithm metadata.
     * Returns ['public_key', 'algorithm', 'bits', 'secure'].
     * On any failure returns an array with empty/null values rather than throwing.
     */
    public function analyzePrivateKey(string $privateKey): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ansilume_key_');
        // Normalise to Unix line endings — browsers submit \r\n from textareas
        // and OpenSSH key format requires \n only.
        $normalized = str_replace("\r\n", "\n", str_replace("\r", "\n", $privateKey));
        file_put_contents($tmp, rtrim($normalized) . "\n");
        chmod($tmp, 0600);

        try {
            // Extract public key; -P "" supplies empty passphrase so ssh-keygen
            // never prompts interactively (no TTY available in the web process).
            $pubOut = [];
            $this->runCommand(['ssh-keygen', '-y', '-P', '', '-f', $tmp], $pubOut);
            $pubKey = trim(implode('', $pubOut));

            // Get fingerprint/type line: "256 SHA256:xxx label (ED25519)"
            $infoOut = [];
            $this->runCommand(['ssh-keygen', '-l', '-P', '', '-f', $tmp], $infoOut);
            $info = trim(implode('', $infoOut));

            $bits = 0;
            $algorithm = 'unknown';
            if (preg_match('/^(\d+)\s+\S+\s+.*\((\S+)\)\s*$/', $info, $m)) {
                $bits = (int)$m[1];
                $algorithm = strtolower($m[2]);
            }

            $secure = $this->isKeySecure($algorithm, $bits);

            return [
                'public_key' => $pubKey,
                'algorithm' => $algorithm,
                'bits' => $bits,
                'key_secure' => $secure,
            ];
        } catch (\RuntimeException $e) {
            \Yii::warning('CredentialService: analyzePrivateKey failed: ' . $e->getMessage(), __CLASS__);
            return ['public_key' => '', 'algorithm' => 'unknown', 'bits' => 0, 'key_secure' => null];
        } finally {
            \app\helpers\FileHelper::safeUnlink($tmp);
        }
    }

    /**
     * Determine whether a key algorithm+size combination is considered secure.
     * null = unknown, true = secure, false = insecure/weak.
     */
    public function isKeySecure(string $algorithm, int $bits): ?bool
    {
        return match ($algorithm) {
            'ed25519' => true,
            'ed448' => true,
            'ecdsa' => $bits >= 384, // nistp256 = 256 bits → false, nistp384/521 → true
            'rsa' => $bits >= 4096,
            'dsa' => false,
            default => null,
        };
    }

    /**
     * Run a command as a subprocess, returning stdout lines.
     * @throws \RuntimeException on non-zero exit.
     */
    private function runCommand(array $cmd, array &$output = []): void
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('proc_open failed for: ' . implode(' ', $cmd));
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $output = $stdout !== false ? explode("\n", $stdout) : [];
        if ($exit !== 0) {
            throw new \RuntimeException('Command failed (exit ' . $exit . '): ' . implode(' ', $cmd));
        }
    }

    private function encrypt(string $plaintext): string
    {
        $key = $this->deriveKey();
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($cipher === false) {
            throw new Exception('Credential encryption failed.');
        }

        return base64_encode($iv . $cipher);
    }

    private function decrypt(string $ciphertext): string
    {
        $key = $this->deriveKey();
        $raw = base64_decode($ciphertext, true);

        if ($raw === false || strlen($raw) < 17) {
            throw new Exception('Credential decryption failed: invalid ciphertext.');
        }

        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

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
