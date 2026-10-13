<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepChangeRefusedException;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\log\Logger;

/**
 * WorkflowStepChangeGuard: every change of a workflow's steps runs with the
 * workflow's row locked, after the approval-step rule (403) and the
 * active-run check (409); removing a step that other steps route to is
 * refused (409). A refusal writes nothing.
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

        $refusal = $this->refusalOf(fn () => $this->guard()->remove($workflow, (int)$this->operator->id, (int)$approval->id));
        $this->assertSame(403, $refusal->status);
        $this->assertSame(ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED, $refusal->getMessage());
        $this->assertNotNull(WorkflowStep::findOne($approval->id));

        $this->assertNotNull($this->guard()->remove($workflow, (int)$this->admin->id, (int)$approval->id));
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
     * An API create: no row to lock and no runs, only the approval-step rule.
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
        }

        $refusal = $this->refusalOf(fn () => $this->guard()->change($workflow, (int)$this->operator->id, true, static fn (): bool => true));
        $this->assertSame(403, $refusal->status);
        $this->assertSame(ApprovalRuleAssignmentPolicy::APPROVAL_STEP_ADD_REFUSED, $refusal->getMessage());
    }

    // -- remove() -----------------------------------------------------------------

    public function testRemoveDeletesTheStepAndReturnsIt(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $step = $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_PAUSE);
        $other = $this->createWorkflowStep((int)$workflow->id, 20, WorkflowStep::TYPE_PAUSE);

        $removed = $this->guard()->remove($workflow, (int)$this->operator->id, (int)$step->id);

        $this->assertNotNull($removed);
        $this->assertSame((int)$step->id, (int)$removed->id);
        $this->assertNull(WorkflowStep::findOne($step->id));
        $this->assertNotNull(WorkflowStep::findOne($other->id));
    }

    public function testRemoveIgnoresAStepTheWorkflowDoesNotHave(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $foreign = $this->createWorkflowStep((int)$this->createWorkflowTemplate((int)$this->admin->id)->id, 10, WorkflowStep::TYPE_PAUSE);

        $this->assertNull($this->guard()->remove($workflow, (int)$this->admin->id, 987654321));
        $this->assertNull($this->guard()->remove($workflow, (int)$this->admin->id, (int)$foreign->id));
        $this->assertNotNull(WorkflowStep::findOne($foreign->id), 'a step of another workflow is not removed');
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

        $refusal = $this->refusalOf(fn () => $this->guard()->remove($workflow, (int)$this->admin->id, (int)$target->id));

        $this->assertSame(409, $refusal->status);
        $this->assertSame(
            sprintf(WorkflowStepChangeGuard::STEP_ROUTED_TO, '"Deploy \"prod\""', '#10 "Build"'),
            $refusal->getMessage()
        );
        $this->assertSame(['step_ids' => [(int)$routing->id]], $refusal->details);
        $this->assertNotNull(WorkflowStep::findOne($target->id), 'not removed');
    }

    public function testTheRefusalNamesEveryRoutingStepByStepOrder(): void
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
            '(#10 "step-10", #30 "step-30")',
            $this->refusalOf(fn () => $this->guard()->remove($workflow, (int)$this->admin->id, (int)$target->id))->getMessage()
        );
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

        $this->assertNotNull($this->guard()->remove($workflow, (int)$this->admin->id, (int)$step->id));
        $this->assertNull(WorkflowStep::findOne($step->id));
    }

    public function testRemoveIsRefusedWhileARunIsActive(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $step = $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_PAUSE);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);

        $refusal = $this->refusalOf(fn () => $this->guard()->remove($workflow, (int)$this->admin->id, (int)$step->id));

        $this->assertSame(409, $refusal->status);
        $this->assertSame(['workflow_job_ids' => [(int)$run->id]], $refusal->details);
        $this->assertNotNull(WorkflowStep::findOne($step->id));
    }

    // -- Helpers ------------------------------------------------------------------

    private function guard(): WorkflowStepChangeGuard
    {
        /** @var WorkflowStepChangeGuard $guard */
        $guard = \Yii::$app->get('workflowStepChangeGuard');
        return $guard;
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
