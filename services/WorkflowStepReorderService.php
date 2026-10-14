<?php

declare(strict_types=1);

namespace app\services;

use app\components\WorkflowStepPosition;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use yii\base\Component;

/**
 * Where the steps of a workflow stand, and the writes that place them.
 *
 * Steps run in the run order (WorkflowTemplate::RUN_ORDER: by step_order,
 * ties by ID). A step's position is its 1-based index in that order, and
 * position 1 is the start step. step_order is an internal sort key that
 * nobody types: every write renumbers the workflow's steps ORDER_STEP,
 * 2 × ORDER_STEP, … (10, 20, 30…) in their run order, and writes only the
 * values that differ. Steps numbered 0, 1, 2… (the API's bulk list) or
 * sharing a step_order (older versions, database edits) read the same
 * through the run order, and the next write renumbers them.
 *
 * - orderedSteps() and positions() read the run order.
 * - insert() places a step that was just saved, move() moves a step, and
 *   renumber() renumbers the steps (after a remove). insert() and move()
 *   resolve a WorkflowStepPosition against the run order without the step
 *   ("rest"): the start is index 0 and the end count(rest); after a step is
 *   right behind it (422 AFTER_ITSELF for the step itself, 422
 *   AFTER_STEP_MISSING for a step that is not in rest); a position p takes
 *   1 ≤ p ≤ count(rest) + 1 (422 POSITION_OUT_OF_RANGE); up and down (a
 *   move only) go one place, and stop at either end. A move of any
 *   distance is one call, and a move to where the step stands, such as ▲
 *   on the first step, writes nothing. A refusal is a
 *   WorkflowStepChangeRefusedException (422), thrown before anything is
 *   written.
 *
 * The writers run inside WorkflowStepChangeGuard::change(), which locks the
 * workflow's row: the order they read is the order they write, and no run
 * starts meanwhile. Each writes in a transaction of its own (a savepoint in
 * the guard's), so a renumbering is never half done. The step write service
 * (WorkflowStepWriteService) calls them for the web and the API; ▲ and ▼ are
 * a move() up or down, so they also move a step that shares its step_order
 * with its neighbour.
 */
class WorkflowStepReorderService extends Component
{
    /** The gap between the step_order values of consecutive steps after a write. */
    public const ORDER_STEP = 10;

    /** sprintf(): the ID of the step to place it after, which is not (or no longer) part of the workflow. */
    public const AFTER_STEP_MISSING = 'The step to place it after (step ID %d) is not part of this workflow. '
        . 'Load the workflow again and choose another position.';

    /** A step to be placed after itself. */
    public const AFTER_ITSELF = 'A step cannot be placed after itself.';

    /** sprintf(): the highest position the step can take, which is the number of steps with it. */
    public const POSITION_OUT_OF_RANGE = 'position must be "start", "end" or a number from 1 to %d.';

    /**
     * sprintf(): the workflow template's ID and the step's ID. insert() and
     * move() place a saved step of the workflow: a caller's error (500),
     * never an answer to a user.
     */
    public const STEP_NOT_IN_WORKFLOW = 'Workflow template #%1$d has no step with ID %2$d to place.';

    /**
     * insert() was given up or down, which only move a step that has a
     * place already: a caller's error (500), never an answer to a user.
     */
    public const DIRECTION_ON_INSERT = 'A step that is added goes to the start, to the end, after a step or to a '
        . 'position, not up or down.';

    /**
     * The steps of workflow template $workflowTemplateId in run order.
     *
     * @return list<WorkflowStep>
     */
    public function orderedSteps(int $workflowTemplateId): array
    {
        /** @var list<WorkflowStep> $steps */
        $steps = WorkflowStep::find()
            ->where(['workflow_template_id' => $workflowTemplateId])
            ->orderBy(WorkflowTemplate::RUN_ORDER)
            ->all();

        return $steps;
    }

    /**
     * The position of each step of workflow template $workflowTemplateId:
     * step ID => 1-based position in the run order.
     *
     * @return array<int, int>
     */
    public function positions(int $workflowTemplateId): array
    {
        $positions = [];
        foreach (array_keys($this->storedOrders($workflowTemplateId)) as $index => $stepId) {
            $positions[$stepId] = $index + 1;
        }

        return $positions;
    }

    /**
     * Place $saved, a step just saved into its workflow, at $at, and
     * renumber the workflow's steps. from is 0; order_before is the run
     * order without the step, order_after the run order with it.
     *
     * @return array{from: int, to: int, count: int, order_before: list<int>, order_after: list<int>}
     * @throws WorkflowStepChangeRefusedException (422) for a place the step cannot take
     * @throws \InvalidArgumentException for a step that is not saved, and for up or down
     */
    public function insert(WorkflowStep $saved, WorkflowStepPosition $at): array
    {
        $stored = $this->storedOrdersWith($saved);
        $stepId = (int)$saved->id;
        $rest = self::without($stored, $stepId);
        $index = self::target($at, $stepId, $rest, null);
        $after = self::placed($rest, $stepId, $index);
        $this->write($stored, $after, $saved);

        return self::placement(0, $index + 1, $rest, $after);
    }

    /**
     * Move $step to $to, and renumber the workflow's steps. When the step
     * stays where it is, nothing is written, and from === to.
     *
     * @return array{from: int, to: int, count: int, order_before: list<int>, order_after: list<int>}
     * @throws WorkflowStepChangeRefusedException (422) for a place the step cannot take
     * @throws \InvalidArgumentException for a step that is not part of its workflow
     */
    public function move(WorkflowStep $step, WorkflowStepPosition $to): array
    {
        $stored = $this->storedOrdersWith($step);
        $stepId = (int)$step->id;
        $before = array_keys($stored);
        $from = (int)array_search($stepId, $before, true);
        $rest = self::without($stored, $stepId);
        $index = self::target($to, $stepId, $rest, $from);
        $after = self::placed($rest, $stepId, $index);
        if ($after !== $before) {
            $this->write($stored, $after, $step);
        }

        return self::placement($from + 1, $index + 1, $before, $after);
    }

    /**
     * Renumber the steps of workflow template $workflowTemplateId
     * ORDER_STEP, 2 × ORDER_STEP, … in run order, writing only the values
     * that differ.
     */
    public function renumber(int $workflowTemplateId): void
    {
        $stored = $this->storedOrders($workflowTemplateId);
        $this->write($stored, array_keys($stored), null);
    }

    /**
     * Step ID => stored step_order of the steps of workflow template
     * $workflowTemplateId, in run order.
     *
     * @return array<int, int>
     */
    private function storedOrders(int $workflowTemplateId): array
    {
        $stored = [];
        foreach ($this->orderedSteps($workflowTemplateId) as $step) {
            $stored[(int)$step->id] = (int)$step->step_order;
        }

        return $stored;
    }

    /**
     * storedOrders() of the workflow of $step, which holds $step.
     *
     * @return array<int, int>
     * @throws \InvalidArgumentException for a step that is not part of its workflow (not saved, or removed)
     */
    private function storedOrdersWith(WorkflowStep $step): array
    {
        $workflowTemplateId = (int)$step->workflow_template_id;
        $stored = $this->storedOrders($workflowTemplateId);
        if (!array_key_exists((int)$step->id, $stored)) {
            throw new \InvalidArgumentException(
                sprintf(self::STEP_NOT_IN_WORKFLOW, $workflowTemplateId, (int)$step->id)
            );
        }

        return $stored;
    }

    /**
     * The run order of $stored without the step $stepId.
     *
     * @param array<int, int> $stored
     * @return list<int>
     */
    private static function without(array $stored, int $stepId): array
    {
        unset($stored[$stepId]);

        return array_keys($stored);
    }

    /**
     * The 0-based index in the new run order at which $at puts the step
     * $stepId. $rest is the run order without the step, and $current the
     * step's index before a move (null when it is inserted).
     *
     * @param list<int> $rest
     * @throws WorkflowStepChangeRefusedException (422)
     */
    private static function target(WorkflowStepPosition $at, int $stepId, array $rest, ?int $current): int
    {
        return match ($at->kind()) {
            WorkflowStepPosition::KIND_START => 0,
            WorkflowStepPosition::KIND_END => count($rest),
            WorkflowStepPosition::KIND_AFTER => self::afterIndex((int)$at->stepId(), $stepId, $rest),
            WorkflowStepPosition::KIND_AT => self::atIndex((int)$at->position(), count($rest)),
            WorkflowStepPosition::KIND_UP => max(self::currentIndex($current) - 1, 0),
            WorkflowStepPosition::KIND_DOWN => min(self::currentIndex($current) + 1, count($rest)),
        };
    }

    /**
     * Right behind the step $anchorId, which must be another step of the
     * workflow.
     *
     * @param list<int> $rest
     * @throws WorkflowStepChangeRefusedException (422)
     */
    private static function afterIndex(int $anchorId, int $stepId, array $rest): int
    {
        if ($anchorId === $stepId) {
            throw self::refused(self::AFTER_ITSELF);
        }
        $index = array_search($anchorId, $rest, true);
        if ($index === false) {
            throw self::refused(sprintf(self::AFTER_STEP_MISSING, $anchorId));
        }

        return $index + 1;
    }

    /**
     * At the 1-based position $position: 1 to $restCount + 1.
     *
     * @throws WorkflowStepChangeRefusedException (422)
     */
    private static function atIndex(int $position, int $restCount): int
    {
        if ($position < 1 || $position > $restCount + 1) {
            throw self::refused(sprintf(self::POSITION_OUT_OF_RANGE, $restCount + 1));
        }

        return $position - 1;
    }

    /**
     * The index of a step before it moves up or down. A step that is
     * inserted has none.
     *
     * @throws \InvalidArgumentException when it is inserted
     */
    private static function currentIndex(?int $current): int
    {
        if ($current === null) {
            throw new \InvalidArgumentException(self::DIRECTION_ON_INSERT);
        }

        return $current;
    }

    /**
     * $rest with the step $stepId at the index $index.
     *
     * @param list<int> $rest
     * @return list<int>
     */
    private static function placed(array $rest, int $stepId, int $index): array
    {
        return array_merge(array_slice($rest, 0, $index), [$stepId], array_slice($rest, $index));
    }

    /**
     * Write (k + 1) × ORDER_STEP as the step_order of the k-th step of
     * $order wherever $stored holds another value, in one transaction.
     * $step, when given, keeps what was written for it.
     *
     * @param array<int, int> $stored step ID => stored step_order
     * @param list<int> $order step IDs in the run order to write
     */
    private function write(array $stored, array $order, ?WorkflowStep $step): void
    {
        $changes = [];
        foreach ($order as $index => $stepId) {
            $value = ($index + 1) * self::ORDER_STEP;
            if ($stored[$stepId] !== $value) {
                $changes[$stepId] = $value;
            }
        }
        if ($changes === []) {
            return;
        }
        WorkflowStep::getDb()->transaction(static function () use ($changes): void {
            foreach ($changes as $stepId => $value) {
                WorkflowStep::updateAll(['step_order' => $value], ['id' => $stepId]);
            }
        });
        if ($step !== null && isset($changes[(int)$step->id])) {
            $step->step_order = $changes[(int)$step->id];
            $step->setOldAttribute('step_order', $step->step_order);
        }
    }

    /**
     * What insert() and move() return: the step's position before ($from)
     * and after ($to), and the run order before and after.
     *
     * @param list<int> $before
     * @param list<int> $after
     * @return array{from: int, to: int, count: int, order_before: list<int>, order_after: list<int>}
     */
    private static function placement(int $from, int $to, array $before, array $after): array
    {
        return [
            'from' => $from,
            'to' => $to,
            'count' => count($after),
            'order_before' => $before,
            'order_after' => $after,
        ];
    }

    private static function refused(string $message): WorkflowStepChangeRefusedException
    {
        return new WorkflowStepChangeRefusedException($message, WorkflowStepChangeRefusedException::INVALID);
    }
}
