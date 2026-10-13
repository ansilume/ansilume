<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalDecisionRefusedException;
use app\services\ApprovalSettlement;
use app\services\AuditService;
use app\services\JobCancellationService;
use app\services\WorkflowExecutionService;
use app\services\WorkflowTemplateDeletionService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The job of a workflow step whose step or run has ended no longer waits
 * for approval. A run cancel that failed part-way (its claim committed, then
 * failing the step or canceling the step's job threw) leaves the step's job
 * pending_approval and its request pending. Neither a vote nor a timeout may
 * then queue the job of the canceled run: the request is closed as canceled
 * (reason workflow_step_ended), the job is canceled in the same transaction,
 * approval.canceled and job.canceled are audited, and a vote answers 409.
 */
class ApprovalEndedWorkflowStepTest extends DbTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;
    use ApprovalVoteFixtures;

    private RecordingNotificationDispatcher $notifications;

    /** @var list<array{job: int, approved: bool}> */
    public array $engineCalls = [];

    private mixed $engineBefore = null;

    private User $launcher;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = $this->recordNotifications();
        $this->launcher = $this->approverUser('ended-launcher');
        $this->approver = $this->approverUser('ended-approver');
        $this->engineBefore = \Yii::$app->get('workflowExecutionService');
        $test = $this;
        \Yii::$app->set('workflowExecutionService', new class ($test) extends WorkflowExecutionService {
            public function __construct(private readonly ApprovalEndedWorkflowStepTest $test)
            {
                parent::__construct();
            }

            public function onApprovalResolved(Job $job, bool $approved): void
            {
                $this->test->engineCalls[] = ['job' => (int)$job->id, 'approved' => $approved];
            }
        });
    }

    protected function tearDown(): void
    {
        \Yii::$app->set('workflowExecutionService', $this->engineBefore);
        $this->restoreNotifications();
        parent::tearDown();
    }

    /**
     * Regression: approving the gated job of a canceled run queued it. The
     * run cancel claimed the run and failed the step, then canceling the
     * step's job threw (a lock wait timeout, say): the job stayed
     * pending_approval and its request pending, nothing retried, and an
     * approver's approval queued the playbook of the canceled run.
     */
    public function testRegressionApprovingTheGatedJobOfACanceledRunQueuedIt(): void
    {
        $flow = $this->stranded(WorkflowStep::TYPE_JOB, WorkflowJobStep::STATUS_FAILED, WorkflowJob::STATUS_CANCELED);
        $request = $flow['request'];
        $before = time();

        $refusal = $this->assertVoteRefused(
            $request,
            $this->approver,
            ApprovalDecision::DECISION_APPROVED,
            ApprovalDecisionRefusedException::REASON_JOB_NOT_WAITING,
            sprintf(ApprovalDecisionRefusedException::JOB_NOT_WAITING, (int)$request->id, (int)$flow['job']->id)
        );

        $this->assertSame(409, $refusal->httpStatus());
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $refusal->requestStatus());
        $this->assertClosedAndCanceled($flow, (int)$this->approver->id, $before);
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_APPROVAL_DECIDED, 'approval_request', (int)$request->id));
        $this->assertSame([], $this->notifications->payloadsOf(NotificationTemplate::EVENT_APPROVAL_APPROVED));
        $this->assertSame([], $this->notifications->payloadsOf(NotificationTemplate::EVENT_APPROVAL_REJECTED));
    }

    /**
     * Every way the step or its run can have ended, for both kinds of
     * workflow job, and for an approval and a rejection alike.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function endedProvider(): array
    {
        return [
            'gated job step failed, run canceled, rejected' => [
                WorkflowStep::TYPE_JOB, WorkflowJobStep::STATUS_FAILED, WorkflowJob::STATUS_CANCELED, ApprovalDecision::DECISION_REJECTED,
            ],
            'gated job step still running, run canceled (failing the step threw)' => [
                WorkflowStep::TYPE_JOB, WorkflowJobStep::STATUS_RUNNING, WorkflowJob::STATUS_CANCELED, ApprovalDecision::DECISION_APPROVED,
            ],
            'gated job step failed, run still running' => [
                WorkflowStep::TYPE_JOB, WorkflowJobStep::STATUS_FAILED, WorkflowJob::STATUS_RUNNING, ApprovalDecision::DECISION_APPROVED,
            ],
            'gated job step failed, run failed' => [
                WorkflowStep::TYPE_JOB, WorkflowJobStep::STATUS_FAILED, WorkflowJob::STATUS_FAILED, ApprovalDecision::DECISION_APPROVED,
            ],
            'approval step failed, run canceled' => [
                WorkflowStep::TYPE_APPROVAL, WorkflowJobStep::STATUS_FAILED, WorkflowJob::STATUS_CANCELED, ApprovalDecision::DECISION_APPROVED,
            ],
            'approval step still running, run canceled' => [
                WorkflowStep::TYPE_APPROVAL, WorkflowJobStep::STATUS_RUNNING, WorkflowJob::STATUS_CANCELED, ApprovalDecision::DECISION_REJECTED,
            ],
        ];
    }

    /**
     * @dataProvider endedProvider
     */
    public function testAVoteOnTheJobOfAnEndedStepOrRunClosesTheRequestAndCancelsTheJob(
        string $stepType,
        string $stepStatus,
        string $runStatus,
        string $decision
    ): void {
        $flow = $this->stranded($stepType, $stepStatus, $runStatus);
        $before = time();

        $this->assertFalse($this->approvals()->canUserApprove($flow['request'], (int)$this->approver->id), 'no buttons');
        $this->assertSame(
            ApprovalDecisionRefusedException::REASON_JOB_NOT_WAITING,
            $this->approvals()->decisionRefusal($flow['request'], (int)$this->approver->id)?->reason()
        );
        $this->assertVoteRefused($flow['request'], $this->approver, $decision, ApprovalDecisionRefusedException::REASON_JOB_NOT_WAITING);

        $this->assertClosedAndCanceled($flow, (int)$this->approver->id, $before);
    }

    /**
     * The timeout of such a request closes it the same way (no user): its
     * approve action never queues the job, and no approval.timed_out is
     * written for a request that did not time out.
     */
    public function testATimeoutOfTheJobOfAnEndedRunClosesTheRequestAndCancelsTheJob(): void
    {
        $flow = $this->stranded(WorkflowStep::TYPE_JOB, WorkflowJobStep::STATUS_FAILED, WorkflowJob::STATUS_CANCELED);
        $this->expireRequest($flow['request'], 60, ApprovalRule::TIMEOUT_ACTION_APPROVE);
        $before = time();

        $this->assertSame(1, $this->approvals()->processTimeouts());

        $this->assertClosedAndCanceled($flow, null, $before);
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_APPROVAL_TIMED_OUT, 'approval_request', (int)$flow['request']->id));
        $this->assertSame(0, $this->approvals()->processTimeouts(), 'closed for good');
    }

    /**
     * Regression: a vote on the job of a run that deleting its workflow
     * template had canceled audited the job's cancel with the reason
     * workflow_canceled. The template delete's cancel of the run failed at
     * the step's job (a lost connection), so the job stayed pending_approval
     * with its request pending; the stranded-job sweep, and a step that
     * starts in the canceled run, cancel such a job for
     * workflow_template_deleted, so the reason depended on who came first.
     * Now the vote takes the reason the run was canceled for, as they do
     * (WorkflowCancellationService::cancelOf() and jobReason()).
     */
    public function testRegressionTheJobOfARunCanceledByItsTemplatesDeleteWasCanceledForAnotherReason(): void
    {
        $flow = $this->workflowStepWaiting(WorkflowStep::TYPE_JOB, $this->rule(), $this->launcher);
        $admin = $this->createUserWithRole('ended-admin', 'admin');
        $workflow = WorkflowTemplate::findOne($flow['run']->workflow_template_id);
        $this->assertNotNull($workflow);
        $lostConnection = new \yii\db\Exception('SQLSTATE[HY000]: General error: 2006', ['HY000', 2006, 'MySQL server has gone away']);
        $before = \Yii::$app->get('jobCancellationService');
        \Yii::$app->set('jobCancellationService', new FlakyJobCancellation($lostConnection));
        try {
            /** @var WorkflowTemplateDeletionService $deletion */
            $deletion = \Yii::$app->get('workflowTemplateDeletionService');
            $deleted = $deletion->delete($workflow, (int)$admin->id, 'web');
        } finally {
            \Yii::$app->set('jobCancellationService', $before);
        }
        $this->assertSame(['deleted' => true, 'canceled_runs' => 1, 'failed_runs' => 0], $deleted);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $this->reloadJob($flow['job'])->status, 'the run cancel left its job waiting');
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $this->reloadRequest($flow['request'])->status);
        $voted = time();

        $this->assertVoteRefused(
            $flow['request'],
            $this->approver,
            ApprovalDecision::DECISION_APPROVED,
            ApprovalDecisionRefusedException::REASON_JOB_NOT_WAITING
        );

        $canceled = $this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$flow['job']->id);
        $this->assertSame(
            ['reason' => JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED, 'workflow_job_id' => (int)$flow['run']->id],
            $this->contextOf($canceled)
        );
        $this->assertClosedAndCanceled($flow, (int)$this->approver->id, $voted, JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED);
    }

    /**
     * Why the run of the job was canceled, as cancelOf() reads it: the
     * newest workflow.canceled audit entry of the run, else the state of its
     * workflow template.
     *
     * @return array<string, array{0: string|null, 1: bool, 2: string}>
     */
    public static function runCancelProvider(): array
    {
        return [
            'a user canceled the run' => [
                JobCancellationService::REASON_OPERATOR, false, JobCancellationService::REASON_WORKFLOW_CANCELED,
            ],
            'deleting its workflow template canceled the run' => [
                JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED, true, JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED,
            ],
            'a user canceled the run, its workflow template was deleted later' => [
                JobCancellationService::REASON_OPERATOR, true, JobCancellationService::REASON_WORKFLOW_CANCELED,
            ],
            'no cancel entry, its workflow template is deleted' => [
                null, true, JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED,
            ],
            'no cancel entry, its workflow template is live' => [
                null, false, JobCancellationService::REASON_WORKFLOW_CANCELED,
            ],
        ];
    }

    /**
     * A vote and a timeout cancel the job of an ended run for the reason
     * the run was canceled for.
     *
     * @dataProvider runCancelProvider
     */
    public function testTheJobOfAnEndedRunIsCanceledForTheReasonTheRunWasCanceledFor(
        ?string $runCancelReason,
        bool $workflowDeleted,
        string $jobReason
    ): void {
        $voted = $this->stranded(WorkflowStep::TYPE_JOB, WorkflowJobStep::STATUS_FAILED, WorkflowJob::STATUS_CANCELED);
        $timedOut = $this->stranded(WorkflowStep::TYPE_JOB, WorkflowJobStep::STATUS_RUNNING, WorkflowJob::STATUS_CANCELED);
        foreach ([$voted, $timedOut] as $flow) {
            $this->endedRunCanceledFor($flow['run'], $runCancelReason, $workflowDeleted);
        }
        $this->expireRequest($timedOut['request'], 60, ApprovalRule::TIMEOUT_ACTION_APPROVE);
        $before = time();

        $this->assertVoteRefused($voted['request'], $this->approver, ApprovalDecision::DECISION_APPROVED, ApprovalDecisionRefusedException::REASON_JOB_NOT_WAITING);
        $this->assertSame(1, $this->approvals()->processTimeouts());

        $this->assertClosedAndCanceled($voted, (int)$this->approver->id, $before, $jobReason);
        $this->assertClosedAndCanceled($timedOut, null, $before, $jobReason);
    }

    /**
     * A job of a running step in an active run still waits: the settlement
     * decides it as before, and the engine hears of a closed gate.
     */
    public function testTheJobOfARunningStepInAnActiveRunStillWaits(): void
    {
        $flow = $this->workflowStepWaiting(WorkflowStep::TYPE_JOB, $this->rule(), $this->launcher);

        $this->assertTrue($this->approvals()->canUserApprove($flow['request'], (int)$this->approver->id));
        $this->approvals()->recordDecision($flow['request'], (int)$this->approver->id, ApprovalDecision::DECISION_REJECTED);

        $this->assertSame(ApprovalRequest::STATUS_REJECTED, $this->reloadRequest($flow['request'])->status);
        $this->assertSame(Job::STATUS_REJECTED, $this->reloadJob($flow['job'])->status);
        $this->assertSame([['job' => (int)$flow['job']->id, 'approved' => false]], $this->engineCalls);
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$flow['job']->id));
    }

    /**
     * A request whose job already left pending_approval is closed as
     * before, with reason job_not_waiting and no job.canceled: the job keeps
     * its status, also in a canceled run.
     */
    public function testAJobThatNoLongerWaitsKeepsItsStatusAndTheReasonStaysJobNotWaiting(): void
    {
        $flow = $this->stranded(WorkflowStep::TYPE_JOB, WorkflowJobStep::STATUS_FAILED, WorkflowJob::STATUS_CANCELED);
        Job::updateAll(['status' => Job::STATUS_FAILED, 'finished_at' => time()], ['id' => $flow['job']->id]);

        $this->assertVoteRefused($flow['request'], $this->approver, ApprovalDecision::DECISION_APPROVED, ApprovalDecisionRefusedException::REASON_JOB_NOT_WAITING);

        $this->assertSame(Job::STATUS_FAILED, $this->reloadJob($flow['job'])->status);
        $entry = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', (int)$flow['request']->id);
        $this->assertSame(ApprovalSettlement::REASON_JOB_NOT_WAITING, $this->contextOf($entry)['reason'] ?? null);
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$flow['job']->id));
    }

    // -- Helpers ----------------------------------------------------------------------

    private function rule(): ApprovalRule
    {
        return $this->listRule((int)$this->launcher->id, [$this->approver]);
    }

    /**
     * A workflow step's job waiting for approval, its step and run then
     * left as a run cancel that failed part-way leaves them.
     *
     * @return array{run: WorkflowJob, step: WorkflowStep, execution: WorkflowJobStep, job: Job, request: ApprovalRequest, next: WorkflowStep}
     */
    private function stranded(string $stepType, string $stepStatus, string $runStatus): array
    {
        $flow = $this->workflowStepWaiting($stepType, $this->rule(), $this->launcher);
        WorkflowJob::updateAll(['status' => $runStatus], ['id' => $flow['run']->id]);
        WorkflowJobStep::updateAll(['status' => $stepStatus], ['id' => $flow['execution']->id]);

        return $flow;
    }

    /**
     * Let $run, which has ended, look canceled for $reason as
     * WorkflowCancellationService::cancelRun() records it (null: no
     * workflow.canceled entry, as for a run that ended otherwise), and
     * soft-delete its workflow template when $workflowDeleted.
     */
    private function endedRunCanceledFor(WorkflowJob $run, ?string $reason, bool $workflowDeleted): void
    {
        if ($reason !== null) {
            /** @var AuditService $audit */
            $audit = \Yii::$app->get('auditService');
            $audit->log(AuditLog::ACTION_WORKFLOW_CANCELED, 'workflow_job', (int)$run->id, (int)$this->launcher->id, ['reason' => $reason]);
        }
        if ($workflowDeleted) {
            $workflow = WorkflowTemplate::findOne($run->workflow_template_id);
            $this->assertNotNull($workflow);
            $this->assertTrue($workflow->softDelete());
        }
    }

    /**
     * The request is canceled with the reason workflow_step_ended, the job
     * is canceled with finished_at, both audited by $actorId (the job for
     * $jobReason, the reason its run was canceled for), and the engine heard
     * nothing.
     *
     * @param array{run: WorkflowJob, job: Job, request: ApprovalRequest} $flow
     */
    private function assertClosedAndCanceled(
        array $flow,
        ?int $actorId,
        int $before,
        string $jobReason = JobCancellationService::REASON_WORKFLOW_CANCELED
    ): void {
        $request = $this->reloadRequest($flow['request']);
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status);
        $this->assertGreaterThanOrEqual($before, (int)$request->resolved_at);
        $job = $this->reloadJob($flow['job']);
        $this->assertSame(Job::STATUS_CANCELED, $job->status, 'never queued');
        $this->assertNull($job->queued_at);
        $this->assertGreaterThanOrEqual($before, (int)$job->finished_at);
        $this->assertSame(0, $this->decisionCount($request), 'no vote is recorded');

        $closed = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', (int)$request->id);
        $this->assertSame($actorId, $closed->user_id === null ? null : (int)$closed->user_id);
        $this->assertSame(
            ['job_id' => (int)$job->id, 'rule_id' => (int)$request->approval_rule_id, 'reason' => ApprovalSettlement::REASON_WORKFLOW_STEP_ENDED],
            $this->contextOf($closed)
        );
        $canceled = $this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id);
        $this->assertSame($actorId, $canceled->user_id === null ? null : (int)$canceled->user_id);
        $this->assertSame(
            ['reason' => $jobReason, 'workflow_job_id' => (int)$flow['run']->id],
            $this->contextOf($canceled)
        );
        $this->assertSame([], $this->engineCalls, 'an ended step has nothing to advance');
        $this->assertSame([], $this->notifications->payloadsOf(NotificationTemplate::EVENT_JOB_CANCELED), 'the run\'s cancel covers it');
    }
}
