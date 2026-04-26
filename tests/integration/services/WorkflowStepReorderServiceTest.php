<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\WorkflowStep;
use app\services\WorkflowStepReorderService;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for WorkflowStepReorderService.
 *
 * Steps inside a workflow used to expose step_order as a free integer
 * field with no move action — the only way to reorder was to manually
 * renumber every step. The service replaces that with atomic
 * moveUp/moveDown plus a sparse 10/20/30 layout so future inserts can
 * wedge in a new step by typing any value in between.
 */
class WorkflowStepReorderServiceTest extends DbTestCase
{
    private WorkflowStepReorderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WorkflowStepReorderService();
    }

    public function testResequenceRewritesAllStepsToSparseLayout(): void
    {
        [$wt, $steps] = $this->seedSteps([1, 2, 3, 5]);

        $this->service->resequence($wt);

        $orders = $this->reload($steps);
        $this->assertSame([10, 20, 30, 40], array_values($orders));
    }

    public function testResequenceFromAlreadySparseLayoutIsNoOp(): void
    {
        [$wt, $steps] = $this->seedSteps([10, 20, 30]);

        $this->service->resequence($wt);

        $orders = $this->reload($steps);
        $this->assertSame([10, 20, 30], array_values($orders));
    }

    public function testResequenceTolaratesEqualOrderValues(): void
    {
        // Two rows on the same step_order shouldn't cause the resequencer
        // to deadlock or skip — they're broken on id.
        [$wt, $steps] = $this->seedSteps([5, 5, 5]);

        $this->service->resequence($wt);

        $orders = $this->reload($steps);
        $this->assertSame([10, 20, 30], array_values($orders));
    }

    public function testMoveUpSwapsWithImmediatePredecessor(): void
    {
        [$wt, [$a, $b, $c]] = $this->seedSteps([10, 20, 30]);

        $this->assertTrue($this->service->moveUp($b));

        $orders = $this->reload([$a, $b, $c]);
        // After swap + resequence: order of rows by step_order is b, a, c.
        $sorted = $this->orderById($orders);
        $this->assertSame([20, 10, 30], $sorted, 'After moveUp(b): a→20, b→10, c→30.');
    }

    public function testMoveDownSwapsWithImmediateSuccessor(): void
    {
        [$wt, [$a, $b, $c]] = $this->seedSteps([10, 20, 30]);

        $this->assertTrue($this->service->moveDown($b));

        $sorted = $this->orderById($this->reload([$a, $b, $c]));
        $this->assertSame([10, 30, 20], $sorted, 'After moveDown(b): a→10, b→30, c→20.');
    }

    public function testMoveUpAtTopOfListIsSoftNoOp(): void
    {
        [, [$a, $b]] = $this->seedSteps([10, 20]);

        $this->assertFalse($this->service->moveUp($a), 'Top step has no predecessor → false.');

        $sorted = $this->orderById($this->reload([$a, $b]));
        $this->assertSame([10, 20], $sorted, 'Soft no-op must not mutate any step_order.');
    }

    public function testMoveDownAtBottomOfListIsSoftNoOp(): void
    {
        [, [$a, $b]] = $this->seedSteps([10, 20]);

        $this->assertFalse($this->service->moveDown($b));

        $sorted = $this->orderById($this->reload([$a, $b]));
        $this->assertSame([10, 20], $sorted);
    }

    public function testMoveUpThenMoveDownReturnsToOriginalLayout(): void
    {
        [, [$a, $b, $c]] = $this->seedSteps([10, 20, 30]);

        $this->service->moveUp($b);
        $this->service->moveDown($b);

        $sorted = $this->orderById($this->reload([$a, $b, $c]));
        $this->assertSame([10, 20, 30], $sorted, 'Up-then-down must be a true round-trip.');
    }

    public function testMoveDoesNotTouchStepsInOtherTemplates(): void
    {
        $user = $this->createUser();
        $wt1 = $this->createWorkflowTemplate($user->id);
        $wt2 = $this->createWorkflowTemplate($user->id);

        $a = $this->createWorkflowStep($wt1->id, 10);
        $b = $this->createWorkflowStep($wt1->id, 20);
        $foreign = $this->createWorkflowStep($wt2->id, 999);

        $this->service->moveUp($b);

        $foreign->refresh();
        $this->assertSame(999, (int)$foreign->step_order, 'Move must not leak across templates.');
    }

    /**
     * @param int[] $stepOrders
     * @return array{0: \app\models\WorkflowTemplate, 1: WorkflowStep[]}
     */
    private function seedSteps(array $stepOrders): array
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);
        $steps = [];
        foreach ($stepOrders as $order) {
            $steps[] = $this->createWorkflowStep($wt->id, $order);
        }
        return [$wt, $steps];
    }

    /**
     * @param WorkflowStep[] $steps
     * @return array<int, int> [stepId => step_order]
     */
    private function reload(array $steps): array
    {
        $out = [];
        foreach ($steps as $s) {
            $s->refresh();
            $out[$s->id] = (int)$s->step_order;
        }
        return $out;
    }

    /**
     * Order the [id => order] map by id ascending so test assertions can
     * compare against the seed order.
     *
     * @param array<int, int> $orders
     * @return list<int>
     */
    private function orderById(array $orders): array
    {
        ksort($orders);
        return array_values($orders);
    }
}
