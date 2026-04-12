<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property int         $workflow_template_id
 * @property string      $name
 * @property int         $step_order
 * @property string      $step_type            job, approval, or pause
 * @property int|null    $job_template_id
 * @property int|null    $approval_rule_id
 * @property int|null    $on_success_step_id
 * @property int|null    $on_failure_step_id
 * @property int|null    $on_always_step_id
 * @property string|null $extra_vars_template  JSON variable mapping
 * @property int         $created_at
 * @property int         $updated_at
 *
 * @property WorkflowTemplate $workflowTemplate
 * @property JobTemplate|null $jobTemplate
 * @property ApprovalRule|null $approvalRule
 * @property WorkflowStep|null $onSuccessStep
 * @property WorkflowStep|null $onFailureStep
 * @property WorkflowStep|null $onAlwaysStep
 */
class WorkflowStep extends ActiveRecord
{
    public const TYPE_JOB = 'job';
    public const TYPE_APPROVAL = 'approval';
    public const TYPE_PAUSE = 'pause';

    /**
     * Sentinel value stored in on_success_step_id / on_failure_step_id
     * to mean "end the workflow here" rather than advancing to the next
     * step by step_order (which is the default when the column is NULL).
     *
     * Uses 0 because the column is unsigned and no real step has id=0.
     * NULL means "auto-advance to the next step by step_order".
     */
    public const END_WORKFLOW = 0;

    public static function tableName(): string
    {
        return '{{%workflow_step}}';
    }

    public function behaviors(): array
    {
        return [\yii\behaviors\TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['workflow_template_id', 'name', 'step_type'], 'required'],
            [['name'], 'string', 'max' => 128],
            [['step_type'], 'in', 'range' => [self::TYPE_JOB, self::TYPE_APPROVAL, self::TYPE_PAUSE]],
            [['step_order'], 'integer', 'min' => 0],
            [['workflow_template_id', 'job_template_id', 'approval_rule_id'], 'integer'],
            [['on_success_step_id', 'on_failure_step_id', 'on_always_step_id'], 'integer', 'min' => 0],
            [['extra_vars_template'], 'string', 'max' => 65535],
            [['extra_vars_template'], 'validateJson'],
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

    /**
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_JOB => 'Job',
            self::TYPE_APPROVAL => 'Approval',
            self::TYPE_PAUSE => 'Pause',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getParsedExtraVarsTemplate(): array
    {
        if (empty($this->extra_vars_template)) {
            return [];
        }
        $decoded = json_decode($this->extra_vars_template, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function getWorkflowTemplate(): ActiveQuery
    {
        return $this->hasOne(WorkflowTemplate::class, ['id' => 'workflow_template_id']);
    }

    public function getJobTemplate(): ActiveQuery
    {
        return $this->hasOne(JobTemplate::class, ['id' => 'job_template_id']);
    }

    public function getApprovalRule(): ActiveQuery
    {
        return $this->hasOne(ApprovalRule::class, ['id' => 'approval_rule_id']);
    }

    public function getOnSuccessStep(): ActiveQuery
    {
        return $this->hasOne(self::class, ['id' => 'on_success_step_id']);
    }

    public function getOnFailureStep(): ActiveQuery
    {
        return $this->hasOne(self::class, ['id' => 'on_failure_step_id']);
    }

    public function getOnAlwaysStep(): ActiveQuery
    {
        return $this->hasOne(self::class, ['id' => 'on_always_step_id']);
    }
}
