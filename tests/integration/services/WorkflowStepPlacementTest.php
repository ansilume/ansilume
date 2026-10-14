<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\WorkflowStepPosition;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowStepChangeRefusedException;
use app\services\WorkflowStepReorderService;
use app\tests\integration\DbTestCase;
use yii\log\Logger;

/**
 * Placement (contract C3): the run order and the positions of a workflow's
 * steps, and the writes that insert, move and renumber them, on every
 * layout step_order can have: sparse as the web numbers steps (10, 20, 30),
 * dense as the API's bulk list numbers them (0, 1, 2), and tied as older
 * versions or database edits left them (5, 5, 5). Every write leaves the
 * steps numbered 10, 20, 30… in their new run order; a refusal (422) and a
 * move to where the step stands write nothing; other workflows are never
 * touched.
 */
class WorkflowStepPlacementTest extends DbTestCase
{
    /** The step orders of the steps A, B, C (and D), created in this order. */
    private const LAYOUTS = [
        'sparse' => [10, 20, 30, 40],
        'dense' => [0, 1, 2, 3],
        'tied' => [5, 5, 5, 5],
    ];

    private WorkflowStepReorderService $placement;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->placement = new WorkflowStepReorderService();
        $this->userId = (int)$this->createUser('placement')->id;
    }

    // -- Reading the run order ------------------------------------------------------------

    /**
     * The run order is by step order, ties by ID; the steps of other
     * workflows are not part of it.
     */
    public function testOrderedStepsListsTheRunOrder(): void
    {
        [$workflow] = $this->workflowWith(['A' => 20, 'B' => 5, 'C' => 5, 'D' => 0]);
        $this->workflowWith(['other' => 1]);

        $steps = $this->placement->orderedSteps((int)$workflow->id);

        $this->assertSame(['D', 'B', 'C', 'A'], array_map(static fn (WorkflowStep $s): string => $s->name, $steps));
        $this->assertSame([], $this->placement->orderedSteps((int)$this->createWorkflowTemplate($this->userId)->id));
    }

    /**
     * A step's position is its 1-based index in the run order, and position
     * 1 is the step a run starts with.
     */
    public function testPositionsAreTheIndexesInTheRunOrderFromOne(): void
    {
        [$workflow, $s] = $this->workflowWith(['A' => 20, 'B' => 5, 'C' => 5, 'D' => 0]);

        $positions = $this->placement->positions((int)$workflow->id);

        $this->assertSame([$s['D']->id => 1, $s['B']->id => 2, $s['C']->id => 3, $s['A']->id => 4], $positions);
        $this->assertSame(1, $positions[(int)$workflow->getStartStep()?->id]);
        $this->assertSame([], $this->placement->positions((int)$this->createWorkflowTemplate($this->userId)->id));
    }

    // -- Inserting a step that was just saved ---------------------------------------------

    /**
     * @return array<string, array{0: list<int>, 1: string, 2: list<string>, 3: int}>
     */
    public static function insertProvider(): array
    {
        $cases = [
            'at the start' => ['start', ['N', 'A', 'B', 'C'], 1],
            'at the end' => ['end', ['A', 'B', 'C', 'N'], 4],
            'after the first step' => ['after:A', ['A', 'N', 'B', 'C'], 2],
            'after a step in the middle' => ['after:B', ['A', 'B', 'N', 'C'], 3],
            'after the last step' => ['after:C', ['A', 'B', 'C', 'N'], 4],
            'at position 1' => ['at:1', ['N', 'A', 'B', 'C'], 1],
            'at position 2' => ['at:2', ['A', 'N', 'B', 'C'], 2],
            'at the position after the last step' => ['at:4', ['A', 'B', 'C', 'N'], 4],
        ];
        $data = [];
        foreach (self::LAYOUTS as $layout => $orders) {
            foreach ($cases as $label => [$spec, $expected, $to]) {
                $data["{$layout}: {$label}"] = [array_slice($orders, 0, 3), $spec, $expected, $to];
            }
        }

        return $data;
    }

    /**
     * A step that was just saved (with the column's default step order 0)
     * goes where the position says, and the steps are renumbered 10, 20,
     * 30… in their new run order.
     *
     * @dataProvider insertProvider
     * @param list<int> $orders
     * @param list<string> $expected
     */
    public function testInsertPlacesTheSavedStep(array $orders, string $spec, array $expected, int $to): void
    {
        [$workflow, $s] = $this->workflowWith(array_combine(['A', 'B', 'C'], $orders));
        $new = $this->step($workflow, 'N', 0);

        $placement = $this->placement->insert($new, $this->position($spec, $s));

        $this->assertSame(array_combine($expected, [10, 20, 30, 40]), $this->stored($workflow));
        $this->assertSame([
            'from' => 0,
            'to' => $to,
            'count' => 4,
            'order_before' => $this->ids($s, ['A', 'B', 'C']),
            'order_after' => $this->ids($s + ['N' => $new], $expected),
        ], $placement);
        $this->assertSame($to * WorkflowStepReorderService::ORDER_STEP, $new->step_order, 'the model holds what was stored');
        $this->assertSame([], $new->getDirtyAttributes());
    }

    /**
     * Where the step goes does not depend on the step order it was saved
     * with.
     */
    public function testInsertIgnoresTheStepOrderTheStepWasSavedWith(): void
    {
        foreach ([0, 15, WorkflowStep::MAX_STEP_ORDER] as $savedWith) {
            [$workflow, $s] = $this->workflowWith(['A' => 10, 'B' => 20]);
            $new = $this->step($workflow, 'N', $savedWith);

            $this->placement->insert($new, WorkflowStepPosition::after((int)$s['A']->id));

            $this->assertSame(['A' => 10, 'N' => 20, 'B' => 30], $this->stored($workflow), "saved with {$savedWith}");
        }
    }

    /**
     * The first step of a workflow takes the start, the end or position 1.
     */
    public function testInsertTheFirstStepOfAWorkflow(): void
    {
        foreach (['start', 'end', 'at:1'] as $spec) {
            [$workflow] = $this->workflowWith([]);
            $new = $this->step($workflow, 'N', 0);

            $placement = $this->placement->insert($new, $this->position($spec, []));

            $this->assertSame(['N' => 10], $this->stored($workflow), $spec);
            $this->assertSame(
                ['from' => 0, 'to' => 1, 'count' => 1, 'order_before' => [], 'order_after' => [(int)$new->id]],
                $placement,
                $spec
            );
        }
    }

    public function testInsertAfterItselfIsRefused(): void
    {
        [$workflow] = $this->workflowWith(['A' => 10]);
        $new = $this->step($workflow, 'N', 0);

        $this->assertRefused(
            'A step cannot be placed after itself.',
            fn () => $this->placement->insert($new, WorkflowStepPosition::after((int)$new->id))
        );
        $this->assertSame(['N' => 0, 'A' => 10], $this->stored($workflow), 'nothing was written');
    }

    /**
     * The step to place it after must be another step of the same workflow:
     * a step of another workflow, a removed one (a stale form) and an ID
     * that never existed are refused, and nothing is written.
     */
    public function testInsertAfterAStepThatIsNotPartOfTheWorkflowIsRefused(): void
    {
        [$workflow, $s] = $this->workflowWith(['A' => 10, 'B' => 20, 'C' => 30]);
        [, $other] = $this->workflowWith(['other' => 10]);
        $removedId = (int)$s['C']->id;
        $s['C']->delete();
        $new = $this->step($workflow, 'N', 0);

        foreach ([(int)$other['other']->id, $removedId, 987654321] as $anchorId) {
            $this->assertRefused(
                sprintf(WorkflowStepReorderService::AFTER_STEP_MISSING, $anchorId),
                fn () => $this->placement->insert($new, WorkflowStepPosition::after($anchorId))
            );
        }
        $this->assertSame(
            'The step to place it after (step ID 987654321) is not part of this workflow. '
                . 'Load the workflow again and choose another position.',
            $this->refusalOf(fn () => $this->placement->insert($new, WorkflowStepPosition::after(987654321)))
        );
        $this->assertSame(['N' => 0, 'A' => 10, 'B' => 20], $this->stored($workflow), 'nothing was written');
        $this->assertSame(['other' => 10], $this->stored($this->workflowOf($other['other'])));
    }

    /**
     * A step added to three takes a position from 1 to 4.
     */
    public function testInsertAtAPositionOutOfRangeIsRefused(): void
    {
        [$workflow] = $this->workflowWith(['A' => 10, 'B' => 20, 'C' => 30]);
        $new = $this->step($workflow, 'N', 0);

        foreach ([0, -1, 5, PHP_INT_MAX] as $position) {
            $this->assertRefused(
                'position must be "start", "end" or a number from 1 to 4.',
                fn () => $this->placement->insert($new, WorkflowStepPosition::at($position))
            );
        }
        $this->assertSame(['N' => 0, 'A' => 10, 'B' => 20, 'C' => 30], $this->stored($workflow), 'nothing was written');
    }

    /**
     * Up and down move a step that has a place already: inserting with
     * either is a caller's error, and writes nothing.
     */
    public function testInsertTakesNeitherUpNorDown(): void
    {
        [$workflow] = $this->workflowWith(['A' => 10]);
        $new = $this->step($workflow, 'N', 0);

        foreach ([WorkflowStepPosition::up(), WorkflowStepPosition::down()] as $direction) {
            try {
                $this->placement->insert($new, $direction);
                $this->fail('expected a caller error for ' . $direction->kind());
            } catch (\InvalidArgumentException $e) {
                $this->assertSame(WorkflowStepReorderService::DIRECTION_ON_INSERT, $e->getMessage());
            }
        }
        $this->assertSame(['N' => 0, 'A' => 10], $this->stored($workflow), 'nothing was written');
    }

    /**
     * insert() places a step that is saved; one that is not is a caller's
     * error.
     */
    public function testInsertOfAStepThatIsNotSavedIsACallerError(): void
    {
        [$workflow] = $this->workflowWith(['A' => 10]);
        $unsaved = new WorkflowStep();
        $unsaved->workflow_template_id = (int)$workflow->id;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Workflow template #%d has no step with ID 0 to place.', $workflow->id));
        $this->placement->insert($unsaved, WorkflowStepPosition::end());
    }

    // -- Moving a step --------------------------------------------------------------------

    /**
     * @return array<string, array{0: list<int>, 1: string, 2: string, 3: list<string>, 4: int, 5: int}>
     */
    public static function moveProvider(): array
    {
        $cases = [
            'the first step to the end' => ['A', 'end', ['B', 'C', 'D', 'A'], 1, 4],
            'the last step to the start' => ['D', 'start', ['D', 'A', 'B', 'C'], 4, 1],
            'forward, after a later step' => ['A', 'after:C', ['B', 'C', 'A', 'D'], 1, 3],
            'back, after an earlier step' => ['D', 'after:A', ['A', 'D', 'B', 'C'], 4, 2],
            'to the last position' => ['B', 'at:4', ['A', 'C', 'D', 'B'], 2, 4],
            'to the first position' => ['C', 'at:1', ['C', 'A', 'B', 'D'], 3, 1],
            'up' => ['B', 'up', ['B', 'A', 'C', 'D'], 2, 1],
            'down' => ['B', 'down', ['A', 'C', 'B', 'D'], 2, 3],
            'the last step up' => ['D', 'up', ['A', 'B', 'D', 'C'], 4, 3],
            'the first step down' => ['A', 'down', ['B', 'A', 'C', 'D'], 1, 2],
        ];
        $data = [];
        foreach (self::LAYOUTS as $layout => $orders) {
            foreach ($cases as $label => [$name, $spec, $expected, $from, $to]) {
                $data["{$layout}: {$label}"] = [$orders, $name, $spec, $expected, $from, $to];
            }
        }

        return $data;
    }

    /**
     * A move of any distance is one call: the step goes where the position
     * says, the others keep their order, and the steps are renumbered 10,
     * 20, 30… in their new run order.
     *
     * @dataProvider moveProvider
     * @param list<int> $orders
     * @param list<string> $expected
     */
    public function testMovePlacesTheStep(array $orders, string $name, string $spec, array $expected, int $from, int $to): void
    {
        [$workflow, $s] = $this->workflowWith(array_combine(['A', 'B', 'C', 'D'], $orders));

        $placement = $this->placement->move($s[$name], $this->position($spec, $s));

        $this->assertSame(array_combine($expected, [10, 20, 30, 40]), $this->stored($workflow));
        $this->assertSame([
            'from' => $from,
            'to' => $to,
            'count' => 4,
            'order_before' => $this->ids($s, ['A', 'B', 'C', 'D']),
            'order_after' => $this->ids($s, $expected),
        ], $placement);
        $this->assertSame($to * WorkflowStepReorderService::ORDER_STEP, $s[$name]->step_order, 'the model holds what was stored');
    }

    /**
     * @return array<string, array{0: list<int>, 1: string, 2: string, 3: int}>
     */
    public static function noOpMoveProvider(): array
    {
        $cases = [
            'the first step up' => ['A', 'up', 1],
            'the last step down' => ['D', 'down', 4],
            'the first step to the start' => ['A', 'start', 1],
            'the last step to the end' => ['D', 'end', 4],
            'after the step before it' => ['C', 'after:B', 3],
            'to its own position' => ['B', 'at:2', 2],
        ];
        $data = [];
        foreach (self::LAYOUTS as $layout => $orders) {
            foreach ($cases as $label => [$name, $spec, $position]) {
                $data["{$layout}: {$label}"] = [$orders, $name, $spec, $position];
            }
        }

        return $data;
    }

    /**
     * A move to where the step stands writes nothing, not even a
     * renumbering of a dense or tied layout, and says so: from === to.
     *
     * @dataProvider noOpMoveProvider
     * @param list<int> $orders
     */
    public function testAMoveToWhereTheStepStandsWritesNothing(array $orders, string $name, string $spec, int $position): void
    {
        [$workflow, $s] = $this->workflowWith(array_combine(['A', 'B', 'C', 'D'], $orders));
        $order = $this->ids($s, ['A', 'B', 'C', 'D']);

        $placement = null;
        $writes = $this->writesDuring(function () use (&$placement, $s, $name, $spec): void {
            $placement = $this->placement->move($s[$name], $this->position($spec, $s));
        });

        $this->assertSame([], $writes);
        $this->assertSame(
            ['from' => $position, 'to' => $position, 'count' => 4, 'order_before' => $order, 'order_after' => $order],
            $placement
        );
        $this->assertSame(array_combine(['A', 'B', 'C', 'D'], $orders), $this->stored($workflow));
    }

    /**
     * A move writes only the step order: other changes of the model it is
     * given (an edit saved after the move) stay unsaved.
     */
    public function testAMoveWritesOnlyTheStepOrder(): void
    {
        [$workflow, $s] = $this->workflowWith(['A' => 10, 'B' => 20, 'C' => 30]);
        $step = $s['A'];
        $step->name = 'renamed, not saved yet';

        $this->placement->move($step, WorkflowStepPosition::end());

        $this->assertSame(['B' => 10, 'C' => 20, 'A' => 30], $this->stored($workflow));
        $this->assertSame(30, $step->step_order);
        $this->assertSame(['name' => 'renamed, not saved yet'], $step->getDirtyAttributes());
    }

    /**
     * The model of a moved step may be stale: the move reads the run order
     * from the database, not from the model.
     */
    public function testAMoveReadsTheRunOrderFromTheDatabase(): void
    {
        [$workflow, $s] = $this->workflowWith(['A' => 10, 'B' => 20, 'C' => 30]);
        WorkflowStep::updateAll(['step_order' => 40], ['id' => $s['A']->id]);

        $placement = $this->placement->move($s['A'], WorkflowStepPosition::up());

        $this->assertSame(['from' => 3, 'to' => 2], array_slice($placement, 0, 2));
        $this->assertSame(['B' => 10, 'A' => 20, 'C' => 30], $this->stored($workflow));
    }

    public function testMoveAfterItselfIsRefused(): void
    {
        [$workflow, $s] = $this->workflowWith(['A' => 0, 'B' => 1, 'C' => 2]);

        $this->assertRefused(
            WorkflowStepReorderService::AFTER_ITSELF,
            fn () => $this->placement->move($s['B'], WorkflowStepPosition::after((int)$s['B']->id))
        );
        $this->assertSame(['A' => 0, 'B' => 1, 'C' => 2], $this->stored($workflow), 'nothing was written');
    }

    /**
     * The step to place it after must be another step of the same workflow.
     */
    public function testMoveAfterAStepThatIsNotPartOfTheWorkflowIsRefused(): void
    {
        [$workflow, $s] = $this->workflowWith(['A' => 5, 'B' => 5, 'C' => 5]);
        [, $other] = $this->workflowWith(['other' => 5]);
        $removedId = (int)$s['C']->id;
        $s['C']->delete();

        foreach ([(int)$other['other']->id, $removedId, 987654321] as $anchorId) {
            $this->assertRefused(
                sprintf(WorkflowStepReorderService::AFTER_STEP_MISSING, $anchorId),
                fn () => $this->placement->move($s['A'], WorkflowStepPosition::after($anchorId))
            );
        }
        $this->assertSame(['A' => 5, 'B' => 5], $this->stored($workflow), 'nothing was written');
    }

    /**
     * A step of four takes a position from 1 to 4.
     */
    public function testMoveToAPositionOutOfRangeIsRefused(): void
    {
        [$workflow, $s] = $this->workflowWith(['A' => 10, 'B' => 20, 'C' => 30, 'D' => 40]);

        foreach ([0, -1, 5] as $position) {
            $this->assertRefused(
                'position must be "start", "end" or a number from 1 to 4.',
                fn () => $this->placement->move($s['B'], WorkflowStepPosition::at($position))
            );
        }
        $this->assertSame(['A' => 10, 'B' => 20, 'C' => 30, 'D' => 40], $this->stored($workflow), 'nothing was written');
    }

    /**
     * The only step stays where it is, wherever it is moved; a position or
     * an anchor it cannot have is refused.
     */
    public function testTheOnlyStepOfAWorkflow(): void
    {
        [$workflow, $s] = $this->workflowWith(['A' => 7]);

        foreach (['start', 'end', 'up', 'down', 'at:1'] as $spec) {
            $placement = $this->placement->move($s['A'], $this->position($spec, $s));
            $this->assertSame([1, 1], [$placement['from'], $placement['to']], $spec);
        }
        $this->assertSame(['A' => 7], $this->stored($workflow), 'nothing was written');
        $this->assertRefused(
            sprintf(WorkflowStepReorderService::POSITION_OUT_OF_RANGE, 1),
            fn () => $this->placement->move($s['A'], WorkflowStepPosition::at(2))
        );
    }

    /**
     * move() moves a step of its workflow; a step that was removed is a
     * caller's error, which a caller that reloads the step under the lock
     * answers as not found first.
     */
    public function testMoveOfARemovedStepIsACallerError(): void
    {
        [$workflow, $s] = $this->workflowWith(['A' => 10, 'B' => 20]);
        $s['A']->delete();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Workflow template #%d has no step with ID %d to place.', $workflow->id, $s['A']->id));
        $this->placement->move($s['A'], WorkflowStepPosition::end());
    }

    // -- Renumbering ------------------------------------------------------------------------

    /**
     * @return array<string, array{0: array<string, int>, 1: array<string, int>, 2: int}>
     */
    public static function renumberProvider(): array
    {
        return [
            'sparse: nothing to write' => [['A' => 10, 'B' => 20, 'C' => 30], ['A' => 10, 'B' => 20, 'C' => 30], 0],
            'dense' => [['A' => 0, 'B' => 1, 'C' => 2], ['A' => 10, 'B' => 20, 'C' => 30], 3],
            'tied: by ID' => [['A' => 5, 'B' => 5, 'C' => 5], ['A' => 10, 'B' => 20, 'C' => 30], 3],
            'created out of order' => [['A' => 30, 'B' => 10, 'C' => 20], ['B' => 10, 'C' => 20, 'A' => 30], 0],
            'one value differs' => [['A' => 10, 'B' => 25, 'C' => 30], ['A' => 10, 'B' => 20, 'C' => 30], 1],
            'the gap of a removed step' => [['A' => 10, 'C' => 30], ['A' => 10, 'C' => 20], 1],
        ];
    }

    /**
     * renumber() numbers the steps 10, 20, 30… in run order, and writes
     * only the values that differ.
     *
     * @dataProvider renumberProvider
     * @param array<string, int> $orders
     * @param array<string, int> $expected
     */
    public function testRenumberWritesOnlyTheValuesThatDiffer(array $orders, array $expected, int $updates): void
    {
        [$workflow] = $this->workflowWith($orders);

        $writes = $this->writesDuring(fn () => $this->placement->renumber((int)$workflow->id));

        $this->assertSame($expected, $this->stored($workflow));
        $this->assertCount($updates, preg_grep('/^UPDATE `workflow_step` SET `step_order`=/', $writes) ?: []);
    }

    public function testRenumberOfAWorkflowWithoutStepsWritesNothing(): void
    {
        $workflow = $this->createWorkflowTemplate($this->userId);

        $this->assertSame([], $this->writesDuring(fn () => $this->placement->renumber((int)$workflow->id)));
    }

    // -- Other workflows --------------------------------------------------------------------

    /**
     * Inserting, moving and renumbering the steps of one workflow leaves
     * every other workflow's steps as they are, also tied ones.
     */
    public function testOtherWorkflowsStayUntouched(): void
    {
        [$workflow, $s] = $this->workflowWith(['A' => 5, 'B' => 5, 'C' => 5]);
        [$other] = $this->workflowWith(['X' => 5, 'Y' => 5, 'Z' => 0]);

        $this->placement->insert($this->step($workflow, 'N', 0), WorkflowStepPosition::start());
        $this->placement->move($s['C'], WorkflowStepPosition::at(1));
        $this->placement->renumber((int)$workflow->id);

        $this->assertSame(['C' => 10, 'N' => 20, 'A' => 30, 'B' => 40], $this->stored($workflow));
        $this->assertSame(['Z' => 0, 'X' => 5, 'Y' => 5], $this->stored($other));
    }

    // -- Helpers ----------------------------------------------------------------------------

    /**
     * A workflow with pause steps named and numbered as $orders, created in
     * this order, so by ascending ID.
     *
     * @param array<string, int> $orders name => step order
     * @return array{0: WorkflowTemplate, 1: array<string, WorkflowStep>}
     */
    private function workflowWith(array $orders): array
    {
        $workflow = $this->createWorkflowTemplate($this->userId);
        $steps = [];
        foreach ($orders as $name => $order) {
            $steps[$name] = $this->step($workflow, $name, $order);
        }

        return [$workflow, $steps];
    }

    private function step(WorkflowTemplate $workflow, string $name, int $order): WorkflowStep
    {
        $step = new WorkflowStep();
        $step->workflow_template_id = (int)$workflow->id;
        $step->name = $name;
        $step->step_type = WorkflowStep::TYPE_PAUSE;
        $step->step_order = $order;
        $step->save(false);

        return $step;
    }

    private function workflowOf(WorkflowStep $step): WorkflowTemplate
    {
        $workflow = WorkflowTemplate::findOne((int)$step->workflow_template_id);
        $this->assertNotNull($workflow);

        return $workflow;
    }

    /**
     * The stored step orders of the workflow's steps, name => step order,
     * read from the database by step order, then ID.
     *
     * @return array<string, int>
     */
    private function stored(WorkflowTemplate $workflow): array
    {
        $rows = (new \yii\db\Query())
            ->select(['name', 'step_order'])
            ->from(WorkflowStep::tableName())
            ->where(['workflow_template_id' => $workflow->id])
            ->orderBy(['step_order' => SORT_ASC, 'id' => SORT_ASC])
            ->all();
        $stored = [];
        foreach ($rows as $row) {
            $stored[(string)$row['name']] = (int)$row['step_order'];
        }

        return $stored;
    }

    /**
     * @param array<string, WorkflowStep> $steps
     * @param list<string> $names
     * @return list<int>
     */
    private function ids(array $steps, array $names): array
    {
        return array_map(static fn (string $name): int => (int)$steps[$name]->id, $names);
    }

    /**
     * "start", "end", "up", "down", "at:<position>" or "after:<name>".
     *
     * @param array<string, WorkflowStep> $steps
     */
    private function position(string $spec, array $steps): WorkflowStepPosition
    {
        [$kind, $argument] = array_pad(explode(':', $spec, 2), 2, '');

        return match ($kind) {
            'start' => WorkflowStepPosition::start(),
            'end' => WorkflowStepPosition::end(),
            'up' => WorkflowStepPosition::up(),
            'down' => WorkflowStepPosition::down(),
            'at' => WorkflowStepPosition::at((int)$argument),
            'after' => WorkflowStepPosition::after((int)$steps[$argument]->id),
        };
    }

    /**
     * $call is refused with $message as a 422 without details.
     */
    private function assertRefused(string $message, callable $call): void
    {
        try {
            $call();
            $this->fail("expected the refusal: {$message}");
        } catch (WorkflowStepChangeRefusedException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame(WorkflowStepChangeRefusedException::INVALID, $e->status);
            $this->assertSame([], $e->details);
        }
    }

    /**
     * The message $call is refused with.
     */
    private function refusalOf(callable $call): string
    {
        try {
            $call();
        } catch (WorkflowStepChangeRefusedException $e) {
            return $e->getMessage();
        }
        $this->fail('expected a refusal');
    }

    /**
     * The statements $action sends that are not reads.
     *
     * @return list<string>
     */
    private function writesDuring(callable $action): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $action();
        } finally {
            \Yii::setLogger($previous);
        }
        $writes = [];
        foreach ($logger->messages as $message) {
            if (
                in_array($message[2], ['yii\db\Command::query', 'yii\db\Command::execute'], true)
                && $message[1] === Logger::LEVEL_INFO
                && is_string($message[0])
                && preg_match('/^\s*(SELECT|SHOW)\b/i', $message[0]) !== 1
            ) {
                $writes[] = $message[0];
            }
        }

        return $writes;
    }
}
