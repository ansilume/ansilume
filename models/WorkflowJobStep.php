<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property int         $workflow_job_id
 * @property int         $workflow_step_id
 * @property int|null    $job_id
 * @property string      $status
 * @property int|null    $started_at
 * @property int|null    $finished_at
 * @property string|null $output_vars      JSON
 * @property int         $created_at
 * @property int         $updated_at
 *
 * @property WorkflowJob $workflowJob
 * @property WorkflowStep $workflowStep
 * @property Job|null $job
 */
class WorkflowJobStep extends ActiveRecord
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public static function tableName(): string
    {
        return '{{%workflow_job_step}}';
    }

    public function behaviors(): array
    {
        return [\yii\behaviors\TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['workflow_job_id', 'workflow_step_id'], 'required'],
            [['workflow_job_id', 'workflow_step_id', 'job_id'], 'integer'],
            [['status'], 'in', 'range' => [
                self::STATUS_PENDING,
                self::STATUS_RUNNING,
                self::STATUS_SUCCEEDED,
                self::STATUS_FAILED,
                self::STATUS_SKIPPED,
            ]],
            [['started_at', 'finished_at'], 'integer'],
            [['output_vars'], 'string'],
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_SKIPPED,
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function getParsedOutputVars(): array
    {
        if (empty($this->output_vars)) {
            return [];
        }
        $decoded = json_decode($this->output_vars, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_RUNNING => 'Running',
            self::STATUS_SUCCEEDED => 'Succeeded',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_SKIPPED => 'Skipped',
            default => $status,
        };
    }

    public static function statusCssClass(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'secondary',
            self::STATUS_RUNNING => 'primary',
            self::STATUS_SUCCEEDED => 'success',
            self::STATUS_FAILED => 'danger',
            self::STATUS_SKIPPED => 'info',
            default => 'secondary',
        };
    }

    public function getWorkflowJob(): ActiveQuery
    {
        return $this->hasOne(WorkflowJob::class, ['id' => 'workflow_job_id']);
    }

    public function getWorkflowStep(): ActiveQuery
    {
        return $this->hasOne(WorkflowStep::class, ['id' => 'workflow_step_id']);
    }

    public function getJob(): ActiveQuery
    {
        return $this->hasOne(Job::class, ['id' => 'job_id']);
    }
}
