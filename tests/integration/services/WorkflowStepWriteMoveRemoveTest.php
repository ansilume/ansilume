<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\WorkflowStepChange;
use app\components\WorkflowStepPosition;
use app\models\AuditLog;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepChangeSummary;
use app\services\WorkflowStepReorderService;
use app\services\WorkflowStepWriteService;
use app\tests\integration\DbTestCase;

/**
 * WorkflowStepWriteService::move() and remove() (contract C5). A move: 404
 * for a step the workflow does not have, the guard, the step again (404),
 * the placement (422); a move to where the step stands writes nothing. A
 * remove: the guard, null for an unknown step, 409 while other steps route
 * to it, then the delete and the renumbering.
 */
class WorkflowStepWriteMoveRemoveTest extends DbTestCase
{
    use WorkflowStepWriteFixtures;

    private User $admin;
    private WorkflowTemplate $workflow;
    private WorkflowStep $build;
    private WorkflowStep $test;
    private WorkflowStep $deploy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('write-move-admin', 'admin');
        $this->workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $this->build = $this->namedStep((int)$this->workflow->id, 'Build', 10);
        $this->test = $this->namedStep((int)$this->workflow->id, 'Test', 20);
        $this->deploy = $this->namedStep((int)$this->workflow->id, 'Deploy', 30);
    }

    // -- move() -------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>, 3: int, 4: int}>
     */
    public static function moveProvider(): array
    {
        return [
            'to the start' => ['deploy', 'start', ['Deploy', 'Build', 'Test'], 3, 1],
            'to the end' => ['build', 'end', ['Test', 'Deploy', 'Build'], 1, 3],
            'after a step' => ['build', 'after:test', ['Test', 'Build', 'Deploy'], 1, 2],
            'to a position' => ['deploy', 'at:2', ['Build', 'Deploy', 'Test'], 3, 2],
            'up' => ['test', 'up', ['Test', 'Build', 'Deploy'], 2, 1],
            'down' => ['test', 'down', ['Build', 'Deploy', 'Test'], 2, 3],
        ];
    }

    /**
     * Any distance is one call; the step keeps its ID.
     *
     * @dataProvider moveProvider
     * @param list<string> $order
     */
    public function testAStepMovesWhereThePositionPutsIt(string $step, string $to, array $order, int $from, int $position): void
    {
        $moved = $this->stepNamed($step);

        $change = $this->writer()->move($this->workflow, (int)$moved->id, $this->position($to), (int)$this->admin->id, WorkflowStepWriteService::SOURCE_WEB);

        $this->assertSame(WorkflowStepChange::MOVED, $change->kind);
        $this->assertSame((int)$moved->id, (int)$change->step->id);
        $this->assertSame([$from, $position], [$change->from, $change->to]);
        $this->assertTrue($change->moved());
        $this->assertSame([], $change->changes);
        $this->assertSame(['Build', 'Test', 'Deploy'], $this->names($change->stepsBefore));
        $this->assertSame($order, $this->names($change->stepsAfter));
        $this->assertSame($order, $this->runOrder((int)$this->workflow->id));
        $this->assertSame(WorkflowStepWriteService::SOURCE_WEB, $change->source);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function stayProvider(): array
    {
        return [
            'up on the first step' => ['build', 'up'],
            'down on the last step' => ['deploy', 'down'],
            'after the step before it' => ['test', 'after:build'],
            'to its own position' => ['test', 'at:2'],
            'the first to the start' => ['build', 'start'],
            'the last to the end' => ['deploy', 'end'],
        ];
    }

    /**
     * A move to where the step stands writes nothing and is not audited. It
     * is a MOVED change with from === to, so the summary says where the step
     * stays (STAYS) instead of calling it unchanged like an edit that
     * changed nothing.
     *
     * @dataProvider stayProvider
     */
    public function testAMoveToWhereTheStepStandsWritesNothing(string $step, string $to): void
    {
        $stays = $this->stepNamed($step);
        $change = null;

        $writes = $this->writesOf(function () use (&$change, $stays, $to): void {
            $change = $this->writer()->move($this->workflow, (int)$stays->id, $this->position($to), (int)$this->admin->id, 'web');
        });

        $this->assertInstanceOf(WorkflowStepChange::class, $change);
        $this->assertSame(WorkflowStepChange::MOVED, $change->kind);
        $this->assertSame($change->from, $change->to);
        $this->assertFalse($change->moved());
        $summary = WorkflowStepChangeSummary::of($change);
        $this->assertSame(
            sprintf(WorkflowStepChangeSummary::STAYS, (string)$stays->name, $change->to),
            $summary->message()
        );
        $this->assertTrue($summary->isInfo());
        $this->assertSame([], $summary->warnings());
        $this->assertSame($change->stepsBefore, $change->stepsAfter);
        $this->assertSame([], $writes);
        $this->assertSame([], $this->stepAudits((int)$this->workflow->id));
    }

    /**
     * Steps that share a step order (older versions, database edits) move
     * by their run order, ties by ID.
     */
    public function testStepsThatShareAStepOrderMoveByTheRunOrder(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $first = $this->namedStep((int)$workflow->id, 'First', 5);
        $second = $this->namedStep((int)$workflow->id, 'Second', 5);

        $change = $this->writer()->move($workflow, (int)$second->id, WorkflowStepPosition::up(), (int)$this->admin->id, 'web');

        $this->assertSame([WorkflowStepChange::MOVED, 2, 1], [$change->kind, $change->from, $change->to]);
        $this->assertSame(['Second', 'First'], $this->runOrder((int)$workflow->id));
        $this->assertSame([10, 20], [WorkflowStep::findOne($second->id)?->step_order, WorkflowStep::findOne($first->id)?->step_order]);
    }

    /**
     * 404 before the guard: an operator on a gated workflow learns that the
     * step does not exist, not that they may not change it.
     */
    public function testAStepTheWorkflowDoesNotHaveIsNotFound(): void
    {
        $operator = $this->createUserWithRole('write-move-operator', 'operator');
        $this->namedStep((int)$this->workflow->id, 'Approve', 40, WorkflowStep::TYPE_APPROVAL, null, (int)$this->createApprovalRule((int)$this->admin->id)->id);
        $elsewhere = $this->namedStep((int)$this->createWorkflowTemplate((int)$this->admin->id)->id, 'Elsewhere', 10);

        foreach ([987654321, (int)$elsewhere->id] as $stepId) {
            $refusal = $this->refusalOf(fn () => $this->writer()->move($this->workflow, $stepId, WorkflowStepPosition::start(), (int)$operator->id, 'api'));
            $this->assertSame(404, $refusal->status);
            $this->assertSame(sprintf(WorkflowStepWriteService::STEP_NOT_FOUND, (int)$this->workflow->id, $stepId), $refusal->getMessage());
        }
    }

    public function testAStepRemovedMeanwhileIsNotFound(): void
    {
        $refusal = $this->meanwhile(
            fn () => WorkflowStep::deleteAll(['id' => $this->test->id]),
            fn () => $this->refusalOf(fn () => $this->writer()->move($this->workflow, (int)$this->test->id, WorkflowStepPosition::start(), (int)$this->admin->id, 'api'))
        );

        $this->assertSame(404, $refusal->status);
        $this->assertSame(sprintf(WorkflowStepWriteService::STEP_NOT_FOUND, (int)$this->workflow->id, (int)$this->test->id), $refusal->getMessage());
    }

    public function testAPlaceTheStepCannotTakeIsRefused(): void
    {
        $cases = [
            'after itself' => [WorkflowStepPosition::after((int)$this->test->id), WorkflowStepReorderService::AFTER_ITSELF],
            'after no step of the workflow' => [WorkflowStepPosition::after(987654321), sprintf(WorkflowStepReorderService::AFTER_STEP_MISSING, 987654321)],
            'past the end' => [WorkflowStepPosition::at(4), sprintf(WorkflowStepReorderService::POSITION_OUT_OF_RANGE, 3)],
        ];
        foreach ($cases as $case => [$to, $message]) {
            $refusal = $this->refusalOf(fn () => $this->writer()->move($this->workflow, (int)$this->test->id, $to, (int)$this->admin->id, 'api'));
            $this->assertSame([422, $message], [$refusal->status, $refusal->getMessage()], $case);
        }
        $this->assertSame(['Build', 'Test', 'Deploy'], $this->runOrder((int)$this->workflow->id));
        $this->assertSame([], $this->stepAudits((int)$this->workflow->id));
    }

    public function testAMoveIsRefusedWhileARunIsActive(): void
    {
        $run = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);

        $refusal = $this->refusalOf(fn () => $this->writer()->move($this->workflow, (int)$this->test->id, WorkflowStepPosition::start(), (int)$this->admin->id, 'web'));

        $this->assertSame([409, ['workflow_job_ids' => [(int)$run->id]]], [$refusal->status, $refusal->details]);
        $this->assertSame(['Build', 'Test', 'Deploy'], $this->runOrder((int)$this->workflow->id));
    }

    /**
     * ▲ and ▼ record their direction; the other moves do not.
     */
    public function testTheAuditEntryOfAMove(): void
    {
        $this->writer()->move($this->workflow, (int)$this->deploy->id, WorkflowStepPosition::up(), (int)$this->admin->id, 'web');
        $this->writer()->move($this->workflow, (int)$this->build->id, WorkflowStepPosition::after((int)$this->deploy->id), (int)$this->admin->id, 'api');

        $identity = fn (WorkflowStep $step): array => [
            'step_id' => (int)$step->id,
            'step_name' => (string)$step->name,
            'step_type' => WorkflowStep::TYPE_PAUSE,
            'job_template_id' => null,
            'approval_rule_id' => null,
        ];
        [$build, $test, $deploy] = [(int)$this->build->id, (int)$this->test->id, (int)$this->deploy->id];
        $this->assertSame([
            [
                'action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_MOVED,
                'user_id' => (int)$this->admin->id,
                'context' => $identity($this->deploy) + [
                    'from_position' => 3,
                    'to_position' => 2,
                    'order_before' => [$build, $test, $deploy],
                    'order_after' => [$build, $deploy, $test],
                    'direction' => 'up',
                    'source' => 'web',
                ],
            ],
            [
                'action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_MOVED,
                'user_id' => (int)$this->admin->id,
                'context' => $identity($this->build) + [
                    'from_position' => 1,
                    'to_position' => 2,
                    'order_before' => [$build, $deploy, $test],
                    'order_after' => [$deploy, $build, $test],
                    'source' => 'api',
                ],
            ],
        ], $this->stepAudits((int)$this->workflow->id));
    }

    // -- remove() -----------------------------------------------------------------

    public function testRemoveDeletesTheStepRenumbersTheOthersAndReportsIt(): void
    {
        $change = $this->writer()->remove($this->workflow, (int)$this->test->id, (int)$this->admin->id, WorkflowStepWriteService::SOURCE_API);

        $this->assertNotNull($change);
        $this->assertSame(WorkflowStepChange::REMOVED, $change->kind);
        $this->assertSame((int)$this->test->id, (int)$change->step->id);
        $this->assertSame('Test', $change->step->name);
        $this->assertSame([2, 0], [$change->from, $change->to]);
        $this->assertSame(2, $change->count());
        $this->assertSame(['Build', 'Test', 'Deploy'], $this->names($change->stepsBefore));
        $this->assertSame(['Build', 'Deploy'], $this->names($change->stepsAfter));
        $this->assertNull(WorkflowStep::findOne($this->test->id));
        $this->assertSame(
            [(int)$this->build->id => 10, (int)$this->deploy->id => 20],
            array_map('intval', WorkflowStep::find()->select('step_order')->where(['workflow_template_id' => $this->workflow->id])->indexBy('id')->column())
        );
    }

    public function testRemoveIgnoresAStepTheWorkflowDoesNotHave(): void
    {
        $foreign = $this->namedStep((int)$this->createWorkflowTemplate((int)$this->admin->id)->id, 'Elsewhere', 10);

        $this->assertNull($this->writer()->remove($this->workflow, 987654321, (int)$this->admin->id, 'web'));
        $this->assertNull($this->writer()->remove($this->workflow, (int)$foreign->id, (int)$this->admin->id, 'web'));
        $this->assertNotNull(WorkflowStep::findOne($foreign->id), 'a step of another workflow is not removed');
        $this->assertSame([], $this->stepAudits((int)$this->workflow->id));
    }

    /**
     * The guard comes first: an operator gets 403 on a gated workflow even
     * for a step it does not have.
     */
    public function testTheGuardComesBeforeTheStep(): void
    {
        $operator = $this->createUserWithRole('write-remove-operator', 'operator');
        $this->namedStep((int)$this->workflow->id, 'Approve', 40, WorkflowStep::TYPE_APPROVAL, null, (int)$this->createApprovalRule((int)$this->admin->id)->id);

        $this->assertSame(403, $this->refusalOf(fn () => $this->writer()->remove($this->workflow, 987654321, (int)$operator->id, 'web'))->status);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function routeProvider(): array
    {
        return [
            'on success' => ['on_success_step_id'],
            'on failure' => ['on_failure_step_id'],
            'always' => ['on_always_step_id'],
        ];
    }

    /**
     * Regression guard: removing a step left the routes of other steps
     * pointing at it; a run that reached such a route ended there.
     *
     * @dataProvider routeProvider
     */
    public function testRemovingAStepThatAnotherStepRoutesToIsRefused(string $route): void
    {
        $this->deploy->name = 'Deploy "prod"';
        $this->deploy->save(false);
        $this->test->setAttribute($route, (int)$this->deploy->id);
        $this->test->save(false);

        $refusal = $this->refusalOf(fn () => $this->writer()->remove($this->workflow, (int)$this->deploy->id, (int)$this->admin->id, 'web'));

        $this->assertSame(409, $refusal->status);
        $this->assertSame(sprintf(WorkflowStepChangeGuard::STEP_ROUTED_TO, '"Deploy \"prod\""', 'step 2 "Test"'), $refusal->getMessage());
        $this->assertSame(['step_ids' => [(int)$this->test->id]], $refusal->details);
        $this->assertNotNull(WorkflowStep::findOne($this->deploy->id), 'not removed');
        $this->assertSame([], $this->stepAudits((int)$this->workflow->id));
    }

    public function testTheRefusalNamesEveryRoutingStepByPosition(): void
    {
        foreach ([$this->deploy, $this->build] as $step) {
            $step->on_failure_step_id = (int)$this->test->id;
            $step->save(false);
        }

        $refusal = $this->refusalOf(fn () => $this->writer()->remove($this->workflow, (int)$this->test->id, (int)$this->admin->id, 'web'));

        $this->assertSame(sprintf(WorkflowStepChangeGuard::STEP_ROUTED_TO, '"Test"', 'step 1 "Build", step 3 "Deploy"'), $refusal->getMessage());
        $this->assertSame(['step_ids' => [(int)$this->build->id, (int)$this->deploy->id]], $refusal->details);
    }

    /**
     * A step that routes to itself (a retry loop) and routes from steps of
     * other workflows, which no run takes, do not keep a step.
     */
    public function testOnlyRoutesFromOtherStepsOfTheWorkflowKeepAStep(): void
    {
        $this->test->on_failure_step_id = (int)$this->test->id;
        $this->test->save(false);
        $elsewhere = $this->namedStep((int)$this->createWorkflowTemplate((int)$this->admin->id)->id, 'Elsewhere', 10);
        $elsewhere->on_success_step_id = (int)$this->test->id;
        $elsewhere->save(false);

        $this->assertNotNull($this->writer()->remove($this->workflow, (int)$this->test->id, (int)$this->admin->id, 'web'));
        $this->assertNull(WorkflowStep::findOne($this->test->id));
    }

    public function testRemoveIsRefusedWhileARunIsActive(): void
    {
        $run = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);

        $refusal = $this->refusalOf(fn () => $this->writer()->remove($this->workflow, (int)$this->test->id, (int)$this->admin->id, 'web'));

        $this->assertSame([409, ['workflow_job_ids' => [(int)$run->id]]], [$refusal->status, $refusal->details]);
        $this->assertNotNull(WorkflowStep::findOne($this->test->id));
    }

    public function testTheAuditEntryOfARemove(): void
    {
        $this->writer()->remove($this->workflow, (int)$this->deploy->id, (int)$this->admin->id, WorkflowStepWriteService::SOURCE_WEB);

        $this->assertSame([[
            'action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_REMOVED,
            'user_id' => (int)$this->admin->id,
            'context' => [
                'step_id' => (int)$this->deploy->id,
                'step_name' => 'Deploy',
                'step_type' => WorkflowStep::TYPE_PAUSE,
                'job_template_id' => null,
                'approval_rule_id' => null,
                'position' => 3,
                'source' => 'web',
            ],
        ]], $this->stepAudits((int)$this->workflow->id));
    }

    private function stepNamed(string $key): WorkflowStep
    {
        return match ($key) {
            'build' => $this->build,
            'test' => $this->test,
            default => $this->deploy,
        };
    }

    private function position(string $to): WorkflowStepPosition
    {
        return match (true) {
            $to === 'start' => WorkflowStepPosition::start(),
            $to === 'end' => WorkflowStepPosition::end(),
            $to === 'up' => WorkflowStepPosition::up(),
            $to === 'down' => WorkflowStepPosition::down(),
            str_starts_with($to, 'at:') => WorkflowStepPosition::at((int)substr($to, 3)),
            default => WorkflowStepPosition::after((int)$this->stepNamed(substr($to, 6))->id),
        };
    }
}
