<?php

declare(strict_types=1);

namespace app\components;

use app\helpers\FileHelper;
use app\models\Credential;

/**
 * Converts decrypted credential data into ansible-playbook CLI args,
 * environment variables, and temporary files.
 *
 * Two entry points:
 *  - {@see inject()} for a single credential (kept for back-compat).
 *  - {@see injectAll()} for the multi-credential path used by the
 *    launch flow. Merges args + env vars + temp files across every
 *    linked credential with first-wins semantics on single-slot
 *    ansible arguments (--user, --private-key, --vault-password-file).
 *
 * Does NOT handle decryption — expects already-decrypted credential data.
 * Callers must clean up temp files via $result->tempFiles after execution.
 */
class CredentialInjector
{
    /**
     * Produce CLI args, env vars, and temp files for a single credential.
     *
     * @param array{credential_type: string, username: string|null, env_var_name?: string|null, secrets: array<string, string>}|null $credentialData
     */
    public function inject(?array $credentialData): CredentialInjectionResult
    {
        if ($credentialData === null || empty($credentialData['credential_type'])) {
            return CredentialInjectionResult::empty();
        }

        return $this->injectSingle($credentialData);
    }

    /**
     * Merge the injection results of multiple credentials.
     *
     * First-wins for single-slot arguments: once `--user` or
     * `--private-key` or `--vault-password-file` has been claimed by
     * one credential, subsequent credentials cannot override it.
     * Callers control the order by sorting upstream (the pivot's
     * sort_order field).
     *
     * Env vars: every credential may contribute its own, but if two
     * credentials target the same env var name the first wins and a
     * debug note is written to the Yii log.
     *
     * @param list<array{credential_type: string, username: string|null, env_var_name?: string|null, secrets: array<string, string>}> $credentials
     */
    public function injectAll(array $credentials): CredentialInjectionResult
    {
        $args = [];
        $env = [];
        $tempFiles = [];
        $slotsClaimed = [];

        foreach ($credentials as $data) {
            if (empty($data['credential_type'])) {
                continue;
            }
            $single = $this->injectSingle($data);

            // Merge args with single-slot guards.
            $filteredArgs = $this->filterSingleSlotArgs($single->args, $slotsClaimed);
            $args = array_merge($args, $filteredArgs);

            // Merge env vars first-wins; track duplicates for an operator-visible log.
            foreach ($single->env as $name => $value) {
                if (array_key_exists($name, $env)) {
                    $this->logWarning("CredentialInjector: env var '{$name}' claimed by more than one credential — first wins.");
                    continue;
                }
                $env[$name] = $value;
            }

            $tempFiles = array_merge($tempFiles, $single->tempFiles);
        }

        return new CredentialInjectionResult($args, $env, $tempFiles);
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
     * @param array{credential_type: string, username: string|null, env_var_name?: string|null, secrets: array<string, string>} $data
     */
    private function injectSingle(array $data): CredentialInjectionResult
    {
        return match ($data['credential_type']) {
            Credential::TYPE_SSH_KEY => $this->injectSshKey($data),
            Credential::TYPE_USERNAME_PASSWORD => $this->injectUsernamePassword($data),
            Credential::TYPE_VAULT => $this->injectVault($data),
            Credential::TYPE_TOKEN => $this->injectToken($data),
            default => CredentialInjectionResult::empty(),
        };
    }

    /**
     * Drop args that duplicate a single-slot ansible flag already seen.
     *
     * Tracked flags: --user, --private-key, --vault-password-file.
     * Values bound to such a flag are also dropped (we assume each flag
     * is immediately followed by its value, matching how Ansilume's
     * inject methods always emit them).
     *
     * @param string[] $args
     * @param array<string, true> $slotsClaimed
     * @return string[]
     */
    private function filterSingleSlotArgs(array $args, array &$slotsClaimed): array
    {
        $singleSlotFlags = ['--user', '--private-key', '--vault-password-file'];
        $out = [];
        $skipNext = false;
        foreach ($args as $arg) {
            if ($skipNext) {
                $skipNext = false;
                continue;
            }
            if (in_array($arg, $singleSlotFlags, true)) {
                if (isset($slotsClaimed[$arg])) {
                    $skipNext = true;
                    $this->logInfo("CredentialInjector: '{$arg}' already claimed — ignoring duplicate from lower-priority credential.");
                    continue;
                }
                $slotsClaimed[$arg] = true;
            }
            $out[] = $arg;
        }
        return $out;
    }

    /**
     * @param array{credential_type: string, username: string|null, env_var_name?: string|null, secrets: array<string, string>} $data
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
     * @param array{credential_type: string, username: string|null, env_var_name?: string|null, secrets: array<string, string>} $data
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
     * @param array{credential_type: string, username: string|null, env_var_name?: string|null, secrets: array<string, string>} $data
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
     * @param array{credential_type: string, username: string|null, env_var_name?: string|null, secrets: array<string, string>} $data
     */
    private function injectToken(array $data): CredentialInjectionResult
    {
        $token = $data['secrets']['token'] ?? '';
        if ($token === '') {
            return CredentialInjectionResult::empty();
        }

        $envName = trim((string)($data['env_var_name'] ?? ''));
        if ($envName === '') {
            $envName = Credential::DEFAULT_TOKEN_ENV_VAR;
        }

        return new CredentialInjectionResult([], [$envName => $token], []);
    }

    /**
     * Yii-aware info log that degrades to a no-op when the app container
     * is not bootstrapped (unit-test context).
     */
    private function logInfo(string $message): void
    {
        if (class_exists('\Yii', false) && \Yii::$app !== null) {
            \Yii::info($message, __CLASS__);
        }
    }

    /**
     * Yii-aware warning log that degrades to a no-op when the app
     * container is not bootstrapped (unit-test context).
     */
    private function logWarning(string $message): void
    {
        if (class_exists('\Yii', false) && \Yii::$app !== null) {
            \Yii::warning($message, __CLASS__);
        }
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
