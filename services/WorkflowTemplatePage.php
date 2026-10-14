<?php

declare(strict_types=1);

namespace app\services;

use app\components\WorkflowStepCommand;
use app\components\WorkflowStepPosition;
use app\models\ApprovalRule;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\runstate\RunState;
use app\services\runstate\Viewer;
use app\services\runstate\WorkflowRunStates;
use yii\helpers\ArrayHelper;

/**
 * The workflow template page (views/workflow-template/view.php) and the
 * page that edits one of its steps (edit-step.php), for one user: what they
 * show the user, and the requests their step forms post.
 * WorkflowTemplateController handles the requests; WorkflowStepWriteService
 * changes the steps.
 *
 * Both pages build the route map of the workflow's steps once
 * (WorkflowRouteMap::stepsOf(), fromModels()): the steps table
 * (WorkflowStepsTable), the step form (WorkflowStepFormView) and the steps
 * that route to an edited step (WorkflowStepRoutedBy) are read from it.
 *
 * Gated steps are for admins (approval-rule.update; ApprovalRuleAssignmentPolicy):
 * approval steps, and job steps whose job template has an approval rule.
 * The step form offers everyone else neither approval rules nor job
 * templates with an approval rule, and says why; on a workflow that has a
 * gated step they get a notice instead of the step controls. While a run of
 * the workflow is active, nobody changes its steps (WorkflowStepChangeGuard):
 * the template page shows no step controls, and the edit page shows the
 * runs and a form that cannot be sent.
 */
final class WorkflowTemplatePage
{
    /** The hint under the step form's job templates, for users who may not add gated job steps. */
    public const GATED_TEMPLATES_HINT = 'Job templates with an approval rule are not listed: '
        . 'only admins may add a job step for them.';

    /**
     * The notice of a workflow whose steps the user may not change says why
     * (ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED or
     * GATED_JOB_WORKFLOW_STEPS_REFUSED), then this.
     */
    public const STEPS_LOCKED_NOTE = 'You may still rename the workflow, launch it and manage its trigger token.';

    /** The first crumb of the edit page's breadcrumb: the list of workflow templates. */
    public const TEMPLATES_CRUMB = 'Workflow Templates';

    /** The permission that changes a workflow and its steps (WorkflowTemplateController's access rules). */
    private const UPDATE_PERMISSION = 'workflow-template.update';

    /** The permission that shows workflow runs (WorkflowJobController's access rules). */
    private const RUNS_PERMISSION = 'workflow.view';

    public function __construct(private readonly int $userId)
    {
    }

    /**
     * What the template page shows the user for $model, which they may
     * view: whether they may operate it (team scoping) and change it, the
     * step form's job templates and approval rules, whether they may add
     * gated steps, why they may not change the steps (null when they may),
     * how many runs are active, which lock the steps for everyone, and the
     * IDs of the oldest of them (at most
     * WorkflowStepChangeGuard::RUN_LIST_LIMIT); to a user who may see runs
     * (workflow.view) also these runs and what each active run is doing;
     * whom the inbound trigger runs as (null without a token); the steps
     * table (with the step actions when the user may change the steps, and
     * how many runs wait at each step) and the Add Step form (null when they
     * may not), whose position is after the step $after when the workflow
     * has it (WorkflowStepFormView::forAdd()).
     *
     * @return array{
     *     model: WorkflowTemplate,
     *     canOperate: bool,
     *     canChange: bool,
     *     canChangeSteps: bool,
     *     jobTemplateOptions: array<int, string>,
     *     approvalRuleOptions: array<int, string>,
     *     mayAssignRules: bool,
     *     stepsLock: string|null,
     *     activeRunIds: list<int>,
     *     activeRuns: list<WorkflowJob>,
     *     runStates: array<int, RunState>,
     *     activeRunCount: int,
     *     triggerUser: User|null,
     *     stepsTable: WorkflowStepsTable,
     *     stepForm: WorkflowStepFormView|null
     * }
     */
    public function viewParams(WorkflowTemplate $model, ?int $after = null): array
    {
        $viewer = Viewer::of($this->userId);
        $choices = $this->choices($model, $viewer);
        $runs = self::activeRunsOf((int)$model->id);
        $shownRuns = $viewer->may(self::RUNS_PERMISSION) ? $runs : [];
        $runStates = $shownRuns === [] ? [] : self::runStates()->forRuns($shownRuns, $viewer);
        $canChangeSteps = $choices['canChange'] && $choices['stepsLock'] === null && $runs === [];
        $models = WorkflowRouteMap::stepsOf($model);
        $map = WorkflowRouteMap::fromModels($models);

        return [
            'model' => $model,
            'canOperate' => $choices['canOperate'],
            'canChange' => $choices['canChange'],
            'canChangeSteps' => $canChangeSteps,
            'jobTemplateOptions' => $choices['jobTemplateOptions'],
            'approvalRuleOptions' => $choices['approvalRuleOptions'],
            'mayAssignRules' => $choices['mayAssignRules'],
            'stepsLock' => $choices['stepsLock'],
            'activeRunIds' => array_map(
                static fn (WorkflowJob $run): int => (int)$run->id,
                array_slice($runs, 0, WorkflowStepChangeGuard::RUN_LIST_LIMIT)
            ),
            'activeRuns' => array_slice($shownRuns, 0, WorkflowStepChangeGuard::RUN_LIST_LIMIT),
            'runStates' => $runStates,
            'activeRunCount' => count($runs),
            'triggerUser' => self::triggerUser($model),
            'stepsTable' => new WorkflowStepsTable($map, $models, $canChangeSteps, self::waitingSteps($runStates)),
            'stepForm' => $canChangeSteps ? WorkflowStepFormView::forAdd($map, $after) : null,
        ];
    }

    /**
     * What the page that edits $step, a step of $model, shows the user, who
     * may change its steps: its position, the step form (with $position, the
     * Position posted on a re-render, selected when the form offers it,
     * WorkflowStepFormView::forEdit()), the step the form shows ($posted, as
     * the user filled it in when the change was refused, else $step as
     * stored), the form's job templates and approval rules, why the change
     * was refused ($error), the steps that route to it (exactly those that
     * keep it from being removed, WorkflowStepChangeGuard::stepsRoutingTo(),
     * each with a note when no run takes its branch here;
     * WorkflowStepRoutedBy) and the active runs, which lock the steps. The
     * step is read again with the workflow's steps (the route map's model,
     * with its relations loaded).
     *
     * @param mixed $position the Position posted with a refused save; null for the page itself
     * @throws WorkflowStepChangeRefusedException (404, WorkflowStepWriteService::STEP_NOT_FOUND)
     *     when the workflow no longer has the step
     *
     * @return array{
     *     model: WorkflowTemplate,
     *     step: WorkflowStep,
     *     position: int,
     *     form: WorkflowStepFormView,
     *     formModel: WorkflowStep,
     *     jobTemplateOptions: array<int, string>,
     *     approvalRuleOptions: array<int, string>,
     *     mayAssignRules: bool,
     *     error: string|null,
     *     routedBy: list<array{label: string, note: string|null}>,
     *     activeRunIds: list<int>
     * }
     */
    public function editParams(
        WorkflowTemplate $model,
        WorkflowStep $step,
        ?WorkflowStep $posted,
        ?string $error,
        mixed $position = null
    ): array {
        $models = WorkflowRouteMap::stepsOf($model);
        $stepId = (int)$step->id;
        $stored = self::find($models, $stepId);
        if ($stored === null) {
            throw new WorkflowStepChangeRefusedException(
                sprintf(WorkflowStepWriteService::STEP_NOT_FOUND, (int)$model->id, $stepId),
                WorkflowStepChangeRefusedException::NOT_FOUND
            );
        }
        $map = WorkflowRouteMap::fromModels($models);
        $choices = $this->choices($model, Viewer::of($this->userId));

        return [
            'model' => $model,
            'step' => $stored,
            'position' => (int)$map->position($stepId),
            'form' => WorkflowStepFormView::forEdit($map, $stored, is_string($position) ? $position : null),
            'formModel' => $posted ?? $stored,
            'jobTemplateOptions' => $choices['jobTemplateOptions'],
            'approvalRuleOptions' => $choices['approvalRuleOptions'],
            'mayAssignRules' => $choices['mayAssignRules'],
            'error' => $error,
            'routedBy' => WorkflowStepRoutedBy::of($map, $stepId, array_map(
                static fn (WorkflowStep $routing): int => (int)$routing->id,
                self::guard()->stepsRoutingTo($stored)
            )),
            'activeRunIds' => self::guard()->activeRunIds((int)$model->id),
        ];
    }

    /**
     * What the step form posts (WorkflowStepCommand::fromForm()): its
     * WorkflowStep fields $posted and its Position $position, for Add Step,
     * or for Edit Step when $edit.
     *
     * @throws WorkflowStepChangeRefusedException (422) for a value the form does not offer
     */
    public static function formCommand(mixed $posted, mixed $position, bool $edit): WorkflowStepCommand
    {
        $command = WorkflowStepCommand::fromForm(is_array($posted) ? $posted : [], $position, $edit);
        if (is_string($command)) {
            throw self::invalid($command);
        }

        return $command;
    }

    /**
     * Where a move sends a step: ▲ and ▼ post a direction ("up", "down"),
     * the Move to… menu a Position ("start", "end", "after:<step ID>").
     * Exactly one of them: neither, or both, is refused before anything is
     * looked up, as WorkflowStepPosition::fromWeb() would read a missing
     * Position as the end.
     *
     * @throws WorkflowStepChangeRefusedException (422)
     */
    public static function movePosition(mixed $direction, mixed $position): WorkflowStepPosition
    {
        $byDirection = $direction !== null && $direction !== '';
        if ($byDirection === ($position !== null && $position !== '')) {
            throw self::invalid(WorkflowStepPosition::FORM_INVALID);
        }
        $to = $byDirection
            ? WorkflowStepPosition::fromDirection($direction)
            : WorkflowStepPosition::fromWeb($position, false);
        if (!$to instanceof WorkflowStepPosition) {
            throw self::invalid($to ?? WorkflowStepPosition::FORM_INVALID);
        }

        return $to;
    }

    /**
     * $stored as the edit form posted it ($posted, its WorkflowStep
     * fields), for the form shown again after a refusal: the name and the
     * targets as typed, and the branches as chosen, a branch the form left
     * out or empty being none, as WorkflowStepCommand::fromForm() reads it.
     * The type stays the stored one. $stored itself is not changed.
     */
    public static function postedStep(WorkflowStep $stored, mixed $posted): WorkflowStep
    {
        $posted = is_array($posted) ? $posted : [];
        $step = clone $stored;
        foreach (['name', 'job_template_id', 'approval_rule_id'] as $field) {
            if (array_key_exists($field, $posted) && is_scalar($posted[$field])) {
                $step->setAttribute($field, $posted[$field]);
            }
        }
        foreach (WorkflowStepCommand::ROUTE_FIELDS as $field) {
            $value = $posted[$field] ?? null;
            $step->setAttribute($field, is_scalar($value) && $value !== '' ? $value : null);
        }

        return $step;
    }

    /**
     * Step ID => how many of the runs $runStates wait at that step: their
     * current step waits for an approval, a resume or a runner
     * (RunState::$waitingFor). The steps table marks those steps.
     *
     * @param array<int, RunState> $runStates
     * @return array<int, int>
     */
    public static function waitingSteps(array $runStates): array
    {
        $waiting = [];
        foreach ($runStates as $state) {
            $current = $state->current;
            if ($current !== null && $current->waitingFor !== null && $current->workflowStepId !== null) {
                $waiting[$current->workflowStepId] = ($waiting[$current->workflowStepId] ?? 0) + 1;
            }
        }

        return $waiting;
    }

    /**
     * What the step form offers the user for $model, and why they may not
     * change its steps (null when they may).
     *
     * @return array{
     *     canOperate: bool,
     *     canChange: bool,
     *     mayAssignRules: bool,
     *     jobTemplateOptions: array<int, string>,
     *     approvalRuleOptions: array<int, string>,
     *     stepsLock: string|null
     * }
     */
    private function choices(WorkflowTemplate $model, Viewer $viewer): array
    {
        $canOperate = self::workflows()->canOperateWorkflowTemplate($this->userId, (int)$model->id);
        $policy = self::policy();
        $mayAssignRules = $policy->mayAssign($this->userId);

        return [
            'canOperate' => $canOperate,
            // Changing the workflow needs the RBAC permission and operator
            // access to every job step's project.
            'canChange' => $canOperate && $viewer->may(self::UPDATE_PERMISSION),
            'mayAssignRules' => $mayAssignRules,
            'jobTemplateOptions' => $canOperate ? $this->jobTemplateOptions($mayAssignRules) : [],
            // Approval steps, and so their rules, are for admins.
            'approvalRuleOptions' => $canOperate && $mayAssignRules ? self::approvalRuleOptions() : [],
            // Why the user may not change the steps: the workflow has an
            // approval step or a gated job step. Admins may.
            'stepsLock' => $mayAssignRules ? null : $policy->workflowStepsChangeRefusal($this->userId, $model, false),
        ];
    }

    /**
     * The step form's job templates: those the user may operate (a step
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
     * The active runs of the workflow $workflowTemplateId, oldest first, with
     * the users who launched them.
     *
     * @return list<WorkflowJob>
     */
    private static function activeRunsOf(int $workflowTemplateId): array
    {
        /** @var list<WorkflowJob> $runs */
        $runs = WorkflowJob::find()
            ->where(['workflow_template_id' => $workflowTemplateId, 'status' => WorkflowJob::activeStatuses()])
            ->with('launcher')
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return $runs;
    }

    /**
     * The step $stepId among $models; null when they do not hold it.
     *
     * @param list<WorkflowStep> $models
     */
    private static function find(array $models, int $stepId): ?WorkflowStep
    {
        foreach ($models as $model) {
            if ((int)$model->id === $stepId) {
                return $model;
            }
        }

        return null;
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

    private static function invalid(string $message): WorkflowStepChangeRefusedException
    {
        return new WorkflowStepChangeRefusedException($message, WorkflowStepChangeRefusedException::INVALID);
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

    private static function runStates(): WorkflowRunStates
    {
        /** @var WorkflowRunStates $states */
        $states = \Yii::$app->get('workflowRunStates');
        return $states;
    }

    private static function workflows(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $checker */
        $checker = \Yii::$app->get('workflowAccessChecker');
        return $checker;
    }
}
