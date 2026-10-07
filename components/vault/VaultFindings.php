<?php

declare(strict_types=1);

namespace app\components\vault;

/**
 * What an operator should know about a checkout's vault setup: password
 * sources committed to the repository, ansible.cfg settings that break or
 * weaken jobs when runners apply the repository's settings ('Ansilume and
 * repo' mode), and vault content Ansible cannot read.
 */
final class VaultFindings
{
    public const PASSWORD_FILE_COMMITTED = 'password_file_committed';
    public const PASSWORD_SCRIPT = 'password_script';
    public const ASK_VAULT_PASS = 'ask_vault_pass';
    public const VAULT_ID_MATCH = 'vault_id_match';
    public const ENCRYPT_SALT = 'encrypt_salt';
    public const MALFORMED = 'malformed';
    public const TRUNCATED = 'truncated';

    /** Malformed entries named one by one; the rest are counted in one finding. */
    public const MAX_MALFORMED = 20;

    /** File names a plaintext vault password commonly gets, in the repository root. */
    private const PASSWORD_FILE_NAMES = [
        '.vault_pass',
        '.vault_password',
        'vault_pass.txt',
        'vault_password.txt',
        '.vault-password',
    ];

    /** vault_identity_list sources that make Ansible ask for the password. */
    private const PROMPTS = ['prompt', 'prompt_ask_vault_pass'];

    private const CONFIG_FILE = 'ansible.cfg';

    private const MESSAGES = [
        self::PASSWORD_FILE_COMMITTED => 'A plaintext vault password is committed to the repository.',
        self::PASSWORD_SCRIPT => "This vault password script runs on runners in 'Ansilume and repository' mode.",
        self::ASK_VAULT_PASS => "Jobs fail in 'Ansilume and repository' mode: ansible.cfg asks for a vault password"
            . ' and runners have no prompt.',
        self::VAULT_ID_MATCH => 'vault_id_match is on (any value, even False): Ansible uses a password only for'
            . " files with the same vault ID, and in 'Ansilume and repository' mode Ansilume's password counts as 'default'.",
        self::ENCRYPT_SALT => 'vault_encrypt_salt is set: files encrypted with the same password reuse key and IV.',
        self::TRUNCATED => 'The scan stopped at a limit; vault content beyond it is not listed.',
    ];

    /**
     * Password sources come from vault_password_file and from every
     * vault_identity_list entry (`label@source`); a source counts only when it
     * resolves to a regular file inside the checkout. Findings are ordered:
     * password sources, settings, malformed entries, truncation.
     *
     * @return list<array{code: string, path: string|null, message: string}> path relative to the checkout root
     */
    public static function collect(string $root, AnsibleCfgVaultSettings $cfg, VaultScanResult $scan): array
    {
        return array_merge(
            self::passwordSourceFindings($root, $cfg),
            self::settingFindings($cfg),
            self::scanFindings($scan),
        );
    }

    /**
     * @return list<array{code: string, path: string, message: string}>
     */
    private static function passwordSourceFindings(string $root, AnsibleCfgVaultSettings $cfg): array
    {
        $findings = [];
        foreach (self::configuredSources($cfg) as $source) {
            $finding = self::sourceFinding($root, $source);
            if ($finding !== null) {
                $findings[$finding['path']] = $finding;
            }
        }
        // A well-known name only says "plaintext password" when it is no script.
        foreach (self::PASSWORD_FILE_NAMES as $name) {
            $finding = self::sourceFinding($root, $name);
            if ($finding !== null && $finding['code'] === self::PASSWORD_FILE_COMMITTED) {
                $findings[$finding['path']] ??= $finding;
            }
        }

        return array_values($findings);
    }

    /**
     * @return list<string>
     */
    private static function configuredSources(AnsibleCfgVaultSettings $cfg): array
    {
        $sources = $cfg->passwordFile === null ? [] : [$cfg->passwordFile];
        foreach (self::identitySources($cfg) as $source) {
            if (!in_array($source, self::PROMPTS, true)) {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    /**
     * The sources of vault_identity_list, a comma-separated list of
     * `label@source` or `source` entries.
     *
     * @return list<string>
     */
    private static function identitySources(AnsibleCfgVaultSettings $cfg): array
    {
        $sources = [];
        foreach (explode(',', (string)$cfg->identityList) as $item) {
            $item = AnsibleCfgParser::unquote(trim($item));
            $at = strpos($item, '@');
            $source = $at === false ? $item : substr($item, $at + 1);
            if ($source !== '') {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    /**
     * @return array{code: string, path: string, message: string}|null
     */
    private static function sourceFinding(string $root, string $source): ?array
    {
        // Relative sources resolve against the directory of ansible.cfg, the
        // checkout root; Ansible replaces {{CWD}} with the runner's working
        // directory, which is the checkout root as well.
        $path = str_replace('{{CWD}}', '.', $source);
        $relative = RepoPath::realRelative($root, str_starts_with($path, '/') ? $path : $root . '/' . $path);
        if ($relative === null || !is_file($root . '/' . $relative)) {
            return null;
        }
        // Ansible runs a password file with any execute bit set.
        $executable = ((int)fileperms($root . '/' . $relative) & 0111) !== 0;
        $code = $executable ? self::PASSWORD_SCRIPT : self::PASSWORD_FILE_COMMITTED;

        return ['code' => $code, 'path' => $relative, 'message' => self::MESSAGES[$code]];
    }

    /**
     * @return list<array{code: string, path: string|null, message: string}>
     */
    private static function settingFindings(AnsibleCfgVaultSettings $cfg): array
    {
        $prompts = array_intersect(self::identitySources($cfg), self::PROMPTS) !== [];
        $codes = array_keys(array_filter([
            self::ASK_VAULT_PASS => $cfg->askVaultPass || $prompts,
            self::VAULT_ID_MATCH => $cfg->idMatch,
            self::ENCRYPT_SALT => $cfg->encryptSalt,
        ]));

        return array_map(
            static fn (string $code): array => self::finding($code, self::CONFIG_FILE, self::MESSAGES[$code]),
            $codes
        );
    }

    /**
     * @return list<array{code: string, path: string|null, message: string}>
     */
    private static function scanFindings(VaultScanResult $scan): array
    {
        $malformed = array_values(array_filter($scan->entries, static fn (VaultScanEntry $entry): bool => $entry->error !== null));
        $findings = array_map(
            static fn (VaultScanEntry $entry): array => self::finding(self::MALFORMED, $entry->path, (string)$entry->error),
            array_slice($malformed, 0, self::MAX_MALFORMED)
        );
        // A bounded summary: the entry list names every unreadable file.
        $more = count($malformed) - self::MAX_MALFORMED;
        if ($more > 0) {
            $findings[] = self::finding(self::MALFORMED, null, "{$more} more encrypted files or values Ansible cannot read; the entry list names them.");
        }
        if ($scan->truncated) {
            $findings[] = self::finding(self::TRUNCATED, null, self::MESSAGES[self::TRUNCATED]);
        }

        return $findings;
    }

    /**
     * @return array{code: string, path: string|null, message: string}
     */
    private static function finding(string $code, ?string $path, string $message): array
    {
        return ['code' => $code, 'path' => $path, 'message' => $message];
    }
}
