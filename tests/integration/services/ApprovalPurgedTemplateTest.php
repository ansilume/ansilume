<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\ApprovalDecisionRefusedException;
use app\services\JobCancellationService;
use app\services\StrandedJobSweep;
use app\services\WorkflowExecutionService;
use app\services\WorkflowStepRouter;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * A waiting job whose job template was purged no longer waits for approval.
 * Deleting a project purges the rows of its deleted job templates, and the
 * foreign key sets job.job_template_id to NULL. A job left so (purged by an
 * older version, or its cancel failed) counted as waiting: a vote approved
 * it and queued it, although no runner claims a job without a template, and
 * no sweep ever found it. It now counts as a job of a deleted template
 * (ApprovalJobState): the vote answers 409, and the request is closed and
 * the job canceled as for a deleted template (reason template_deleted).
 * The placeholder of an approval step has no template by design and still
 * waits.
 */
class ApprovalPurgedTemplateTest extends DbTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;
    use ApprovalVoteFixtures;

    private RecordingNotificationDispatcher $notifications;

    /** @var list<array{call: string, job: int}> */
    public array $engineCalls = [];

    private mixed $engineBefore = null;

    private User $launcher;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = $this->recordNotifications();
        $this->launcher = $this->approverUser('purged-launcher');
        $this->approver = $this->approverUser('purged-approver');
        $this->engineBefore = \Yii::$app->get('workflowExecutionService');
        $test = $this;
        // The real engine, which also records what it is told.
        \Yii::$app->set('workflowExecutionService', new class ($test) extends WorkflowExecutionService {
            public function __construct(private readonly ApprovalPurgedTemplateTest $test)
            {
                parent::__construct();
            }

            public function onApprovalResolved(Job $job, bool $approved): void
            {
                $this->test->engineCalls[] = ['call' => 'onApprovalResolved', 'job' => (int)$job->id];
                parent::onApprovalResolved($job, $approved);
            }

            public function onChildJobCompleted(Job $job): void
            {
                $this->test->engineCalls[] = ['call' => 'onChildJobCompleted', 'job' => (int)$job->id];
                parent::onChildJobCompleted($job);
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
     * Regression: an approval of a waiting job whose job template was purged
     * queued it, and it stayed queued for good: no runner claims a job
     * without a template, and no sweep found it. Now the vote is refused
     * with 409, the request is closed and the job canceled, never queued.
     */
    public function testRegressionAnApprovalOfAWaitingJobWhosePurgedTemplateQueuedIt(): void
    {
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$this->approver], 1, $this->launcher);
        $this->purgeTemplateOf($job);
        $before = time();

        $refusal = $this->assertVoteRefused(
            $request,
            $this->approver,
            ApprovalDecision::DECISION_APPROVED,
            ApprovalDecisionRefusedException::REASON_JOB_NOT_WAITING,
            sprintf(ApprovalDecisionRefusedException::JOB_NOT_WAITING, (int)$request->id, (int)$job->id)
        );

        $this->assertSame(409, $refusal->httpStatus());
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $refusal->requestStatus());
        $this->assertClosedAndCanceled($request, $job, (int)$this->approver->id, $before);
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_APPROVAL_DECIDED, 'approval_request', (int)$request->id));
        $this->assertSame([], $this->notifications->payloadsOf(NotificationTemplate::EVENT_APPROVAL_APPROVED));
        $this->assertSame([], $this->engineCalls, 'a job outside a workflow advances nothing');
        $this->assertSame(0, $this->strandedJobs()->sweep()['stranded_jobs_remaining'], 'nothing is left for the sweep');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function decisionProvider(): array
    {
        return [
            'an approval' => [ApprovalDecision::DECISION_APPROVED],
            'a rejection' => [ApprovalDecision::DECISION_REJECTED],
        ];
    }

    /**
     * The request page offers no decision, and an approval and a rejection
     * alike close the request and cancel the job.
     *
     * @dataProvider decisionProvider
     */
    public function testAVoteOnAWaitingJobWhoseTemplateWasPurgedClosesTheRequestAndCancelsTheJob(string $decision): void
    {
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$this->approver], 1, $this->launcher);
        $this->purgeTemplateOf($job);
        $before = time();

        $this->assertFalse($this->approvals()->canUserApprove($request, (int)$this->approver->id), 'no buttons');
        $this->assertSame(
            ApprovalDecisionRefusedException::REASON_JOB_NOT_WAITING,
            $this->approvals()->decisionRefusal($request, (int)$this->approver->id)?->reason()
        );
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $this->reloadRequest($request)->status, 'the page changes nothing');
        $this->assertVoteRefused($request, $this->approver, $decision, ApprovalDecisionRefusedException::REASON_JOB_NOT_WAITING);

        $this->assertClosedAndCanceled($request, $job, (int)$this->approver->id, $before);
        $this->assertSame([], $this->notifications->payloadsOf(NotificationTemplate::EVENT_APPROVAL_REJECTED));
    }

    /**
     * Regression: the approve action of such a request's timeout queued the
     * job. The timeout closes it the same way, with no user, and writes no
     * approval.timed_out.
     */
    public function testRegressionATimeoutOfAWaitingJobWhosePurgedTemplateQueuedIt(): void
    {
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$this->approver], 1, $this->launcher);
        $this->expireRequest($request, 60, ApprovalRule::TIMEOUT_ACTION_APPROVE);
        $this->purgeTemplateOf($job);
        $before = time();

        $this->assertSame(1, $this->approvals()->processTimeouts());

        $this->assertClosedAndCanceled($request, $job, null, $before);
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_APPROVAL_TIMED_OUT, 'approval_request', (int)$request->id));
        $this->assertSame(0, $this->approvals()->processTimeouts(), 'closed for good');
    }

    /**
     * A gated job step of a running workflow whose job template was purged:
     * its job is canceled, and its step ends as a gate that never opened.
     * Without an On Failure branch the run ends as failed; the next step
     * never starts.
     */
    public function testTheJobOfAGatedWorkflowStepWhoseTemplateWasPurgedEndsItsStepAsAClosedGate(): void
    {
        $flow = $this->workflowStepWaiting(WorkflowStep::TYPE_JOB, $this->rule(), $this->launcher);
        $this->purgeTemplateOf($flow['job']);
        $before = time();

        $this->assertVoteRefused(
            $flow['request'],
            $this->approver,
            ApprovalDecision::DECISION_APPROVED,
            ApprovalDecisionRefusedException::REASON_JOB_NOT_WAITING
        );

        $this->assertClosedAndCanceled($flow['request'], $flow['job'], (int)$this->approver->id, $before);
        $this->assertSame([['call' => 'onChildJobCompleted', 'job' => (int)$flow['job']->id]], $this->engineCalls);
        $execution = WorkflowJobStep::findOne($flow['execution']->id);
        $this->assertNotNull($execution);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(sprintf(WorkflowStepRouter::JOB_APPROVAL_CANCELED, (int)$flow['request']->id), $execution->error_message);
        $run = WorkflowJob::findOne($flow['run']->id);
        $this->assertNotNull($run);
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status, 'a closed gate without On Failure ends the run');
        $this->assertFalse(
            WorkflowJobStep::find()->where(['workflow_job_id' => $flow['run']->id, 'workflow_step_id' => $flow['next']->id])->exists(),
            'the step behind the gate never ran'
        );
    }

    /**
     * The placeholder of an approval step has no job template by design: it
     * still waits, and an approval is recorded and opens its gate.
     */
    public function testAnApprovalStepsPlaceholderStillWaitsAndIsApproved(): void
    {
        $flow = $this->workflowStepWaiting(WorkflowStep::TYPE_APPROVAL, $this->rule(), $this->launcher);
        $this->assertNull($flow['job']->job_template_id);

        $this->assertTrue($this->approvals()->canUserApprove($flow['request'], (int)$this->approver->id));
        $this->assertNull($this->voteAs($flow['request'], $this->approver, ApprovalDecision::DECISION_APPROVED));

        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $this->reloadRequest($flow['request'])->status);
        $this->assertSame(Job::STATUS_SUCCEEDED, $this->reloadJob($flow['job'])->status);
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$flow['job']->id));
    }

    /**
     * The placeholder of an approval step that was removed from its workflow
     * while the run waited is told by its execution's snapshot: it still
     * waits.
     */
    public function testThePlaceholderOfARemovedApprovalStepStillWaits(): void
    {
        $flow = $this->workflowStepWaiting(WorkflowStep::TYPE_APPROVAL, $this->rule(), $this->launcher);
        $this->assertSame(1, WorkflowStep::deleteAll(['id' => $flow['step']->id]));
        $execution = WorkflowJobStep::findOne($flow['execution']->id);
        $this->assertNotNull($execution);
        $this->assertNull($execution->workflow_step_id);

        $this->assertTrue($this->approvals()->canUserApprove($flow['request'], (int)$this->approver->id));
    }

    // -- Helpers ----------------------------------------------------------------------

    private function rule(): ApprovalRule
    {
        return $this->listRule((int)$this->launcher->id, [$this->approver]);
    }

    /**
     * Purge $job's template as a project delete purged it before its waiting
     * jobs were canceled first: soft-deleted, then its row deleted, which
     * sets the job's job_template_id to NULL (foreign key).
     */
    private function purgeTemplateOf(Job $job): void
    {
        $template = JobTemplate::findOne($job->job_template_id);
        $this->assertNotNull($template);
        $this->assertTrue($template->softDelete());
        $this->assertSame(1, JobTemplate::deleteAll(['id' => $template->id]));
        $job->refresh();
        $this->assertNull($job->job_template_id);
    }

    /**
     * The request is canceled with the reason template_deleted, the job is
     * canceled with finished_at and never queued, both audited by $actorId
     * as the template delete's cancel audits them, and the job.canceled
     * notification went out once.
     */
    private function assertClosedAndCanceled(ApprovalRequest $request, Job $job, ?int $actorId, int $before): void
    {
        $request = $this->reloadRequest($request);
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status);
        $this->assertGreaterThanOrEqual($before, (int)$request->resolved_at);
        $job = $this->reloadJob($job);
        $this->assertSame(Job::STATUS_CANCELED, $job->status, 'never queued');
        $this->assertNull($job->queued_at);
        $this->assertGreaterThanOrEqual($before, (int)$job->finished_at);
        $this->assertSame(0, $this->decisionCount($request), 'no vote is recorded');

        $closed = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', (int)$request->id);
        $this->assertSame($actorId, $closed->user_id === null ? null : (int)$closed->user_id);
        $this->assertSame(
            ['job_id' => (int)$job->id, 'rule_id' => (int)$request->approval_rule_id, 'reason' => JobCancellationService::REASON_TEMPLATE_DELETED],
            $this->contextOf($closed)
        );
        $canceled = $this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id);
        $this->assertSame($actorId, $canceled->user_id === null ? null : (int)$canceled->user_id);
        $this->assertSame(['reason' => JobCancellationService::REASON_TEMPLATE_DELETED], $this->contextOf($canceled));

        $notified = $this->notifications->payloadsOf(NotificationTemplate::EVENT_JOB_CANCELED);
        $this->assertCount(1, $notified);
        $payload = $notified[0]['job'] ?? null;
        $this->assertIsArray($payload);
        $this->assertSame((string)$job->id, $payload['id'] ?? null);
        $this->assertSame(Job::STATUS_CANCELED, $payload['status'] ?? null);
    }

    private function strandedJobs(): StrandedJobSweep
    {
        /** @var StrandedJobSweep $sweep */
        $sweep = \Yii::$app->get('strandedJobSweep');

        return $sweep;
    }
}
