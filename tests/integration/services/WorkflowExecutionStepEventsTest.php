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
use app\services\WorkflowRunEvents;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * A step that ends with its job announces it the same way, whichever path
 * ends it: the workflow.step.completed audit entry ({status, job_id}) and,
 * when it failed, the workflow.step_failed notification. That holds for an
 * approval decision (WorkflowExecutionService::onApprovalResolved()) as for
 * a job's completion or a cancel (onChildJobCompleted()).
 *
 * Regression: a closed gate reached through an approval decision (a vote or
 * a timeout after the step was linked) wrote neither, while the same gate
 * reached through a cancel wrote both; who learned about a rejection
 * depended on timing, and step_failed subscribers missed rejections that an
 * explicit On Failure branch went past.
 */
class WorkflowExecutionStepEventsTest extends DbTestCase
{
    use TeamScopeFixtures;
    use WorkflowEngineFixtures;
    use CancellationFixtures;
    use StrandedRunFixtures;

    private RecordingNotificationDispatcher $notifications;
    private User $launcher;
    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = $this->recordNotifications();
        $this->launcher = $this->operator('wf-step-events-launcher');
        $this->approver = $this->operator('wf-step-events-approver');
    }

    protected function tearDown(): void
    {
        $this->restoreSwappedServices();
        $this->restoreNotifications();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function closedGateProvider(): array
    {
        return [
            'an approval step, rejected' => [WorkflowStep::TYPE_APPROVAL, ApprovalRequest::STATUS_REJECTED],
            'an approval step, timed out' => [WorkflowStep::TYPE_APPROVAL, ApprovalRequest::STATUS_TIMED_OUT],
            'a gated job step, rejected' => [WorkflowStep::TYPE_JOB, ApprovalRequest::STATUS_REJECTED],
            'a gated job step, timed out' => [WorkflowStep::TYPE_JOB, ApprovalRequest::STATUS_TIMED_OUT],
        ];
    }

    /**
     * Regression (see the class description): a rejection through
     * onApprovalResolved() writes the step's audit entry and sends its
     * notification, also when an On Failure branch goes on.
     *
     * @dataProvider closedGateProvider
     */
    public function testRegressionAClosedGateReachedThroughADecisionIsAnnounced(string $stepType, string $requestStatus): void
    {
        ['run' => $run, 'step' => $step, 'onFailure' => $onFailure] = $this->gatedRun($stepType);
        $job = $this->jobOfStep($run, $step);

        $this->decide($job, $requestStatus);

        $execution = $this->executionOfStep($run, $step);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $completed = $this->auditEntries(AuditLog::ACTION_WORKFLOW_STEP_COMPLETED, 'workflow_job_step', (int)$execution->id);
        $this->assertCount(1, $completed);
        $this->assertSame(['status' => WorkflowJobStep::STATUS_FAILED, 'job_id' => (int)$job->id], $this->metadataOf($completed[0]));
        $failed = $this->notifications->payloadsOf(NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED);
        $this->assertCount(1, $failed);
        $this->assertSame(['id' => (string)$execution->id, 'job_id' => (string)$job->id], $failed[0]['step'] ?? null);
        $workflow = $failed[0]['workflow'] ?? null;
        $this->assertIsArray($workflow);
        $this->assertSame((string)$run->id, $workflow['id'] ?? null);
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $this->executionOfStep($run, $onFailure)->status, 'the On Failure branch runs');
    }

    /**
     * The cancel path announces a closed gate the same way, as it did.
     *
     * @dataProvider stepTypeProvider
     */
    public function testAClosedGateReachedThroughACancelIsAnnouncedTheSameWay(string $stepType): void
    {
        ['run' => $run, 'step' => $step] = $this->gatedRun($stepType);
        $job = $this->jobOfStep($run, $step);

        $this->jobCancellation()->cancel($job, (int)$this->launcher->id);

        $execution = $this->executionOfStep($run, $step);
        $completed = $this->auditEntries(AuditLog::ACTION_WORKFLOW_STEP_COMPLETED, 'workflow_job_step', (int)$execution->id);
        $this->assertCount(1, $completed);
        $this->assertSame(['status' => WorkflowJobStep::STATUS_FAILED, 'job_id' => (int)$job->id], $this->metadataOf($completed[0]));
        $this->assertCount(1, $this->notifications->payloadsOf(NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function stepTypeProvider(): array
    {
        return ['an approval step' => [WorkflowStep::TYPE_APPROVAL], 'a gated job step' => [WorkflowStep::TYPE_JOB]];
    }

    /**
     * An approved approval step ends with its placeholder: the entry says it
     * succeeded, and no step_failed notification goes out.
     */
    public function testAnApprovedApprovalStepIsAuditedAsSucceeded(): void
    {
        ['run' => $run, 'step' => $step, 'next' => $next] = $this->gatedRun(WorkflowStep::TYPE_APPROVAL);
        $placeholder = $this->jobOfStep($run, $step);

        $this->decide($placeholder, ApprovalRequest::STATUS_APPROVED);

        $execution = $this->executionOfStep($run, $step);
        $completed = $this->auditEntries(AuditLog::ACTION_WORKFLOW_STEP_COMPLETED, 'workflow_job_step', (int)$execution->id);
        $this->assertCount(1, $completed);
        $this->assertSame(['status' => WorkflowJobStep::STATUS_SUCCEEDED, 'job_id' => (int)$placeholder->id], $this->metadataOf($completed[0]));
        $this->assertSame([], $this->notifications->payloadsOf(NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED));
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $this->executionOfStep($run, $next)->status);
    }

    /**
     * An approved gated job step waits for its job: nothing is announced
     * until the job ends, and then once.
     */
    public function testAnApprovedGatedJobStepIsAnnouncedWhenItsJobEnds(): void
    {
        ['run' => $run, 'step' => $step] = $this->gatedRun(WorkflowStep::TYPE_JOB);
        $job = $this->jobOfStep($run, $step);
        $execution = $this->executionOfStep($run, $step);

        $this->decide($job, ApprovalRequest::STATUS_APPROVED);
        $this->assertSame([], $this->auditEntries(AuditLog::ACTION_WORKFLOW_STEP_COMPLETED, 'workflow_job_step', (int)$execution->id));

        $this->finishJob($job, Job::STATUS_SUCCEEDED);
        $completed = $this->auditEntries(AuditLog::ACTION_WORKFLOW_STEP_COMPLETED, 'workflow_job_step', (int)$execution->id);
        $this->assertCount(1, $completed);
        $this->assertSame(['status' => WorkflowJobStep::STATUS_SUCCEEDED, 'job_id' => (int)$job->id], $this->metadataOf($completed[0]));
    }

    /**
     * Regression: an error in the step_failed notification (reading the
     * notification templates) escaped before the run was moved on, so the
     * run stayed active at a step that had ended. Notifications are
     * best-effort now: logged, and the run goes on.
     */
    public function testRegressionAFailedStepNotificationNoLongerStopsTheRun(): void
    {
        ['run' => $run, 'step' => $step, 'onFailure' => $onFailure] = $this->gatedRun(WorkflowStep::TYPE_APPROVAL);
        $this->swapService(
            'notificationDispatcher',
            new FailingNotificationDispatcher([NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED], $this->lostConnection())
        );

        $logged = $this->errorsLoggedBy(
            WorkflowRunEvents::class,
            fn () => $this->decide($this->jobOfStep($run, $step), ApprovalRequest::STATUS_REJECTED)
        );

        $this->assertSame(
            [sprintf(WorkflowRunEvents::NOTIFY_FAILED, $run->id, NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED, \yii\db\Exception::class, 'HY000', '2006')],
            $logged['errors']
        );
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $this->executionOfStep($run, $onFailure)->status, 'the run went on');
    }

    /**
     * The run's end is written although its notification fails.
     */
    public function testARunEndsAlthoughItsNotificationFails(): void
    {
        ['run' => $run, 'step' => $step] = $this->gatedRun(WorkflowStep::TYPE_APPROVAL, false);
        $this->swapService(
            'notificationDispatcher',
            new FailingNotificationDispatcher([NotificationTemplate::EVENT_WORKFLOW_FAILED], $this->lostConnection())
        );

        $logged = $this->errorsLoggedBy(
            WorkflowRunEvents::class,
            fn () => $this->decide($this->jobOfStep($run, $step), ApprovalRequest::STATUS_REJECTED)
        );

        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
        $this->assertCount(1, $this->auditEntries(AuditLog::ACTION_WORKFLOW_FAILED, 'workflow_job', (int)$run->id));
        $this->assertSame(
            [sprintf(WorkflowRunEvents::NOTIFY_FAILED, $run->id, NotificationTemplate::EVENT_WORKFLOW_FAILED, \yii\db\Exception::class, 'HY000', '2006')],
            $logged['errors']
        );
    }

    // -- Helpers ------------------------------------------------------------------------

    /**
     * A run at its first step, an approval step or a job step whose job
     * template has a rule the approver decides; then a pause step ("next")
     * and another pause step, its On Failure branch ("onFailure") unless
     * $withFailureBranch is false.
     *
     * @return array{run: WorkflowJob, step: WorkflowStep, next: WorkflowStep, onFailure: WorkflowStep}
     */
    private function gatedRun(string $stepType, bool $withFailureBranch = true): array
    {
        $rule = $this->usersRuleOf((int)$this->launcher->id, (int)$this->approver->id);
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $step = $stepType === WorkflowStep::TYPE_APPROVAL
            ? $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id)
            : $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->gatedTemplate((int)$this->launcher->id, $rule)->id);
        $next = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $onFailure = $this->createWorkflowStep((int)$workflow->id, 2, WorkflowStep::TYPE_PAUSE);
        if ($withFailureBranch) {
            $this->branchTo($step, 'on_failure_step_id', (int)$onFailure->id);
        }

        return [
            'run' => $this->engine()->launch($workflow, (int)$this->launcher->id),
            'step' => $step,
            'next' => $next,
            'onFailure' => $onFailure,
        ];
    }
}
