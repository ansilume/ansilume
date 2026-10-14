<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\WorkflowRoute;
use app\models\ApprovalRequest;
use app\models\Job;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowRouteMap;
use app\services\WorkflowStepRouter;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The durable guard of the route map (contract C7): the page, the API and
 * the change messages say where a branch leads from WorkflowRouteMap, and a
 * run goes where WorkflowStepRouter and WorkflowExecutionService send it.
 * These tests fail as soon as the two disagree.
 *
 * - Every outcome of every step of a workflow with every kind of step and
 *   branch, laid out sparse (10, 20, 30…), dense (0, 1, 2…, as the bulk API
 *   writes them), tied (every step order the same) and mixed with ties: the
 *   map names the step the router names, or the end or the missing step the
 *   router ends the run with, and the run outcome advance() gives it. The
 *   start step and the run order are the model's.
 * - Real runs end, go on or loop as the map says, and fail at a step that
 *   cannot run without starting the steps the map calls unreachable.
 */
class WorkflowRouteMapEngineAgreementTest extends DbTestCase
{
    use TeamScopeFixtures;
    use WorkflowEngineFixtures;

    private const GONE = 987654321;

    private User $user;
    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->operator('route-agreement');
        $this->approver = $this->operator('route-agreement-approver');
    }

    /**
     * @return array<string, array{0: list<int>}>
     */
    public static function layoutProvider(): array
    {
        return [
            'sparse (10, 20, 30…)' => [[10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 110]],
            'dense (0, 1, 2…, the bulk API)' => [[0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10]],
            'tied (5, 5, 5…)' => [[5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5]],
            'mixed with ties' => [[30, 10, 10, 50, 20, 20, 20, 40, 10, 60, 5]],
        ];
    }

    /**
     * @dataProvider layoutProvider
     * @param list<int> $orders
     */
    public function testTheMapRoutesEveryOutcomeAsTheRouter(array $orders): void
    {
        $workflow = $this->allKindsWorkflow($orders);
        $map = WorkflowRouteMap::forTemplate($workflow);
        /** @var list<WorkflowStep> $models */
        $models = $workflow->getSteps()->all();

        $this->assertSame($workflow->getStartStep()?->id, $map->start()?->id, 'the start step');
        $this->assertSame(
            array_map(static fn (WorkflowStep $model): int => (int)$model->id, $models),
            array_map(static fn ($step): int => $step->id, $map->steps()),
            'the run order'
        );
        $compared = 0;
        foreach ($models as $model) {
            $step = $map->find((int)$model->id);
            $this->assertNotNull($step);
            foreach ($map->outcomes($step) as $outcome) {
                $this->assertSame(
                    $this->routerAnswer($model, $outcome),
                    self::mapAnswer($map->route($step, $outcome)),
                    "step {$model->name} ({$model->step_type}), outcome {$outcome}"
                );
                $compared++;
            }
        }
        $this->assertSame(21, $compared, 'every outcome of every step but the one of an unknown type');
    }

    public function testAWorkflowWithoutStepsStartsNowhere(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);

        $this->assertNull($workflow->getStartStep());
        $this->assertNull(WorkflowRouteMap::forTemplate($workflow)->start());
    }

    /**
     * @return array<string, array{0: string, 1: int|null, 2: string, 3: string}>
     */
    public static function endingProvider(): array
    {
        return [
            'On Success 0, after a success' => ['on_success_step_id', 0, Job::STATUS_SUCCEEDED, 'success'],
            'On Failure 0, after a failure' => ['on_failure_step_id', 0, Job::STATUS_FAILED, 'failure'],
            'Always 0, after a success' => ['on_always_step_id', 0, Job::STATUS_SUCCEEDED, 'success'],
            'Always 0, after a failure' => ['on_always_step_id', 0, Job::STATUS_FAILED, 'failure'],
            'On Success to a step that is gone' => ['on_success_step_id', self::GONE, Job::STATUS_SUCCEEDED, 'success'],
            'On Failure to a step that is gone' => ['on_failure_step_id', self::GONE, Job::STATUS_FAILED, 'failure'],
        ];
    }

    /**
     * A branch that ends the run, or leads to a step that is gone, ends a
     * real run as the map's run outcome says, and starts no other step.
     *
     * @dataProvider endingProvider
     */
    public function testARunEndsAsTheMapSays(string $field, ?int $branch, string $jobStatus, string $outcome): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $first = $this->jobStep($workflow, 10);
        $second = $this->jobStep($workflow, 20);
        $this->branchTo($first, $field, $branch);

        $route = $this->routeOf($workflow, $first, $outcome);
        $run = $this->engine()->launch($workflow, (int)$this->user->id);
        $this->finishJob($this->jobOfStep($run, $first), $jobStatus);

        $run->refresh();
        $this->assertNotSame(WorkflowRoute::KIND_STEP, $route->kind);
        $this->assertSame($this->statusOf($route), $run->status);
        $this->assertNull($this->runOf($run, $second), 'no other step started');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function lastStepProvider(): array
    {
        return [
            'after a success' => [Job::STATUS_SUCCEEDED, 'success'],
            'after a failure' => [Job::STATUS_FAILED, 'failure'],
        ];
    }

    /**
     * @dataProvider lastStepProvider
     */
    public function testAnEmptyBranchOfTheLastStepEndsTheRunWithItsOutcome(string $jobStatus, string $outcome): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $only = $this->jobStep($workflow, 10);

        $route = $this->routeOf($workflow, $only, $outcome);
        $run = $this->engine()->launch($workflow, (int)$this->user->id);
        $this->finishJob($this->jobOfStep($run, $only), $jobStatus);

        $run->refresh();
        $this->assertSame([WorkflowRoute::KIND_END, WorkflowRoute::VIA_NEXT], [$route->kind, $route->via]);
        $this->assertSame($this->statusOf($route), $run->status);
    }

    /**
     * After a closed gate, an empty On Failure ends the run as failed, for an
     * approval step and for a gated job step alike.
     */
    public function testAClosedGateWithAnEmptyOnFailureEndsTheRunAsTheMapSays(): void
    {
        $rule = $this->usersRuleOf((int)$this->user->id, (int)$this->approver->id);
        $approvalFlow = $this->createWorkflowTemplate((int)$this->user->id);
        $gate = $this->createWorkflowStep((int)$approvalFlow->id, 10, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $afterGate = $this->jobStep($approvalFlow, 20);
        $gatedFlow = $this->createWorkflowTemplate((int)$this->user->id);
        $gatedStep = $this->createWorkflowStep(
            (int)$gatedFlow->id,
            10,
            WorkflowStep::TYPE_JOB,
            (int)$this->gatedTemplate((int)$this->user->id, $rule)->id
        );
        $afterGatedStep = $this->jobStep($gatedFlow, 20);

        foreach ([[$approvalFlow, $gate, $afterGate], [$gatedFlow, $gatedStep, $afterGatedStep]] as [$workflow, $step, $after]) {
            $route = $this->routeOf($workflow, $step, WorkflowRouteMap::OUTCOME_CLOSED_GATE);
            $run = $this->engine()->launch($workflow, (int)$this->user->id);
            $this->decide($this->jobOfStep($run, $step), ApprovalRequest::STATUS_REJECTED);

            $run->refresh();
            $this->assertSame([WorkflowRoute::KIND_END, WorkflowRoute::RUN_FAILS], [$route->kind, $route->runOutcome]);
            $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status, "step {$step->step_type}");
            $this->assertNull($this->runOf($run, $after), 'the next step never ran');
        }
    }

    /**
     * An empty branch goes on with the next step in the run order: a step
     * that shares its step order with the next one does not skip it.
     */
    public function testAnEmptyBranchGoesOnWithTheNextStepAsTheMapSays(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $x = $this->jobStep($workflow, 10);
        $y = $this->jobStep($workflow, 10);
        $this->jobStep($workflow, 20);

        $route = $this->routeOf($workflow, $x, WorkflowRouteMap::OUTCOME_SUCCESS);
        $run = $this->engine()->launch($workflow, (int)$this->user->id);
        $this->finishJob($this->jobOfStep($run, $x));

        $run->refresh();
        $this->assertSame([WorkflowRoute::KIND_STEP, (int)$y->id], [$route->kind, $route->stepId]);
        $this->assertSame((int)$y->id, (int)$run->current_step_id);
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $this->executionOfStep($run, $y)->status);
    }

    /**
     * A branch back to the step itself runs it again: a loop, as the map
     * marks it.
     */
    public function testABranchToTheStepItselfRunsItAgain(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $retry = $this->jobStep($workflow, 10);
        $this->branchTo($retry, 'on_failure_step_id', (int)$retry->id);

        $route = $this->routeOf($workflow, $retry, WorkflowRouteMap::OUTCOME_FAILURE);
        $run = $this->engine()->launch($workflow, (int)$this->user->id);
        $this->finishJob($this->jobOfStep($run, $retry), Job::STATUS_FAILED);

        $this->assertSame([WorkflowRoute::KIND_STEP, (int)$retry->id, true], [$route->kind, $route->stepId, $route->loop]);
        $run->refresh();
        $this->assertSame(2, (int)WorkflowJobStep::find()->where(['workflow_job_id' => $run->id, 'workflow_step_id' => $retry->id])->count());
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status);
    }

    /**
     * regression: a step after one that cannot run counted as reachable,
     * on the page and in the API. A run fails at a job step whose job
     * template was deleted without following a branch, and never starts
     * the step after it; the map says that step is not reachable.
     */
    public function testRegressionARunFailsAtAStepThatCannotRunAndNeverReachesTheStepTheMapCallsUnreachable(): void
    {
        $admin = $this->createUserWithRole('route-agreement-admin', 'admin');
        $workflow = $this->createWorkflowTemplate((int)$admin->id);
        $gone = $this->openTemplate((int)$admin->id);
        $build = $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_JOB, (int)$gone->id);
        $deploy = $this->jobStep($workflow, 20);
        $gone->softDelete();

        $map = WorkflowRouteMap::forTemplate($workflow);
        $run = $this->engine()->launch($workflow, (int)$admin->id);

        $run->refresh();
        $this->assertSame('job_template_missing', $map->find((int)$build->id)?->problem);
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
        $this->assertNull($this->runOf($run, $deploy), 'the step after it never started');
        $this->assertFalse($map->isReachable((int)$deploy->id));
        $this->assertFalse($map->toApi()['steps'][(int)$deploy->id]['reachable']);
    }

    // -- Helpers ------------------------------------------------------------------------

    /**
     * Eleven steps of every kind, created in this order with the step orders
     * $orders, and branched so that every kind of branch value occurs:
     *
     * s1 job: failure → s4; s2 approval: no branches; s3 gated job: success
     * 0; s4 job: success → s1, failure → s4 (itself); s5 pause; s6 job:
     * Always 0; s7 job: success → a step that is gone, failure 0; s8
     * approval: failure → s3, an old Always → s1; s9 "JOB" (an unknown type):
     * success → s1; s10 gated job: failure → s5, Always → s2; s11 job.
     *
     * @param list<int> $orders
     */
    private function allKindsWorkflow(array $orders): WorkflowTemplate
    {
        $userId = (int)$this->user->id;
        $open = (int)$this->openTemplate($userId)->id;
        $rule = $this->usersRuleOf($userId, (int)$this->approver->id);
        $gated = (int)$this->gatedTemplate($userId, $rule)->id;
        $workflow = $this->createWorkflowTemplate($userId);
        $kinds = [
            [WorkflowStep::TYPE_JOB, $open, null],
            [WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id],
            [WorkflowStep::TYPE_JOB, $gated, null],
            [WorkflowStep::TYPE_JOB, $open, null],
            [WorkflowStep::TYPE_PAUSE, null, null],
            [WorkflowStep::TYPE_JOB, $open, null],
            [WorkflowStep::TYPE_JOB, $open, null],
            [WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id],
            ['JOB', $open, null],
            [WorkflowStep::TYPE_JOB, $gated, null],
            [WorkflowStep::TYPE_JOB, $open, null],
        ];
        $s = [];
        foreach ($kinds as $index => [$type, $templateId, $ruleId]) {
            $step = $this->createWorkflowStep((int)$workflow->id, $orders[$index], $type, $templateId, $ruleId);
            $s[$index + 1] = (int)$step->id;
            WorkflowStep::updateAll(['name' => 's' . ($index + 1)], ['id' => $step->id]);
        }
        $branches = [
            1 => [null, $s[4], null],
            3 => [0, null, null],
            4 => [$s[1], $s[4], null],
            6 => [null, null, 0],
            7 => [self::GONE, 0, null],
            8 => [null, $s[3], $s[1]],
            9 => [$s[1], null, null],
            10 => [null, $s[5], $s[2]],
        ];
        foreach ($branches as $number => [$success, $failure, $always]) {
            WorkflowStep::updateAll(
                ['on_success_step_id' => $success, 'on_failure_step_id' => $failure, 'on_always_step_id' => $always],
                ['id' => $s[$number]]
            );
        }

        return $workflow;
    }

    private function jobStep(WorkflowTemplate $workflow, int $order): WorkflowStep
    {
        return $this->createWorkflowStep(
            (int)$workflow->id,
            $order,
            WorkflowStep::TYPE_JOB,
            (int)$this->openTemplate((int)$this->user->id)->id
        );
    }

    /**
     * The map's route for $outcome of $step, read from the database.
     */
    private function routeOf(WorkflowTemplate $workflow, WorkflowStep $step, string $outcome): WorkflowRoute
    {
        $map = WorkflowRouteMap::forTemplate($workflow);
        $routeStep = $map->find((int)$step->id);
        $this->assertNotNull($routeStep);
        $route = $map->route($routeStep, $outcome);
        $this->assertNotNull($route, "step {$step->id} can end with {$outcome}");

        return $route;
    }

    /**
     * The run status the map's run outcome of an ending $route stands for.
     */
    private function statusOf(WorkflowRoute $route): string
    {
        $this->assertNotNull($route->runOutcome);

        return $route->runOutcome === WorkflowRoute::RUN_SUCCEEDS ? WorkflowJob::STATUS_SUCCEEDED : WorkflowJob::STATUS_FAILED;
    }

    /**
     * Where the router sends a run after $model ended with $outcome: the next
     * step's ID, or the end or the missing step with the run outcome that
     * WorkflowExecutionService::advance() gives the run.
     *
     * @return array{0: string, 1: int|string|null, 2: string|null}
     */
    private function routerAnswer(WorkflowStep $model, string $outcome): array
    {
        /** @var WorkflowStepRouter $router */
        $router = \Yii::$app->get('workflowStepRouter');
        $succeeded = $outcome === WorkflowRouteMap::OUTCOME_SUCCESS;
        $route = $router->route($model, $succeeded, $outcome === WorkflowRouteMap::OUTCOME_CLOSED_GATE);
        if ($route['next'] !== null) {
            return ['step', (int)$route['next']->id, null];
        }
        $run = $succeeded && $route['error'] === null ? WorkflowRoute::RUN_SUCCEEDS : WorkflowRoute::RUN_FAILS;

        return $route['error'] === null ? ['end', null, $run] : ['missing', $route['error'], $run];
    }

    /**
     * The map's $route in the shape of routerAnswer().
     *
     * @return array{0: string, 1: int|string|null, 2: string|null}
     */
    private static function mapAnswer(?WorkflowRoute $route): array
    {
        if ($route === null) {
            return ['none', null, null];
        }

        return match ($route->kind) {
            WorkflowRoute::KIND_STEP => ['step', $route->stepId, null],
            WorkflowRoute::KIND_MISSING => [
                'missing',
                sprintf(WorkflowStepRouter::ROUTE_TARGET_MISSING, (int)$route->stepId),
                $route->runOutcome,
            ],
            default => ['end', null, $route->runOutcome],
        };
    }
}
