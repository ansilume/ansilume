<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobHostSummary;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalService;
use app\services\JobCancellationService;
use app\services\JobCompletionService;
use app\services\JobLaunchService;
use app\services\WorkflowCancellationService;
use app\services\WorkflowStepRouter;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\db\BaseActiveRecord;

/**
 * The window between a job step's launch and its link. JobLaunchService
 * commits the job (with the approval request of a gated template, which it
 * then announces) before it returns; only then is the step's execution
 * linked to the job (workflow_job_step.job_id). A vote, a timeout, a cancel
 * or the runner's report in that window finds no step to move on. The
 * engine reads the job again after the link (WorkflowStepDispatcher::
 * settleStart()) and ends the step with a job that has ended, so the run
 * moves on instead of waiting for ever; when someone else saw the link too,
 * the step still routes once.
 *
 * An approval step's placeholder has no such window: it is linked in the
 * transaction that writes its request.
 */
class WorkflowExecutionLinkWindowTest extends DbTestCase
{
    use TeamScopeFixtures;
    use WorkflowEngineFixtures;

    /** @var list<array{0: string, 1: mixed}> */
    private array $swapped = [];

    /** @var list<array{0: string, 1: \Closure}> */
    private array $hooks = [];

    private User $launcher;
    private User $approver;
    private RecordingNotificationDispatcher $notifications;

    protected function setUp(): void
    {
        parent::setUp();
        $this->launcher = $this->operator('wf-link-launcher');
        $this->approver = $this->operator('wf-link-approver');
        $this->notifications = new RecordingNotificationDispatcher();
        $this->swap('notificationDispatcher', $this->notifications);
    }

    protected function tearDown(): void
    {
        foreach ($this->hooks as [$event, $hook]) {
            Event::off(WorkflowJobStep::class, $event, $hook);
        }
        $this->hooks = [];
        foreach (array_reverse($this->swapped) as [$id, $original]) {
            \Yii::$app->set($id, $original);
        }
        $this->swapped = [];
        parent::tearDown();
    }

    // -- A gated job whose gate closed in the window --------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function gateClosedInTheWindowProvider(): array
    {
        return [
            'rejected by a vote' => ['reject', WorkflowStepRouter::JOB_APPROVAL_REJECTED],
            'timed out' => ['time out', WorkflowStepRouter::JOB_APPROVAL_TIMED_OUT],
            'canceled while it waited' => ['cancel', WorkflowStepRouter::JOB_APPROVAL_CANCELED],
        ];
    }

    /**
     * Regression: a gated job step whose approval was rejected or timed out,
     * or whose job was canceled, after the launch had committed the job but
     * before the step was linked to it, stayed running for ever: its run
     * never ended, and the active run blocked every step change of its
     * workflow until someone canceled it.
     *
     * @dataProvider gateClosedInTheWindowProvider
     */
    public function testRegressionAGatedJobWhoseGateClosedBeforeTheLinkEndsItsStep(string $how, string $format): void
    {
        ['workflow' => $workflow, 'first' => $gated, 'next' => $next] = $this->workflowOf($this->gatedTemplateForTheApprover());
        $this->inTheWindow(function (Job $job) use ($how): void {
            $this->closeTheGate($job, $how);
        });

        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);

        $request = $this->requestOfJob($this->jobOfStep($run, $gated));
        $execution = $this->executionOfStep($run, $gated);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(sprintf($format, $request->id), $execution->error_message);
        $this->assertNull($this->runOf($run, $next), 'the step behind the gate never ran');
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
        $this->assertCount(1, $this->auditEntries(AuditLog::ACTION_WORKFLOW_STEP_COMPLETED, 'workflow_job_step', (int)$execution->id));
        $this->assertCount(1, $this->auditEntries(AuditLog::ACTION_WORKFLOW_FAILED, 'workflow_job', (int)$run->id));
    }

    /**
     * Regression, as it happens: the request is announced (notifications go
     * out one after another) before the launch returns, and the first
     * approver to read one rejects it while the others are still being sent.
     */
    public function testRegressionARejectionVotedWhileTheRequestIsAnnouncedEndsTheGatedJobStep(): void
    {
        ['workflow' => $workflow, 'first' => $gated, 'next' => $next] = $this->workflowOf($this->gatedTemplateForTheApprover());
        $this->voteWhenAnnounced(ApprovalDecision::DECISION_REJECTED);

        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);

        $job = $this->jobOfStep($run, $gated);
        $this->assertSame(Job::STATUS_REJECTED, $job->status);
        $request = $this->requestOfJob($job);
        $this->assertSame(ApprovalRequest::STATUS_REJECTED, $request->status);
        $execution = $this->executionOfStep($run, $gated);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame("Not run: approval request #{$request->id} was rejected.", $execution->error_message);
        $this->assertNull($this->runOf($run, $next));
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
    }

    /**
     * An approval voted in the window queues the job, which has not ended:
     * the step waits for it as usual.
     */
    public function testAnApprovalVotedWhileTheRequestIsAnnouncedLetsTheStepWaitForItsJob(): void
    {
        ['workflow' => $workflow, 'first' => $gated, 'next' => $next] = $this->workflowOf($this->gatedTemplateForTheApprover());
        $this->voteWhenAnnounced(ApprovalDecision::DECISION_APPROVED);

        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);

        $this->assertSame(Job::STATUS_QUEUED, $this->jobOfStep($run, $gated)->status);
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $this->executionOfStep($run, $gated)->status);
        $this->assertNull($this->runOf($run, $next));
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status);
    }

    /**
     * The closed gate routes as any closed gate does: an explicit On Failure
     * branch is followed.
     */
    public function testAGateClosedBeforeTheLinkFollowsAnExplicitFailureBranch(): void
    {
        ['workflow' => $workflow, 'first' => $gated, 'next' => $next, 'other' => $rollback] = $this->workflowOf(
            $this->gatedTemplateForTheApprover()
        );
        $this->branchTo($gated, 'on_failure_step_id', (int)$rollback->id);
        $this->inTheWindow(function (Job $job): void {
            $this->closeTheGate($job, 'reject');
        });

        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);

        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $this->executionOfStep($run, $gated)->status);
        $this->assertNull($this->runOf($run, $next));
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $this->executionOfStep($run, $rollback)->status, 'the failure branch runs');
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status);
    }

    // -- A job that ended in the window ---------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function jobEndedInTheWindowProvider(): array
    {
        return [
            'succeeded' => ['succeed', WorkflowJobStep::STATUS_SUCCEEDED],
            'failed' => ['fail', WorkflowJobStep::STATUS_FAILED],
            'timed out' => ['time out', WorkflowJobStep::STATUS_FAILED],
            'canceled while queued' => ['cancel', WorkflowJobStep::STATUS_FAILED],
        ];
    }

    /**
     * Regression: a job that ended before its step was linked to it (its
     * runner reported, or it was canceled while queued) left the step
     * running for ever, and the run with it. The step now ends with the job
     * and the run moves on with the usual routing: a job that ran (or was
     * canceled without waiting for approval) is no closed gate, so an empty
     * On Failure branch goes on with the next step.
     *
     * @dataProvider jobEndedInTheWindowProvider
     */
    public function testRegressionAJobThatEndedBeforeTheLinkEndsItsStepAndTheRunMovesOn(string $how, string $stepStatus): void
    {
        ['workflow' => $workflow, 'first' => $first, 'next' => $next] = $this->workflowOf($this->openTemplate((int)$this->launcher->id));
        $this->inTheWindow(function (Job $job) use ($how): void {
            $this->endTheJob($job, $how);
        });

        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);

        $execution = $this->executionOfStep($run, $first);
        $this->assertSame($stepStatus, $execution->status);
        $this->assertNull($execution->error_message);
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $this->executionOfStep($run, $next)->status, 'the run moved on');
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status);
        $this->assertCount(1, $this->auditEntries(AuditLog::ACTION_WORKFLOW_STEP_COMPLETED, 'workflow_job_step', (int)$execution->id));
    }

    /**
     * A job that ended in the window as the last step ends the run with its
     * outcome.
     */
    public function testAJobThatEndedBeforeTheLinkAsTheLastStepEndsTheRun(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $only = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->openTemplate((int)$this->launcher->id)->id);
        $this->inTheWindow(function (Job $job): void {
            $this->endTheJob($job, 'succeed');
        });

        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);

        $this->assertSame(WorkflowJobStep::STATUS_SUCCEEDED, $this->executionOfStep($run, $only)->status);
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_SUCCEEDED, $run->status);
        $this->assertCount(1, $this->auditEntries(AuditLog::ACTION_WORKFLOW_COMPLETED, 'workflow_job', (int)$run->id));
    }

    /**
     * @return array<string, array{0: bool, 1: string|null}>
     */
    public static function jobStillGoingProvider(): array
    {
        return [
            'queued' => [false, null],
            'claimed by a runner' => [false, Job::STATUS_RUNNING],
            'waiting for approval' => [true, null],
        ];
    }

    /**
     * A job that has not ended at the link is left to its runner or its
     * approvers: the step waits for it, as it always did.
     *
     * @dataProvider jobStillGoingProvider
     */
    public function testAJobThatHasNotEndedAtTheLinkKeepsItsStepWaiting(bool $gated, ?string $status): void
    {
        $template = $gated ? $this->gatedTemplateForTheApprover() : $this->openTemplate((int)$this->launcher->id);
        ['workflow' => $workflow, 'first' => $first, 'next' => $next] = $this->workflowOf($template);
        $this->inTheWindow(static function (Job $job) use ($status): void {
            if ($status !== null) {
                Job::updateAll(['status' => $status, 'started_at' => time()], ['id' => $job->id]);
            }
        });

        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);

        $execution = $this->executionOfStep($run, $first);
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $execution->status);
        $this->assertSame((int)$this->jobOfStep($run, $first)->id, (int)$execution->job_id);
        $this->assertNull($this->runOf($run, $next));
        $this->assertSame([], $this->auditEntries(AuditLog::ACTION_WORKFLOW_STEP_COMPLETED, 'workflow_job_step', (int)$execution->id));
    }

    /**
     * A job row that is gone at the read (no code path deletes a job that
     * was just launched; jobs have no foreign key from the step) is not
     * taken for a job that ended: nothing routes, nothing fails.
     */
    public function testAJobThatVanishedBeforeTheReadIsNotTakenForAnEndedOne(): void
    {
        ['workflow' => $workflow, 'first' => $first, 'next' => $next] = $this->workflowOf($this->openTemplate((int)$this->launcher->id));
        $this->inTheWindow(static function (Job $job): void {
            Job::deleteAll(['id' => $job->id]);
        });

        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);

        $execution = $this->executionOfStep($run, $first);
        $this->assertNotNull($execution->job_id);
        $this->assertNull(Job::findOne(['id' => $execution->job_id]));
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $execution->status);
        $this->assertNull($this->runOf($run, $next));
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status);
    }

    /**
     * The job is read again after the link, with a locking read: a vote, a
     * timeout, a cancel or a runner's report still writing the job is waited
     * for, so either it finds the link (and moves the step on itself) or this
     * read sees what it wrote.
     */
    public function testTheJobIsReadWithALockAfterTheStepIsLinked(): void
    {
        ['workflow' => $workflow, 'first' => $first] = $this->workflowOf($this->openTemplate((int)$this->launcher->id));
        $runs = [];

        $statements = $this->statementsOf(function () use ($workflow, &$runs): void {
            $runs[] = $this->engine()->launch($workflow, (int)$this->launcher->id);
        });

        $this->assertCount(1, $runs);
        $job = $this->jobOfStep($runs[0], $first);
        $link = $this->statementIndex($statements, '~^UPDATE `workflow_job_step` SET `job_id`=' . $job->id . '\b~');
        $read = $this->statementIndex($statements, '~^SELECT \* FROM `job` WHERE `id` = ' . $job->id . ' LOCK IN SHARE MODE$~');
        $this->assertLessThan($read, $link, implode("\n", $statements));
    }

    /**
     * A report that came right after the link found the step and moved it
     * on; the engine's own read after the link then finds the step ended and
     * routes nothing: the step completes once, and the next step starts once.
     */
    public function testAJobReportedOnBothSidesOfTheLinkRoutesTheStepOnce(): void
    {
        ['workflow' => $workflow, 'first' => $first, 'next' => $next] = $this->workflowOf($this->openTemplate((int)$this->launcher->id));
        $this->inTheWindow(function (Job $job): void {
            $this->endTheJob($job, 'succeed');
        });
        $this->whenTheStepIsLinked(function (Job $job): void {
            $this->engine()->onChildJobCompleted($job);
        });

        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);

        $execution = $this->executionOfStep($run, $first);
        $this->assertSame(WorkflowJobStep::STATUS_SUCCEEDED, $execution->status);
        $this->assertCount(1, $this->auditEntries(AuditLog::ACTION_WORKFLOW_STEP_COMPLETED, 'workflow_job_step', (int)$execution->id));
        $this->assertSame(
            1,
            (int)WorkflowJobStep::find()->where(['workflow_job_id' => $run->id, 'workflow_step_id' => $next->id])->count(),
            'the next step started once'
        );
    }

    /**
     * A run canceled while its job step started keeps its cancel, also when
     * the step's job ended at once: the step ends with the cancel's message,
     * and the run is not moved on.
     */
    public function testAJobThatEndedInTheWindowOfACanceledRunDoesNotMoveItOn(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $first = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$this->openTemplate((int)$this->launcher->id)->id);
        $next = $this->createWorkflowStep((int)$workflow->id, 2, WorkflowStep::TYPE_PAUSE);
        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);
        $this->cancelBeforeTheStepIsWritten($run, $first);
        $this->inTheWindow(function (Job $job): void {
            $this->endTheJob($job, 'succeed');
        });

        $this->engine()->resume($run, (int)$this->launcher->id);

        $execution = $this->executionOfStep($run, $first);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(WorkflowCancellationService::STEP_RUN_CANCELED, $execution->error_message);
        $this->assertSame(Job::STATUS_SUCCEEDED, $this->jobOfStep($run, $first)->status, 'the job keeps its outcome');
        $this->assertNull($this->runOf($run, $next));
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $run->status);
        $this->assertSame([], $this->auditEntries(AuditLog::ACTION_WORKFLOW_STEP_COMPLETED, 'workflow_job_step', (int)$execution->id));
    }

    // -- An approval step has no window ------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function placeholderDecisionProvider(): array
    {
        return [
            'rejected' => [ApprovalDecision::DECISION_REJECTED, WorkflowJobStep::STATUS_FAILED, WorkflowJob::STATUS_FAILED],
            'approved' => [ApprovalDecision::DECISION_APPROVED, WorkflowJobStep::STATUS_SUCCEEDED, WorkflowJob::STATUS_RUNNING],
        ];
    }

    /**
     * The placeholder of an approval step is linked in the transaction that
     * writes its request, so a decision made the moment the request is
     * announced (the earliest anyone can see it) already finds the step and
     * moves the run on through the approval's own effects.
     *
     * @dataProvider placeholderDecisionProvider
     */
    public function testADecisionTheMomentAnApprovalStepIsAnnouncedFindsTheStep(
        string $decision,
        string $stepStatus,
        string $runStatus
    ): void {
        $rule = $this->usersRuleOf((int)$this->launcher->id, (int)$this->approver->id);
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $gate = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $next = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $this->voteWhenAnnounced($decision);

        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);

        $execution = $this->executionOfStep($run, $gate);
        $this->assertSame($stepStatus, $execution->status);
        $request = $this->requestOfJob($this->jobOfStep($run, $gate));
        if ($decision === ApprovalDecision::DECISION_REJECTED) {
            $this->assertSame("Approval request #{$request->id} was rejected.", $execution->error_message);
            $this->assertNull($this->runOf($run, $next));
        } else {
            $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $this->executionOfStep($run, $next)->status);
        }
        $run->refresh();
        $this->assertSame($runStatus, $run->status);
        // The decision moved the step on (onApprovalResolved(), which audits
        // the step's end as a job's completion does), and only it: an
        // approval step's placeholder is never read again after the link.
        $completed = $this->auditEntries(AuditLog::ACTION_WORKFLOW_STEP_COMPLETED, 'workflow_job_step', (int)$execution->id);
        $this->assertCount(1, $completed);
        $this->assertSame(['status' => $stepStatus, 'job_id' => (int)$execution->job_id], $this->metadataOf($completed[0]));
    }

    // -- Helpers -----------------------------------------------------------------------------

    /**
     * A workflow whose first step is a job step of $template ("first"),
     * followed by two pause steps ("next", "other"), so a step that starts
     * stays to be seen.
     *
     * @return array{workflow: WorkflowTemplate, first: WorkflowStep, next: WorkflowStep, other: WorkflowStep}
     */
    private function workflowOf(JobTemplate $template): array
    {
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);

        return [
            'workflow' => $workflow,
            'first' => $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$template->id),
            'next' => $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE),
            'other' => $this->createWorkflowStep((int)$workflow->id, 2, WorkflowStep::TYPE_PAUSE),
        ];
    }

    /**
     * A template whose jobs wait for a rule only the approver (not the
     * launcher) decides.
     */
    private function gatedTemplateForTheApprover(): JobTemplate
    {
        return $this->gatedTemplate(
            (int)$this->launcher->id,
            $this->usersRuleOf((int)$this->launcher->id, (int)$this->approver->id)
        );
    }

    /**
     * Run $meanwhile on the launched job after JobLaunchService committed and
     * announced it, before the launch returns and the step is linked to it.
     * $meanwhile gets its own copy of the job; the launch returns its model
     * as it left it, as another process's change would leave it.
     *
     * @param callable(Job): void $meanwhile
     */
    private function inTheWindow(callable $meanwhile): void
    {
        $this->swap('jobLaunchService', new class ($meanwhile) extends JobLaunchService {
            /** @var callable(Job): void */
            private $meanwhile;

            public function __construct(callable $meanwhile)
            {
                parent::__construct();
                $this->meanwhile = $meanwhile;
            }

            public function launch(JobTemplate $template, int $launchedBy, array $overrides = []): Job
            {
                $job = parent::launch($template, $launchedBy, $overrides);
                ($this->meanwhile)(Job::findOne(['id' => $job->id]) ?? $job);

                return $job;
            }
        });
    }

    /**
     * The approver decides $decision as soon as an approval request has been
     * announced.
     */
    private function voteWhenAnnounced(string $decision): void
    {
        $this->swap('approvalService', new class ((int)$this->approver->id, $decision) extends ApprovalService {
            public function __construct(private int $approverId, private string $decision)
            {
                parent::__construct();
            }

            public function announceRequest(ApprovalRequest $request, Job $job, ApprovalRule $rule): void
            {
                parent::announceRequest($request, $job, $rule);
                $this->recordDecision(ApprovalRequest::findOne(['id' => $request->id]) ?? $request, $this->approverId, $this->decision);
            }
        });
    }

    /**
     * Close the gate of the waiting $job the way $how says: the approver
     * rejects it, its request times out (the rule rejects then), or the
     * launcher cancels the job.
     */
    private function closeTheGate(Job $job, string $how): void
    {
        $request = $this->requestOfJob($job);
        if ($how === 'reject') {
            $this->approvals()->recordDecision($request, (int)$this->approver->id, ApprovalDecision::DECISION_REJECTED);
        } elseif ($how === 'time out') {
            ApprovalRequest::updateAll(['expires_at' => time() - 60], ['id' => $request->id]);
            $this->assertGreaterThanOrEqual(1, $this->approvals()->processTimeouts());
        } else {
            $this->assertTrue($this->cancellation()->cancel($job, (int)$this->launcher->id));
        }
        $job->refresh();
        $this->assertTrue($job->isFinished(), "the job ended in the window ({$how})");
    }

    /**
     * End the queued $job the way $how says, as its runner or a user would:
     * the runner reports success (with a host it ran on), failure or a
     * timeout, or the launcher cancels the job.
     */
    private function endTheJob(Job $job, string $how): void
    {
        /** @var JobCompletionService $completion */
        $completion = \Yii::$app->get('jobCompletionService');
        if ($how === 'succeed') {
            $this->ranOnAHost($job);
            $completion->complete($job, 0);
        } elseif ($how === 'fail') {
            $completion->complete($job, 2);
        } elseif ($how === 'time out') {
            $completion->completeTimedOut($job);
        } else {
            $this->assertTrue($this->cancellation()->cancel($job, (int)$this->launcher->id));
        }
        $job->refresh();
        $this->assertTrue($job->isFinished(), "the job ended in the window ({$how})");
    }

    /**
     * The runner's host summary of $job: a job that exited 0 succeeded only
     * when it ran on a host.
     */
    private function ranOnAHost(Job $job): void
    {
        $summary = new JobHostSummary();
        $summary->job_id = (int)$job->id;
        $summary->host = 'web1';
        $summary->ok = 1;
        $summary->changed = 0;
        $summary->failed = 0;
        $summary->skipped = 0;
        $summary->unreachable = 0;
        $summary->rescued = 0;
        $summary->created_at = time();
        $summary->save(false);
    }

    /**
     * Call $report with the step's job once the engine has linked an
     * execution to its job, as a report that arrives right after the link
     * would.
     *
     * @param callable(Job): void $report
     */
    private function whenTheStepIsLinked(callable $report): void
    {
        $fired = false;
        $hook = static function (Event $event) use ($report, &$fired): void {
            $execution = $event->sender;
            if ($fired || !$execution instanceof WorkflowJobStep || $execution->job_id === null) {
                return;
            }
            $job = Job::findOne(['id' => $execution->job_id]);
            if ($job === null || !$job->isFinished()) {
                return;
            }
            $fired = true;
            $report($job);
        };
        Event::on(WorkflowJobStep::class, BaseActiveRecord::EVENT_AFTER_UPDATE, $hook);
        $this->hooks[] = [BaseActiveRecord::EVENT_AFTER_UPDATE, $hook];
    }

    /**
     * A user cancels $run just before the engine writes the execution of
     * $step, so the cancel cannot see the step.
     */
    private function cancelBeforeTheStepIsWritten(WorkflowJob $run, WorkflowStep $step): void
    {
        $stepId = (int)$step->id;
        $userId = (int)$this->launcher->id;
        $fired = false;
        $hook = static function (Event $event) use ($run, $stepId, $userId, &$fired): void {
            if ($fired || !$event->sender instanceof WorkflowJobStep || (int)$event->sender->workflow_step_id !== $stepId) {
                return;
            }
            $fired = true;
            /** @var WorkflowCancellationService $cancellation */
            $cancellation = \Yii::$app->get('workflowCancellationService');
            $cancellation->cancelRun(WorkflowJob::findOne(['id' => $run->id]) ?? $run, $userId, JobCancellationService::REASON_OPERATOR);
        };
        Event::on(WorkflowJobStep::class, BaseActiveRecord::EVENT_BEFORE_INSERT, $hook);
        $this->hooks[] = [BaseActiveRecord::EVENT_BEFORE_INSERT, $hook];
    }

    private function approvals(): ApprovalService
    {
        /** @var ApprovalService $approvals */
        $approvals = \Yii::$app->get('approvalService');

        return $approvals;
    }

    private function cancellation(): JobCancellationService
    {
        /** @var JobCancellationService $cancellation */
        $cancellation = \Yii::$app->get('jobCancellationService');

        return $cancellation;
    }

    private function swap(string $id, object $replacement): void
    {
        $this->swapped[] = [$id, \Yii::$app->getComponents(true)[$id] ?? null];
        \Yii::$app->set($id, $replacement);
    }
}
