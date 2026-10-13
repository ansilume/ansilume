<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\Job;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\JobCancellationService;
use app\services\JobCompletionService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\log\Logger;

/**
 * JobCancellationService: one conditional transition that closes the job's
 * pending approval request, with the audit entries, the notification and
 * the workflow advance after the commit.
 *
 * The regression tests go through JobCompletionService::cancel(), the entry
 * point the controllers used before this service existed, so they fail on
 * the code before the fix.
 */
class JobCancellationServiceTest extends DbTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;

    private RecordingNotificationDispatcher $notifications;
    private User $launcher;
    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = $this->recordNotifications();
        $this->launcher = $this->createUserWithRole('cancel-launcher', 'operator');
        $this->approver = $this->createUserWithRole('cancel-approver', 'operator');
    }

    protected function tearDown(): void
    {
        $this->restoreNotifications();
        parent::tearDown();
    }

    // -- The approval request ------------------------------------------------------

    /**
     * Regression: canceling a job left its approval request pending and
     * decidable. Approvers still saw Approve and Reject, a vote turned the
     * request approved or rejected (with its notification) while the job
     * stayed canceled, and the request stayed on the dashboard for good.
     */
    public function testCancelingAJobClosesItsPendingApprovalRequest(): void
    {
        ['job' => $job, 'request' => $request, 'rule' => $rule] = $this->waitingJob();
        $before = time();

        $canceled = $this->completion()->cancel($job, (int)$this->launcher->id);

        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status);
        $this->assertTrue($canceled);
        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertGreaterThanOrEqual($before, (int)$request->resolved_at);
        $this->assertFalse($this->approvalService()->canUserApprove($request, (int)$this->approver->id), 'no Approve/Reject buttons');
        $refused = null;
        try {
            $this->approvalService()->recordDecision($request, (int)$this->approver->id, ApprovalDecision::DECISION_APPROVED);
        } catch (\RuntimeException $e) {
            $refused = $e;
        }
        $this->assertNotNull($refused, 'a vote on the canceled request is refused');
        $this->assertSame(0, (int)ApprovalDecision::find()->where(['approval_request_id' => $request->id])->count());

        $entry = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', (int)$request->id);
        $this->assertSame((int)$this->launcher->id, (int)$entry->user_id);
        $this->assertSame(
            ['job_id' => (int)$job->id, 'rule_id' => (int)$rule->id, 'reason' => JobCancellationService::REASON_OPERATOR],
            $this->contextOf($entry)
        );
        $this->assertSame([], $this->notifications->payloadsOf(NotificationTemplate::EVENT_APPROVAL_APPROVED));
        $this->assertSame([], $this->notifications->payloadsOf(NotificationTemplate::EVENT_APPROVAL_REJECTED));
    }

    /**
     * A request that was decided keeps its decision: the job was approved
     * (and queued) before the cancel, which is the true history.
     */
    public function testADecidedRequestKeepsItsDecision(): void
    {
        ['job' => $job, 'request' => $request] = $this->waitingJob();
        $this->approvalService()->recordDecision($request, (int)$this->approver->id, ApprovalDecision::DECISION_APPROVED);
        $request->refresh();
        $job->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
        $decided = $request->getAttributes();

        $this->assertTrue($this->jobCancellation()->cancel($job, (int)$this->launcher->id));

        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $request->refresh();
        $this->assertSame($decided, $request->getAttributes());
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', (int)$request->id));
    }

    /**
     * A request whose rule is gone (deleting a rule sets the request's
     * approval_rule_id to NULL, migration m000083) is closed as well; its
     * audit entry names no rule rather than rule 0.
     */
    public function testARequestWithoutItsRuleIsClosedAndNamesNoRule(): void
    {
        ['job' => $job, 'request' => $request] = $this->waitingJob();
        ApprovalRequest::updateAll(['approval_rule_id' => null], ['id' => $request->id]);

        $this->assertTrue($this->jobCancellation()->cancel($job, (int)$this->launcher->id));

        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status);
        $this->assertSame(
            ['job_id' => (int)$job->id, 'rule_id' => null, 'reason' => JobCancellationService::REASON_OPERATOR],
            $this->contextOf($this->onlyAuditOf(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', (int)$request->id))
        );
    }

    /**
     * A job without a request is canceled as before; nothing else is closed.
     */
    public function testAJobWithoutARequestIsCanceled(): void
    {
        $job = $this->createJob((int)$this->template()->id, (int)$this->launcher->id, Job::STATUS_QUEUED);
        $closedBefore = (int)AuditLog::find()->where(['action' => AuditLog::ACTION_APPROVAL_CANCELED])->count();

        $this->assertTrue($this->jobCancellation()->cancel($job, (int)$this->launcher->id));

        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertSame($closedBefore, (int)AuditLog::find()->where(['action' => AuditLog::ACTION_APPROVAL_CANCELED])->count());
    }

    // -- The conditional transition -------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function cancelableStatusProvider(): array
    {
        return [
            'pending' => [Job::STATUS_PENDING],
            'queued' => [Job::STATUS_QUEUED],
            'running' => [Job::STATUS_RUNNING],
            'pending_approval' => [Job::STATUS_PENDING_APPROVAL],
        ];
    }

    /**
     * @dataProvider cancelableStatusProvider
     */
    public function testAJobInACancelableStatusIsCanceled(string $status): void
    {
        $job = $this->createJob((int)$this->template()->id, (int)$this->launcher->id, $status);
        $queuedAt = $job->queued_at;
        $before = time();

        $this->assertTrue($this->jobCancellation()->cancel($job, (int)$this->launcher->id));

        $this->assertSame(Job::STATUS_CANCELED, $job->status, 'the model is refreshed');
        $this->assertGreaterThanOrEqual($before, (int)$job->finished_at);
        $this->assertGreaterThanOrEqual($before, (int)$job->updated_at);
        $this->assertSame((int)$queuedAt, (int)$job->queued_at, 'queued_at stays');
        $this->assertTrue($job->isFinished());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function finishedStatusProvider(): array
    {
        return [
            'succeeded' => [Job::STATUS_SUCCEEDED],
            'failed' => [Job::STATUS_FAILED],
            'canceled' => [Job::STATUS_CANCELED],
            'timed_out' => [Job::STATUS_TIMED_OUT],
            'rejected' => [Job::STATUS_REJECTED],
        ];
    }

    /**
     * A finished job is not canceled, and nothing is written.
     *
     * @dataProvider finishedStatusProvider
     */
    public function testAFinishedJobIsNotCanceled(string $status): void
    {
        $job = $this->createJob((int)$this->template()->id, (int)$this->launcher->id, $status);
        $job->finished_at = 1700000000;
        $job->save(false);
        $stored = Job::findOne($job->id)?->getAttributes();

        $this->assertFalse($this->jobCancellation()->cancel($job, (int)$this->launcher->id));

        $this->assertSame($stored, Job::findOne($job->id)?->getAttributes());
        $this->assertSame($status, $job->status);
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id));
        $this->assertSame([], $this->notifications->events);
    }

    /**
     * Every status the cancel takes is one Job::isCancelable() shows the
     * Cancel button for, and the other way round.
     */
    public function testTheCancelableStatusesAreThoseOfTheModel(): void
    {
        $template = $this->template();
        foreach (Job::statuses() as $status) {
            $job = $this->createJob((int)$template->id, (int)$this->launcher->id, $status);
            $cancelable = $job->isCancelable();

            $this->assertSame($cancelable, $this->jobCancellation()->cancel($job, (int)$this->launcher->id), $status);
        }
    }

    /**
     * Regression: the cancel checked the status of the model as the caller
     * had loaded it and saved without a condition, so a job that finished
     * after it was loaded (its runner reported success) was turned into a
     * canceled one, with an audit entry and a notification.
     */
    public function testAJobThatFinishedAfterItWasLoadedKeepsItsOutcome(): void
    {
        $job = $this->createJob((int)$this->template()->id, (int)$this->launcher->id, Job::STATUS_RUNNING);
        $this->finishBehindTheModel($job, Job::STATUS_SUCCEEDED);
        $stored = Job::findOne($job->id)?->getAttributes();
        $this->assertSame(Job::STATUS_RUNNING, $job->status, 'the model is stale');

        $canceled = $this->completion()->cancel($job, (int)$this->launcher->id);

        $this->assertSame($stored, Job::findOne($job->id)?->getAttributes(), 'nothing was written');
        $this->assertFalse($canceled);
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status, 'the model is refreshed');
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id));
        $this->assertSame([], $this->notifications->events);
    }

    /**
     * The request of a job whose approval was rejected meanwhile stays
     * rejected: a cancel that changed nothing writes nothing.
     */
    public function testARequestDecidedAfterTheJobWasLoadedIsLeftAlone(): void
    {
        ['job' => $job, 'request' => $request] = $this->waitingJob();
        $this->approvalService()->recordDecision($request, (int)$this->approver->id, ApprovalDecision::DECISION_REJECTED);
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_REJECTED, $request->status);
        $decided = $request->getAttributes();
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $job->status, 'the model is stale');

        $this->assertFalse($this->jobCancellation()->cancel($job, (int)$this->launcher->id));

        $this->assertSame(Job::STATUS_REJECTED, $job->status);
        $request->refresh();
        $this->assertSame($decided, $request->getAttributes());
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', (int)$request->id));
    }

    /**
     * Regression: a stale cancel after an approval ran both workflow routes.
     * A cancel with the model of an approval step's placeholder as loaded
     * before the approval (the job page was open) rewrote the placeholder,
     * which the approval had marked succeeded, to canceled. The step was
     * then finished a second time, as failed, and the failure route ran as
     * well as the success route.
     */
    public function testAStaleCancelAfterAnApprovalRunsNoSecondRoute(): void
    {
        $flow = $this->runAtApprovalStep();
        $stale = Job::findOne($flow['placeholder']->id);
        $this->assertNotNull($stale);
        $this->approvalService()->recordDecision($flow['request'], (int)$flow['approver']->id, ApprovalDecision::DECISION_APPROVED);
        $flow['placeholder']->refresh();
        $this->assertSame(Job::STATUS_SUCCEEDED, $flow['placeholder']->status);
        $this->assertNotNull($this->executionOf($flow['run'], $flow['onSuccess']), 'the success route ran');
        $notificationsBefore = count($this->notifications->events);

        $this->completion()->cancel($stale, (int)$flow['launcher']->id);

        $this->assertNull($this->executionOf($flow['run'], $flow['onFailure']), 'the failure route must not run');
        $this->assertSame(WorkflowJobStep::STATUS_SUCCEEDED, $this->executionOf($flow['run'], $flow['approval'])?->status);
        $this->assertSame(Job::STATUS_SUCCEEDED, $stale->status);
        $flow['run']->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $flow['run']->status);
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$stale->id));
        $this->assertCount($notificationsBefore, $this->notifications->events, 'no notification');
    }

    // -- What follows the commit ------------------------------------------------------

    public function testACancelWritesOneAuditEntryWithoutContextAndOneNotification(): void
    {
        $job = $this->createJob((int)$this->template()->id, (int)$this->launcher->id, Job::STATUS_QUEUED);

        $this->assertTrue($this->jobCancellation()->cancel($job, (int)$this->launcher->id));

        $entry = $this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id);
        $this->assertSame((int)$this->launcher->id, (int)$entry->user_id);
        $this->assertNull($this->contextOf($entry), 'a user cancel keeps the entry as before');
        $payloads = $this->notifications->payloadsOf(NotificationTemplate::EVENT_JOB_CANCELED);
        $this->assertCount(1, $payloads);
        $payload = $payloads[0]['job'] ?? null;
        $this->assertIsArray($payload);
        $this->assertSame((string)$job->id, $payload['id'] ?? null);
        $this->assertSame(Job::STATUS_CANCELED, $payload['status'] ?? null);
        $this->assertCount(1, $this->notifications->events, 'nothing else');
    }

    /**
     * Deleting a job template cancels its waiting jobs with this reason:
     * the audit entries say so, the notification goes out, and the job of
     * a workflow step moves its run on as for a user's cancel. Without an
     * actor (the maintenance sweep) the entries have no user.
     */
    public function testATemplateDeletionCancelNamesItsReason(): void
    {
        ['job' => $job, 'request' => $request] = $this->waitingJob();

        $this->assertTrue($this->jobCancellation()->cancel($job, null, JobCancellationService::REASON_TEMPLATE_DELETED));

        $entry = $this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id);
        $this->assertNull($entry->user_id);
        $this->assertSame(['reason' => JobCancellationService::REASON_TEMPLATE_DELETED], $this->contextOf($entry));
        $closed = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', (int)$request->id);
        $this->assertNull($closed->user_id);
        $this->assertSame(JobCancellationService::REASON_TEMPLATE_DELETED, $this->contextOf($closed)['reason'] ?? null);
        $this->assertCount(1, $this->notifications->payloadsOf(NotificationTemplate::EVENT_JOB_CANCELED));
    }

    /**
     * A job that waits for an approval request that is gone (2.9.0 deleted
     * the requests of a deleted rule) is canceled for that reason: the entry
     * names it, there is no request to close, and the notification goes out.
     * The maintenance run's StrandedJobSweep cancels such jobs.
     */
    public function testACancelForAMissingApprovalRequestNamesItsReason(): void
    {
        $job = $this->createJob((int)$this->template()->id, (int)$this->launcher->id, Job::STATUS_PENDING_APPROVAL);

        $this->assertTrue($this->jobCancellation()->cancel($job, null, JobCancellationService::REASON_APPROVAL_MISSING));

        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertSame('approval_request_missing', JobCancellationService::REASON_APPROVAL_MISSING);
        $entry = $this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id);
        $this->assertNull($entry->user_id);
        $this->assertSame(['reason' => JobCancellationService::REASON_APPROVAL_MISSING], $this->contextOf($entry));
        $this->assertCount(1, $this->notifications->payloadsOf(NotificationTemplate::EVENT_JOB_CANCELED));
    }

    /**
     * The job of a workflow step: the step fails and the run follows the
     * step's failure route, as for a job that failed.
     */
    public function testCancelingTheJobOfAWorkflowStepMovesTheRunOn(): void
    {
        $template = $this->template();
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $first = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$template->id);
        $onFailure = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $first->on_failure_step_id = $onFailure->id;
        $first->save(false);
        $run = $this->workflowEngine()->launch($workflow, (int)$this->launcher->id);
        $execution = $this->executionOf($run, $first);
        $this->assertNotNull($execution);
        $job = Job::findOne($execution->job_id);
        $this->assertNotNull($job);

        $this->assertTrue($this->jobCancellation()->cancel($job, (int)$this->launcher->id));

        $execution->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $this->executionOf($run, $onFailure)?->status);
    }

    /**
     * The rows are locked and written in one transaction, the request
     * before the job (the lock order of votes and timeouts), and the audit
     * entries are written only after it ended, so they never hold the locks.
     */
    public function testTheRequestIsLockedBeforeTheJobAndTheEffectsFollowTheCommit(): void
    {
        ['job' => $job, 'request' => $request] = $this->waitingJob();

        $statements = $this->statementsOf(fn () => $this->jobCancellation()->cancel($job, (int)$this->launcher->id));

        $savepoint = $this->indexOf($statements, '~^SAVEPOINT LEVEL\d+$~');
        $lock = $this->indexOf($statements, '~^SELECT \* FROM `approval_request` WHERE `job_id` = ' . $job->id . ' FOR UPDATE$~');
        $jobUpdate = $this->indexOf($statements, '~^UPDATE `job` SET .* WHERE \(`id`=' . $job->id . '\) AND \(`status` IN \(.*\)\)$~s');
        $close = $this->indexOf($statements, '~^UPDATE `approval_request` SET .* WHERE \(`id`=' . $request->id . '\) AND \(`status`=\'pending\'\)$~s');
        $release = $this->indexOf($statements, '~^RELEASE SAVEPOINT LEVEL\d+$~');
        $firstAudit = $this->indexOf($statements, '~^INSERT INTO `audit_log`~');
        $this->assertTrue(
            $savepoint < $lock && $lock < $jobUpdate && $jobUpdate < $close && $close < $release && $release < $firstAudit,
            implode("\n", $statements)
        );
    }

    // -- Within a workflow run ------------------------------------------------------------

    /**
     * The job of a running step of a run being canceled: the transition and
     * the audit entries with the run's ID, but no notification and no
     * workflow advance (the caller ends the step and the run).
     */
    public function testACancelWithinAWorkflowNamesTheRunAndNeitherNotifiesNorAdvances(): void
    {
        $flow = $this->runAtApprovalStep();
        $runId = (int)$flow['run']->id;
        $notificationsBefore = count($this->notifications->events);

        $this->assertTrue($this->jobCancellation()->cancelWithinWorkflow($flow['placeholder'], (int)$flow['launcher']->id, $runId));

        $this->assertSame(Job::STATUS_CANCELED, $flow['placeholder']->status);
        $entry = $this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$flow['placeholder']->id);
        $this->assertSame((int)$flow['launcher']->id, (int)$entry->user_id);
        $this->assertSame(
            ['reason' => JobCancellationService::REASON_WORKFLOW_CANCELED, 'workflow_job_id' => $runId],
            $this->contextOf($entry)
        );
        $flow['request']->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $flow['request']->status);
        $closed = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', (int)$flow['request']->id);
        $this->assertSame(JobCancellationService::REASON_WORKFLOW_CANCELED, $this->contextOf($closed)['reason'] ?? null);
        $this->assertCount($notificationsBefore, $this->notifications->events, 'no notification');
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $this->executionOf($flow['run'], $flow['approval'])?->status, 'no advance');
        $this->assertNull($this->executionOf($flow['run'], $flow['onFailure']));
    }

    public function testACancelWithinADeletedWorkflowNamesThatReason(): void
    {
        $flow = $this->runAtApprovalStep();

        $this->assertTrue($this->jobCancellation()->cancelWithinWorkflow(
            $flow['placeholder'],
            null,
            (int)$flow['run']->id,
            JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED
        ));

        $entry = $this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$flow['placeholder']->id);
        $this->assertSame(JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED, $this->contextOf($entry)['reason'] ?? null);
        $closed = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', (int)$flow['request']->id);
        $this->assertSame(JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED, $this->contextOf($closed)['reason'] ?? null);
    }

    public function testAFinishedJobIsNotCanceledWithinAWorkflowEither(): void
    {
        $job = $this->createJob((int)$this->template()->id, (int)$this->launcher->id, Job::STATUS_SUCCEEDED);

        $this->assertFalse($this->jobCancellation()->cancelWithinWorkflow($job, (int)$this->launcher->id, 1));

        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id));
    }

    // -- Reasons and messages -------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function wrongReasonProvider(): array
    {
        $workflowReasons = JobCancellationService::REASON_WORKFLOW_CANCELED . ', ' . JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED;
        $jobReasons = JobCancellationService::REASON_OPERATOR . ', ' . JobCancellationService::REASON_TEMPLATE_DELETED
            . ', ' . JobCancellationService::REASON_APPROVAL_MISSING;

        return [
            'a workflow reason for a plain cancel' => ['cancel', JobCancellationService::REASON_WORKFLOW_CANCELED, $jobReasons],
            'an unknown reason' => ['cancel', 'because', $jobReasons],
            'a user reason within a workflow' => ['cancelWithinWorkflow', JobCancellationService::REASON_OPERATOR, $workflowReasons],
        ];
    }

    /**
     * A reason the method does not take is a programming error: refused
     * before anything is written.
     *
     * @dataProvider wrongReasonProvider
     */
    public function testAReasonTheMethodDoesNotTakeIsRefused(string $method, string $reason, string $expected): void
    {
        $job = $this->createJob((int)$this->template()->id, (int)$this->launcher->id, Job::STATUS_QUEUED);

        try {
            if ($method === 'cancel') {
                $this->jobCancellation()->cancel($job, (int)$this->launcher->id, $reason);
            } else {
                $this->jobCancellation()->cancelWithinWorkflow($job, (int)$this->launcher->id, 1, $reason);
            }
            $this->fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(sprintf(JobCancellationService::UNKNOWN_REASON, $reason, $expected), $e->getMessage());
        }

        $job->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function notCanceledMessageProvider(): array
    {
        return [
            'succeeded' => [Job::STATUS_SUCCEEDED, 'succeeded'],
            'timed out' => [Job::STATUS_TIMED_OUT, 'timed out'],
            'canceled' => [Job::STATUS_CANCELED, 'canceled'],
        ];
    }

    /**
     * @dataProvider notCanceledMessageProvider
     */
    public function testTheNotCanceledMessageNamesTheStatusInWords(string $status, string $words): void
    {
        $job = new Job(['status' => $status]);
        $job->id = 42;

        $this->assertSame("Job #42 was not canceled: it is already {$words}.", JobCancellationService::notCanceledMessage($job));
    }

    // -- Helpers ----------------------------------------------------------------------------

    private function template(): \app\models\JobTemplate
    {
        return $this->cancelFixtureTemplate((int)$this->launcher->id);
    }

    /**
     * @return array{job: Job, request: ApprovalRequest, rule: \app\models\ApprovalRule}
     */
    private function waitingJob(): array
    {
        return $this->jobWaitingForApproval($this->template(), (int)$this->launcher->id, (int)$this->approver->id);
    }

    /**
     * The job ends behind the back of $job, as when its runner reports while
     * a user's page with the Cancel button is open.
     */
    private function finishBehindTheModel(Job $job, string $status): void
    {
        Job::updateAll(
            ['status' => $status, 'exit_code' => 0, 'finished_at' => 1700000100, 'updated_at' => 1700000100],
            ['id' => $job->id]
        );
    }

    private function completion(): JobCompletionService
    {
        /** @var JobCompletionService $service */
        $service = \Yii::$app->get('jobCompletionService');

        return $service;
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
