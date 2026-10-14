<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\WorkflowAccessChecker;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepChangeRefusedException;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\log\Logger;

/**
 * WorkflowStepChangeGuard: every change of a workflow's steps runs with the
 * workflow's row locked, after the team scope (403), the approval-step rule
 * (403) and the active-run check (409); removing a step that other steps
 * route to is refused (409, assertNotRoutedTo(), which the step write
 * service calls under the lock). A refusal writes nothing. The removal
 * itself is WorkflowStepWriteService's (WorkflowStepWriteMoveRemoveTest).
 */
class WorkflowStepChangeGuardTest extends DbTestCase
{
    use TeamScopeFixtures;

    private User $admin;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('guard-admin', 'admin');
        $this->operator = $this->createUserWithRole('guard-operator', 'operator');
    }

    // -- change() ---------------------------------------------------------------

    public function testAChangeRunsAfterTheLockInOneTransactionAndReturnsItsResult(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $id = (int)$workflow->id;

        $result = null;
        $statements = $this->statementsOf(function () use ($workflow, &$result): void {
            $result = $this->guard()->change($workflow, (int)$this->operator->id, false, function () use ($workflow): string {
                $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_PAUSE);
                return 'written';
            });
        });

        $this->assertSame('written', $result);
        $this->assertSame(1, (int)WorkflowStep::find()->where(['workflow_template_id' => $id])->count());
        $savepoint = $this->indexOf($statements, '~^SAVEPOINT LEVEL\d+$~');
        $lock = $this->indexOf($statements, '~^SELECT `deleted_at` FROM `workflow_template` WHERE `id` = ' . $id . ' FOR UPDATE$~');
        $runs = $this->indexOf($statements, '~^SELECT `id` FROM `workflow_job` WHERE .*`workflow_template_id`=' . $id . '~');
        $insert = $this->indexOf($statements, '~^INSERT INTO `workflow_step`~');
        $release = $this->indexOf($statements, '~^RELEASE SAVEPOINT LEVEL\d+$~');
        $this->assertTrue(
            $savepoint < $lock && $lock < $runs && $runs < $insert && $insert < $release,
            "lock, then the active-run check, then the change, in one transaction:\n" . implode("\n", $statements)
        );
    }

    public function testARefusalOfTheChangeItselfRollsBackWhatItWrote(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);

        $refusal = $this->refusalOf(fn () => $this->guard()->change($workflow, (int)$this->admin->id, false, function () use ($workflow): void {
            $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_PAUSE);
            throw new WorkflowStepChangeRefusedException('Name cannot be blank.', WorkflowStepChangeRefusedException::INVALID);
        }));

        $this->assertSame(422, $refusal->status);
        $this->assertSame('Name cannot be blank.', $refusal->getMessage());
        $this->assertFalse(WorkflowStep::find()->where(['workflow_template_id' => $workflow->id])->exists(), 'rolled back');
    }

    public function testAnyOtherFailureRollsBackAndIsRethrown(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);

        $thrown = null;
        try {
            $this->guard()->change($workflow, (int)$this->admin->id, false, function () use ($workflow): void {
                $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_PAUSE);
                throw new \LogicException('database went away');
            });
        } catch (\LogicException $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\LogicException::class, $thrown, 'the failure reaches the caller');
        $this->assertSame('database went away', $thrown->getMessage());
        $this->assertFalse(WorkflowStep::find()->where(['workflow_template_id' => $workflow->id])->exists());
    }

    /**
     * Regression guard: while a run is active, a change of the steps would
     * route it differently than it started, or strand it.
     */
    public function testAnActiveRunRefusesTheChangeWith409NamingIt(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);
        $called = false;

        $refusal = $this->refusalOf(function () use ($workflow, &$called): void {
            $this->guard()->change($workflow, (int)$this->admin->id, false, static function () use (&$called): void {
                $called = true;
            });
        });

        $this->assertSame(409, $refusal->status);
        $this->assertSame(sprintf(WorkflowStepChangeGuard::RUNS_ACTIVE, '#' . $run->id), $refusal->getMessage());
        $this->assertSame(['workflow_job_ids' => [(int)$run->id]], $refusal->details);
        $this->assertFalse($called, 'the change does not run');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function finishedStatusProvider(): array
    {
        return [
            'succeeded' => [WorkflowJob::STATUS_SUCCEEDED],
            'failed' => [WorkflowJob::STATUS_FAILED],
            'canceled' => [WorkflowJob::STATUS_CANCELED],
        ];
    }

    /**
     * @dataProvider finishedStatusProvider
     */
    public function testAFinishedRunDoesNotBlockAChange(string $status): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id, $status);

        $this->assertTrue($this->guard()->change($workflow, (int)$this->admin->id, false, static fn (): bool => true));
    }

    public function testARunOfAnotherWorkflowDoesNotBlockAChange(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $other = $this->createWorkflowTemplate((int)$this->admin->id);
        $this->createWorkflowJob((int)$other->id, (int)$this->admin->id);

        $this->assertTrue($this->guard()->change($workflow, (int)$this->admin->id, false, static fn (): bool => true));
    }

    public function testTheRefusalNamesTheFirstTenActiveRunsAndCountsTheRest(): void
    {
        $this->assertSame(
            sprintf(WorkflowStepChangeGuard::RUNS_ACTIVE, '#1, #2, #3, #4, #5, #6, #7, #8, #9, #10 and 2 more'),
            WorkflowStepChangeGuard::runsActiveMessage(range(1, 12))
        );
        $this->assertSame(
            sprintf(WorkflowStepChangeGuard::RUNS_ACTIVE, '#4, #9'),
            WorkflowStepChangeGuard::runsActiveMessage([4, 9])
        );
        $this->assertStringEndsWith('#10 of this workflow are active: wait for them to finish or cancel them.', WorkflowStepChangeGuard::runsActiveMessage(range(1, 10)));
    }

    public function testTheActiveRunsAreTheRunningOnesOfTheWorkflowInOrder(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $first = $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);
        $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id, WorkflowJob::STATUS_SUCCEEDED);
        $second = $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);
        $this->createWorkflowJob((int)$this->createWorkflowTemplate((int)$this->admin->id)->id, (int)$this->admin->id);

        $this->assertSame([(int)$first->id, (int)$second->id], $this->guard()->activeRunIds((int)$workflow->id));
        $this->assertSame([], $this->guard()->activeRunIds(987654321));
    }

    public function testAWorkflowDeletedMeanwhileIsNotFound(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $stale = WorkflowTemplate::findOne($workflow->id);
        $this->assertNotNull($stale);
        $workflow->softDelete();
        $missing = $this->createWorkflowTemplate((int)$this->admin->id);
        $missing->id = 987654321;

        foreach ([$stale, $missing] as $template) {
            $refusal = $this->refusalOf(fn () => $this->guard()->change($template, (int)$this->admin->id, false, static fn (): bool => true));
            $this->assertSame(404, $refusal->status);
            $this->assertSame(sprintf(WorkflowStepChangeGuard::TEMPLATE_GONE, (int)$template->id), $refusal->getMessage());
        }
    }

    // -- The team scope, again under the lock ---------------------------------

    /**
     * regression: the team scope was checked only before the lock (the
     * template page and the API ask it first). A step of a project the user
     * may only view, added by another user between that check and the lock,
     * did not stop the change. Under the lock, the guard now refuses a user
     * who may not operate the project of every job step the workflow has
     * (403, TEAM_SCOPE_REFUSED, the API's text), and the change does not run.
     */
    public function testRegressionTheTeamScopeWasNotCheckedAgainUnderTheLock(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps((int)$s['admin']->id, (int)$s['own']->id, (int)$s['open']->id);
        $this->assertTrue($this->workflows()->canOperateWorkflowTemplate((int)$s['member']->id, (int)$workflow->id), 'the member may operate it at first');
        // Another user's change, after the member's own check: a job step of a project the member may only view.
        $this->createWorkflowStep((int)$workflow->id, 20, WorkflowStep::TYPE_JOB, (int)$s['viewed']->id);
        $called = false;

        $refusal = $this->refusalOf(fn () => $this->guard()->change($workflow, (int)$s['member']->id, false, static function () use (&$called): void {
            $called = true;
        }));

        $this->assertSame([403, WorkflowStepChangeGuard::TEAM_SCOPE_REFUSED, []], [$refusal->status, $refusal->getMessage(), $refusal->details]);
        $this->assertSame('Forbidden.', WorkflowStepChangeGuard::TEAM_SCOPE_REFUSED);
        $this->assertFalse($called, 'the change does not run');
    }

    /**
     * A user who may operate the project of every job step changes the
     * steps; an admin and a superadmin are not restricted by team scoping,
     * and neither is anybody on a workflow without job steps.
     */
    public function testWhoMayOperateEveryJobStepChangesTheSteps(): void
    {
        $s = $this->teamScope();
        $operable = $this->createWorkflowWithJobSteps((int)$s['admin']->id, (int)$s['own']->id, (int)$s['open']->id);
        $foreign = $this->createWorkflowWithJobSteps((int)$s['admin']->id, (int)$s['own']->id, (int)$s['foreign']->id);
        $pausesOnly = $this->createWorkflowTemplate((int)$s['admin']->id);
        $this->createWorkflowStep((int)$pausesOnly->id, 10, WorkflowStep::TYPE_PAUSE);
        $superadmin = $this->createUser('guard-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);

        $this->assertSame('member', $this->guard()->change($operable, (int)$s['member']->id, false, static fn (): string => 'member'));
        $this->assertSame('pauses', $this->guard()->change($pausesOnly, (int)$s['member']->id, false, static fn (): string => 'pauses'));
        $this->assertSame('admin', $this->guard()->change($foreign, (int)$s['admin']->id, false, static fn (): string => 'admin'));
        $this->assertSame('superadmin', $this->guard()->change($foreign, (int)$superadmin->id, false, static fn (): string => 'superadmin'));
        foreach ([$s['member'], $s['outsider']] as $user) {
            $this->assertSame(403, $this->refusalOf(fn () => $this->guard()->change($foreign, (int)$user->id, false, static fn (): bool => true))->status);
        }
    }

    /**
     * The order under the lock: 404 for a workflow deleted meanwhile first,
     * then the team scope, before the rule of gated steps (403 with the
     * policy's reason) and the active runs (409).
     */
    public function testTheTeamScopeComesAfterTheLockAndBeforeThePolicyAndTheRuns(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps((int)$s['admin']->id, (int)$s['viewed']->id);
        $this->createWorkflowStep((int)$workflow->id, 20, WorkflowStep::TYPE_APPROVAL, null, (int)$this->createApprovalRule((int)$s['admin']->id)->id);
        $this->createWorkflowJob((int)$workflow->id, (int)$s['admin']->id);
        $stale = WorkflowTemplate::findOne($workflow->id);
        $this->assertNotNull($stale);

        $refusal = $this->refusalOf(fn () => $this->guard()->change($workflow, (int)$s['member']->id, false, static fn (): bool => true));
        $this->assertSame([403, WorkflowStepChangeGuard::TEAM_SCOPE_REFUSED], [$refusal->status, $refusal->getMessage()], 'not the policy\'s reason, not 409');

        $operable = $this->createWorkflowWithJobSteps((int)$s['admin']->id, (int)$s['own']->id);
        $this->createWorkflowStep((int)$operable->id, 20, WorkflowStep::TYPE_APPROVAL, null, (int)$this->createApprovalRule((int)$s['admin']->id)->id);
        $refusal = $this->refusalOf(fn () => $this->guard()->change($operable, (int)$s['member']->id, false, static fn (): bool => true));
        $this->assertSame([403, ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED], [$refusal->status, $refusal->getMessage()], 'an operable workflow: the policy decides');

        $this->assertTrue($workflow->softDelete());
        $refusal = $this->refusalOf(fn () => $this->guard()->change($stale, (int)$s['member']->id, false, static fn (): bool => true));
        $this->assertSame([404, sprintf(WorkflowStepChangeGuard::TEMPLATE_GONE, (int)$workflow->id)], [$refusal->status, $refusal->getMessage()]);
    }

    /**
     * The team scope is read with the workflow's row locked: after the lock,
     * before the policy's queries and the active-run check.
     */
    public function testTheTeamScopeIsReadWithTheRowLocked(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps((int)$s['admin']->id, (int)$s['own']->id);
        $id = (int)$workflow->id;

        $statements = $this->statementsOf(function () use ($workflow, $s): void {
            $this->assertTrue($this->guard()->change($workflow, (int)$s['member']->id, false, static fn (): bool => true));
        });

        $lock = $this->indexOf($statements, '~^SELECT `deleted_at` FROM `workflow_template` WHERE `id` = ' . $id . ' FOR UPDATE$~');
        $scope = $this->indexOf($statements, '~^SELECT DISTINCT `job_template_id` FROM `workflow_step` WHERE \(+`workflow_template_id`=' . $id . '\)~');
        $policy = $this->indexOf($statements, '~^SELECT EXISTS\(SELECT \* FROM `workflow_step` WHERE \(`workflow_template_id`=' . $id . '\) AND \(CAST\(`step_type` AS BINARY\)~');
        $runs = $this->indexOf($statements, '~^SELECT `id` FROM `workflow_job` WHERE .*`workflow_template_id`=' . $id . '~');
        $this->assertTrue(
            $lock < $scope && $scope < $policy && $policy < $runs,
            "lock, team scope, policy, active runs:\n" . implode("\n", $statements)
        );
    }

    // -- Approval steps are for admins ---------------------------------------

    /**
     * Regression: an operator could add an approval step, with any rule.
     */
    public function testOnlyAnAdminMayAddAnApprovalStep(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);

        $refusal = $this->refusalOf(fn () => $this->guard()->change($workflow, (int)$this->operator->id, true, static fn (): bool => true));
        $this->assertSame(403, $refusal->status);
        $this->assertSame(ApprovalRuleAssignmentPolicy::APPROVAL_STEP_ADD_REFUSED, $refusal->getMessage());

        $this->assertTrue($this->guard()->change($workflow, (int)$this->admin->id, true, static fn (): bool => true));
    }

    /**
     * Regression: an operator could remove, move or bypass the approval
     * step of a workflow.
     */
    public function testOnlyAnAdminMayChangeTheStepsOfAWorkflowWithAnApprovalStep(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $approval = $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_APPROVAL, null, (int)$this->createApprovalRule((int)$this->admin->id)->id);
        $remove = static fn (): int => WorkflowStep::deleteAll(['id' => $approval->id]);

        $refusal = $this->refusalOf(fn () => $this->guard()->change($workflow, (int)$this->operator->id, false, $remove));
        $this->assertSame(403, $refusal->status);
        $this->assertSame(ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED, $refusal->getMessage());
        $this->assertNotNull(WorkflowStep::findOne($approval->id), 'the change did not run');

        $this->assertSame(1, $this->guard()->change($workflow, (int)$this->admin->id, false, $remove));
        $this->assertNull(WorkflowStep::findOne($approval->id));
    }

    public function testTheApprovalStepRuleComesBeforeTheActiveRunCheck(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_APPROVAL, null, (int)$this->createApprovalRule((int)$this->admin->id)->id);
        $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);

        $this->assertSame(403, $this->refusalOf(fn () => $this->guard()->change($workflow, (int)$this->operator->id, false, static fn (): bool => true))->status);
        $this->assertSame(409, $this->refusalOf(fn () => $this->guard()->change($workflow, (int)$this->admin->id, false, static fn (): bool => true))->status);
    }

    /**
     * An API create: no row to lock, no steps whose team scope to check and
     * no runs, only the approval-step rule.
     */
    public function testANewWorkflowNeedsNoLockAndHasNoRuns(): void
    {
        $workflow = new WorkflowTemplate();

        $statements = $this->statementsOf(function () use ($workflow): void {
            $this->assertSame('new', $this->guard()->change($workflow, (int)$this->operator->id, false, static fn (): string => 'new'));
        });
        foreach ($statements as $statement) {
            $this->assertStringNotContainsString('FOR UPDATE', $statement);
            $this->assertStringNotContainsString('`workflow_job`', $statement);
            $this->assertStringNotContainsString('SELECT DISTINCT `job_template_id`', $statement, 'no team scope to check');
        }

        $refusal = $this->refusalOf(fn () => $this->guard()->change($workflow, (int)$this->operator->id, true, static fn (): bool => true));
        $this->assertSame(403, $refusal->status);
        $this->assertSame(ApprovalRuleAssignmentPolicy::APPROVAL_STEP_ADD_REFUSED, $refusal->getMessage());
    }

    // -- A step that other steps route to ------------------------------------------

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
     * Regression: removing a step left the routes of other steps pointing at
     * it; a run that reached such a route ended there.
     *
     * @dataProvider routeProvider
     */
    public function testRemovingAStepThatAnotherStepRoutesToIsRefused(string $route): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $target = $this->createWorkflowStep((int)$workflow->id, 30, WorkflowStep::TYPE_PAUSE);
        $target->name = 'Deploy "prod"';
        $target->save(false);
        $routing = $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_JOB);
        $routing->name = 'Build';
        $routing->setAttribute($route, (int)$target->id);
        $routing->save(false);

        $refusal = $this->refusalOf(fn () => $this->guard()->assertNotRoutedTo($target, $this->positions($workflow)));

        $this->assertSame(409, $refusal->status);
        $this->assertSame(
            sprintf(WorkflowStepChangeGuard::STEP_ROUTED_TO, '"Deploy \"prod\""', 'step 1 "Build"'),
            $refusal->getMessage()
        );
        $this->assertSame(['step_ids' => [(int)$routing->id]], $refusal->details);
    }

    public function testTheRefusalNamesEveryRoutingStepByPosition(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $target = $this->createWorkflowStep((int)$workflow->id, 40, WorkflowStep::TYPE_PAUSE);
        $late = $this->createWorkflowStep((int)$workflow->id, 30, WorkflowStep::TYPE_PAUSE);
        $early = $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_PAUSE);
        foreach ([$late, $early] as $step) {
            $step->on_failure_step_id = (int)$target->id;
            $step->save(false);
        }

        $this->assertSame(
            [(int)$early->id, (int)$late->id],
            array_map(static fn (WorkflowStep $s): int => (int)$s->id, $this->guard()->stepsRoutingTo($target))
        );
        $this->assertStringContainsString(
            '(step 1 "step-10", step 2 "step-30")',
            $this->refusalOf(fn () => $this->guard()->assertNotRoutedTo($target, $this->positions($workflow)))->getMessage()
        );
    }

    /**
     * regression: the refusal to remove a routed-to step named the shifting
     * step order ("#10"). step_order is an internal sort key that every
     * change renumbers (and the API's bulk list writes 0, 1, 2…), so "#0"
     * and "#7" named nothing the page shows. The steps that route to it are
     * named by their positions now, and the advice is to edit them.
     */
    public function testRegressionTheRoutedToRefusalNamesPositionsNotTheShiftingStepOrder(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $build = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $retry = $this->createWorkflowStep((int)$workflow->id, 7, WorkflowStep::TYPE_PAUSE);
        $deploy = $this->createWorkflowStep((int)$workflow->id, 30, WorkflowStep::TYPE_PAUSE);
        foreach (['Build' => $build, 'Retry' => $retry, 'Deploy' => $deploy] as $name => $step) {
            $step->name = $name;
            $step->on_success_step_id = $name === 'Deploy' ? WorkflowStep::END_WORKFLOW : (int)$deploy->id;
            $step->save(false);
        }

        /** @var \app\services\WorkflowStepWriteService $writer */
        $writer = \Yii::$app->get('workflowStepWriteService');
        $message = $this->refusalOf(fn () => $writer->remove($workflow, (int)$deploy->id, (int)$this->admin->id, 'web'))->getMessage();

        $this->assertSame(
            'Step "Deploy" was not removed: other steps route to it (step 1 "Build", step 2 "Retry"). '
                . 'Edit those steps to lead elsewhere first, then remove it.',
            $message
        );
        $this->assertStringNotContainsString('#', $message);
        $this->assertNotNull(WorkflowStep::findOne($deploy->id), 'not removed');
    }

    /**
     * routedToMessage() names each routing step by the position it is
     * given, in the order given; assertNotRoutedTo() refuses with it.
     */
    public function testTheRoutedToMessageTakesThePositionsItIsGiven(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $target = $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_PAUSE);
        $first = $this->createWorkflowStep((int)$workflow->id, 20, WorkflowStep::TYPE_PAUSE);
        $second = $this->createWorkflowStep((int)$workflow->id, 30, WorkflowStep::TYPE_PAUSE);

        $this->assertSame(
            sprintf(WorkflowStepChangeGuard::STEP_ROUTED_TO, '"step-10"', 'step 4 "step-20", step 9 "step-30"'),
            WorkflowStepChangeGuard::routedToMessage($target, [$first, $second], [(int)$first->id => 4, (int)$second->id => 9])
        );
        $this->assertSame('step 3 "Build \"x\""', sprintf(WorkflowStepChangeGuard::ROUTING_STEP_LABEL, 3, '"Build \"x\""'));

        $this->guard()->assertNotRoutedTo($target, [(int)$target->id => 1]);
        $second->on_always_step_id = (int)$target->id;
        $second->save(false);
        $refusal = $this->refusalOf(fn () => $this->guard()->assertNotRoutedTo($target, [(int)$second->id => 3]));
        $this->assertSame(409, $refusal->status);
        $this->assertSame(sprintf(WorkflowStepChangeGuard::STEP_ROUTED_TO, '"step-10"', 'step 3 "step-30"'), $refusal->getMessage());
        $this->assertSame(['step_ids' => [(int)$second->id]], $refusal->details);
    }

    /**
     * A step that routes to itself (a retry loop) and routes from steps of
     * other workflows, which no run takes, do not keep a step.
     */
    public function testOnlyRoutesFromOtherStepsOfTheWorkflowKeepAStep(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $step = $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_JOB);
        $step->on_failure_step_id = (int)$step->id;
        $step->on_success_step_id = WorkflowStep::END_WORKFLOW;
        $step->save(false);
        $elsewhere = $this->createWorkflowStep((int)$this->createWorkflowTemplate((int)$this->admin->id)->id, 10, WorkflowStep::TYPE_PAUSE);
        $elsewhere->on_success_step_id = (int)$step->id;
        $elsewhere->save(false);
        $ending = $this->createWorkflowStep((int)$workflow->id, 20, WorkflowStep::TYPE_PAUSE);
        $ending->on_success_step_id = WorkflowStep::END_WORKFLOW;
        $ending->save(false);

        $this->assertSame([], $this->guard()->stepsRoutingTo($step), 'its own loop and another workflow\'s step do not count');
        $this->guard()->assertNotRoutedTo($step, $this->positions($workflow));
        $this->assertSame((int)$step->id, (int)$elsewhere->on_success_step_id, 'the other workflow\'s step does route to it');
    }

    // -- Helpers ------------------------------------------------------------------

    /**
     * @return array<int, int> the workflow's steps: step ID => position
     */
    private function positions(WorkflowTemplate $workflow): array
    {
        /** @var \app\services\WorkflowStepReorderService $placement */
        $placement = \Yii::$app->get('workflowStepReorderService');

        return $placement->positions((int)$workflow->id);
    }

    private function guard(): WorkflowStepChangeGuard
    {
        /** @var WorkflowStepChangeGuard $guard */
        $guard = \Yii::$app->get('workflowStepChangeGuard');
        return $guard;
    }

    private function workflows(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $workflows */
        $workflows = \Yii::$app->get('workflowAccessChecker');
        return $workflows;
    }

    private function refusalOf(callable $action): WorkflowStepChangeRefusedException
    {
        try {
            $action();
        } catch (WorkflowStepChangeRefusedException $e) {
            return $e;
        }
        $this->fail('Expected a WorkflowStepChangeRefusedException.');
    }

    /**
     * The SQL statements $action sends, in order.
     *
     * @return list<string>
     */
    private function statementsOf(callable $action): array
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
        $statements = [];
        foreach ($logger->messages as $message) {
            if (
                in_array($message[2], ['yii\db\Command::query', 'yii\db\Command::execute'], true)
                && $message[1] === Logger::LEVEL_INFO
                && is_string($message[0])
            ) {
                $statements[] = $message[0];
            }
        }

        return $statements;
    }

    /**
     * @param list<string> $statements
     */
    private function indexOf(array $statements, string $pattern): int
    {
        foreach ($statements as $index => $statement) {
            if (preg_match($pattern, $statement) === 1) {
                return $index;
            }
        }
        $this->fail("No statement matches {$pattern}:\n" . implode("\n", $statements));
    }
}
