<?php

declare(strict_types=1);

namespace app\components;

use app\models\Credential;
use app\models\JobTemplateVaultCheck;
use app\models\Project;
use app\models\ProjectVaultEntry;

/**
 * Decides a job template's vault check status (JobTemplateVaultCheck) from
 * the entries the template probably loads and its vault password:
 *
 * 1. nothing relevant: no_files, or incomplete when the scan stopped at a limit;
 * 2. no vault password: missing_password, or repo_managed when the repository
 *    supplies passwords; a password without a usable secret: unusable_password;
 * 3. an entry changed since the scan: stale; the time budget ran out: incomplete;
 * 4. entries the password does not open, or cannot be tried on because the
 *    repository's ansible.cfg sets vault_id_match: mismatch (repo_managed when
 *    the repository supplies passwords);
 * 5. damaged entries Ansible cannot read: damaged; file names Ansilume cannot
 *    read again: incomplete; a scan that stopped at a limit: incomplete;
 * 6. otherwise ok.
 *
 * @phpstan-type Listed array{path: string, line: int|null, key: string|null, reason: string|null}
 * @phpstan-type Outcome array{status: string, reason: string|null, entries: list<Listed>}
 */
final class VaultCheckOutcome
{
    /** The vault ID Ansible gives the template's password (--vault-password-file). */
    private const DEFAULT_VAULT_ID = 'default';

    /**
     * @param list<ProjectVaultEntry> $relevant
     * @param string|null $password the credential's usable password; null when there is none
     * @return Outcome
     */
    public static function decide(VaultCheckRun $run, Project $project, array $relevant, ?Credential $credential, ?string $password, string $root): array
    {
        if ($relevant === []) {
            return $project->vaultScanTruncated()
                ? self::incomplete(JobTemplateVaultCheck::REASON_SCAN_LIMIT, [])
                : self::result(JobTemplateVaultCheck::STATUS_NO_FILES, []);
        }
        if ($credential === null) {
            $status = $project->repositorySuppliesVaultPasswords() ? JobTemplateVaultCheck::STATUS_REPO_MANAGED : JobTemplateVaultCheck::STATUS_MISSING_PASSWORD;
            return self::result($status, []);
        }
        if ($password === null) {
            return self::result(JobTemplateVaultCheck::STATUS_UNUSABLE_PASSWORD, []);
        }
        try {
            return self::verify($run, $project, $relevant, self::secretKey($credential), $password, $root);
        } catch (VaultCheckTimeout) {
            return self::incomplete(JobTemplateVaultCheck::REASON_TIME_LIMIT, []);
        }
    }

    /**
     * @param list<ProjectVaultEntry> $relevant
     * @return Outcome
     * @throws VaultCheckTimeout
     */
    private static function verify(VaultCheckRun $run, Project $project, array $relevant, string $secretKey, string $password, string $root): array
    {
        $parts = self::partition($relevant, self::vaultIdMatchApplies($project));
        $unopened = $run->unopened($root, $parts['verify'], $secretKey, $password);
        if ($unopened === null) {
            return self::result(JobTemplateVaultCheck::STATUS_STALE, []);
        }
        $shut = array_merge(self::listed($unopened, null), self::listed($parts['label'], JobTemplateVaultCheck::ENTRY_VAULT_ID_MATCH));
        if ($shut !== []) {
            usort($shut, static fn (array $a, array $b): int => [$a['path'], (int)$a['line']] <=> [$b['path'], (int)$b['line']]);
            $status = $project->repositorySuppliesVaultPasswords() ? JobTemplateVaultCheck::STATUS_REPO_MANAGED : JobTemplateVaultCheck::STATUS_MISMATCH;
            return self::result($status, $shut);
        }

        return match (true) {
            $parts['damaged'] !== [] => self::result(JobTemplateVaultCheck::STATUS_DAMAGED, self::listed($parts['damaged'], null)),
            $parts['unnamed'] !== [] => self::incomplete(JobTemplateVaultCheck::REASON_FILE_NAME, self::listed($parts['unnamed'], null)),
            $project->vaultScanTruncated() => self::incomplete(JobTemplateVaultCheck::REASON_SCAN_LIMIT, []),
            default => self::result(JobTemplateVaultCheck::STATUS_OK, []),
        };
    }

    /**
     * Splits the entries: those to verify with the password, those
     * vault_id_match keeps it from (a vault ID other than 'default'), the
     * damaged ones and those whose file name Ansilume cannot read again.
     *
     * @param list<ProjectVaultEntry> $relevant
     * @return array{verify: list<ProjectVaultEntry>, label: list<ProjectVaultEntry>, damaged: list<ProjectVaultEntry>, unnamed: list<ProjectVaultEntry>}
     */
    private static function partition(array $relevant, bool $vaultIdMatch): array
    {
        $parts = ['verify' => [], 'label' => [], 'damaged' => [], 'unnamed' => []];
        foreach ($relevant as $entry) {
            if ($entry->error === ProjectVaultEntry::ERROR_NAME_NOT_UTF8) {
                $parts['unnamed'][] = $entry;
            } elseif ($entry->error !== null) {
                $parts['damaged'][] = $entry;
            } elseif ($vaultIdMatch && $entry->vault_id !== null && $entry->vault_id !== self::DEFAULT_VAULT_ID) {
                $parts['label'][] = $entry;
            } else {
                $parts['verify'][] = $entry;
            }
        }

        return $parts;
    }

    /**
     * Whether the repository's ansible.cfg turns vault_id_match on for jobs
     * and brings no passwords of its own: Ansible then tries the template's
     * password, labelled 'default', only on vaults without a vault ID or with
     * the ID 'default' (an empty ID matches nothing). 'Ansilume only' mode
     * turns it off on runners.
     */
    private static function vaultIdMatchApplies(Project $project): bool
    {
        $settings = $project->repositoryVaultSettings();

        return $settings !== null && $settings->idMatch && !$settings->definesPasswordSource();
    }

    /**
     * Identifies the password for the run's cache: the credential and its
     * stored (encrypted) secret, so a new secret is checked again. Never the
     * password itself.
     */
    private static function secretKey(Credential $credential): string
    {
        return $credential->id . ':' . md5((string)$credential->secret_data);
    }

    /**
     * @param list<ProjectVaultEntry> $entries
     * @return list<Listed>
     */
    private static function listed(array $entries, ?string $reason): array
    {
        return array_map(static fn (ProjectVaultEntry $entry): array => [
            'path' => $entry->path,
            'line' => $entry->line === null ? null : (int)$entry->line,
            'key' => $entry->var_key,
            'reason' => $reason,
        ], $entries);
    }

    /**
     * @param list<Listed> $entries
     * @return Outcome
     */
    private static function result(string $status, array $entries): array
    {
        return ['status' => $status, 'reason' => null, 'entries' => $entries];
    }

    /**
     * @param list<Listed> $entries
     * @return Outcome
     */
    private static function incomplete(string $reason, array $entries): array
    {
        return ['status' => JobTemplateVaultCheck::STATUS_INCOMPLETE, 'reason' => $reason, 'entries' => $entries];
    }
}
