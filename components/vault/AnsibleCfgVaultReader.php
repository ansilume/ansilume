<?php

declare(strict_types=1);

namespace app\components\vault;

/**
 * Reads the vault settings of <root>/ansible.cfg, the file Ansible uses when
 * it runs in the checkout: runners start ansible-playbook there.
 *
 * Values are interpreted like Ansible's config manager does for an ini file:
 * ask_vault_pass is a boolean (y, yes, on, 1, true, t, case-insensitive; a
 * quoted "True" is false), vault_id_match and vault_encrypt_salt are untyped
 * strings with one pair of quotes removed, so any non-empty value counts.
 * [DEFAULT] values apply to [defaults], as in configparser.
 */
final class AnsibleCfgVaultReader
{
    private const FILE = 'ansible.cfg';

    private const MAX_BYTES = 1000000;

    private const TRUE_VALUES = ['y', 'yes', 'on', '1', 'true', 't'];

    /**
     * A missing, unreadable or oversized file, or one that is a symlink out of
     * the checkout, means no settings.
     */
    public static function read(string $root): AnsibleCfgVaultSettings
    {
        $values = self::defaults((string)self::load($root));

        return new AnsibleCfgVaultSettings(
            self::nonEmpty($values['vault_password_file'] ?? ''),
            self::nonEmpty($values['vault_identity_list'] ?? ''),
            in_array(strtolower($values['ask_vault_pass'] ?? ''), self::TRUE_VALUES, true),
            AnsibleCfgParser::unquote($values['vault_id_match'] ?? '') !== '',
            AnsibleCfgParser::unquote($values['vault_encrypt_salt'] ?? '') !== '',
        );
    }

    private static function load(string $root): ?string
    {
        $path = $root . '/' . self::FILE;
        $inside = RepoPath::realRelative($root, $path) !== null;
        if (!$inside || !is_file($path) || !is_readable($path) || filesize($path) > self::MAX_BYTES) {
            return null;
        }
        $text = file_get_contents($path);

        return $text === false ? null : $text;
    }

    /**
     * @return array<string, string>
     */
    private static function defaults(string $text): array
    {
        $sections = AnsibleCfgParser::parse($text);
        if (!isset($sections['defaults'])) {
            return [];
        }

        return $sections['defaults'] + ($sections['DEFAULT'] ?? []);
    }

    private static function nonEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
