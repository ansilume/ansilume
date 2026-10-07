<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;
use yii\db\Query;

/**
 * Whether a job template's vault password opens the encrypted files the
 * template probably loads, checked without decrypting (vault envelope HMAC).
 * Informational: jobs are never blocked by it.
 *
 * @property int         $job_template_id
 * @property string      $status         see STATUS_*
 * @property int|null    $credential_id  the vault password that was checked
 * @property int         $relevant_count encrypted files and values the template probably loads
 * @property int         $unopened_count how many entries are behind the status: unopened, or damaged
 * @property string|null $unopened       JSON list of the first of them, {path, line, key, reason}
 * @property string|null $incomplete_reason see REASON_*, for STATUS_INCOMPLETE
 * @property int         $checked_at
 * @property int|null    $scanned_at     the project scan the check used
 *
 * @property JobTemplate      $jobTemplate
 * @property Credential|null  $credential
 */
class JobTemplateVaultCheck extends ActiveRecord
{
    /** The vault password opens every relevant entry. */
    public const STATUS_OK = 'ok';
    /** The vault password does not open at least one relevant entry. */
    public const STATUS_MISMATCH = 'mismatch';
    /** Relevant entries exist, but the template has no vault password. */
    public const STATUS_MISSING_PASSWORD = 'missing_password';
    /** The template's vault password has no usable secret. */
    public const STATUS_UNUSABLE_PASSWORD = 'unusable_password';
    /** The repository's ansible.cfg supplies vault passwords Ansilume cannot check. */
    public const STATUS_REPO_MANAGED = 'repo_managed';
    /** The template probably loads no encrypted content. */
    public const STATUS_NO_FILES = 'no_files';
    /** The checkout changed since the last scan; rescan to check again. */
    public const STATUS_STALE = 'stale';
    /**
     * Encrypted files or values the template probably loads are damaged:
     * Ansible cannot read them, whatever the password (the entries are listed).
     */
    public const STATUS_DAMAGED = 'damaged';
    /** Not everything the template probably loads could be checked, see incomplete_reason. */
    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUSES = [
        self::STATUS_OK,
        self::STATUS_MISMATCH,
        self::STATUS_MISSING_PASSWORD,
        self::STATUS_UNUSABLE_PASSWORD,
        self::STATUS_REPO_MANAGED,
        self::STATUS_NO_FILES,
        self::STATUS_STALE,
        self::STATUS_DAMAGED,
        self::STATUS_INCOMPLETE,
    ];

    /** The scan stopped at a limit (files, entries, time or symlinks), so it may have missed files. */
    public const REASON_SCAN_LIMIT = 'scan_limit';
    /** The check ran out of time; the repository has many or large encrypted files. */
    public const REASON_TIME_LIMIT = 'time_limit';
    /** A file name is not valid UTF-8, so Ansilume cannot read the file again to check it. */
    public const REASON_FILE_NAME = 'file_name';

    public const REASONS = [self::REASON_SCAN_LIMIT, self::REASON_TIME_LIMIT, self::REASON_FILE_NAME];

    /**
     * Why an unopened entry stays shut although the password might fit: the
     * repository's ansible.cfg sets vault_id_match, so Ansible tries the
     * password ('default') only on vaults without a vault ID or with the ID
     * 'default'. Null in an entry: the password does not open it.
     */
    public const ENTRY_VAULT_ID_MATCH = 'vault_id_match';

    public static function tableName(): string
    {
        return '{{%job_template_vault_check}}';
    }

    /**
     * The order in which a run of checks takes the templates: first those
     * without a current check (never checked, checked against an older scan,
     * or cut short by the time budget), then the longest unchecked. A run
     * that runs out of time so gets further the next time. Ids of templates
     * that do not exist are dropped.
     *
     * @param list<int> $templateIds
     * @return list<int>
     */
    public static function stalestFirst(array $templateIds): array
    {
        $rows = (new Query())
            ->select(['id' => 't.id', 'checked_at' => 'c.checked_at', 'reason' => 'c.incomplete_reason', 'scanned_at' => 'c.scanned_at', 'project_scanned_at' => 'p.vault_scanned_at'])
            ->from(['t' => JobTemplate::tableName()])
            ->leftJoin(['c' => self::tableName()], 'c.job_template_id = t.id')
            ->leftJoin(['p' => Project::tableName()], 'p.id = t.project_id')
            ->where(['t.id' => $templateIds])
            ->all();
        $ranks = [];
        foreach ($rows as $row) {
            $checkedAt = self::intOrNull($row['checked_at']);
            $scannedAt = self::intOrNull($row['scanned_at']);
            $current = $checkedAt !== null && $row['reason'] !== self::REASON_TIME_LIMIT
                && $scannedAt !== null && $scannedAt === self::intOrNull($row['project_scanned_at']);
            $ranks[(int)self::intOrNull($row['id'])] = [$current ? 1 : 0, (int)$checkedAt];
        }
        $ordered = array_values(array_filter($templateIds, static fn (int $id): bool => isset($ranks[$id])));
        usort($ordered, static fn (int $a, int $b): int => $ranks[$a] <=> $ranks[$b]);

        return $ordered;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int)$value : null;
    }

    public function getJobTemplate(): \yii\db\ActiveQuery
    {
        return $this->hasOne(JobTemplate::class, ['id' => 'job_template_id']);
    }

    public function getCredential(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Credential::class, ['id' => 'credential_id']);
    }

    /**
     * The stored entries behind the status: unopened for STATUS_MISMATCH and
     * STATUS_REPO_MANAGED, damaged for STATUS_DAMAGED, unreadable file names
     * for STATUS_INCOMPLETE. reason is ENTRY_VAULT_ID_MATCH or null.
     *
     * @return list<array{path: string, line: int|null, key: string|null, reason: string|null}>
     */
    public function unopenedEntries(): array
    {
        $decoded = json_decode((string)$this->unopened, true);
        if (!is_array($decoded)) {
            return [];
        }
        $entries = [];
        foreach ($decoded as $entry) {
            if (is_array($entry) && is_string($entry['path'] ?? null)) {
                $entries[] = [
                    'path' => $entry['path'],
                    'line' => is_int($entry['line'] ?? null) ? $entry['line'] : null,
                    'key' => is_string($entry['key'] ?? null) ? $entry['key'] : null,
                    'reason' => is_string($entry['reason'] ?? null) ? $entry['reason'] : null,
                ];
            }
        }

        return $entries;
    }

    /**
     * Whether the check used the project's last scan; a check from an older
     * scan (cut short, or racing a rescan) counts as STATUS_STALE.
     */
    public function isCurrentFor(Project $project): bool
    {
        return $project->vault_scanned_at !== null && (int)$this->scanned_at === (int)$project->vault_scanned_at;
    }

    /**
     * The status to show: STATUS_STALE when the check is from an older scan.
     */
    public function effectiveStatus(Project $project): string
    {
        return $this->isCurrentFor($project) ? (string)$this->status : self::STATUS_STALE;
    }
}
