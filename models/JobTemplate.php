<?php

declare(strict_types=1);

namespace app\models;

use app\components\SurveyField;
use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property int         $project_id
 * @property int         $inventory_id
 * @property int|null    $credential_id
 * @property string      $playbook
 * @property string|null $extra_vars        JSON
 * @property int         $verbosity
 * @property int         $forks
 * @property bool        $become
 * @property string      $become_method
 * @property string      $become_user
 * @property string|null $limit
 * @property string|null $tags
 * @property string|null $skip_tags
 * @property int         $timeout_minutes   Timeout in minutes (default 120)
 * @property int|null    $runner_group_id
 * @property string|null $survey_fields     JSON array of SurveyField definitions
 * @property bool        $notify_on_failure
 * @property bool        $notify_on_success
 * @property string|null $notify_emails     JSON array of email addresses
 * @property string|null $trigger_token     Hashed trigger token (bcrypt)
 * @property string|null $lint_output       Last ansible-lint output
 * @property int|null    $lint_at           Unix timestamp of last lint run
 * @property int|null    $lint_exit_code    Exit code of last ansible-lint run (0 = clean)
 * @property int         $created_by
 * @property int         $created_at
 * @property int         $updated_at
 * @property int|null    $deleted_at    Unix timestamp when soft-deleted (null = active)
 *
 * @property Project     $project
 * @property Inventory   $inventory
 * @property Credential|null $credential
 * @property RunnerGroup|null $runnerGroup
 * @property User        $creator
 * @property Job[]       $jobs
 */
class JobTemplate extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%job_template}}';
    }

    /**
     * Default scope: exclude soft-deleted templates from all queries.
     * Use {@see findWithDeleted()} to include them.
     */
    public static function find(): \yii\db\ActiveQuery
    {
        return parent::find()->andWhere(['{{%job_template}}.deleted_at' => null]);
    }

    /**
     * Query that includes soft-deleted templates (for admin / audit views).
     */
    public static function findWithDeleted(): \yii\db\ActiveQuery
    {
        return parent::find();
    }

    /**
     * Soft-delete this template by setting deleted_at.
     */
    public function softDelete(): bool
    {
        $this->deleted_at = time();
        return $this->save(false, ['deleted_at']);
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public function behaviors(): array
    {
        return [\yii\behaviors\TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['name', 'project_id', 'inventory_id', 'playbook', 'runner_group_id'], 'required'],
            [['name'], 'string', 'max' => 128],
            [['description'], 'string'],
            [['playbook'], 'string', 'max' => 512],
            [['extra_vars'], 'string'],
            [['extra_vars'], 'validateJson'],
            [['verbosity'], 'integer', 'min' => 0, 'max' => 5],
            [['forks'], 'integer', 'min' => 1, 'max' => 200],
            [['become'], 'boolean'],
            [['become_method'], 'in', 'range' => ['sudo', 'su', 'pbrun', 'pfexec', 'doas']],
            [['become_user'], 'string', 'max' => 64],
            [['limit'], 'string', 'max' => 255],
            [['timeout_minutes'], 'integer', 'min' => 1, 'max' => 1440],
            [['tags', 'skip_tags'], 'string', 'max' => 512],
            [['survey_fields', 'notify_emails'], 'string'],
            [['survey_fields'], 'validateJson'],
            [['notify_emails'], 'validateJson'],
            [['notify_on_failure', 'notify_on_success'], 'boolean'],
            [['trigger_token'], 'string', 'max' => 64],
            [['project_id', 'inventory_id', 'credential_id', 'runner_group_id', 'created_by'], 'integer'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'runner_group_id' => 'Runner',
        ];
    }

    public function validateJson(string $attribute): void
    {
        if (!empty($this->$attribute)) {
            json_decode((string)$this->$attribute);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->addError($attribute, ucfirst($attribute) . ' must be valid JSON.');
            }
        }
    }

    public function getProject(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Project::class, ['id' => 'project_id']);
    }

    public function getInventory(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Inventory::class, ['id' => 'inventory_id']);
    }

    public function getCredential(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Credential::class, ['id' => 'credential_id']);
    }

    public function getRunnerGroup(): \yii\db\ActiveQuery
    {
        return $this->hasOne(RunnerGroup::class, ['id' => 'runner_group_id']);
    }

    public function getCreator(): \yii\db\ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    public function getJobs(): \yii\db\ActiveQuery
    {
        return $this->hasMany(Job::class, ['job_template_id' => 'id']);
    }

    /**
     * Parse survey_fields JSON into SurveyField value objects.
     *
     * @return SurveyField[]
     */
    public function getSurveyFields(): array
    {
        return SurveyField::parseJson($this->survey_fields);
    }

    public function hasSurvey(): bool
    {
        return !empty($this->survey_fields) && $this->getSurveyFields() !== [];
    }

    /**
     * Parse notify_emails JSON into a flat array of strings.
     *
     * @return string[]
     */
    public function getNotifyEmailList(): array
    {
        if (empty($this->notify_emails)) {
            return [];
        }
        $list = json_decode($this->notify_emails, true);
        return is_array($list) ? array_filter($list, 'is_string') : [];
    }

    /**
     * Generate and persist a new random trigger token.
     * Returns the raw token string.
     */
    public function generateTriggerToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->trigger_token = $token;
        $this->save(false, ['trigger_token']);
        return $token;
    }

    /**
     * Remove the trigger token, effectively disabling the inbound trigger.
     */
    public function revokeTriggerToken(): void
    {
        $this->trigger_token = null;
        $this->save(false, ['trigger_token']);
    }

    /**
     * Look up a template by its trigger token.
     */
    public static function findByTriggerToken(string $token): ?self
    {
        if ($token === '') {
            return null;
        }
        /** @var static|null $result */
        $result = static::findOne(['trigger_token' => $token]);
        return $result;
    }
}
