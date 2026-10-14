<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\Job;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowExecutionService;
use app\services\WorkflowStepRouter;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\db\BaseActiveRecord;

/**
 * How a run moves on: a branch to a step that is gone fails the run instead
 * of ending it as done, naming the step's ID; an empty branch goes on with
 * the next step in the run order (by step order, ties by ID); a step leaves
 * running once, so a stale or concurrent completion and a second resume
 * route nothing; a run ends once.
 */
class WorkflowExecutionRoutingTest extends DbTestCase
{
    use TeamScopeFixtures;
    use WorkflowEngineFixtures;

    private User $user;
    private ?\Closure $hook = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->operator('wf-routing');
    }

    protected function tearDown(): void
    {
        if ($this->hook !== null) {
            Event::off(WorkflowJobStep::class, BaseActiveRecord::EVENT_AFTER_FIND, $this->hook);
            $this->hook = null;
        }
        parent::tearDown();
    }

    // -- Branches to steps that are gone --------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function brokenBranchProvider(): array
    {
        return [
            'On Success after a success' => ['on_success_step_id', Job::STATUS_SUCCEEDED],
            'On Failure after a failure' => ['on_failure_step_id', Job::STATUS_FAILED],
            'Always after a success' => ['on_always_step_id', Job::STATUS_SUCCEEDED],
        ];
    }

    /**
     * Regression: a branch to a step that had been removed from the workflow
     * ended the run there, as succeeded when the step had succeeded: the rest
     * of the workflow was skipped without a word.
     *
     * @dataProvider brokenBranchProvider
     */
    public function testRegressionABranchToARemovedStepFailsTheRun(string $field, string $jobStatus): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $first = $this->jobStep($workflow, 0);
        $removed = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $this->branchTo($first, $field, (int)$removed->id);
        $removedId = (int)$removed->id;
        $removed->delete();
        $run = $this->engine()->launch($workflow, (int)$this->user->id);

        $this->finishJob($this->jobOfStep($run, $first), $jobStatus);

        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
        $execution = $this->executionOfStep($run, $first);
        $this->assertSame(
            $jobStatus === Job::STATUS_SUCCEEDED ? WorkflowJobStep::STATUS_SUCCEEDED : WorkflowJobStep::STATUS_FAILED,
            $execution->status,
            'the step keeps its outcome'
        );
        $this->assertSame(sprintf(WorkflowStepRouter::ROUTE_TARGET_MISSING, $removedId), $execution->error_message);
        $this->assertSame("The step this branch leads to no longer exists (step ID {$removedId}).", $execution->error_message);
    }

    /**
     * Regression: the router error showed a step ID like a step number
     * ("The next step #7 of this workflow no longer exists."), while "#N"
     * names runs, jobs and requests, and "N." a step's position. It names
     * the step ID as one now.
     */
    public function testRegressionTheRouterErrorShowedAStepIdLikeAStepNumber(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $step = $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_PAUSE);
        $this->branchTo($step, 'on_success_step_id', 7);

        $route = (new WorkflowStepRouter())->route($step, true, false);

        $this->assertNull($route['next']);
        $this->assertSame('The step this branch leads to no longer exists (step ID 7).', $route['error']);
        $this->assertStringNotContainsString('#7', (string)$route['error']);
    }

    /**
     * A branch to a step of another workflow (saved before the model refused
     * it) is no step of this workflow either.
     */
    public function testABranchIntoAnotherWorkflowFailsTheRun(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $first = $this->jobStep($workflow, 0);
        $elsewhere = $this->createWorkflowStep((int)$this->createWorkflowTemplate((int)$this->user->id)->id, 0, WorkflowStep::TYPE_PAUSE);
        $this->branchTo($first, 'on_success_step_id', (int)$elsewhere->id);
        $run = $this->engine()->launch($workflow, (int)$this->user->id);

        $this->finishJob($this->jobOfStep($run, $first));

        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
        $this->assertSame(sprintf(WorkflowStepRouter::ROUTE_TARGET_MISSING, $elsewhere->id), $this->executionOfStep($run, $first)->error_message);
        $this->assertSame(0, (int)WorkflowJobStep::find()->where(['workflow_step_id' => $elsewhere->id])->count());
    }

    /**
     * A closed gate whose failure branch is gone says both why it closed
     * and why the run could not go on.
     */
    public function testAClosedGateWithABrokenFailureBranchSaysBoth(): void
    {
        $approver = $this->operator('wf-routing-approver');
        $rule = $this->usersRuleOf((int)$this->user->id, (int)$approver->id);
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $gate = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $this->branchTo($gate, 'on_failure_step_id', 987654321);
        $run = $this->engine()->launch($workflow, (int)$this->user->id);
        $placeholder = $this->jobOfStep($run, $gate);
        $request = $this->requestOfJob($placeholder);

        $this->decide($placeholder, ApprovalRequest::STATUS_REJECTED);

        $this->assertSame(
            "Approval request #{$request->id} was rejected. The step this branch leads to no longer exists (step ID 987654321).",
            $this->executionOfStep($run, $gate)->error_message
        );
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
    }

    /**
     * A step that was removed from its workflow while it ran: its execution
     * stays (history), and the run, which cannot be routed on without the
     * step, fails with why.
     */
    public function testAStepRemovedWhileItRanFailsTheRun(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $first = $this->jobStep($workflow, 0);
        $next = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $run = $this->engine()->launch($workflow, (int)$this->user->id);
        $job = $this->jobOfStep($run, $first);
        $execution = $this->executionOfStep($run, $first);
        $first->delete();

        $this->finishJob($job);

        $execution->refresh();
        $this->assertNull($execution->workflow_step_id);
        $this->assertSame(WorkflowJobStep::STATUS_SUCCEEDED, $execution->status);
        $this->assertSame(WorkflowExecutionService::STEP_REMOVED, $execution->error_message);
        $this->assertNull($this->runOf($run, $next));
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
    }

    // -- Empty branches follow the run order ------------------------------------------------

    /**
     * Regression: an empty route skipped a step that shares its step order.
     * With X and Y at step order 10 and Z at 20, the run went from X
     * straight to Z, the first step with a higher step order, and never ran
     * Y. The run order breaks ties by ID: X, then Y, then Z.
     */
    public function testRegressionAnEmptyRouteSkippedAStepThatSharesItsStepOrder(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $x = $this->jobStep($workflow, 10);
        $y = $this->jobStep($workflow, 10);
        $z = $this->createWorkflowStep((int)$workflow->id, 20, WorkflowStep::TYPE_PAUSE);
        $run = $this->engine()->launch($workflow, (int)$this->user->id);
        $this->assertNotNull($this->runOf($run, $x), 'the run starts with X: the lowest step order, then the lowest ID');
        $this->assertNull($this->runOf($run, $y));

        $this->finishJob($this->jobOfStep($run, $x));

        $this->assertNotNull($this->runOf($run, $y), 'X succeeded, so Y runs');
        $this->assertNull($this->runOf($run, $z), 'Z waits for Y');

        $this->finishJob($this->jobOfStep($run, $y));

        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $this->executionOfStep($run, $z)->status, 'then Z');
    }

    /**
     * A run whose last steps share their step order runs them both, in ID
     * order, and ends with the outcome of the last one.
     */
    public function testARunWhoseLastStepsAreTiedRunsBothAndEndsAfterTheLast(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $first = $this->jobStep($workflow, 5);
        $second = $this->jobStep($workflow, 5);
        $run = $this->engine()->launch($workflow, (int)$this->user->id);

        $this->finishJob($this->jobOfStep($run, $first));
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status, 'the second step runs');

        $this->finishJob($this->jobOfStep($run, $second), Job::STATUS_FAILED);

        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status, 'the run ends with the outcome of the last step');
        $this->assertNull($this->executionOfStep($run, $second)->error_message);
    }

    /**
     * @return array<string, array{0: list<int>, 1: list<int>}>
     */
    public static function runOrderProvider(): array
    {
        // The step orders of the steps, in the order they are created (by
        // ID); the run order, as indexes into them.
        return [
            'sparse' => [[10, 20, 30], [0, 1, 2]],
            'dense, as the API numbers steps' => [[0, 1, 2], [0, 1, 2]],
            'all tied' => [[5, 5, 5], [0, 1, 2]],
            'a tie, then a higher step order' => [[10, 10, 20], [0, 1, 2]],
            'a higher step order first, then a tie' => [[20, 10, 10], [1, 2, 0]],
            'created out of order' => [[30, 10, 20], [1, 2, 0]],
        ];
    }

    /**
     * An empty branch goes on with the next step in the run order (by step
     * order, ties by ID), after a success and after a failure; after the
     * last step the run ends.
     *
     * @dataProvider runOrderProvider
     * @param list<int> $orders
     * @param list<int> $runOrder
     */
    public function testAnEmptyBranchGoesOnWithTheNextStepInTheRunOrder(array $orders, array $runOrder): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $steps = [];
        foreach ($orders as $order) {
            $steps[] = $this->createWorkflowStep((int)$workflow->id, $order, WorkflowStep::TYPE_PAUSE);
        }
        $router = new WorkflowStepRouter();

        $expected = [];
        $actual = [];
        foreach ($runOrder as $k => $index) {
            $next = isset($runOrder[$k + 1]) ? (int)$steps[$runOrder[$k + 1]]->id : null;
            foreach ([true, false] as $succeeded) {
                $route = $router->route($steps[$index], $succeeded, false);
                $this->assertNull($route['error']);
                $expected[] = $next;
                $actual[] = $route['next'] === null ? null : (int)$route['next']->id;
            }
        }

        $this->assertSame($expected, $actual);
        $this->assertSame((int)$steps[$runOrder[0]]->id, (int)$workflow->getStartStep()?->id, 'the run starts with the first');
    }

    // -- A step leaves running once ---------------------------------------------------------

    /**
     * Regression: a second completion report for the same child job (a
     * stale model, a retried request) ended the step again and started the
     * next step a second time, with a second job.
     */
    public function testRegressionAStaleJobCompletionRoutesTheStepOnlyOnce(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $first = $this->jobStep($workflow, 0);
        $second = $this->jobStep($workflow, 1);
        $run = $this->engine()->launch($workflow, (int)$this->user->id);
        $job = $this->jobOfStep($run, $first);
        $this->finishJob($job);
        $execution = $this->executionOfStep($run, $first);

        $this->engine()->onChildJobCompleted($job);

        $this->assertSame(1, (int)WorkflowJobStep::find()->where(['workflow_job_id' => $run->id, 'workflow_step_id' => $second->id])->count());
        $this->assertCount(1, $this->auditEntries(AuditLog::ACTION_WORKFLOW_STEP_COMPLETED, 'workflow_job_step', (int)$execution->id));
    }

    /**
     * Two completions that both found the step running: only the one whose
     * conditional update moved it routes.
     */
    public function testOfTwoConcurrentCompletionsOnlyTheFirstRoutes(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $first = $this->jobStep($workflow, 0);
        $second = $this->jobStep($workflow, 1);
        $run = $this->engine()->launch($workflow, (int)$this->user->id);
        $job = $this->jobOfStep($run, $first);
        $execution = $this->executionOfStep($run, $first);
        Job::updateAll(['status' => Job::STATUS_SUCCEEDED, 'finished_at' => time()], ['id' => $job->id]);
        $job->refresh();
        $this->endTheStepWhenTheEngineLoadsIt((int)$execution->id, WorkflowJobStep::STATUS_FAILED);

        $this->engine()->onChildJobCompleted($job);

        $execution->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status, 'the other caller\'s outcome stays');
        $this->assertNull($this->runOf($run, $second), 'nothing was routed');
        $this->assertSame([], $this->auditEntries(AuditLog::ACTION_WORKFLOW_STEP_COMPLETED, 'workflow_job_step', (int)$execution->id));
    }

    /**
     * A decision for a step that another caller ended between the engine's
     * read and its write routes nothing.
     */
    public function testADecisionThatAnotherCallerBeatRoutesNothing(): void
    {
        $approver = $this->operator('wf-routing-beaten');
        $rule = $this->usersRuleOf((int)$this->user->id, (int)$approver->id);
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $gate = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $next = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $run = $this->engine()->launch($workflow, (int)$this->user->id);
        $placeholder = $this->jobOfStep($run, $gate);
        $execution = $this->executionOfStep($run, $gate);
        ApprovalRequest::updateAll(['status' => ApprovalRequest::STATUS_APPROVED, 'resolved_at' => time()], ['job_id' => $placeholder->id]);
        Job::updateAll(['status' => Job::STATUS_SUCCEEDED, 'finished_at' => time()], ['id' => $placeholder->id]);
        $placeholder->refresh();
        $this->endTheStepWhenTheEngineLoadsIt((int)$execution->id, WorkflowJobStep::STATUS_FAILED);

        $this->engine()->onApprovalResolved($placeholder, true);

        $execution->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status, 'the other caller\'s outcome stays');
        $this->assertNull($this->runOf($run, $next));
    }

    /**
     * A step that ends after its run ended (it was canceled meanwhile) keeps
     * its job's outcome, and the run is not moved on.
     */
    public function testAStepThatEndsAfterItsRunEndedMovesNothingOn(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $first = $this->jobStep($workflow, 0);
        $second = $this->jobStep($workflow, 1);
        $run = $this->engine()->launch($workflow, (int)$this->user->id);
        WorkflowJob::updateAll(['status' => WorkflowJob::STATUS_CANCELED, 'finished_at' => time()], ['id' => $run->id]);

        $this->finishJob($this->jobOfStep($run, $first));

        $this->assertSame(WorkflowJobStep::STATUS_SUCCEEDED, $this->executionOfStep($run, $first)->status);
        $this->assertNull($this->runOf($run, $second));
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $run->status);
    }

    /**
     * A job that was rejected while it waited for approval and reaches the
     * engine as a completion is a closed gate too.
     */
    public function testARejectedJobReportedAsEndedIsAClosedGate(): void
    {
        $approver = $this->operator('wf-routing-rejected');
        $rule = $this->usersRuleOf((int)$this->user->id, (int)$approver->id);
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $gated = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->gatedTemplate((int)$this->user->id, $rule)->id);
        $next = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $run = $this->engine()->launch($workflow, (int)$this->user->id);
        $job = $this->jobOfStep($run, $gated);
        $request = $this->requestOfJob($job);
        ApprovalRequest::updateAll(['status' => ApprovalRequest::STATUS_REJECTED, 'resolved_at' => time()], ['id' => $request->id]);

        $this->finishJob($job, Job::STATUS_REJECTED);

        $this->assertSame("Not run: approval request #{$request->id} was rejected.", $this->executionOfStep($run, $gated)->error_message);
        $this->assertNull($this->runOf($run, $next));
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
    }

    public function testAnExecutionThatCannotBeSavedIsAnError(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $run = $this->engine()->launch($workflow, (int)$this->user->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to create workflow job step: {"workflow_step_id":');
        $this->engine()->executeStep($run, new WorkflowStep());
    }

    public function testOfTwoResumesOnlyTheFirstMovesTheRunOn(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $pause = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $next = $this->jobStep($workflow, 1);
        $run = $this->engine()->launch($workflow, (int)$this->user->id);
        $this->endTheStepWhenTheEngineLoadsIt((int)$this->executionOfStep($run, $pause)->id, WorkflowJobStep::STATUS_SUCCEEDED);

        try {
            $this->engine()->resume($run, (int)$this->user->id);
            $this->fail('expected a refusal');
        } catch (\RuntimeException $e) {
            $this->assertSame(WorkflowExecutionService::NO_PAUSED_STEP, $e->getMessage());
        }

        $this->assertNull($this->runOf($run, $next));
        $this->assertSame([], $this->auditEntries(AuditLog::ACTION_WORKFLOW_STEP_RESUMED, 'workflow_job_step', (int)$this->executionOfStep($run, $pause)->id));
    }

    /**
     * The paused execution is found by the type it ran as and named by the
     * name it ran as, also when the step was removed meanwhile; the run,
     * which cannot be routed on without the step, fails.
     */
    public function testResumeFindsThePausedStepByWhatItRanAs(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $pause = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $run = $this->engine()->launch($workflow, (int)$this->user->id);
        $execution = $this->executionOfStep($run, $pause);
        $pause->delete();

        $this->engine()->resume($run, (int)$this->user->id);

        $execution->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_SUCCEEDED, $execution->status);
        $this->assertSame(WorkflowExecutionService::STEP_REMOVED, $execution->error_message);
        $resumed = $this->auditEntries(AuditLog::ACTION_WORKFLOW_STEP_RESUMED, 'workflow_job_step', (int)$execution->id);
        $this->assertCount(1, $resumed);
        $this->assertSame(['step_name' => 'step-0'], $this->metadataOf($resumed[0]));
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
    }

    // -- The run --------------------------------------------------------------------------

    /**
     * A run ends once: a second completion, or the completion of a run that
     * was canceled meanwhile, writes nothing.
     */
    public function testARunEndsOnce(): void
    {
        $recorder = new RecordingNotificationDispatcher();
        $before = \Yii::$app->getComponents(true)['notificationDispatcher'] ?? null;
        \Yii::$app->set('notificationDispatcher', $recorder);
        try {
            $workflow = $this->createWorkflowTemplate((int)$this->user->id);
            $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
            $run = $this->engine()->launch($workflow, (int)$this->user->id);
            $stale = WorkflowJob::findOne($run->id);
            $this->assertNotNull($stale);

            $this->assertTrue($this->engine()->completeWorkflow($run, WorkflowJob::STATUS_SUCCEEDED));
            $this->assertFalse($this->engine()->completeWorkflow($stale, WorkflowJob::STATUS_FAILED));

            $this->assertSame(WorkflowJob::STATUS_SUCCEEDED, $stale->status, 'the model is refreshed');
            $this->assertCount(1, $this->auditEntries(AuditLog::ACTION_WORKFLOW_COMPLETED, 'workflow_job', (int)$run->id));
            $this->assertSame([], $this->auditEntries(AuditLog::ACTION_WORKFLOW_FAILED, 'workflow_job', (int)$run->id));
            $this->assertCount(1, $recorder->payloadsOf(NotificationTemplate::EVENT_WORKFLOW_SUCCEEDED));
            $this->assertSame([], $recorder->payloadsOf(NotificationTemplate::EVENT_WORKFLOW_FAILED));
        } finally {
            \Yii::$app->set('notificationDispatcher', $before);
        }
    }

    /**
     * completeWorkflow() with the canceled status sends the canceled event.
     */
    public function testCompletingARunAsCanceledSendsTheCanceledEvent(): void
    {
        $recorder = new RecordingNotificationDispatcher();
        $before = \Yii::$app->getComponents(true)['notificationDispatcher'] ?? null;
        \Yii::$app->set('notificationDispatcher', $recorder);
        try {
            $workflow = $this->createWorkflowTemplate((int)$this->user->id);
            $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
            $run = $this->engine()->launch($workflow, (int)$this->user->id);

            $this->assertTrue($this->engine()->completeWorkflow($run, WorkflowJob::STATUS_CANCELED));

            $this->assertSame(WorkflowJob::STATUS_CANCELED, $run->status);
            $this->assertCount(1, $recorder->payloadsOf(NotificationTemplate::EVENT_WORKFLOW_CANCELED));
        } finally {
            \Yii::$app->set('notificationDispatcher', $before);
        }
    }

    /**
     * Each execution keeps the name and type its step had when it ran.
     */
    public function testAnExecutionKeepsWhatItsStepWasWhenItRan(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $step = $this->jobStep($workflow, 0);
        $run = $this->engine()->launch($workflow, (int)$this->user->id);
        WorkflowStep::updateAll(['name' => 'renamed later'], ['id' => $step->id]);

        $execution = $this->executionOfStep($run, $step);

        $this->assertSame('step-0', $execution->getAttribute('step_name'));
        $this->assertSame(WorkflowStep::TYPE_JOB, $execution->getAttribute('step_type'));
        $this->assertSame('step-0', $execution->stepName());
    }

    // -- Helpers ---------------------------------------------------------------------------

    private function jobStep(WorkflowTemplate $workflow, int $order): WorkflowStep
    {
        return $this->createWorkflowStep((int)$workflow->id, $order, WorkflowStep::TYPE_JOB, (int)$this->openTemplate((int)$this->user->id)->id);
    }

    /**
     * Another caller ends execution #$executionId with $status right after
     * the engine read it as running, before the engine writes.
     */
    private function endTheStepWhenTheEngineLoadsIt(int $executionId, string $status): void
    {
        $fired = false;
        $this->hook = static function (Event $event) use ($executionId, $status, &$fired): void {
            $loaded = $event->sender;
            if ($fired || !$loaded instanceof WorkflowJobStep || (int)$loaded->id !== $executionId || $loaded->status !== WorkflowJobStep::STATUS_RUNNING) {
                return;
            }
            $fired = true;
            WorkflowJobStep::updateAll(['status' => $status, 'finished_at' => time()], ['id' => $executionId]);
        };
        Event::on(WorkflowJobStep::class, BaseActiveRecord::EVENT_AFTER_FIND, $this->hook);
    }
}
