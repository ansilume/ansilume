<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\WorkflowStepPosition;
use app\models\ApprovalRule;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepReorderService;
use app\services\WorkflowStepWriteService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\WorkflowStepWriteFixtures;

/**
 * The per-step endpoints of the workflow API where the workflow's state
 * refuses a change (contract C13, answers 10 to 15): runs that are active
 * (409 with workflow_job_ids), a step that other steps route to (409 with
 * step_ids, DELETE), a workflow or a step that another request removed
 * meanwhile (404), a lock that another request gave the workflow meanwhile
 * (403), and a position the step cannot take (422). Nothing is written then,
 * and nothing is audited.
 */
class WorkflowStepsControllerConflictTest extends WebControllerTestCase
{
    use WorkflowStepApiRequests;
    use WorkflowStepWriteFixtures;

    /** @var array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate} */
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->teamScope();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function endpointProvider(): array
    {
        return ['POST' => ['create'], 'PUT' => ['update'], 'move' => ['move'], 'DELETE' => ['delete']];
    }

    /**
     * Nobody changes the steps while a run of the workflow is active, an
     * admin neither: 409 with the runs in workflow_job_ids.
     *
     * @dataProvider endpointProvider
     */
    public function testNobodyChangesTheStepsWhileARunIsActive(string $endpoint): void
    {
        [$workflow, $ids] = $this->pipeline();
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$this->s['admin']->id);
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->s['admin']);

        $answer = $this->api(...$this->request($endpoint, (int)$workflow->id, $ids['Test']));

        $this->assertApiError(409, WorkflowStepChangeGuard::runsActiveMessage([(int)$run->id]), $answer, ['workflow_job_ids' => [(int)$run->id]]);
        $this->assertSame($before, $this->stepRows((int)$workflow->id));
        $this->assertSame([], $this->stepAudits((int)$workflow->id));
    }

    /**
     * A finished run does not block a change.
     *
     * @dataProvider endpointProvider
     */
    public function testAFinishedRunDoesNotBlockAChange(string $endpoint): void
    {
        [$workflow, $ids] = $this->pipeline();
        $this->createWorkflowJob((int)$workflow->id, (int)$this->s['admin']->id, WorkflowJob::STATUS_SUCCEEDED);
        $this->tokenOf($this->s['member']);

        $answer = $this->api(...$this->request($endpoint, (int)$workflow->id, $ids['Test']));

        $this->assertSame($endpoint === 'create' ? 201 : 200, $answer['status'], (string)json_encode($answer['body']));
    }

    /**
     * The answers that come before the active run's 409: the body (6) and
     * the model's rules (9); and the ones after it: a step removed
     * meanwhile (13), a position the step cannot take (14) and the steps
     * that route to it (15).
     */
    public function testAnActiveRunIsReportedAfterTheRequestAndBeforeTheWorkflowsState(): void
    {
        [$workflow, $ids] = $this->pipeline();
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$this->s['admin']->id);
        $this->tokenOf($this->s['member']);
        $id = (int)$workflow->id;
        $runsActive = ['message' => WorkflowStepChangeGuard::runsActiveMessage([(int)$run->id]), 'workflow_job_ids' => [(int)$run->id]];

        $this->assertApiError(422, WorkflowStepPosition::DIRECTION_INVALID, $this->api('POST', $this->movePath($id, $ids['Test']), ['direction' => 'left']));
        $this->assertApiError(422, 'Name cannot be blank.', $this->api('POST', $this->stepsPath($id), ['name' => '', 'step_type' => 'pause']));
        $this->assertSame(['error' => $runsActive], $this->api('POST', $this->stepsPath($id), ['name' => 'Lint', 'step_type' => 'pause', 'position' => 9])['body']);
        $this->assertSame(['error' => $runsActive], $this->api('DELETE', $this->stepPath($id, $ids['Cleanup']))['body'], 'Build routes to Cleanup');
        $removedMeanwhile = $this->meanwhile(
            static fn () => WorkflowStep::deleteAll(['id' => $ids['Test']]),
            fn (): array => $this->api('PUT', $this->stepPath($id, $ids['Test']), ['name' => 'Renamed'])
        );
        $this->assertSame(['error' => $runsActive], $removedMeanwhile['body']);
    }

    /**
     * A step that other steps route to is not removed (409, naming them by
     * position, with their IDs in step_ids); once those steps lead
     * elsewhere, it is.
     */
    public function testAStepThatOtherStepsRouteToIsRemovedOnlyOnceTheyLeadElsewhere(): void
    {
        [$workflow, $ids] = $this->pipeline();
        $cleanup = WorkflowStep::findOne($ids['Cleanup']);
        $build = WorkflowStep::findOne($ids['Build']);
        $this->assertNotNull($cleanup);
        $this->assertNotNull($build);
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->s['member']);
        $path = $this->stepPath((int)$workflow->id, $ids['Cleanup']);

        $refused = $this->api('DELETE', $path);

        $this->assertApiError(
            409,
            WorkflowStepChangeGuard::routedToMessage($cleanup, [$build], [$ids['Build'] => 1, $ids['Test'] => 2, $ids['Cleanup'] => 3]),
            $refused,
            ['step_ids' => [$ids['Build']]]
        );
        $this->assertStringContainsString('(step 1 "Build")', (string)$refused['body']['error']['message']);
        $this->assertSame($before, $this->stepRows((int)$workflow->id));

        $this->dataOf($this->api('PUT', $this->stepPath((int)$workflow->id, $ids['Build']), ['on_failure_step_id' => 0]));
        $removed = $this->dataOf($this->api('DELETE', $path));

        $this->assertSame(['deleted' => true, 'id' => $ids['Cleanup']], $removed);
        $this->assertSame(['Build', 'Test'], $this->runOrder((int)$workflow->id));
    }

    /**
     * A step whose only route leads to itself (a retry loop) is removed.
     */
    public function testAStepThatOnlyRoutesToItselfIsRemoved(): void
    {
        [$workflow, $ids] = $this->pipeline();
        WorkflowStep::updateAll(['on_failure_step_id' => $ids['Test']], ['id' => $ids['Test']]);
        $this->tokenOf($this->s['member']);

        $this->dataOf($this->api('DELETE', $this->stepPath((int)$workflow->id, $ids['Test'])));

        $this->assertNull(WorkflowStep::findOne($ids['Test']));
    }

    /**
     * A workflow that another request deleted after the lookup answers 404
     * in the API's error shape, from the write path's lock.
     *
     * @dataProvider endpointProvider
     */
    public function testAWorkflowDeletedMeanwhileIs404(string $endpoint): void
    {
        [$workflow, $ids] = $this->pipeline();
        $this->tokenOf($this->s['member']);

        $answer = $this->meanwhile(
            static fn () => WorkflowTemplate::updateAll(['deleted_at' => time()], ['id' => $workflow->id]),
            fn (): array => $this->api(...$this->request($endpoint, (int)$workflow->id, $ids['Test']))
        );

        $this->assertApiError(404, sprintf(WorkflowStepChangeGuard::TEMPLATE_GONE, (int)$workflow->id), $answer);
        $this->assertSame([], $this->stepAudits((int)$workflow->id));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function stepEndpointProvider(): array
    {
        return ['PUT' => ['update'], 'move' => ['move'], 'DELETE' => ['delete']];
    }

    /**
     * A step that another request removed after the lookup answers 404
     * with STEP_NOT_FOUND.
     *
     * @dataProvider stepEndpointProvider
     */
    public function testAStepRemovedMeanwhileIs404(string $endpoint): void
    {
        [$workflow, $ids] = $this->pipeline();
        $this->tokenOf($this->s['member']);

        $answer = $this->meanwhile(
            static fn () => WorkflowStep::deleteAll(['id' => $ids['Test']]),
            fn (): array => $this->api(...$this->request($endpoint, (int)$workflow->id, $ids['Test']))
        );

        $this->assertApiError(404, sprintf(WorkflowStepWriteService::STEP_NOT_FOUND, (int)$workflow->id, $ids['Test']), $answer);
        $this->assertSame([], $this->stepAudits((int)$workflow->id));
    }

    /**
     * The lock is checked again with the workflow locked: an approval step
     * that an admin added meanwhile locks the steps for an operator (403).
     *
     * @dataProvider endpointProvider
     */
    public function testALockAddedMeanwhileRefusesAnOperator(string $endpoint): void
    {
        [$workflow, $ids] = $this->pipeline();
        $rule = $this->createApprovalRule((int)$this->s['admin']->id, ApprovalRule::APPROVER_TYPE_USERS, (string)json_encode(['user_ids' => [(int)$this->s['admin']->id]]));
        $this->tokenOf($this->s['member']);

        $answer = $this->meanwhile(
            fn () => $this->namedStep((int)$workflow->id, 'Approve', 5, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id),
            fn (): array => $this->api(...$this->request($endpoint, (int)$workflow->id, $ids['Test']))
        );

        $this->assertApiError(403, ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED, $answer);
        $this->assertSame([], $this->stepAudits((int)$workflow->id));
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function positionProvider(): array
    {
        $missing = 'missing';

        return [
            'POST after an unknown step' => ['create', ['after_step_id' => 987654321], $missing],
            'POST after a step of another workflow' => ['create', ['after_step_id' => 'OTHER'], $missing],
            'POST at position 5 of 4' => ['create', ['position' => 5], sprintf(WorkflowStepReorderService::POSITION_OUT_OF_RANGE, 4)],
            'PUT at position 4 of 3' => ['update', ['position' => 4], sprintf(WorkflowStepReorderService::POSITION_OUT_OF_RANGE, 3)],
            'PUT after itself' => ['update', ['after_step_id' => 'SELF'], WorkflowStepReorderService::AFTER_ITSELF],
            'PUT after an unknown step' => ['update', ['after_step_id' => 987654321], $missing],
            'move to position 9' => ['move', ['position' => 9], sprintf(WorkflowStepReorderService::POSITION_OUT_OF_RANGE, 3)],
            'move after itself' => ['move', ['after_step_id' => 'SELF'], WorkflowStepReorderService::AFTER_ITSELF],
            'move after an unknown step' => ['move', ['after_step_id' => 987654321], $missing],
        ];
    }

    /**
     * A position the step cannot take answers 422 with the placement's
     * text; nothing is written, also not the other fields of a PUT.
     *
     * @dataProvider positionProvider
     * @param array<string, mixed> $placement
     */
    public function testAPositionTheStepCannotTakeIs422(string $endpoint, array $placement, string $message): void
    {
        [$workflow, $ids] = $this->pipeline();
        [, $otherIds] = $this->pipeline();
        $placement = array_map(static fn (mixed $value): mixed => match ($value) {
            'SELF' => $ids['Test'],
            'OTHER' => $otherIds['Test'],
            default => $value,
        }, $placement);
        $after = $placement['after_step_id'] ?? null;
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->s['member']);
        $body = $endpoint === 'move' ? $placement : ['name' => 'Renamed', 'step_type' => 'job', 'job_template_id' => (int)$this->s['own']->id] + $placement;

        $answer = $this->api(...$this->request($endpoint, (int)$workflow->id, $ids['Test'], $body));

        $this->assertApiError(422, $message === 'missing' ? sprintf(WorkflowStepReorderService::AFTER_STEP_MISSING, $after) : $message, $answer);
        $this->assertSame($before, $this->stepRows((int)$workflow->id));
        $this->assertSame([], $this->stepAudits((int)$workflow->id));
    }

    /**
     * after_step_id names a step, not a place that shifts: a step that
     * another request removed meanwhile answers 422, and nothing is added.
     */
    public function testAnAnchorRemovedMeanwhileIs422(): void
    {
        [$workflow, $ids] = $this->pipeline();
        $this->tokenOf($this->s['member']);

        $answer = $this->meanwhile(
            static fn () => WorkflowStep::deleteAll(['id' => $ids['Test']]),
            fn (): array => $this->api('POST', $this->stepsPath((int)$workflow->id), ['name' => 'Lint', 'step_type' => 'pause', 'after_step_id' => $ids['Test']])
        );

        $this->assertApiError(422, sprintf(WorkflowStepReorderService::AFTER_STEP_MISSING, $ids['Test']), $answer);
        $this->assertSame(['Build', 'Cleanup'], $this->runOrder((int)$workflow->id));
    }

    /**
     * A branch to a step that another request removed meanwhile is checked
     * again when the step is saved: 422, nothing is written.
     */
    public function testABranchToAStepRemovedMeanwhileIs422(): void
    {
        [$workflow, $ids] = $this->pipeline();
        $this->tokenOf($this->s['member']);

        $answer = $this->meanwhile(
            static fn () => WorkflowStep::deleteAll(['id' => $ids['Test']]),
            fn (): array => $this->api('PUT', $this->stepPath((int)$workflow->id, $ids['Build']), ['on_success_step_id' => $ids['Test']])
        );

        $this->assertApiError(422, WorkflowStep::ROUTE_TARGET_OUTSIDE, $answer);
        $build = WorkflowStep::findOne($ids['Build']);
        $this->assertNotNull($build);
        $this->assertNull($build->on_success_step_id);
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * A workflow of three job steps on the member's own job template, in
     * run order: Build (On Failure: Cleanup), Test, Cleanup (both branches
     * end the workflow).
     *
     * @return array{0: WorkflowTemplate, 1: array{Build: int, Test: int, Cleanup: int}}
     */
    private function pipeline(): array
    {
        $workflow = $this->createWorkflowTemplate((int)$this->s['admin']->id);
        $own = (int)$this->s['own']->id;
        $build = $this->namedStep((int)$workflow->id, 'Build', 10, WorkflowStep::TYPE_JOB, $own);
        $test = $this->namedStep((int)$workflow->id, 'Test', 20, WorkflowStep::TYPE_JOB, $own);
        $cleanup = $this->namedStep((int)$workflow->id, 'Cleanup', 30, WorkflowStep::TYPE_JOB, $own);
        WorkflowStep::updateAll(['on_success_step_id' => 0, 'on_failure_step_id' => 0], ['id' => $cleanup->id]);
        WorkflowStep::updateAll(['on_failure_step_id' => (int)$cleanup->id], ['id' => $build->id]);

        return [$workflow, ['Build' => (int)$build->id, 'Test' => (int)$test->id, 'Cleanup' => (int)$cleanup->id]];
    }

    /**
     * The request of $endpoint on the step $stepId of workflow $workflowId,
     * with $body or one that changes something.
     *
     * @return array{0: string, 1: string, 2: mixed}
     */
    private function request(string $endpoint, int $workflowId, int $stepId, mixed $body = null): array
    {
        return match ($endpoint) {
            'create' => ['POST', $this->stepsPath($workflowId), $body ?? ['name' => 'Lint', 'step_type' => 'pause']],
            'update' => ['PUT', $this->stepPath($workflowId, $stepId), $body ?? ['name' => 'Renamed']],
            'move' => ['POST', $this->movePath($workflowId, $stepId), $body ?? ['position' => 'start']],
            default => ['DELETE', $this->stepPath($workflowId, $stepId), $body ?? []],
        };
    }
}
