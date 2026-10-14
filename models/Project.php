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

    /**
     * The Git fields: what syncs of a git project read. The project form
     * shows them (#git-fields) only while Git is chosen, and sends them
     * whatever the type. What they mean to git (the URL, the branch format,
     * the credential type) is checked for git projects only; the rules the
     * database needs (the column limits, a credential ID that is an integer
     * of an existing credential) hold for every project. In the browser
     * every check of a Git field runs only while Git is chosen, and the form
     * shows the server's errors of Git fields it hides above itself
     * (gitFieldErrors(), GIT_FIELDS_REFUSED).
     */
    public const GIT_FIELDS = ['scm_url', 'scm_branch', 'scm_credential_id'];

    /**
     * The field error of the Credential field of a git project whose SSH URL
     * (git@host:path or ssh://host/path) comes with a credential that is no
     * SSH Key credential, in the form and in the API's 422. A manual
     * project's URL and credential are not checked: no sync reads them.
     */
    public const SSH_URL_NEEDS_SSH_KEY = 'SSH URLs require an SSH Key credential.';

    /**
     * The field error of the Credential field of a git project whose HTTPS
     * URL comes with a credential that is neither a Token nor a
     * Username/Password credential, in the form and in the API's 422. Not
     * checked for a manual project.
     */
    public const HTTPS_URL_NEEDS_TOKEN_OR_PASSWORD = 'HTTPS URLs require a Token or Username/Password credential.';

    /**
     * Above the project form, before the errors of its Git fields, while the
     * form hides those fields, as it does for every type but Git: the rules
     * the database needs refused one of them, such as a credential deleted
     * while the form was open, or a value of a crafted request.
     */
    public const GIT_FIELDS_REFUSED = 'Not saved: the hidden Git fields have errors. Choose Git under Scm Type to see and correct them, then switch back and save.';

    /**
     * The field error of a git project's branch that starts with "-" or holds
     * whitespace, in the form and in the API's 422. Up to 2.10.1 git read a
     * branch that starts with "-" as one of its options; now it takes it for
     * a branch name and the sync fails. git refuses whitespace in a branch
     * name. A git project whose stored branch is such a branch is refused on
     * its next validated save, until the branch is changed. A manual
     * project's branch is not checked: no sync reads it, and the form hides
     * it; it is checked once the project is switched to git.
     */
    public const BRANCH_FORMAT = 'The branch cannot start with "-" or contain whitespace.';

    /**
     * What BRANCH_FORMAT allows. D: "$" matches only at the very end (without
     * it, before a last newline too). u: "\s" also covers Unicode whitespace,
     * as in the browser's check of the form, which gets the pattern without
     * the D, where "$" matches only at the end anyway; a branch that is no
     * valid UTF-8 matches nothing and is refused.
     */
    private const BRANCH_PATTERN = '/^[^\s-]\S*$/uD';

    /**
     * When the browser checks a Git field (the whenClient of every rule of a
     * Git field with a check in the browser): while the project form's Scm
     * Type field (select#project-scm_type) has Git chosen, the value for
     * which the form's script shows the Git fields. Otherwise the form hides
     * them, and a check would refuse a field nobody sees; the server checks
     * them, and the form shows their errors above itself. On a page without
     * that field only the server checks them.
     */
    private const GIT_CHOSEN_IN_THE_FORM = "function () { var type = document.getElementById('project-scm_type');"
        . " return type !== null && type.value === '" . self::SCM_TYPE_GIT . "'; }";

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
        $gitProject = static fn (self $m): bool => $m->scm_type === self::SCM_TYPE_GIT;

        return [
            [['name', 'scm_type'], 'required'],
            [['name'], 'string', 'max' => 128],
            [['description'], 'string', 'max' => 1000],
            [['scm_type'], 'in', 'range' => [self::SCM_TYPE_GIT, self::SCM_TYPE_MANUAL]],
            // The Git fields (GIT_FIELDS): what they mean to git is checked for git
            // projects only ('when'), what the database needs for every project; in
            // the browser every check runs only while Git is chosen ('whenClient').
            [['scm_url'], 'validateScmUrl', 'when' => $gitProject],
            [['scm_url'], 'string', 'max' => 512, 'whenClient' => self::GIT_CHOSEN_IN_THE_FORM],
            [['local_path'], 'string', 'max' => 512],
            [['scm_branch'], 'string', 'max' => 128, 'whenClient' => self::GIT_CHOSEN_IN_THE_FORM],
            // Skipped when empty, as before, and after an error of the rule above.
            [
                ['scm_branch'], 'match', 'pattern' => self::BRANCH_PATTERN, 'message' => self::BRANCH_FORMAT,
                'when' => $gitProject,
                'whenClient' => self::GIT_CHOSEN_IN_THE_FORM,
            ],
            [['scm_credential_id'], 'integer', 'whenClient' => self::GIT_CHOSEN_IN_THE_FORM],
            [['scm_credential_id'], 'exist', 'skipOnError' => true, 'targetClass' => Credential::class, 'targetAttribute' => ['scm_credential_id' => 'id']],
            [['scm_credential_id'], 'validateScmCredentialType', 'when' => $gitProject],
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
     * Validate that the credential type is compatible with the SCM URL scheme:
     * an SSH URL needs an SSH Key credential (SSH_URL_NEEDS_SSH_KEY), an HTTPS
     * URL a Token or Username/Password credential
     * (HTTPS_URL_NEEDS_TOKEN_OR_PASSWORD). The rule runs it for git projects
     * only; called directly, it checks any project.
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
            $this->addError('scm_credential_id', self::SSH_URL_NEEDS_SSH_KEY);
        }

        if ($this->isHttpsScmUrl() && !in_array($credential->credential_type, $httpsTypes, true)) {
            $this->addError('scm_credential_id', self::HTTPS_URL_NEEDS_TOKEN_OR_PASSWORD);
        }
    }

    /**
     * The errors of the Git fields (GIT_FIELDS), in the order of the fields.
     * The project form shows them above itself while it hides those fields,
     * as it does for every type but Git, with the errors under them.
     *
     * @return list<string>
     */
    public function gitFieldErrors(): array
    {
        $errorsByField = $this->getErrors();
        $errors = [];
        foreach (self::GIT_FIELDS as $attribute) {
            foreach ($errorsByField[$attribute] ?? [] as $error) {
                $errors[] = $error;
            }
        }

        return $errors;
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
