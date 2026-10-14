<?php

declare(strict_types=1);

namespace app\models;

use app\components\SurveySecrets;
use app\components\WorkflowStepMapping;
use app\services\JobLaunchSecrets;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * One step of a workflow template.
 *
 * Runs take the steps in the run order: step_order, then ID. A step's
 * position is its 1-based index in that order, and runs start with the step
 * at position 1. step_order is an internal sort key: placement
 * (WorkflowStepReorderService) and the API's bulk replace of all steps (the
 * list index) set it; no form or request body does.
 *
 * A route (on_success_step_id, on_failure_step_id, on_always_step_id) is:
 * - null: the run goes on with the next step in the run order; after the
 *   last step it ends with this step's outcome;
 * - END_WORKFLOW (0): the run ends, succeeded after a success and failed
 *   otherwise;
 * - the ID of a step of this workflow, an earlier or later one, or the step
 *   itself (a retry loop: the run repeats it until a branch leads
 *   elsewhere).
 * A pause step uses only On Success (Resume continues there); an approval
 * step has no Always route (validateNoAlwaysBranch()).
 *
 * @property int         $id
 * @property int         $workflow_template_id
 * @property string      $name
 * @property int         $step_order           internal sort key; see the class description
 * @property string      $step_type            job, approval, or pause
 * @property int|null    $job_template_id
 * @property int|null    $approval_rule_id
 * @property int|null    $on_success_step_id   see the class description for route values
 * @property int|null    $on_failure_step_id
 * @property int|null    $on_always_step_id
 * @property string|null $extra_vars_template  JSON variable mapping (WorkflowStepMapping)
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
     * Sentinel value stored in a route to mean "end the workflow here"
     * rather than going on with the next step in the run order (which is
     * what NULL means).
     *
     * Uses 0 because the column is unsigned and no real step has id=0.
     */
    public const END_WORKFLOW = 0;

    /**
     * The largest step_order the column holds (a signed INT). A larger one,
     * such as a typed 3000000000, answered 500 before it was refused.
     */
    public const MAX_STEP_ORDER = 2147483647;

    /** A job step without a job template. */
    public const JOB_TEMPLATE_REQUIRED = 'A job step needs a job template.';

    /**
     * A job template that does not exist: unknown or deleted, and on the
     * step write path also one the user may not see.
     */
    public const JOB_TEMPLATE_NOT_FOUND = 'The selected job template does not exist.';

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
            // Not mass-assignable: placement sets it (see the class
            // description), so a posted Step Order never decides where a step
            // runs. It may be empty on a new step, which placement then puts
            // in its place.
            [['!step_order'], 'integer', 'min' => 0, 'max' => self::MAX_STEP_ORDER],
            [['job_template_id', 'approval_rule_id'], 'integer'],
            [
                ['job_template_id'],
                'required',
                'when' => static fn (self $step): bool => $step->step_type === self::TYPE_JOB,
                'message' => self::JOB_TEMPLATE_REQUIRED,
            ],
            [
                ['job_template_id'],
                'exist',
                'skipOnError' => true,
                'targetClass' => JobTemplate::class,
                'targetAttribute' => ['job_template_id' => 'id'],
                'message' => self::JOB_TEMPLATE_NOT_FOUND,
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
            [['extra_vars_template'], 'validateMapping'],
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
     * A branch leads to END_WORKFLOW or to a step of this workflow, the
     * step itself included (a retry loop). A run that reached a target
     * outside it used to end there as if the workflow were done.
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
     * The variable mapping must be none or a JSON object of extra var names
     * to the names of output variables (WorkflowStepMapping::isValid()), else
     * WorkflowStepMapping::INVALID. Checked when the mapping changes
     * (WorkflowStepMapping::same()): a mapping of another shape that an older
     * version stored stays while a change leaves it alone, as the template
     * page cannot change it; runs skip what it maps that is not an extra var
     * name to an output variable name (WorkflowRunVars::stepMapping()).
     *
     * Regression: any JSON was stored, such as [1, 2], 5, "text" or
     * {"a": 1}; a list mapped into a job's extra vars made them a JSON list,
     * which Ansible refuses.
     */
    public function validateMapping(string $attribute): void
    {
        $mapping = WorkflowStepMapping::textOf($this->getAttribute($attribute));
        if (WorkflowStepMapping::same(WorkflowStepMapping::textOf($this->getOldAttribute($attribute)), $mapping)) {
            return;
        }
        if (!WorkflowStepMapping::isValid($mapping)) {
            $this->addError($attribute, WorkflowStepMapping::INVALID);
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
     * The variable mapping decoded: [] for none or for JSON that is no
     * array. A mapping an older version stored as a list has integer keys.
     *
     * @return array<int|string, mixed>
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
