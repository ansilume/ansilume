<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\Job;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalService;
use app\services\JobCancellationService;
use app\services\WorkflowStepRouter;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Approval gates fail closed: an approval step that was rejected, timed out
 * (rejecting) or canceled, and a job step whose job never ran because its
 * approval was, end the run as failed unless the step has an explicit On
 * Failure branch; the Always branch is never taken after such a gate. The
 * step says why. An approval opens the gate: an approval step follows Always,
 * then On Success, then the next step; a job step waits for its job.
 *
 * Decisions arrive as ApprovalService delivers them (WorkflowEngineFixtures::
 * decide(), contract C1 of step 3), cancels through JobCancellationService.
 */
class WorkflowExecutionGateTest extends DbTestCase
{
    use TeamScopeFixtures;
    use WorkflowEngineFixtures;

    private User $launcher;
    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->launcher = $this->operator('wf-gate-launcher');
        $this->approver = $this->operator('wf-gate-approver');
    }

    // -- Approval steps ----------------------------------------------------------------

    /**
     * Regression: a rejected approval step without an On Failure branch went
     * on with the next step, so the steps behind the gate ran, and the run
     * could even end as succeeded.
     */
    public function testRegressionARejectedApprovalStepEndsTheRunInsteadOfGoingOn(): void
    {
        ['run' => $run, 'gate' => $gate, 'next' => $next] = $this->approvalWorkflow();
        $placeholder = $this->jobOfStep($run, $gate);
        $request = $this->requestOfJob($placeholder);

        $this->decide($placeholder, ApprovalRequest::STATUS_REJECTED);

        $execution = $this->executionOfStep($run, $gate);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(sprintf(WorkflowStepRouter::APPROVAL_REJECTED, $request->id), $execution->error_message);
        $this->assertSame("Approval request #{$request->id} was rejected.", $execution->error_message);
        $this->assertNull($this->runOf($run, $next), 'the step behind the gate never ran');
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
    }

    public function testAnApprovalStepThatTimedOutEndsTheRun(): void
    {
        ['run' => $run, 'gate' => $gate, 'next' => $next] = $this->approvalWorkflow();
        $placeholder = $this->jobOfStep($run, $gate);
        $request = $this->requestOfJob($placeholder);

        $this->decide($placeholder, ApprovalRequest::STATUS_TIMED_OUT);

        $this->assertSame("Approval request #{$request->id} timed out.", $this->executionOfStep($run, $gate)->error_message);
        $this->assertNull($this->runOf($run, $next));
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
    }

    public function testAnExplicitFailureBranchIsFollowedAfterARejection(): void
    {
        ['run' => $run, 'gate' => $gate, 'next' => $next, 'other' => $rollback] = $this->approvalWorkflow(
            static fn (WorkflowStep $gate, WorkflowStep $next, WorkflowStep $other): array => ['on_failure_step_id' => $other->id]
        );

        $this->decide($this->jobOfStep($run, $gate), ApprovalRequest::STATUS_REJECTED);

        $this->assertNull($this->runOf($run, $next));
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $this->executionOfStep($run, $rollback)->status, 'the failure branch runs');
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status);
    }

    public function testAFailureBranchToTheEndEndsTheRunAsFailed(): void
    {
        ['run' => $run, 'gate' => $gate, 'next' => $next] = $this->approvalWorkflow(
            static fn (): array => ['on_failure_step_id' => WorkflowStep::END_WORKFLOW]
        );

        $this->decide($this->jobOfStep($run, $gate), ApprovalRequest::STATUS_REJECTED);

        $this->assertNull($this->runOf($run, $next));
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
    }

    /**
     * An approval step saved before the Always branch was refused on it
     * keeps one: it is not taken after a rejection, but still after an
     * approval.
     */
    public function testTheAlwaysBranchOfALegacyApprovalStepIsIgnoredOnRejection(): void
    {
        ['run' => $run, 'gate' => $gate, 'next' => $next, 'other' => $always] = $this->approvalWorkflow(
            static fn (WorkflowStep $gate, WorkflowStep $next, WorkflowStep $other): array => ['on_always_step_id' => $other->id]
        );

        $this->decide($this->jobOfStep($run, $gate), ApprovalRequest::STATUS_REJECTED);

        $this->assertNull($this->runOf($run, $always), 'the Always branch is not taken');
        $this->assertNull($this->runOf($run, $next));
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
    }

    public function testTheAlwaysBranchOfALegacyApprovalStepIsTakenOnApproval(): void
    {
        ['run' => $run, 'gate' => $gate, 'next' => $next, 'other' => $always] = $this->approvalWorkflow(
            static fn (WorkflowStep $gate, WorkflowStep $next, WorkflowStep $other): array => ['on_always_step_id' => $other->id]
        );

        $this->decide($this->jobOfStep($run, $gate), ApprovalRequest::STATUS_APPROVED);

        $this->assertSame(WorkflowJobStep::STATUS_SUCCEEDED, $this->executionOfStep($run, $gate)->status);
        $this->assertNotNull($this->runOf($run, $always));
        $this->assertNull($this->runOf($run, $next));
    }

    public function testAnApprovedApprovalStepFollowsItsSuccessBranch(): void
    {
        ['run' => $run, 'gate' => $gate, 'next' => $next, 'other' => $onSuccess] = $this->approvalWorkflow(
            static fn (WorkflowStep $gate, WorkflowStep $next, WorkflowStep $other): array => ['on_success_step_id' => $other->id]
        );
        $placeholder = $this->jobOfStep($run, $gate);

        $this->decide($placeholder, ApprovalRequest::STATUS_APPROVED);

        $execution = $this->executionOfStep($run, $gate);
        $this->assertSame(WorkflowJobStep::STATUS_SUCCEEDED, $execution->status);
        $this->assertNull($execution->error_message);
        $this->assertNotNull($this->runOf($run, $onSuccess));
        $this->assertNull($this->runOf($run, $next));
    }

    /**
     * A second decision for a step that ended takes no route again.
     */
    public function testADecisionForAStepThatEndedIsIgnored(): void
    {
        ['run' => $run, 'gate' => $gate, 'other' => $rollback] = $this->approvalWorkflow(
            static fn (WorkflowStep $gate, WorkflowStep $next, WorkflowStep $other): array => ['on_failure_step_id' => $other->id]
        );
        $placeholder = $this->jobOfStep($run, $gate);
        $this->decide($placeholder, ApprovalRequest::STATUS_REJECTED);

        $this->engine()->onApprovalResolved($placeholder, false);
        $this->engine()->onApprovalResolved($placeholder, true);

        $this->assertSame(1, (int)WorkflowJobStep::find()->where(['workflow_job_id' => $run->id, 'workflow_step_id' => $rollback->id])->count());
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $this->executionOfStep($run, $gate)->status);
    }

    // -- Job steps whose job waits for approval -----------------------------------------

    /**
     * Regression: a job step whose job's approval was rejected went on with
     * the next step, although its job never ran.
     */
    public function testRegressionARejectedGatedJobStepEndsTheRun(): void
    {
        ['run' => $run, 'gated' => $gated, 'next' => $next] = $this->gatedWorkflow();
        $job = $this->jobOfStep($run, $gated);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $job->status);
        $request = $this->requestOfJob($job);

        $this->decide($job, ApprovalRequest::STATUS_REJECTED);

        $execution = $this->executionOfStep($run, $gated);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(sprintf(WorkflowStepRouter::JOB_APPROVAL_REJECTED, $request->id), $execution->error_message);
        $this->assertSame("Not run: approval request #{$request->id} was rejected.", $execution->error_message);
        $this->assertNull($this->runOf($run, $next));
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
    }

    /**
     * The same through a vote: the only approver rejects through the
     * approval service, which settles the request and tells the engine.
     */
    public function testARejectionVotedByTheApproverEndsTheRun(): void
    {
        ['run' => $run, 'gated' => $gated, 'next' => $next] = $this->gatedWorkflow();
        $job = $this->jobOfStep($run, $gated);
        $request = $this->requestOfJob($job);
        /** @var ApprovalService $approvals */
        $approvals = \Yii::$app->get('approvalService');

        $approvals->recordDecision($request, (int)$this->approver->id, ApprovalDecision::DECISION_REJECTED);

        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_REJECTED, $request->status);
        $this->assertSame("Not run: approval request #{$request->id} was rejected.", $this->executionOfStep($run, $gated)->error_message);
        $this->assertNull($this->runOf($run, $next));
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
    }

    public function testAGatedJobStepWhoseApprovalTimedOutEndsTheRun(): void
    {
        ['run' => $run, 'gated' => $gated, 'next' => $next] = $this->gatedWorkflow();
        $job = $this->jobOfStep($run, $gated);
        $request = $this->requestOfJob($job);

        $this->decide($job, ApprovalRequest::STATUS_TIMED_OUT);

        $this->assertSame("Not run: approval request #{$request->id} timed out.", $this->executionOfStep($run, $gated)->error_message);
        $this->assertNull($this->runOf($run, $next));
    }

    /**
     * A job step may have an Always branch (cleanup that runs whatever the
     * job did), but a job that never ran is a closed gate: it is not taken.
     */
    public function testTheAlwaysBranchOfAGatedJobStepIsNotTakenAfterARejection(): void
    {
        ['run' => $run, 'gated' => $gated, 'next' => $next, 'other' => $cleanup] = $this->gatedWorkflow(
            static fn (WorkflowStep $gated, WorkflowStep $next, WorkflowStep $other): array => ['on_always_step_id' => $other->id]
        );

        $this->decide($this->jobOfStep($run, $gated), ApprovalRequest::STATUS_REJECTED);

        $this->assertNull($this->runOf($run, $cleanup));
        $this->assertNull($this->runOf($run, $next));
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
    }

    /**
     * An approval opens a job step's gate: the job is queued and the step
     * waits for it (an approval call for it changes nothing), then ends with
     * the job's outcome.
     */
    public function testAnApprovedGatedJobStepWaitsForItsJobAndEndsWithIt(): void
    {
        ['run' => $run, 'gated' => $gated, 'next' => $next] = $this->gatedWorkflow();
        $job = $this->jobOfStep($run, $gated);

        $this->decide($job, ApprovalRequest::STATUS_APPROVED);
        $this->engine()->onApprovalResolved($job, true);

        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $this->executionOfStep($run, $gated)->status, 'the step waits for its job');
        $job->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $job->status, 'the job is queued, not succeeded');
        $this->assertNull($this->runOf($run, $next));

        $this->finishJob($job);

        $this->assertSame(WorkflowJobStep::STATUS_SUCCEEDED, $this->executionOfStep($run, $gated)->status);
        $this->assertNotNull($this->runOf($run, $next));
    }

    /**
     * A job that ran after its approval and failed is no closed gate: the
     * step keeps the usual routing (an empty On Failure branch goes on with
     * the next step) and has no error of its own.
     */
    public function testAnApprovedJobThatFailedKeepsTheUsualRouting(): void
    {
        ['run' => $run, 'gated' => $gated, 'next' => $next] = $this->gatedWorkflow();
        $job = $this->jobOfStep($run, $gated);
        $this->decide($job, ApprovalRequest::STATUS_APPROVED);

        $this->finishJob($job, Job::STATUS_FAILED);

        $execution = $this->executionOfStep($run, $gated);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertNull($execution->error_message);
        $this->assertNotNull($this->runOf($run, $next));
    }

    // -- Canceled while waiting for approval ----------------------------------------------

    /**
     * Canceling the placeholder of an approval step (from its job page)
     * closes the gate: the request is canceled, the step fails with why, and
     * the run ends instead of going on.
     */
    public function testCancelingThePlaceholderClosesTheGate(): void
    {
        ['run' => $run, 'gate' => $gate, 'next' => $next] = $this->approvalWorkflow();
        $placeholder = $this->jobOfStep($run, $gate);
        $request = $this->requestOfJob($placeholder);

        $this->assertTrue($this->jobs()->cancel($placeholder, (int)$this->launcher->id));

        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status);
        $this->assertSame("Approval request #{$request->id} was canceled.", $this->executionOfStep($run, $gate)->error_message);
        $this->assertNull($this->runOf($run, $next));
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
    }

    public function testCancelingAPlaceholderFollowsAnExplicitFailureBranch(): void
    {
        ['run' => $run, 'gate' => $gate, 'next' => $next, 'other' => $rollback] = $this->approvalWorkflow(
            static fn (WorkflowStep $gate, WorkflowStep $next, WorkflowStep $other): array => ['on_failure_step_id' => $other->id]
        );

        $this->jobs()->cancel($this->jobOfStep($run, $gate), (int)$this->launcher->id);

        $this->assertNotNull($this->runOf($run, $rollback));
        $this->assertNull($this->runOf($run, $next));
    }

    public function testCancelingAGatedJobWhileItWaitsClosesTheGate(): void
    {
        ['run' => $run, 'gated' => $gated, 'next' => $next] = $this->gatedWorkflow();
        $job = $this->jobOfStep($run, $gated);
        $request = $this->requestOfJob($job);

        $this->assertTrue($this->jobs()->cancel($job, (int)$this->launcher->id));

        $this->assertSame("Not run: approval request #{$request->id} was canceled.", $this->executionOfStep($run, $gated)->error_message);
        $this->assertNull($this->runOf($run, $next));
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
    }

    /**
     * A job canceled after its approval, while queued, is a failed job, not
     * a closed gate: the usual routing applies.
     */
    public function testCancelingAnApprovedQueuedJobKeepsTheUsualRouting(): void
    {
        ['run' => $run, 'gated' => $gated, 'next' => $next] = $this->gatedWorkflow();
        $job = $this->jobOfStep($run, $gated);
        $this->decide($job, ApprovalRequest::STATUS_APPROVED);

        $this->assertTrue($this->jobs()->cancel($job, (int)$this->launcher->id));

        $execution = $this->executionOfStep($run, $gated);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertNull($execution->error_message);
        $this->assertNotNull($this->runOf($run, $next));
    }

    /**
     * The message of a gate whose request is gone (2.9.0 and older deleted
     * the requests of a deleted rule) says so, for either kind of step.
     */
    public function testAGateMessageWithoutARequestSaysTheRequestIsGone(): void
    {
        $step = new WorkflowStep();
        $step->name = 'gate';
        $step->step_type = WorkflowStep::TYPE_JOB;
        $execution = new WorkflowJobStep();
        $execution->snapshotStep($step);
        /** @var WorkflowStepRouter $router */
        $router = \Yii::$app->get('workflowStepRouter');

        $this->assertSame(WorkflowStepRouter::JOB_APPROVAL_MISSING, $router->gateMessage($execution, null));
        $this->assertSame('Not run: the approval request of its job no longer exists.', $router->gateMessage($execution, null));
        $step->step_type = WorkflowStep::TYPE_APPROVAL;
        $execution->snapshotStep($step);
        $this->assertSame(WorkflowStepRouter::APPROVAL_MISSING, $router->gateMessage($execution, null));
        $this->assertSame('Not approved: the approval request of this step no longer exists.', $router->gateMessage($execution, null));
    }

    /**
     * A job canceled while it waited for an approval request that is gone
     * never ran: its gate is closed, for an approval step's placeholder and
     * for a gated job step's job alike. Without an On Failure branch the run
     * ends as failed; the next step does not run.
     *
     * Regression: such a cancel (by an operator, or by the maintenance run's
     * StrandedJobSweep) routed the step as an ordinary failure, so the next
     * step after the gate ran.
     *
     * @dataProvider requestlessGateProvider
     */
    public function testRegressionACanceledJobWithoutItsRequestIsAClosedGate(string $kind): void
    {
        $flow = $kind === 'approval' ? $this->approvalWorkflow() : $this->gatedWorkflow();
        $first = $flow[$kind === 'approval' ? 'gate' : 'gated'];
        $job = $this->jobOfStep($flow['run'], $first);
        ApprovalRequest::deleteAll(['job_id' => $job->id]);

        $this->assertTrue($this->jobs()->cancel($job, null, JobCancellationService::REASON_APPROVAL_MISSING));

        $this->assertNull($this->runOf($flow['run'], $flow['next']), 'the step behind the gate never ran');
        $flow['run']->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $flow['run']->status);
        $execution = $this->executionOfStep($flow['run'], $first);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(
            $kind === 'approval' ? WorkflowStepRouter::APPROVAL_MISSING : WorkflowStepRouter::JOB_APPROVAL_MISSING,
            $execution->error_message
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function requestlessGateProvider(): array
    {
        return ['an approval step' => ['approval'], 'a gated job step' => ['job']];
    }

    /**
     * A job without a request that was queued (no gate, or one that opened)
     * is no closed gate when it is canceled: the usual routing applies.
     */
    public function testAQueuedJobCanceledWithoutARequestKeepsTheUsualRouting(): void
    {
        $template = $this->openTemplate((int)$this->launcher->id);
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $first = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$template->id);
        $next = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);
        $job = $this->jobOfStep($run, $first);
        $this->assertNotNull($job->queued_at);

        $this->assertTrue($this->jobs()->cancel($job, (int)$this->launcher->id));

        $execution = $this->executionOfStep($run, $first);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertNull($execution->error_message);
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $this->executionOfStep($run, $next)->status);
    }

    // -- Helpers ---------------------------------------------------------------------------

    /**
     * A run at its approval step ("gate"), whose rule lets the approver
     * decide; the next step by order ("next") and a third step ("other") are
     * pause steps, so a step that starts stays to be seen. $branches gives the
     * gate's branches, as stored (legacy rows included).
     *
     * @param (callable(WorkflowStep, WorkflowStep, WorkflowStep): array<string, int>)|null $branches
     * @return array{run: WorkflowJob, gate: WorkflowStep, next: WorkflowStep, other: WorkflowStep}
     */
    private function approvalWorkflow(?callable $branches = null): array
    {
        $rule = $this->usersRuleOf((int)$this->launcher->id, (int)$this->approver->id);
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $gate = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $next = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $other = $this->createWorkflowStep((int)$workflow->id, 2, WorkflowStep::TYPE_PAUSE);

        return ['run' => $this->launchWith($workflow, $gate, $next, $other, $branches), 'gate' => $gate, 'next' => $next, 'other' => $other];
    }

    /**
     * A run at its job step ("gated"), whose job template waits for a rule
     * the approver may decide; then two pause steps, as for approvalWorkflow().
     *
     * @param (callable(WorkflowStep, WorkflowStep, WorkflowStep): array<string, int>)|null $branches
     * @return array{run: WorkflowJob, gated: WorkflowStep, next: WorkflowStep, other: WorkflowStep}
     */
    private function gatedWorkflow(?callable $branches = null): array
    {
        $rule = $this->usersRuleOf((int)$this->launcher->id, (int)$this->approver->id);
        $template = $this->gatedTemplate((int)$this->launcher->id, $rule);
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $gated = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$template->id);
        $next = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $other = $this->createWorkflowStep((int)$workflow->id, 2, WorkflowStep::TYPE_PAUSE);

        return ['run' => $this->launchWith($workflow, $gated, $next, $other, $branches), 'gated' => $gated, 'next' => $next, 'other' => $other];
    }

    /**
     * @param (callable(WorkflowStep, WorkflowStep, WorkflowStep): array<string, int>)|null $branches
     */
    private function launchWith(
        WorkflowTemplate $workflow,
        WorkflowStep $first,
        WorkflowStep $next,
        WorkflowStep $other,
        ?callable $branches
    ): WorkflowJob {
        foreach ($branches === null ? [] : $branches($first, $next, $other) as $field => $target) {
            $this->branchTo($first, $field, $target);
        }

        return $this->engine()->launch($workflow, (int)$this->launcher->id);
    }

    private function jobs(): JobCancellationService
    {
        /** @var JobCancellationService $service */
        $service = \Yii::$app->get('jobCancellationService');

        return $service;
    }
}
