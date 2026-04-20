<?php

declare(strict_types=1);

namespace app\components;

/**
 * Build the environment variable set the runner needs for a `git clone`
 * or `git pull` subprocess, including SSH-key materialisation and HTTPS
 * credential-helper injection.
 *
 * Extracted from RunnerController so the ~100-LOC credential-handling
 * logic lives in one focused unit rather than bloating the CLI command.
 * The server-side ProjectService does the equivalent work through a
 * different shape (ActiveRecord + CredentialService); this class takes
 * the runner-payload shape (already-decrypted secret arrays) so the
 * runner doesn't need DB access.
 *
 * Security: private keys are written to a 0600 temp file, and the
 * tempfile path is returned via an out-parameter so the caller can
 * unlink it in a `finally` block after the git command completes.
 */
final class GitEnvBuilder
{
    /**
     * @param array{credential_type: string, username: string|null, env_var_name?: string|null, secrets: array<string, string>}|null $scmCredential
     * @param string|null $sshKeyFile Filled in with the temp path when an SSH key was materialised.
     * @return array<string, string>
     */
    public function build(string $scmUrl, ?array $scmCredential, ?string &$sshKeyFile): array
    {
        $env = $this->baseEnv();

        if ($scmCredential === null || $scmUrl === '') {
            return $env;
        }

        if ($this->isSshUrl($scmUrl)) {
            $keyFile = $this->writeSshKeyFile($scmCredential);
            if ($keyFile !== null) {
                $sshKeyFile = $keyFile;
                $env['GIT_SSH_COMMAND'] = 'ssh -i ' . escapeshellarg($keyFile)
                    . ' -o StrictHostKeyChecking=no'
                    . ' -o UserKnownHostsFile=/dev/null'
                    . ' -o BatchMode=yes';
            }
        } elseif ($this->isHttpsUrl($scmUrl)) {
            $this->applyHttpsCredentialEnv($scmCredential, $env);
        }

        return $env;
    }

    /**
     * @return array<string, string>
     */
    private function baseEnv(): array
    {
        return [
            'HOME' => getenv('HOME') ?: '/root',
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'safe.directory',
            'GIT_CONFIG_VALUE_0' => '*',
        ];
    }

    /**
     * SSH URL detection (scp-style `git@host:path` or `ssh://`).
     */
    public function isSshUrl(string $url): bool
    {
        return str_starts_with($url, 'ssh://')
            || (str_contains($url, '@') && str_contains($url, ':') && !str_starts_with($url, 'http'));
    }

    public function isHttpsUrl(string $url): bool
    {
        return str_starts_with($url, 'https://') || str_starts_with($url, 'http://');
    }

    /**
     * @param array{credential_type: string, username: string|null, env_var_name?: string|null, secrets: array<string, string>} $scmCredential
     */
    private function writeSshKeyFile(array $scmCredential): ?string
    {
        if ($scmCredential['credential_type'] !== 'ssh_key') {
            return null;
        }
        $key = $scmCredential['secrets']['private_key'] ?? '';
        if ($key === '') {
            return null;
        }

        $oldUmask = umask(0o177); // create files as 0600
        $keyFile = tempnam(sys_get_temp_dir(), 'ansilume_ssh_');
        file_put_contents($keyFile, $key);
        umask($oldUmask);
        return $keyFile;
    }

    /**
     * @param array{credential_type: string, username: string|null, env_var_name?: string|null, secrets: array<string, string>} $scmCredential
     * @param array<string, string> $env
     */
    private function applyHttpsCredentialEnv(array $scmCredential, array &$env): void
    {
        $username = '';
        $password = '';
        if ($scmCredential['credential_type'] === 'token') {
            $username = !empty($scmCredential['username']) ? (string)$scmCredential['username'] : 'x-access-token';
            $password = $scmCredential['secrets']['token'] ?? '';
        } elseif ($scmCredential['credential_type'] === 'username_password') {
            $username = (string)($scmCredential['username'] ?? '');
            $password = $scmCredential['secrets']['password'] ?? '';
        }
        if ($username === '' || $password === '') {
            return;
        }
        $count = (int)($env['GIT_CONFIG_COUNT'] ?? '0');
        $env['GIT_CONFIG_KEY_' . $count] = 'credential.helper';
        $env['GIT_CONFIG_VALUE_' . $count] = $this->buildCredentialHelperScript($username, $password);
        $env['GIT_CONFIG_COUNT'] = (string)($count + 1);
    }

    private function buildCredentialHelperScript(string $user, string $secret): string
    {
        $safeUser = escapeshellarg('username=' . $user);
        $safePass = escapeshellarg('password=' . $secret); // noqa: not a hardcoded secret
        return '!f() { printf "%s\n" ' . $safeUser . ' ' . $safePass . '; }; f';
    }
}
