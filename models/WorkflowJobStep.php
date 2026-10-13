<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * One execution of a workflow step within a run.
 *
 * @property int         $id
 * @property int         $workflow_job_id
 * @property int|null    $workflow_step_id  null once the step was deleted from its workflow
 * @property int|null    $job_id
 * @property string      $status
 * @property int|null    $started_at
 * @property int|null    $finished_at
 * @property string|null $output_vars      JSON
 * @property string|null $error_message    why the step failed, or why the run could not go on after it:
 *     no access to its job template, a refused launch, a closed approval gate (the step may have its
 *     placeholder or job), a branch to a step that no longer exists (also on a step that
 *     succeeded), a cancel
 * @property string|null $step_name        the step's name when it ran; read through stepName()
 * @property string|null $step_type        the step's type when it ran, as stored; read through stepType()
 * @property int         $created_at
 * @property int         $updated_at
 *
 * @property WorkflowJob $workflowJob
 * @property WorkflowStep|null $workflowStep  the live step; null once it was deleted
 * @property Job|null $job
 *
 * The row outlives its step (the foreign key sets workflow_step_id to NULL),
 * and step_name and step_type keep what the step was. Both columns come with
 * migration m000084; code can run before it, so they are read through
 * stepName() and stepType() and written through snapshotStep() or by
 * beforeSave(), which fills them from the live step when they are empty.
 */
class WorkflowJobStep extends ActiveRecord
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    /** The name stepName() shows for a deleted step without a snapshot. */
    public const UNKNOWN_STEP_NAME = '—';

    /** The snapshot columns and the workflow_step attributes they copy. */
    private const SNAPSHOT = ['step_name' => 'name', 'step_type' => 'step_type'];

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
            [['workflow_job_id'], 'required'],
            // A new execution runs a step. The step can be deleted later, and
            // the row stays as history of the run with workflow_step_id NULL.
            [
                ['workflow_step_id'],
                'required',
                'when' => static fn (self $execution): bool => $execution->getIsNewRecord(),
            ],
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

    /**
     * @param bool $insert
     */
    public function beforeSave($insert): bool
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }
        $this->fillEmptySnapshot();

        return true;
    }

    /**
     * Copy the name and type of $step into the snapshot. Does nothing while
     * the columns do not exist yet. The caller saves.
     */
    public function snapshotStep(WorkflowStep $step): void
    {
        foreach (self::SNAPSHOT as $column => $source) {
            if ($this->hasAttribute($column)) {
                $this->setAttribute($column, $step->getAttribute($source));
            }
        }
    }

    /**
     * The step's name when it ran, else the live step's name, else
     * UNKNOWN_STEP_NAME.
     */
    public function stepName(): string
    {
        return $this->snapshotText('step_name') ?? $this->workflowStep?->name ?? self::UNKNOWN_STEP_NAME;
    }

    /**
     * The step's type when it ran, else the live step's type, as stored (a
     * legacy type such as JOB stays as it is); null when neither is known.
     */
    public function stepType(): ?string
    {
        return $this->snapshotText('step_type') ?? $this->workflowStep?->step_type;
    }

    /**
     * Whether this is the execution of an approval step: its job is a
     * placeholder that holds the approval request and never runs. The job of
     * a job step is a real job, also when its template needs an approval.
     */
    public function isApprovalPlaceholder(): bool
    {
        return $this->stepType() === WorkflowStep::TYPE_APPROVAL;
    }

    public function isPauseStep(): bool
    {
        return $this->stepType() === WorkflowStep::TYPE_PAUSE;
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

    /**
     * The live step; null once it was deleted from its workflow.
     */
    public function getWorkflowStep(): ActiveQuery
    {
        return $this->hasOne(WorkflowStep::class, ['id' => 'workflow_step_id']);
    }

    public function getJob(): ActiveQuery
    {
        return $this->hasOne(Job::class, ['id' => 'job_id']);
    }

    /**
     * Fill the empty snapshot columns from the live step, so executions
     * created without snapshotStep() keep their step too.
     */
    private function fillEmptySnapshot(): void
    {
        $empty = [];
        foreach (self::SNAPSHOT as $column => $source) {
            if ($this->hasAttribute($column) && $this->snapshotText($column) === null) {
                $empty[$column] = $source;
            }
        }
        $step = $empty === [] ? null : $this->workflowStep;
        if ($step === null) {
            return;
        }
        foreach ($empty as $column => $source) {
            $this->setAttribute($column, $step->getAttribute($source));
        }
    }

    /**
     * A snapshot column's text; null when it is empty or does not exist yet.
     */
    private function snapshotText(string $column): ?string
    {
        if (!$this->hasAttribute($column)) {
            return null;
        }
        $value = $this->getAttribute($column);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
