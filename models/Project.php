<?php

declare(strict_types=1);

namespace app\models;

use app\components\vault\AnsibleCfgVaultSettings;
use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property string      $scm_type
 * @property string|null $scm_url
 * @property string      $scm_branch
 * @property string|null $local_path
 * @property int|null    $scm_credential_id
 * @property string      $status
 * @property int|null    $last_synced_at
 * @property int|null    $sync_started_at  UNIX timestamp when status last flipped to SYNCING
 * @property string|null $last_sync_error
 * @property string|null $last_sync_event
 * @property string|null $lint_output       Last ansible-lint output (full project)
 * @property int|null    $lint_at           Unix timestamp of last project lint run
 * @property int|null    $lint_exit_code    Exit code of last project lint run (0 = clean)
 * @property string      $vault_password_source 'ansilume' or 'repository', see VAULT_SOURCE_*
 * @property int|null    $vault_scanned_at  Unix timestamp of the last vault scan
 * @property string|null $vault_scan_commit Commit the last vault scan saw
 * @property string|null $vault_scan_summary JSON: ansible.cfg vault settings, findings, limits
 * @property string|null $vault_scan_error  Why the last vault scan could not run
 * @property int         $created_by
 * @property int         $created_at
 * @property int         $updated_at
 *
 * @property User            $creator
 * @property Credential|null $scmCredential
 * @property JobTemplate[]   $jobTemplates
 * @property Inventory[]     $inventories
 * @property ProjectVaultEntry[] $vaultEntries
 */
class Project extends ActiveRecord
{
    public const STATUS_NEW = 'new';
    public const STATUS_SYNCING = 'syncing';
    public const STATUS_SYNCED = 'synced';
    public const STATUS_ERROR = 'error';

    public const SCM_TYPE_GIT = 'git';
    public const SCM_TYPE_MANUAL = 'manual';

    /** Runners use only Ansilume's vault password; the repository's ansible.cfg vault settings are neutralised. */
    public const VAULT_SOURCE_ANSILUME = 'ansilume';
    /** Runners also apply the repository's ansible.cfg vault settings (the behaviour before 2.8). */
    public const VAULT_SOURCE_REPOSITORY = 'repository';
    public const VAULT_SOURCES = [self::VAULT_SOURCE_ANSILUME, self::VAULT_SOURCE_REPOSITORY];

    public static function tableName(): string
    {
        return '{{%project}}';
    }

    public function behaviors(): array
    {
        return [\yii\behaviors\TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['name', 'scm_type'], 'required'],
            [['name'], 'string', 'max' => 128],
            [['description'], 'string', 'max' => 1000],
            [['scm_type'], 'in', 'range' => [self::SCM_TYPE_GIT, self::SCM_TYPE_MANUAL]],
            [['scm_url'], 'validateScmUrl', 'when' => fn ($m) => $m->scm_type === self::SCM_TYPE_GIT],
            [['scm_url', 'local_path'], 'string', 'max' => 512],
            [['scm_branch'], 'string', 'max' => 128],
            [['scm_credential_id'], 'integer'],
            [['scm_credential_id'], 'exist', 'skipOnError' => true, 'targetClass' => Credential::class, 'targetAttribute' => ['scm_credential_id' => 'id']],
            [['scm_credential_id'], 'validateScmCredentialType'],
            [['status'], 'in', 'range' => [self::STATUS_NEW, self::STATUS_SYNCING, self::STATUS_SYNCED, self::STATUS_ERROR]],
            // New projects only: an empty value on update is an error, never a silent switch.
            [['vault_password_source'], 'default', 'value' => self::VAULT_SOURCE_ANSILUME, 'when' => fn (self $m): bool => $m->isNewRecord],
            [['vault_password_source'], 'required'],
            [['vault_password_source'], 'in', 'range' => self::VAULT_SOURCES],
        ];
    }

    public static function vaultSourceLabel(string $source): string
    {
        return match ($source) {
            self::VAULT_SOURCE_ANSILUME => 'Ansilume only',
            self::VAULT_SOURCE_REPOSITORY => 'Ansilume and repository',
            default => $source,
        };
    }

    /**
     * The vault settings of the repository's ansible.cfg that jobs get, as the
     * last vault scan read them: in 'Ansilume and repository' mode only; null
     * in 'Ansilume only' mode, where runners neutralise them.
     */
    public function repositoryVaultSettings(): ?AnsibleCfgVaultSettings
    {
        if ($this->vault_password_source !== self::VAULT_SOURCE_REPOSITORY) {
            return null;
        }
        $cfg = $this->vaultScanSummary()['cfg'] ?? null;

        return AnsibleCfgVaultSettings::fromArray(is_array($cfg) ? $cfg : []);
    }

    /**
     * True in 'Ansilume and repository' mode when the last vault scan found a
     * vault_password_file or vault_identity_list in the repository's
     * ansible.cfg: runners then get passwords Ansilume cannot see or check.
     */
    public function repositorySuppliesVaultPasswords(): bool
    {
        return $this->repositoryVaultSettings()?->definesPasswordSource() ?? false;
    }

    /**
     * Whether the last vault scan stopped at a limit, so it may have missed
     * vault content.
     */
    public function vaultScanTruncated(): bool
    {
        return ($this->vaultScanSummary()['truncated'] ?? false) === true;
    }

    /**
     * @return array<mixed> the decoded vault_scan_summary, [] when there is none
     */
    private function vaultScanSummary(): array
    {
        $summary = json_decode((string)$this->vault_scan_summary, true);

        return is_array($summary) ? $summary : [];
    }

    public function getVaultEntries(): \yii\db\ActiveQuery
    {
        return $this->hasMany(ProjectVaultEntry::class, ['project_id' => 'id'])->orderBy(['path' => SORT_ASC, 'line' => SORT_ASC]);
    }

    public function getScmCredential(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Credential::class, ['id' => 'scm_credential_id']);
    }

    public function getCreator(): \yii\db\ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    public function getJobTemplates(): \yii\db\ActiveQuery
    {
        return $this->hasMany(JobTemplate::class, ['project_id' => 'id']);
    }

    public function getInventories(): \yii\db\ActiveQuery
    {
        return $this->hasMany(Inventory::class, ['project_id' => 'id']);
    }

    public function isHttpsScmUrl(): bool
    {
        return (bool)preg_match('#^https?://#i', (string)$this->scm_url);
    }

    public function isSshScmUrl(): bool
    {
        $url = (string)$this->scm_url;
        return (bool)(
            preg_match('#^(git|ssh)@[\w.\-]+:.+#i', $url)
            || preg_match('#^ssh://[\w.\-@]+/.+#i', $url)
        );
    }

    /**
     * Validate that the credential type is compatible with the SCM URL scheme.
     * SSH URLs require ssh_key credentials; HTTPS URLs require token or username_password.
     */
    public function validateScmCredentialType(): void
    {
        if ($this->scm_credential_id === null || empty($this->scm_url)) {
            return;
        }

        $credential = Credential::findOne($this->scm_credential_id);
        if ($credential === null) {
            return;
        }

        $sshTypes = [Credential::TYPE_SSH_KEY];
        $httpsTypes = [Credential::TYPE_TOKEN, Credential::TYPE_USERNAME_PASSWORD];

        if ($this->isSshScmUrl() && !in_array($credential->credential_type, $sshTypes, true)) {
            $this->addError('scm_credential_id', 'SSH URLs require an SSH Key credential.');
        }

        if ($this->isHttpsScmUrl() && !in_array($credential->credential_type, $httpsTypes, true)) {
            $this->addError('scm_credential_id', 'HTTPS URLs require a Token or Username/Password credential.');
        }
    }

    public function validateScmUrl(): void
    {
        $url = $this->scm_url;
        if (empty($url)) {
            return; // required rule handles empty
        }
        // Accept HTTP/HTTPS URLs
        if (preg_match('#^https?://.+#i', $url)) {
            return;
        }
        // Accept SSH git URLs: git@host:path.git or ssh://git@host/path
        if (preg_match('#^(git|ssh)@[\w.\-]+(:\d+)?:[\w.\-/~@]+$#i', $url)) {
            return;
        }
        if (preg_match('#^ssh://[\w.\-@]+(:\d+)?/[\w.\-/~@]+$#i', $url)) {
            return;
        }
        $this->addError('scm_url', 'SCM URL must be a valid HTTPS URL (https://…) or SSH URL (git@host:org/repo.git).');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_NEW => 'New',
            self::STATUS_SYNCING => 'Syncing',
            self::STATUS_SYNCED => 'Synced',
            self::STATUS_ERROR => 'Error',
            default => $status,
        };
    }
}
