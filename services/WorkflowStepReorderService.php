<?php

declare(strict_types=1);

namespace app\services;

use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use yii\base\Component;
use yii\db\Transaction;

/**
 * Re-order steps within a {@see WorkflowTemplate}.
 *
 * Three operations:
 *
 *   - {@see moveUp()} / {@see moveDown()}: swap a step with its immediate
 *     neighbour by step_order. Wrapped in a transaction so a partial swap
 *     can never leave two rows pointing at the same slot.
 *
 *   - {@see resequence()}: rewrite every step's step_order to a sparse
 *     10/20/30/... layout. Run after every change so an operator who
 *     types step_order=15 to wedge between 10 and 20 keeps room for
 *     future inserts; without this, sparse becomes dense over time and
 *     "insert between two existing steps" stops working.
 *
 *   - {@see endOrder()}: the step_order after the last step, which a step
 *     added with Step Order left empty gets.
 *
 * The previous UX exposed step_order as a free integer field with no
 * move action — the only way to reorder was to manually renumber every
 * step, which nobody actually did.
 */
class WorkflowStepReorderService extends Component
{
    /** Gap between consecutive step_order values after resequencing. */
    public const ORDER_STEP = 10;

    public function moveUp(WorkflowStep $step): bool
    {
        return $this->swap($step, direction: -1);
    }

    public function moveDown(WorkflowStep $step): bool
    {
        return $this->swap($step, direction: 1);
    }

    /**
     * The step_order that puts a new step of $template after its last step:
     * ORDER_STEP above the highest, so ORDER_STEP in a workflow without
     * steps. It never exceeds WorkflowStep::MAX_STEP_ORDER; a step that ties
     * there goes after the other, as every tie does (resequence()). Read it
     * with the workflow locked (WorkflowStepChangeGuard::change()), so that
     * steps added at the same time go one after the other.
     */
    public function endOrder(WorkflowTemplate $template): int
    {
        $highest = WorkflowStep::find()
            ->where(['workflow_template_id' => $template->id])
            ->max('step_order');

        return min((int)$highest + self::ORDER_STEP, WorkflowStep::MAX_STEP_ORDER);
    }

    /**
     * Rewrite every step in the given template to a 10/20/30/... layout.
     * Runs inside the caller's transaction when one is open; otherwise
     * opens its own.
     */
    public function resequence(WorkflowTemplate $template): void
    {
        $tx = $this->startTx();
        try {
            $steps = WorkflowStep::find()
                ->where(['workflow_template_id' => $template->id])
                ->orderBy(['step_order' => SORT_ASC, 'id' => SORT_ASC])
                ->all();

            $next = self::ORDER_STEP;
            foreach ($steps as $step) {
                /** @var WorkflowStep $step */
                if ((int)$step->step_order !== $next) {
                    $step->step_order = $next;
                    $step->save(false, ['step_order']);
                }
                $next += self::ORDER_STEP;
            }
            $tx?->commit();
        } catch (\Throwable $e) {
            $tx?->rollBack();
            throw $e;
        }
    }

    /**
     * Atomically swap $step with its neighbour in the requested direction
     * (-1 for up, +1 for down). Returns false when the move would fall off
     * either end of the list — that's a soft no-op, not an error, so the
     * caller doesn't need to special-case top/bottom rows.
     */
    private function swap(WorkflowStep $step, int $direction): bool
    {
        $template = $step->workflowTemplate;
        if ($template === null) {
            return false;
        }

        $tx = $this->startTx();
        try {
            $neighbour = $this->findNeighbour($step, $direction);
            if ($neighbour === null) {
                $tx?->commit();
                return false;
            }

            $aOrder = (int)$step->step_order;
            $bOrder = (int)$neighbour->step_order;

            // Two rows can't carry the same step_order while a unique
            // constraint is in place. The schema doesn't have one today,
            // but resequencing tolerates equal values too — swap via a
            // sentinel so this stays safe if a constraint is added later.
            $step->step_order = -1;
            $step->save(false, ['step_order']);

            $neighbour->step_order = $aOrder;
            $neighbour->save(false, ['step_order']);

            $step->step_order = $bOrder;
            $step->save(false, ['step_order']);

            $this->resequence($template);
            $tx?->commit();
            return true;
        } catch (\Throwable $e) {
            $tx?->rollBack();
            throw $e;
        }
    }

    /**
     * Find the step whose step_order is the next one above ($direction=-1)
     * or below ($direction=+1) the given step inside the same template.
     * Tie-breaks on id so a duplicated step_order doesn't deadlock.
     */
    private function findNeighbour(WorkflowStep $step, int $direction): ?WorkflowStep
    {
        $query = WorkflowStep::find()
            ->where(['workflow_template_id' => $step->workflow_template_id])
            ->andWhere(['<>', 'id', $step->id]);

        if ($direction < 0) {
            $query->andWhere([
                'or',
                ['<', 'step_order', $step->step_order],
                ['and', ['step_order' => $step->step_order], ['<', 'id', $step->id]],
            ])->orderBy(['step_order' => SORT_DESC, 'id' => SORT_DESC]);
        } else {
            $query->andWhere([
                'or',
                ['>', 'step_order', $step->step_order],
                ['and', ['step_order' => $step->step_order], ['>', 'id', $step->id]],
            ])->orderBy(['step_order' => SORT_ASC, 'id' => SORT_ASC]);
        }

        /** @var WorkflowStep|null $neighbour */
        $neighbour = $query->one();
        return $neighbour;
    }

    private function startTx(): ?Transaction
    {
        $db = \Yii::$app->db;
        return $db->getTransaction() === null ? $db->beginTransaction() : null;
    }
}
