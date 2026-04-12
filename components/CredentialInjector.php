<?php

declare(strict_types=1);

namespace app\components;

use app\helpers\FileHelper;
use app\models\Credential;

/**
 * Converts decrypted credential data into ansible-playbook CLI args,
 * environment variables, and temporary files.
 *
 * Does NOT handle decryption — expects already-decrypted credential data.
 * Callers must clean up temp files via $result->tempFiles after execution.
 */
class CredentialInjector
{
    /**
     * Produce CLI args, env vars, and temp files for a credential.
     *
     * @param array{credential_type: string, username: string|null, secrets: array<string, string>}|null $credentialData
     */
    public function inject(?array $credentialData): CredentialInjectionResult
    {
        if ($credentialData === null || empty($credentialData['credential_type'])) {
            return CredentialInjectionResult::empty();
        }

        return match ($credentialData['credential_type']) {
            Credential::TYPE_SSH_KEY => $this->injectSshKey($credentialData),
            Credential::TYPE_USERNAME_PASSWORD => $this->injectUsernamePassword($credentialData),
            Credential::TYPE_VAULT => $this->injectVault($credentialData),
            Credential::TYPE_TOKEN => $this->injectToken($credentialData),
            default => CredentialInjectionResult::empty(),
        };
    }

    /**
     * Clean up temporary credential files.
     *
     * @param string[] $tempFiles
     */
    public static function cleanup(array $tempFiles): void
    {
        foreach ($tempFiles as $path) {
            FileHelper::safeUnlink($path);
        }
    }

    /**
     * @param array{credential_type: string, username: string|null, secrets: array<string, string>} $data
     */
    private function injectSshKey(array $data): CredentialInjectionResult
    {
        $privateKey = $data['secrets']['private_key'] ?? '';
        if ($privateKey === '') {
            return CredentialInjectionResult::empty();
        }

        $keyFile = $this->writeTempFile($privateKey, 'ansilume_key_');
        if ($keyFile === null) {
            return CredentialInjectionResult::empty();
        }

        $args = ['--private-key', $keyFile];
        if (!empty($data['username'])) {
            $args[] = '--user';
            $args[] = $data['username'];
        }

        return new CredentialInjectionResult($args, [], [$keyFile]);
    }

    /**
     * @param array{credential_type: string, username: string|null, secrets: array<string, string>} $data
     */
    private function injectUsernamePassword(array $data): CredentialInjectionResult
    {
        $env = [];
        $args = [];

        $password = $data['secrets']['password'] ?? '';
        if ($password !== '') {
            $env['ANSIBLE_SSH_PASS'] = $password;
        }

        if (!empty($data['username'])) {
            $args[] = '--user';
            $args[] = $data['username'];
        }

        return new CredentialInjectionResult($args, $env, []);
    }

    /**
     * @param array{credential_type: string, username: string|null, secrets: array<string, string>} $data
     */
    private function injectVault(array $data): CredentialInjectionResult
    {
        $vaultPassword = $data['secrets']['vault_password'] ?? '';
        if ($vaultPassword === '') {
            return CredentialInjectionResult::empty();
        }

        $vaultFile = $this->writeTempFile($vaultPassword, 'ansilume_vault_');
        if ($vaultFile === null) {
            return CredentialInjectionResult::empty();
        }

        return new CredentialInjectionResult(
            ['--vault-password-file', $vaultFile],
            [],
            [$vaultFile],
        );
    }

    /**
     * @param array{credential_type: string, username: string|null, secrets: array<string, string>} $data
     */
    private function injectToken(array $data): CredentialInjectionResult
    {
        $token = $data['secrets']['token'] ?? '';
        if ($token === '') {
            return CredentialInjectionResult::empty();
        }

        return new CredentialInjectionResult([], ['ANSILUME_CREDENTIAL_TOKEN' => $token], []);
    }

    /**
     * Write content to a secure temp file (mode 0600).
     * Returns the file path, or null on failure.
     */
    private function writeTempFile(string $content, string $prefix): ?string
    {
        // Set umask before tempnam so file is created as 0600 from the start,
        // avoiding a TOCTOU window where the secret is world-readable.
        $oldUmask = umask(0o177);
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            umask($oldUmask);
            return null;
        }

        if (!FileHelper::safeFilePutContents($path, $content)) {
            umask($oldUmask);
            FileHelper::safeUnlink($path);
            return null;
        }
        umask($oldUmask);

        return $path;
    }
}
