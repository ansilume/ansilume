<?php

declare(strict_types=1);

namespace app\services;

use app\components\SurveyDefinition;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use yii\base\Component;

/**
 * Guards every change of a workflow's steps: adding, editing, moving and
 * removing one step (WorkflowStepWriteService, for the web and the API), and
 * replacing them all (API).
 *
 * A change runs in one transaction that first locks the workflow template's
 * row (WorkflowTemplate::lockLive()). A launch locks the same row, so no run
 * starts between the checks below and the write, and two changes of one
 * workflow's steps run one after the other. Then, in this order, each
 * refusal a WorkflowStepChangeRefusedException that rolls back and writes
 * nothing:
 * - 404 when the template was deleted meanwhile;
 * - 403 (TEAM_SCOPE_REFUSED) when the user may not operate the project of
 *   every job step the workflow has now (team scope, WorkflowAccessChecker::
 *   canOperateWorkflowTemplate()). The callers check it before, but another
 *   user may add a step of a project the user may only view, or not see at
 *   all, between that check and the lock: checked again here, no change
 *   goes through for a user who may no longer operate the workflow;
 * - 403 when the user may not change these steps: gated steps, approval
 *   steps and job steps whose job template or its inventory has an approval
 *   rule, are for admins
 *   (ApprovalRuleAssignmentPolicy::workflowStepsChangeRefusal(); a gated
 *   job step that a change adds, or that an edit makes, is checked by the
 *   caller first, ApprovalRuleAssignmentPolicy::stepsAddRefusal(), and
 *   again under the lock (WorkflowStepWriteService, and the API's
 *   replacement of all steps) by the change itself, below);
 * - 409 while a run of the workflow is active (RUNS_ACTIVE, naming the
 *   runs): a run reads the live steps at every transition, so a change would
 *   route it differently than it started, or strand it;
 * - the change itself, which can refuse too: a job template that got, or
 *   whose inventory got, an approval rule since the caller's check (403,
 *   stepsAddRefusal() again), removing a step that other steps route to
 *   (409, STEP_ROUTED_TO), a step that cannot be saved or a position that
 *   cannot be taken (422).
 *
 * A new template (an API create) has no row to lock, no steps and no runs:
 * only the rule of gated steps applies.
 */
class WorkflowStepChangeGuard extends Component
{
    /** sprintf(): the active runs, e.g. "#12, #15". */
    public const RUNS_ACTIVE = 'Steps cannot change while run(s) %s of this workflow are active: '
        . 'wait for them to finish or cancel them.';

    /**
     * sprintf(): the step's name, quoted; the steps that route to it by
     * position (ROUTING_STEP_LABEL), e.g. 'step 1 "Build", step 3 "Retry"'.
     */
    public const STEP_ROUTED_TO = 'Step %s was not removed: other steps route to it (%s). Edit those steps to lead '
        . 'elsewhere first, then remove it.';

    /**
     * One step that STEP_ROUTED_TO lists. sprintf(): its position, its
     * name, quoted. A position, not the step order: positions are what the
     * template page shows.
     */
    public const ROUTING_STEP_LABEL = 'step %1$d %2$s';

    /** sprintf(): the workflow template's ID. */
    public const TEMPLATE_GONE = 'Workflow template #%d not found.';

    /**
     * The 403 of a user who may not operate the project of every job step of
     * the workflow, checked again with the workflow locked: the text of the
     * API's own check before the change (WorkflowStepsController::FORBIDDEN,
     * and the workflow template update's). The guard does not know whether
     * the template page or the API asks; the template page answers this
     * refusal in the words of its own check instead
     * (WorkflowTemplateController maps it to BaseController::MODIFY_FORBIDDEN,
     * the text of TeamScopingTrait::requireWorkflowOperate()).
     */
    public const TEAM_SCOPE_REFUSED = 'Forbidden.';

    /** How many runs RUNS_ACTIVE names; the others are counted ("and 3 more"). */
    public const RUN_LIST_LIMIT = 10;

    /**
     * Run $change in one transaction, after the checks of the class
     * description. $change may refuse with a WorkflowStepChangeRefusedException
     * too; any exception rolls back and is rethrown. Returns what $change
     * returned, after the commit.
     *
     * @template T
     * @param bool $addsApprovalStep the change adds an approval step, or (an API
     *     replacement) the new steps include one
     * @param callable(): T $change
     * @return T
     * @throws WorkflowStepChangeRefusedException
     */
    public function change(WorkflowTemplate $template, int $userId, bool $addsApprovalStep, callable $change): mixed
    {
        $transaction = \Yii::$app->db->beginTransaction();
        try {
            $this->assertMayChange($template, $userId, $addsApprovalStep);
            $result = $change();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        $transaction->commit();

        return $result;
    }

    /**
     * Refuse (409, STEP_ROUTED_TO with the routing steps' step_ids) to
     * remove $step while other steps of its workflow route to it.
     *
     * @param array<int, int> $positions the workflow's steps: step ID => position
     * @throws WorkflowStepChangeRefusedException
     */
    public function assertNotRoutedTo(WorkflowStep $step, array $positions): void
    {
        $routing = $this->stepsRoutingTo($step);
        if ($routing === []) {
            return;
        }
        throw new WorkflowStepChangeRefusedException(
            self::routedToMessage($step, $routing, $positions),
            WorkflowStepChangeRefusedException::CONFLICT,
            ['step_ids' => array_map(static fn (WorkflowStep $s): int => (int)$s->id, $routing)]
        );
    }

    /**
     * The IDs of the workflow's active runs (WorkflowJob::activeStatuses()),
     * ascending.
     *
     * @return list<int>
     */
    public function activeRunIds(int $workflowTemplateId): array
    {
        $ids = WorkflowJob::find()
            ->select('id')
            ->where(['workflow_template_id' => $workflowTemplateId, 'status' => WorkflowJob::activeStatuses()])
            ->orderBy(['id' => SORT_ASC])
            ->column();

        return array_values(array_map('intval', $ids));
    }

    /**
     * The other steps of $step's workflow whose success, failure or always
     * route leads to $step, in the run order (step order, then ID).
     *
     * @return list<WorkflowStep>
     */
    public function stepsRoutingTo(WorkflowStep $step): array
    {
        $stepId = (int)$step->id;
        /** @var list<WorkflowStep> $steps */
        $steps = WorkflowStep::find()
            ->where(['workflow_template_id' => $step->workflow_template_id])
            ->andWhere(['<>', 'id', $stepId])
            ->andWhere([
                'or',
                ['on_success_step_id' => $stepId],
                ['on_failure_step_id' => $stepId],
                ['on_always_step_id' => $stepId],
            ])
            ->orderBy(['step_order' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return $steps;
    }

    /**
     * RUNS_ACTIVE for these runs: the first RUN_LIST_LIMIT by ID, the others
     * counted.
     *
     * @param list<int> $runIds
     */
    public static function runsActiveMessage(array $runIds): string
    {
        $named = array_map(static fn (int $id): string => '#' . $id, array_slice($runIds, 0, self::RUN_LIST_LIMIT));
        $more = count($runIds) - count($named);
        $list = implode(', ', $named) . ($more > 0 ? sprintf(' and %d more', $more) : '');

        return sprintf(self::RUNS_ACTIVE, $list);
    }

    /**
     * @throws WorkflowStepChangeRefusedException
     */
    private function assertMayChange(WorkflowTemplate $template, int $userId, bool $addsApprovalStep): void
    {
        $isNew = $template->getIsNewRecord();
        if (!$isNew) {
            $this->assertLiveAndOperable((int)$template->id, $userId);
        }
        /** @var ApprovalRuleAssignmentPolicy $policy */
        $policy = \Yii::$app->get('approvalRuleAssignmentPolicy');
        $refusal = $policy->workflowStepsChangeRefusal($userId, $template, $addsApprovalStep);
        if ($refusal !== null) {
            throw new WorkflowStepChangeRefusedException($refusal, WorkflowStepChangeRefusedException::FORBIDDEN);
        }
        $active = $isNew ? [] : $this->activeRunIds((int)$template->id);
        if ($active !== []) {
            throw new WorkflowStepChangeRefusedException(
                self::runsActiveMessage($active),
                WorkflowStepChangeRefusedException::CONFLICT,
                ['workflow_job_ids' => $active]
            );
        }
    }

    /**
     * Lock the row of the workflow template $templateId: 404 when it was
     * deleted meanwhile. Then 403 (TEAM_SCOPE_REFUSED) when $userId may not
     * operate the project of every job step it has now, steps that another
     * user added since the caller's own check included.
     *
     * Regression: the team scope was checked only before the lock, so a step
     * of a project the user may only view, added in between, did not stop
     * the change.
     *
     * @throws WorkflowStepChangeRefusedException
     */
    private function assertLiveAndOperable(int $templateId, int $userId): void
    {
        if (!WorkflowTemplate::lockLive($templateId)) {
            throw new WorkflowStepChangeRefusedException(
                sprintf(self::TEMPLATE_GONE, $templateId),
                WorkflowStepChangeRefusedException::NOT_FOUND
            );
        }
        /** @var WorkflowAccessChecker $workflows */
        $workflows = \Yii::$app->get('workflowAccessChecker');
        if (!$workflows->canOperateWorkflowTemplate($userId, $templateId)) {
            throw new WorkflowStepChangeRefusedException(self::TEAM_SCOPE_REFUSED, WorkflowStepChangeRefusedException::FORBIDDEN);
        }
    }

    /**
     * STEP_ROUTED_TO for $step and the steps that route to it, each named by
     * its position in $positions (ROUTING_STEP_LABEL), in the order given.
     *
     * Regression: the steps were named by their step order ("#10"), an
     * internal sort key that every change renumbers and the page no longer
     * shows.
     *
     * @param list<WorkflowStep> $routing
     * @param array<int, int> $positions the workflow's steps: step ID => position
     */
    public static function routedToMessage(WorkflowStep $step, array $routing, array $positions): string
    {
        $labels = array_map(
            static fn (WorkflowStep $s): string => sprintf(
                self::ROUTING_STEP_LABEL,
                $positions[(int)$s->id] ?? 0,
                SurveyDefinition::quote((string)$s->name)
            ),
            $routing
        );

        return sprintf(self::STEP_ROUTED_TO, SurveyDefinition::quote((string)$step->name), implode(', ', $labels));
    }
}
