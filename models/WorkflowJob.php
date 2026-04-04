<?php

declare(strict_types=1);

namespace app\models;

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
 */
class WorkflowJob extends ActiveRecord
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';

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
