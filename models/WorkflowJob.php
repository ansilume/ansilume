<?php

declare(strict_types=1);

namespace app\models;

use app\components\SurveySecrets;
use app\models\traits\SealedSecretExtraVarsTrait;
use app\services\JobLaunchSecrets;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property int         $workflow_template_id
 * @property string      $status
 * @property int         $launched_by
 * @property int|null    $current_step_id
 * @property int|null    $started_at
 * @property int|null    $finished_at
 * @property int         $created_at
 * @property int         $updated_at
 *
 * @property WorkflowTemplate $workflowTemplate
 * @property User $launcher
 * @property WorkflowJobStep[] $stepExecutions
 * @property WorkflowStep|null $currentStep
 *
 * The extra vars the run was launched with live in two columns of migration
 * m000084, read and written only through these methods, as code can run
 * before that migration:
 * - extra_vars, JSON: launchExtraVars() and setLaunchExtraVars(), with
 *   SurveySecrets::PLACEHOLDER in place of every password answer;
 * - secret_extra_vars: the password answers, sealed, through
 *   getSealedSecretExtraVars() and setSealedSecretExtraVars(), see
 *   SealedSecretExtraVarsTrait.
 * Neither has a rule, so load() never assigns them.
 */
class WorkflowJob extends ActiveRecord
{
    use SealedSecretExtraVarsTrait;

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';

    /** Storing launch extra vars before migration m000084 has added their column. */
    public const MIGRATION_PENDING = 'The database update has not finished; run the migrations.';

    /** The column of the public launch extra vars. */
    private const EXTRA_VARS = 'extra_vars';

    public static function tableName(): string
    {
        return '{{%workflow_job}}';
    }

    public function behaviors(): array
    {
        return [\yii\behaviors\TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['workflow_template_id', 'launched_by'], 'required'],
            [['workflow_template_id', 'launched_by', 'current_step_id'], 'integer'],
            [['status'], 'in', 'range' => self::statuses()],
            [['started_at', 'finished_at'], 'integer'],
        ];
    }

    /**
     * @return string[]
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_RUNNING,
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_CANCELED,
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_CANCELED,
        ], true);
    }

    /**
     * The statuses of a run that can still start steps. A run that waits at
     * a pause or approval step, or for a child job, is running too.
     *
     * @return list<string>
     */
    public static function activeStatuses(): array
    {
        return [self::STATUS_RUNNING];
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::activeStatuses(), true);
    }

    /**
     * The extra vars the run was launched with, SurveySecrets::PLACEHOLDER
     * in place of each password answer; [] when there are none, also while
     * the column does not exist yet. Only a stored JSON object counts.
     *
     * @return array<string, mixed>
     */
    public function launchExtraVars(): array
    {
        $json = $this->hasAttribute(self::EXTRA_VARS) ? $this->getAttribute(self::EXTRA_VARS) : null;
        if (!is_string($json) || !str_starts_with(ltrim($json), '{')) {
            return [];
        }
        $decoded = json_decode($json, true);
        $vars = [];
        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            $vars[(string)$key] = $value;
        }

        return $vars;
    }

    /**
     * Store the public part of the launch extra vars, the password answers
     * already replaced by SurveySecrets::PLACEHOLDER (the sealed answers go
     * through setSealedSecretExtraVars()). [] stores none. They are stored
     * as a JSON object, also when the keys are "0" to "n-1". The caller
     * saves.
     *
     * @param array<int|string, mixed> $vars
     * @throws \InvalidArgumentException when JSON cannot hold a value (a
     *     launch refuses such vars before they get here)
     * @throws \RuntimeException when there are vars to store but the column
     *     does not exist yet
     */
    public function setLaunchExtraVars(#[\SensitiveParameter] array $vars): void
    {
        if (!$this->hasAttribute(self::EXTRA_VARS)) {
            if ($vars !== []) {
                throw new \RuntimeException(self::MIGRATION_PENDING);
            }
            return;
        }
        if ($vars === []) {
            $this->setAttribute(self::EXTRA_VARS, null);
            return;
        }
        // Only a list is cast: its keys are all ints, and a cast drops keys that begin with a NUL byte.
        $json = json_encode(array_is_list($vars) ? (object)$vars : $vars, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \InvalidArgumentException(SurveySecrets::unencodable(JobLaunchSecrets::EXTRA_VARS, json_last_error()));
        }
        $this->setAttribute(self::EXTRA_VARS, $json);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_RUNNING => 'Running',
            self::STATUS_SUCCEEDED => 'Succeeded',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_CANCELED => 'Canceled',
            default => $status,
        };
    }

    public static function statusCssClass(string $status): string
    {
        return match ($status) {
            self::STATUS_RUNNING => 'primary',
            self::STATUS_SUCCEEDED => 'success',
            self::STATUS_FAILED => 'danger',
            self::STATUS_CANCELED => 'warning',
            default => 'secondary',
        };
    }

    public function getWorkflowTemplate(): ActiveQuery
    {
        return $this->hasOne(WorkflowTemplate::class, ['id' => 'workflow_template_id'])
            ->where([]); // clear soft-delete scope
    }

    public function getLauncher(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'launched_by']);
    }

    public function getStepExecutions(): ActiveQuery
    {
        return $this->hasMany(WorkflowJobStep::class, ['workflow_job_id' => 'id'])
            ->orderBy(['id' => SORT_ASC]);
    }

    public function getCurrentStep(): ActiveQuery
    {
        return $this->hasOne(WorkflowStep::class, ['id' => 'current_step_id']);
    }
}
