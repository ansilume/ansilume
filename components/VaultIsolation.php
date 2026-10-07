<?php

declare(strict_types=1);

namespace app\components;

use app\helpers\FileHelper;

/**
 * Neutralises the vault settings of a project's ansible.cfg.
 *
 * A project's ansible.cfg can name a vault password file or script, or a
 * vault identity list. Environment variables take precedence over
 * ansible.cfg, so these overrides point every vault setting at a random decoy
 * file and turn the vault password prompt off: Ansible tries the decoy as a
 * password and no repository script runs.
 *
 * - Server-side runs (inventory parsing, lint) never decrypt: the server
 *   would otherwise run the repository's script and the plaintext would end
 *   up in the inventory cache every viewer can read. Decryption fails.
 * - Playbook runs on runners use it for projects whose vault password source
 *   is 'ansilume' ({@see RunnerVaultMode}): the job template's vault password
 *   still arrives as --vault-password-file, which Ansible combines with the
 *   decoy, so vault files decrypt with Ansilume's password only.
 */
final class VaultIsolation
{
    public const ENV_PASSWORD_FILE = 'ANSIBLE_VAULT_PASSWORD_FILE';
    public const ENV_IDENTITY_LIST = 'ANSIBLE_VAULT_IDENTITY_LIST';
    public const ENV_ASK = 'ANSIBLE_ASK_VAULT_PASS';

    private const DECOY_PREFIX = 'ansilume_vault_decoy_';

    private string $directory;

    private ?string $decoy = null;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? sys_get_temp_dir();
    }

    /**
     * Environment overrides, to be applied after the ANSIBLE_* allowlist.
     *
     * vault_id_match is left alone: the setting is untyped, so only an empty
     * value means off, and proc_open() drops empty variables. On the server a
     * repository that turns matching on only keeps Ansible from trying the
     * decoy on files with another vault id; decryption fails either way.
     * Playbook runs clear it with an env(1) prefix ({@see RunnerVaultMode}).
     *
     * @return array<string, string>
     * @throws \RuntimeException when the decoy cannot be created; callers must
     *         not run Ansible without isolation
     */
    public function overrides(): array
    {
        $decoy = $this->decoy ??= $this->createDecoy();

        return [
            self::ENV_PASSWORD_FILE => $decoy,
            self::ENV_IDENTITY_LIST => $decoy,
            self::ENV_ASK => 'False',
        ];
    }

    /**
     * Removes the decoy. Safe to call more than once.
     */
    public function cleanup(): void
    {
        if ($this->decoy !== null) {
            FileHelper::safeUnlink($this->decoy);
            $this->decoy = null;
        }
    }

    /**
     * Whether Ansible output reports vault content it could not decrypt.
     * Colour codes and OSC 8 hyperlinks (ansible-lint --force-color) are
     * stripped first, and \s+ also matches the line breaks rich inserts.
     */
    public static function mentionsDecryptionFailure(string $output): bool
    {
        $plain = (string)preg_replace(
            ['/\e\]8;[^\e\x07]*(?:\e\\\\|\x07)/', '/\e\[[0-9;?]*[ -\/]*[@-~]/'],
            '',
            $output
        );

        return preg_match(
            '/Attempting\s+to\s+decrypt\s+but\s+no\s+vault\s+secrets|Decryption\s+failed\s+(\(no\s+vault\s+secrets|on\s+\S)/i',
            $plain
        ) === 1;
    }

    private function createDecoy(): string
    {
        $path = is_dir($this->directory) && is_writable($this->directory)
            ? tempnam($this->directory, self::DECOY_PREFIX)
            : false;
        if ($path === false) {
            throw new \RuntimeException('Could not prepare vault isolation; refusing to run Ansible without it.');
        }

        if (!chmod($path, 0600) || file_put_contents($path, bin2hex(random_bytes(32))) === false) {
            FileHelper::safeUnlink($path);
            throw new \RuntimeException('Could not prepare vault isolation; refusing to run Ansible without it.');
        }

        return $path;
    }
}
