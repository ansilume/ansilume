<?php

declare(strict_types=1);

namespace app\services;

use app\components\SurveyDefinition;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use yii\base\Component;

/**
 * Guards every change of a workflow's steps: adding, removing and moving a
 * step (web), and replacing them all (API).
 *
 * A change runs in one transaction that first locks the workflow template's
 * row (WorkflowTemplate::lockLive()). A launch locks the same row, so no run
 * starts between the checks below and the write, and two changes of one
 * workflow's steps run one after the other. Then, in this order, each
 * refusal a WorkflowStepChangeRefusedException that rolls back and writes
 * nothing:
 * - 404 when the template was deleted meanwhile;
 * - 403 when the user may not change these steps: gated steps, approval
 *   steps and job steps whose job template has an approval rule, are for
 *   admins (ApprovalRuleAssignmentPolicy::workflowStepsChangeRefusal(); a
 *   gated job step that a change adds is checked by the caller first,
 *   ApprovalRuleAssignmentPolicy::stepsAddRefusal());
 * - 409 while a run of the workflow is active (RUNS_ACTIVE, naming the
 *   runs): a run reads the live steps at every transition, so a change would
 *   route it differently than it started, or strand it;
 * - the change itself, which can refuse too: removing a step that other
 *   steps route to (409, remove()), a step that cannot be saved (422).
 *
 * A new template (an API create) has no row to lock and no runs: only the
 * rule of gated steps applies.
 */
class WorkflowStepChangeGuard extends Component
{
    /** sprintf(): the active runs, e.g. "#12, #15". */
    public const RUNS_ACTIVE = 'Steps cannot change while run(s) %s of this workflow are active: '
        . 'wait for them to finish or cancel them.';

    /** sprintf(): the step's name, quoted; the steps that route to it, e.g. '#10 "Build", #30 "Retry"'. */
    public const STEP_ROUTED_TO = 'Step %s was not removed: other steps route to it (%s). Remove those steps first, '
        . 'and add them again with other routes if you still need them.';

    /** sprintf(): the workflow template's ID. */
    public const TEMPLATE_GONE = 'Workflow template #%d not found.';

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
     * Remove the step $stepId of $template through change(). Refused (409)
     * while other steps of the workflow route to it: the run would fail at
     * such a route. A step that routes to itself only can go. Returns the
     * removed step, or null when the workflow has no such step.
     *
     * @throws WorkflowStepChangeRefusedException
     */
    public function remove(WorkflowTemplate $template, int $userId, int $stepId): ?WorkflowStep
    {
        return $this->change($template, $userId, false, function () use ($template, $stepId): ?WorkflowStep {
            /** @var WorkflowStep|null $step */
            $step = WorkflowStep::findOne(['id' => $stepId, 'workflow_template_id' => $template->id]);
            if ($step === null) {
                return null;
            }
            $routing = $this->stepsRoutingTo($step);
            if ($routing !== []) {
                throw new WorkflowStepChangeRefusedException(
                    self::routedToMessage($step, $routing),
                    WorkflowStepChangeRefusedException::CONFLICT,
                    ['step_ids' => array_map(static fn (WorkflowStep $s): int => (int)$s->id, $routing)]
                );
            }
            $step->delete();

            return $step;
        });
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
     * route leads to $step, by step order.
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
        if (!$isNew && !WorkflowTemplate::lockLive((int)$template->id)) {
            throw new WorkflowStepChangeRefusedException(
                sprintf(self::TEMPLATE_GONE, (int)$template->id),
                WorkflowStepChangeRefusedException::NOT_FOUND
            );
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
     * @param list<WorkflowStep> $routing
     */
    private static function routedToMessage(WorkflowStep $step, array $routing): string
    {
        $labels = array_map(
            static fn (WorkflowStep $s): string => '#' . (int)$s->step_order . ' ' . SurveyDefinition::quote((string)$s->name),
            $routing
        );

        return sprintf(self::STEP_ROUTED_TO, SurveyDefinition::quote((string)$step->name), implode(', ', $labels));
    }
}
