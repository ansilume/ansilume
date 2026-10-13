<?php

declare(strict_types=1);

namespace app\models;

use app\components\SurveySecrets;
use app\services\JobLaunchSecrets;
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

    public const APPROVAL_RULE_REQUIRED = 'An approval step needs an approval rule.';
    public const APPROVAL_RULE_NOT_FOUND = 'The selected approval rule does not exist.';
    public const APPROVAL_ALWAYS_REFUSED = 'An approval step cannot have an Always branch: a rejection would continue past it.';
    public const ROUTE_TARGET_OUTSIDE = 'The selected step is not part of this workflow.';

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
            // The workflow is set in code: a form must not move a step into
            // another workflow.
            [['!workflow_template_id'], 'required'],
            [['!workflow_template_id'], 'integer'],
            [['name', 'step_type'], 'required'],
            [['name'], 'string', 'max' => 128],
            // Text and compared strictly: a JSON body can post true, which a
            // loose comparison takes for every type. Such a step was stored
            // as "1", and a run that reached it waited there until canceled.
            [['step_type'], 'string'],
            [
                ['step_type'],
                'in',
                'range' => [self::TYPE_JOB, self::TYPE_APPROVAL, self::TYPE_PAUSE],
                'strict' => true,
            ],
            [['step_order'], 'integer', 'min' => 0],
            [['job_template_id', 'approval_rule_id'], 'integer'],
            [
                ['job_template_id'],
                'required',
                'when' => static fn (self $step): bool => $step->step_type === self::TYPE_JOB,
                'message' => 'A job step needs a job template.',
            ],
            [
                ['job_template_id'],
                'exist',
                'skipOnError' => true,
                'targetClass' => JobTemplate::class,
                'targetAttribute' => ['job_template_id' => 'id'],
                'message' => 'The selected job template does not exist.',
            ],
            // A run that reaches an approval step without a rule fails there.
            [
                ['approval_rule_id'],
                'required',
                'when' => static fn (self $step): bool => $step->step_type === self::TYPE_APPROVAL,
                'message' => self::APPROVAL_RULE_REQUIRED,
            ],
            [
                ['approval_rule_id'],
                'exist',
                'skipOnError' => true,
                'targetClass' => ApprovalRule::class,
                'targetAttribute' => ['approval_rule_id' => 'id'],
                'message' => self::APPROVAL_RULE_NOT_FOUND,
            ],
            [['on_success_step_id', 'on_failure_step_id', 'on_always_step_id'], 'integer', 'min' => 0],
            [['on_always_step_id'], 'validateNoAlwaysBranch'],
            [['on_success_step_id', 'on_failure_step_id', 'on_always_step_id'], 'validateRouteTarget'],
            [['extra_vars_template'], 'string', 'max' => 65535],
            [['extra_vars_template'], 'validateJson'],
        ];
    }

    /**
     * An approval step has no Always branch: it would be taken after a
     * rejection too, so the run would continue past the gate. Any value,
     * END_WORKFLOW included, is refused; an empty one never gets here.
     */
    public function validateNoAlwaysBranch(string $attribute): void
    {
        if ($this->step_type === self::TYPE_APPROVAL) {
            $this->addError($attribute, self::APPROVAL_ALWAYS_REFUSED);
        }
    }

    /**
     * A branch leads to END_WORKFLOW or to a step of this workflow. A run
     * that reached a target outside it used to end there as if the workflow
     * were done.
     */
    public function validateRouteTarget(string $attribute): void
    {
        $target = (int)$this->$attribute;
        if ($target === self::END_WORKFLOW) {
            return;
        }
        $inWorkflow = self::find()
            ->where(['id' => $target, 'workflow_template_id' => $this->workflow_template_id])
            ->exists();
        if (!$inWorkflow) {
            $this->addError($attribute, self::ROUTE_TARGET_OUTSIDE);
        }
    }

    /**
     * The extra vars mapping must be JSON that can be stored and written
     * back: JSON allows a number too large for it (1e999, -1e999), which PHP
     * reads as infinity and cannot write back (the API returns the mapping
     * decoded). It gets the message a launch refuses such extra vars with.
     */
    public function validateJson(string $attribute): void
    {
        if (empty($this->$attribute)) {
            return;
        }
        $decoded = json_decode((string)$this->$attribute);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->addError($attribute, ucfirst($attribute) . ' must be valid JSON.');
        } elseif (json_encode($decoded) === false) {
            $this->addError($attribute, SurveySecrets::unencodable(JobLaunchSecrets::EXTRA_VARS, json_last_error()));
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
