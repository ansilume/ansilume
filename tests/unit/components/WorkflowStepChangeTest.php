<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\WorkflowStepChange;
use app\models\WorkflowStep;
use PHPUnit\Framework\TestCase;

/**
 * WorkflowStepChange (contract C4): what one change of a workflow's steps
 * did. count() is the number of steps after the change; moved() tells
 * whether the step stands at another position than before, which only a move
 * or an update can do.
 */
class WorkflowStepChangeTest extends TestCase
{
    public function testTheKindsAreLowerCaseWords(): void
    {
        $this->assertSame(
            ['added', 'updated', 'moved', 'removed', 'unchanged'],
            [
                WorkflowStepChange::ADDED,
                WorkflowStepChange::UPDATED,
                WorkflowStepChange::MOVED,
                WorkflowStepChange::REMOVED,
                WorkflowStepChange::UNCHANGED,
            ]
        );
    }

    public function testItKeepsWhatTheWritePathReports(): void
    {
        [$build, $test, $deploy] = [new WorkflowStep(), new WorkflowStep(), new WorkflowStep()];
        $changes = [
            'name' => ['from' => 'Test', 'to' => 'Smoke test'],
            'on_failure_step_id' => ['from' => null, 'to' => 0],
            'position' => ['from' => 2, 'to' => 3],
        ];

        $change = new WorkflowStepChange(
            WorkflowStepChange::UPDATED,
            $test,
            2,
            3,
            [$build, $test, $deploy],
            [$build, $deploy, $test],
            $changes,
            'api'
        );

        $this->assertSame(WorkflowStepChange::UPDATED, $change->kind);
        $this->assertSame($test, $change->step);
        $this->assertSame(2, $change->from);
        $this->assertSame(3, $change->to);
        $this->assertSame([$build, $test, $deploy], $change->stepsBefore);
        $this->assertSame([$build, $deploy, $test], $change->stepsAfter);
        $this->assertSame($changes, $change->changes);
        $this->assertSame('api', $change->source);
        $this->assertSame(3, $change->count());
        $this->assertTrue($change->moved());
    }

    public function testCountIsTheNumberOfStepsAfterTheChange(): void
    {
        $build = new WorkflowStep();
        $deploy = new WorkflowStep();

        $secondAdded = $this->change(WorkflowStepChange::ADDED, 0, 2, [$build], [$build, $deploy]);
        $lastRemoved = $this->change(WorkflowStepChange::REMOVED, 1, 0, [$build], []);

        $this->assertSame(2, $secondAdded->count());
        $this->assertSame(0, $lastRemoved->count());
    }

    /**
     * @return array<string, array{0: 'added'|'updated'|'moved'|'removed'|'unchanged', 1: int, 2: int, 3: bool}>
     */
    public static function movedProvider(): array
    {
        return [
            'a move up' => [WorkflowStepChange::MOVED, 3, 1, true],
            'a move down' => [WorkflowStepChange::MOVED, 1, 2, true],
            'an update that moved the step' => [WorkflowStepChange::UPDATED, 2, 1, true],
            'an update in place' => [WorkflowStepChange::UPDATED, 2, 2, false],
            'an added step was placed, not moved' => [WorkflowStepChange::ADDED, 0, 2, false],
            'a removed step was taken out, not moved' => [WorkflowStepChange::REMOVED, 2, 0, false],
            'an unchanged step' => [WorkflowStepChange::UNCHANGED, 2, 2, false],
        ];
    }

    /**
     * @dataProvider movedProvider
     * @param 'added'|'updated'|'moved'|'removed'|'unchanged' $kind
     */
    public function testOnlyAMoveOrAnUpdateToAnotherPositionMovesTheStep(string $kind, int $from, int $to, bool $moved): void
    {
        $steps = [new WorkflowStep(), new WorkflowStep(), new WorkflowStep()];

        $this->assertSame($moved, $this->change($kind, $from, $to, $steps, $steps)->moved());
    }

    /**
     * @param 'added'|'updated'|'moved'|'removed'|'unchanged' $kind
     * @param list<WorkflowStep> $before
     * @param list<WorkflowStep> $after
     */
    private function change(string $kind, int $from, int $to, array $before, array $after): WorkflowStepChange
    {
        return new WorkflowStepChange($kind, new WorkflowStep(), $from, $to, $before, $after, [], 'web');
    }
}
