<?php

declare(strict_types=1);

namespace app\services;

use app\models\ApprovalRule;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use yii\helpers\ArrayHelper;

/**
 * The workflow template page (views/workflow-template/view.php) for one
 * user: what it shows them, the texts it shows, whether its add-step form
 * may add a step, and the step that form posts. WorkflowTemplateController
 * handles the requests.
 *
 * Gated steps are for admins (approval-rule.update; ApprovalRuleAssignmentPolicy):
 * approval steps, and job steps whose job template has an approval rule.
 * The add-step form offers everyone else neither approval rules nor job
 * templates with an approval rule, and says why; on a workflow that has a
 * gated step they get a notice instead of the step controls. While a run of
 * the workflow is active, nobody changes its steps (WorkflowStepChangeGuard).
 *
 * The steps table labels each route. After a rejected, timed-out or canceled
 * approval a gated step follows only its On Failure route: left empty, the
 * run ends as failed, and the Always route is not taken. A gated job step
 * whose job ran and failed goes on as any job step does.
 *
 * The add-step form's Step Order is empty by default, and a step added with
 * it empty goes after the last step (STEP_ORDER_PLACEHOLDER, STEP_ORDER_HINT).
 * Regression (2.10.1): since 2.3.15 the form pre-filled the number of steps,
 * which sorts before the renumbered 10, 20, 30…, so such a step went first.
 */
final class WorkflowTemplatePage
{
    /** The label of an empty route that goes on with the next step. */
    public const ROUTE_NEXT_STEP = '→ next step';

    /** The label of a route that ends the run (WorkflowStep::END_WORKFLOW). */
    public const ROUTE_END = 'end workflow';

    /** The label of an approval step's empty On Failure route: a closed gate ends the run as failed. */
    public const ROUTE_END_FAILED = 'end workflow (failed)';

    /**
     * The label of the empty On Failure route of a job step whose job
     * template has an approval rule: a job that ran and failed goes on with
     * the next step, a refused approval ends the run as failed.
     */
    public const GATED_JOB_FAILURE_ROUTE = '→ next step; end workflow (failed) after a refused approval';

    /** The hover text of GATED_JOB_FAILURE_ROUTE. */
    public const GATED_JOB_FAILURE_TITLE = 'After a rejected, timed-out or canceled approval, when the job never ran. '
        . 'A job that ran and failed goes on with the next step.';

    /** After the Always route of an approval step, which older versions saved: taken on approval only. */
    public const APPROVAL_ALWAYS_NOTE = '(only on approval)';

    /** After the Always route of a job step whose job template has an approval rule: taken only after its job ran. */
    public const GATED_JOB_ALWAYS_NOTE = '(not after a rejected, timed-out or canceled approval)';

    /** The hint under the add-step form's job templates, for users who may not add gated job steps. */
    public const GATED_TEMPLATES_HINT = 'Job templates with an approval rule are not listed: '
        . 'only admins may add a job step for them.';

    /**
     * The notice of a workflow whose steps the user may not change says why
     * (ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED or
     * GATED_JOB_WORKFLOW_STEPS_REFUSED), then this.
     */
    public const STEPS_LOCKED_NOTE = 'You may still rename the workflow, launch it and manage its trigger token.';

    /** The placeholder of the add-step form's Step Order, which is empty by default: then the step goes last. */
    public const STEP_ORDER_PLACEHOLDER = 'At the end';

    /** The hint below the steps table, for users who may change the steps. */
    public const STEP_ORDER_HINT = 'Leave Step Order empty to add the step at the end. To put a step between two '
        . 'others, enter the number of the step it should follow: steps are renumbered 10, 20, 30… after every '
        . 'add and move. '
        . 'Use ▲ / ▼ to move a step.';

    /** The routes the add-step form posts. */
    private const ROUTE_FIELDS = ['on_success_step_id', 'on_failure_step_id', 'on_always_step_id'];

    public function __construct(private readonly int $userId)
    {
    }

    /**
     * What the page shows the user for $model, which they may view:
     * whether they may operate it (team scoping), the add-step form's job
     * templates and approval rules, whether they may add gated steps, why
     * they may not change the steps (null when they may), the active runs,
     * which lock the steps for everyone, and whom the inbound trigger runs
     * as (null without a token).
     *
     * @return array{model: WorkflowTemplate, canOperate: bool, jobTemplateOptions: array<int, string>, approvalRuleOptions: array<int, string>, mayAssignRules: bool, stepsLock: string|null, activeRunIds: list<int>, triggerUser: User|null}
     */
    public function viewParams(WorkflowTemplate $model): array
    {
        $canOperate = self::workflows()->canOperateWorkflowTemplate($this->userId, (int)$model->id);
        $policy = self::policy();
        $mayAssignRules = $policy->mayAssign($this->userId);

        return [
            'model' => $model,
            'canOperate' => $canOperate,
            'jobTemplateOptions' => $canOperate ? $this->jobTemplateOptions($mayAssignRules) : [],
            // Approval steps, and so their rules, are for admins.
            'approvalRuleOptions' => $canOperate && $mayAssignRules ? self::approvalRuleOptions() : [],
            'mayAssignRules' => $mayAssignRules,
            // Why the user may not change the steps: the workflow has an
            // approval step or a gated job step. Admins may.
            'stepsLock' => $mayAssignRules ? null : $policy->workflowStepsChangeRefusal($this->userId, $model, false),
            'activeRunIds' => self::guard()->activeRunIds((int)$model->id),
            'triggerUser' => self::triggerUser($model),
        ];
    }

    /**
     * Why the user may not add $step to $model (403), or null when they
     * may: only admins add approval steps and gated job steps, or any step
     * to a workflow that has one (ApprovalRuleAssignmentPolicy::stepsAddRefusal()).
     * $step's job template ID must be the int the step stores ("05" is
     * template 5 too).
     */
    public function addRefusal(WorkflowTemplate $model, WorkflowStep $step): ?string
    {
        return self::policy()->stepsAddRefusal($this->userId, $model, [$step]);
    }

    /**
     * Complete $step, loaded from the add-step form, as the form means it.
     * A route is null (next step) when $posted, the form's WorkflowStep
     * fields, leaves it out or empty, and else the posted ID as an int:
     * load() may turn an empty prompt value into null, which is right, but a
     * posted "0" means "end workflow" (WorkflowStep::END_WORKFLOW) and must
     * stay 0. A step keeps only the target of its type: the form posts the
     * job and the approval dropdown for every type, and a job template left
     * on a pause step would still decide who may see the workflow. A Step
     * Order left empty or out (a JSON body may post an empty list) becomes
     * null: the step goes after the last one, which the controller works
     * out with the workflow locked.
     *
     * @param array<array-key, mixed> $posted
     */
    public static function completeFromForm(WorkflowStep $step, array $posted): void
    {
        if (in_array($step->getAttribute('step_order'), ['', []], true)) {
            $step->setAttribute('step_order', null);
        }
        foreach (self::ROUTE_FIELDS as $field) {
            if (!array_key_exists($field, $posted) || $posted[$field] === '') {
                $step->$field = null;
            } else {
                $step->$field = (int)$posted[$field];
            }
        }
        if ($step->step_type !== WorkflowStep::TYPE_JOB) {
            $step->job_template_id = null;
        }
        if ($step->step_type !== WorkflowStep::TYPE_APPROVAL) {
            $step->approval_rule_id = null;
        }
    }

    /**
     * The add-step form's job templates: those the user may operate (a step
     * with any other one would be refused), and, unless they may add gated
     * job steps, only those without an approval rule.
     *
     * @return array<int, string>
     */
    private function jobTemplateOptions(bool $mayAssignRules): array
    {
        $options = self::workflows()->jobTemplateOptions($this->userId);
        if ($mayAssignRules) {
            return $options;
        }
        $gated = ApprovalRuleAssignmentPolicy::gatedJobTemplateIds(array_keys($options));

        return array_diff_key($options, array_flip($gated));
    }

    /**
     * @return array<int, string>
     */
    private static function approvalRuleOptions(): array
    {
        /** @var array<int, string> $options */
        $options = ArrayHelper::map(ApprovalRule::find()->orderBy('name')->all(), 'id', 'name');
        return $options;
    }

    /**
     * The user the inbound trigger runs as: whoever generated the token, or
     * the creator for older tokens. Null when no token is configured.
     */
    private static function triggerUser(WorkflowTemplate $model): ?User
    {
        if (!$model->hasTriggerToken()) {
            return null;
        }
        /** @var User|null $user */
        $user = User::findOne($model->getTriggerUserId());
        return $user;
    }

    private static function policy(): ApprovalRuleAssignmentPolicy
    {
        /** @var ApprovalRuleAssignmentPolicy $policy */
        $policy = \Yii::$app->get('approvalRuleAssignmentPolicy');
        return $policy;
    }

    private static function guard(): WorkflowStepChangeGuard
    {
        /** @var WorkflowStepChangeGuard $guard */
        $guard = \Yii::$app->get('workflowStepChangeGuard');
        return $guard;
    }

    private static function workflows(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $checker */
        $checker = \Yii::$app->get('workflowAccessChecker');
        return $checker;
    }
}
