<?php

declare(strict_types=1);

namespace app\components;

use app\models\Credential;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\JobTemplateVaultCheck;
use app\models\Project;
use yii\db\ActiveQuery;
use yii\db\Expression;
use yii\db\Query;

/**
 * Problems of job templates that jobs may run into. Such templates keep
 * running; the template page, the launch page, the template list and the
 * REST API show these warnings so operators can find and fix them.
 *
 * - multiple_vault_credentials: more than one vault password (saved before
 *   Ansilume rejected that); Ansible only ever gets the first one.
 * - inventory_other_project: a file or dynamic inventory of another project
 *   (saved before Ansilume rejected that), which the runner never reads.
 * - vault_password_mismatch: the template's vault password does not open
 *   encrypted files or values the template probably loads, or has no usable
 *   secret (from the project's last vault scan, checked without decrypting).
 * - vault_password_missing: the template probably loads encrypted files or
 *   values but has no vault password.
 * - vault_file_damaged: encrypted files or values the template probably
 *   loads are damaged; Ansible cannot read them, whatever the password.
 * - vault_check_incomplete: the vault check could not cover everything the
 *   template probably loads (a limit of the scan or the check, or file names
 *   that are not UTF-8); it says nothing about the files it did not reach.
 *
 * A vault check from an older scan than the project's last one counts as
 * stale and gives no vault warning until the template is checked again.
 */
final class JobTemplateWarnings
{
    public const MULTIPLE_VAULT_CREDENTIALS = 'multiple_vault_credentials';
    public const INVENTORY_OTHER_PROJECT = 'inventory_other_project';
    public const VAULT_PASSWORD_MISMATCH = 'vault_password_mismatch';
    public const VAULT_PASSWORD_MISSING = 'vault_password_missing';
    public const VAULT_FILE_DAMAGED = 'vault_file_damaged';
    public const VAULT_CHECK_INCOMPLETE = 'vault_check_incomplete';
    public const CODES = [
        self::MULTIPLE_VAULT_CREDENTIALS,
        self::INVENTORY_OTHER_PROJECT,
        self::VAULT_PASSWORD_MISMATCH,
        self::VAULT_PASSWORD_MISSING,
        self::VAULT_FILE_DAMAGED,
        self::VAULT_CHECK_INCOMPLETE,
    ];

    /** The vault check statuses behind each vault warning. */
    private const VAULT_CHECK_STATUSES = [
        self::VAULT_PASSWORD_MISMATCH => [JobTemplateVaultCheck::STATUS_MISMATCH, JobTemplateVaultCheck::STATUS_UNUSABLE_PASSWORD],
        self::VAULT_PASSWORD_MISSING => [JobTemplateVaultCheck::STATUS_MISSING_PASSWORD],
        self::VAULT_FILE_DAMAGED => [JobTemplateVaultCheck::STATUS_DAMAGED],
        self::VAULT_CHECK_INCOMPLETE => [JobTemplateVaultCheck::STATUS_INCOMPLETE],
    ];

    /** Unopened files a message names before "and N more". */
    private const NAMED_FILES = 3;

    /**
     * @return list<array{code: string, message: string, credential_ids: list<int>}>
     */
    public static function forTemplate(JobTemplate $template): array
    {
        return array_values(array_filter([
            self::multipleVaults($template),
            self::crossProjectInventory($template),
            self::vaultCheck($template),
        ]));
    }

    /**
     * @return array{code: string, message: string, credential_ids: list<int>}|null
     */
    private static function multipleVaults(JobTemplate $template): ?array
    {
        $vaults = VaultCredentialRule::vaults($template->credentialSnapshot());
        if (count($vaults) < 2) {
            return null;
        }
        $ignored = array_slice($vaults, 1);

        return [
            'code' => self::MULTIPLE_VAULT_CREDENTIALS,
            'message' => sprintf(
                'This template has %d vault passwords, but Ansible gets only one: %s takes precedence and %s %s ignored. '
                . 'Saving the template is rejected until only one is left.',
                count($vaults),
                VaultCredentialRule::names([$vaults[0]]),
                VaultCredentialRule::names($ignored),
                count($ignored) === 1 ? 'is' : 'are'
            ),
            'credential_ids' => array_column($ignored, 'id'),
        ];
    }

    /**
     * @return array{code: string, message: string, credential_ids: list<int>}|null
     */
    private static function crossProjectInventory(JobTemplate $template): ?array
    {
        if (!$template->hasCrossProjectInventory()) {
            return null;
        }

        return [
            'code' => self::INVENTORY_OTHER_PROJECT,
            'message' => sprintf(
                'The inventory "%s" is a file or dynamic inventory of another project. The runner checks out only this '
                . 'template\'s project and looks for the inventory there, so jobs do not use it. '
                . 'Switch to an inventory of this project or a static inventory.',
                (string)($template->inventory->name ?? '')
            ),
            'credential_ids' => [],
        ];
    }

    /**
     * @return array{code: string, message: string, credential_ids: list<int>}|null
     */
    private static function vaultCheck(JobTemplate $template): ?array
    {
        $check = $template->vaultCheck;
        $project = $template->project;
        $warning = $check === null || $project === null || !$check->isCurrentFor($project) ? null : self::vaultWarning($check, $project);
        if ($check === null || $warning === null) {
            return null;
        }

        return [
            'code' => $warning[0],
            'message' => $warning[1],
            'credential_ids' => $check->credential_id === null ? [] : [(int)$check->credential_id],
        ];
    }

    /**
     * @return array{0: string, 1: string}|null the code and message of the check's warning, if it has one
     */
    private static function vaultWarning(JobTemplateVaultCheck $check, Project $project): ?array
    {
        return match ($check->status) {
            JobTemplateVaultCheck::STATUS_MISMATCH => [self::VAULT_PASSWORD_MISMATCH, self::mismatchMessage($check, $project)],
            JobTemplateVaultCheck::STATUS_UNUSABLE_PASSWORD => [self::VAULT_PASSWORD_MISMATCH, sprintf(
                'The vault password %s has no usable secret, so jobs fail. Enter its secret on the credential page.',
                self::passwordName($check)
            )],
            JobTemplateVaultCheck::STATUS_MISSING_PASSWORD => [self::VAULT_PASSWORD_MISSING, sprintf(
                'This template has no vault password, but it probably loads %d encrypted files or values. '
                . 'Jobs fail when Ansible needs one of them. Attach the vault password of this environment.',
                (int)$check->relevant_count
            )],
            JobTemplateVaultCheck::STATUS_DAMAGED => [self::VAULT_FILE_DAMAGED, sprintf(
                'Ansible cannot read %d of the encrypted files or values this template probably loads, whatever the password: %s. '
                . 'Jobs fail when Ansible loads one of them. The vault card of the project says what is wrong; encrypt them again.',
                (int)$check->unopened_count,
                self::locations($check->unopenedEntries(), (int)$check->unopened_count)
            )],
            JobTemplateVaultCheck::STATUS_INCOMPLETE => [
                self::VAULT_CHECK_INCOMPLETE,
                'The vault check of this template is incomplete: ' . self::incompleteReason($check),
            ],
            default => null,
        };
    }

    private static function passwordName(JobTemplateVaultCheck $check): string
    {
        return $check->credential === null ? 'of this template' : '"' . $check->credential->name . '"';
    }

    private static function mismatchMessage(JobTemplateVaultCheck $check, Project $project): string
    {
        $entries = $check->unopenedEntries();
        $idMatch = in_array(JobTemplateVaultCheck::ENTRY_VAULT_ID_MATCH, array_column($entries, 'reason'), true);
        $remedy = $project->scm_type === Project::SCM_TYPE_MANUAL
            ? 'Check the password and the inventory; if the files changed, rescan the project.'
            : 'Check the password and the inventory; if the repository changed since the last sync, sync the project.';

        return sprintf(
            'The vault password %s does not open %d of the %d encrypted files or values this template probably loads: %s. '
            . 'Jobs fail when Ansible needs one of them. %s%s',
            self::passwordName($check),
            (int)$check->unopened_count,
            (int)$check->relevant_count,
            self::locations($entries, (int)$check->unopened_count),
            $idMatch
                ? 'The repository\'s ansible.cfg sets vault_id_match, so Ansible tries this password only on vaults without a vault ID or with the ID "default". '
                : '',
            $remedy
        );
    }

    private static function incompleteReason(JobTemplateVaultCheck $check): string
    {
        return match ($check->incomplete_reason) {
            JobTemplateVaultCheck::REASON_SCAN_LIMIT => 'the scan of the project stopped at a limit (too many files, encrypted values or symlinks, '
                . 'or out of time), so encrypted files this template loads may be missing from it.',
            JobTemplateVaultCheck::REASON_FILE_NAME => sprintf(
                'the names of %d encrypted files it probably loads are not valid UTF-8, so Ansilume cannot check them: %s.',
                (int)$check->unopened_count,
                self::locations($check->unopenedEntries(), (int)$check->unopened_count)
            ),
            default => 'the check ran out of time (the repository has many or large encrypted files). '
                . 'The next sync or rescan checks it again.',
        };
    }

    /**
     * "a, b, c and 2 more"
     *
     * @param list<array{path: string, line: int|null, key: string|null, reason: string|null}> $entries the stored (first) ones
     * @param int $total how many there are
     */
    private static function locations(array $entries, int $total): string
    {
        $named = array_map(
            static fn (array $entry): string => $entry['line'] === null ? $entry['path'] : $entry['path'] . ':' . $entry['line'],
            array_slice($entries, 0, self::NAMED_FILES)
        );
        $more = max($total, count($entries)) - count($named);

        return implode(', ', $named) . ($more > 0 ? " and {$more} more" : '');
    }

    /**
     * Restricts a job template query to templates with the given warning.
     * False for an unknown code; the query is then left unchanged.
     */
    public static function filter(ActiveQuery $query, string $code): bool
    {
        if ($code === self::MULTIPLE_VAULT_CREDENTIALS) {
            $query->andWhere(new Expression(
                '(SELECT COUNT(DISTINCT vc.id) FROM {{%credential}} vc WHERE vc.credential_type = :vault_type'
                . ' AND (vc.id = {{%job_template}}.credential_id OR vc.id IN'
                . ' (SELECT vp.credential_id FROM {{%job_template_credential}} vp WHERE vp.job_template_id = {{%job_template}}.id))) > 1',
                [':vault_type' => Credential::TYPE_VAULT]
            ));
            return true;
        }
        if ($code === self::INVENTORY_OTHER_PROJECT) {
            $query->innerJoin(['winv' => Inventory::tableName()], 'winv.id = {{%job_template}}.inventory_id')
                ->andWhere(['winv.inventory_type' => [Inventory::TYPE_FILE, Inventory::TYPE_DYNAMIC]])
                ->andWhere(['not', ['winv.project_id' => null]])
                ->andWhere(new Expression('winv.project_id <> {{%job_template}}.project_id'));
            return true;
        }
        if (isset(self::VAULT_CHECK_STATUSES[$code])) {
            // Only checks against the project's last scan; older ones are stale.
            $query->andWhere(['exists', (new Query())
                ->from(['wvc' => JobTemplateVaultCheck::tableName()])
                ->innerJoin(['wvp' => Project::tableName()], 'wvp.id = {{%job_template}}.project_id')
                ->where(new Expression('wvc.job_template_id = {{%job_template}}.id AND wvc.scanned_at = wvp.vault_scanned_at'))
                ->andWhere(['wvc.status' => self::VAULT_CHECK_STATUSES[$code]])]);
            return true;
        }

        return false;
    }

    /**
     * How many templates of the query have each warning.
     *
     * @return array<string, int> code => count
     */
    public static function counts(ActiveQuery $query): array
    {
        $counts = [];
        foreach (self::CODES as $code) {
            $filtered = clone $query;
            self::filter($filtered, $code);
            $counts[$code] = (int)$filtered->count();
        }

        return $counts;
    }

    public static function label(string $code): string
    {
        return match ($code) {
            self::MULTIPLE_VAULT_CREDENTIALS => 'more than one vault password',
            self::INVENTORY_OTHER_PROJECT => 'an inventory of another project',
            self::VAULT_PASSWORD_MISMATCH => 'a vault password that does not open their encrypted files',
            self::VAULT_PASSWORD_MISSING => 'encrypted files but no vault password',
            self::VAULT_FILE_DAMAGED => 'encrypted files Ansible cannot read',
            self::VAULT_CHECK_INCOMPLETE => 'a vault check that could not cover all encrypted files',
            default => $code,
        };
    }

    /**
     * The sentence of the summary on the template list.
     */
    public static function summary(string $code, int $count): string
    {
        $advice = $code === self::VAULT_CHECK_INCOMPLETE
            ? 'Their jobs are not affected; the check is repeated at the next sync or rescan.'
            : 'They keep running, but need fixing.';

        return $count . ' job template(s) have ' . self::label($code) . '. ' . $advice;
    }
}
