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
use app\services\ApprovalSettlement;
use app\services\JobCancellationService;
use app\services\JobTemplateDeletionService;
use app\services\WorkflowExecutionService;
use app\services\WorkflowStepRouter;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\TeamScopeFixtures;

/**
 * A job of a deleted job template no longer waits for approval. Deleting a
 * template cancels its waiting jobs after the soft delete committed; a vote
 * or a timeout that comes before that cancel (or after it failed) used to
 * approve the job and queue it, although no runner takes a job of a deleted
 * template. Now the request is closed as canceled (reason template_deleted)
 * and the job canceled in the same transaction, the vote answers 409, and
 * what follows is what the template delete's own cancel does
 * (JobCancellationService::cancel(), REASON_TEMPLATE_DELETED): job.canceled
 * and approval.canceled with that reason, the job.canceled notification and,
 * for the job of a workflow step, the step ends as a canceled job.
 */
class ApprovalDeletedTemplateTest extends DbTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;
    use ApprovalVoteFixtures;
    use RestoresComponents;

    private RecordingNotificationDispatcher $notifications;

    /** @var list<array{call: string, job: int}> */
    public array $engineCalls = [];

    private User $launcher;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = $this->recordNotifications();
        $test = $this;
        // The real engine, which also records what it is told. Swapped before
        // any write, so that tearDown() restores it also when a write fails.
        $this->swapComponent('workflowExecutionService', new class ($test) extends WorkflowExecutionService {
            public function __construct(private readonly ApprovalDeletedTemplateTest $test)
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
        $this->launcher = $this->approverUser('deleted-launcher');
        $this->approver = $this->approverUser('deleted-approver');
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        $this->restoreNotifications();
        parent::tearDown();
    }

    /**
     * Regression: an approval of a waiting job of a deleted job template
     * queued it. The template was soft-deleted, but its waiting job was not
     * canceled yet (the delete cancels after its commit, and that cancel can
     * fail): the approval recorded the vote and queued a job that no runner
     * would ever take, and the documented refusal did not happen.
     */
    public function testRegressionAnApprovalOfAWaitingJobOfADeletedJobTemplateQueuedIt(): void
    {
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$this->approver], 1, $this->launcher);
        $this->deleteTemplateOf($job);
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

        // The template delete's own cancel, or the maintenance sweep, finds nothing left to do.
        /** @var JobTemplateDeletionService $deletion */
        $deletion = \Yii::$app->get('jobTemplateDeletionService');
        $sweep = $deletion->sweep();
        $this->assertSame(0, $sweep['canceled_jobs']);
        $this->assertSame(0, $sweep['remaining_jobs']);
        $this->assertCount(1, $this->auditsOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id), 'canceled once');
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
     * The request page offers no decision (ApprovalSettlement::check()), and
     * an approval and a rejection alike close the request and cancel the job.
     *
     * @dataProvider decisionProvider
     */
    public function testAVoteOnAWaitingJobOfADeletedTemplateClosesTheRequestAndCancelsTheJob(string $decision): void
    {
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$this->approver], 1, $this->launcher);
        $this->deleteTemplateOf($job);
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
     * The timeout of such a request closes it the same way, with no user:
     * its approve action never queues the job, and no approval.timed_out is
     * written for a request that did not time out.
     */
    public function testATimeoutOfAWaitingJobOfADeletedTemplateClosesTheRequestAndCancelsTheJob(): void
    {
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$this->approver], 1, $this->launcher);
        $this->expireRequest($request, 60, ApprovalRule::TIMEOUT_ACTION_APPROVE);
        $this->deleteTemplateOf($job);
        $before = time();

        $this->assertSame(1, $this->approvals()->processTimeouts());

        $this->assertClosedAndCanceled($request, $job, null, $before);
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_APPROVAL_TIMED_OUT, 'approval_request', (int)$request->id));
        $this->assertSame(0, $this->approvals()->processTimeouts(), 'closed for good');
    }

    /**
     * The gated job step of a running workflow whose job template was
     * deleted: its job is canceled, and its step ends as the template
     * delete's cancel ends it, a gate that never opened. Without an On
     * Failure branch the run ends as failed; the next step never starts.
     */
    public function testTheJobOfAGatedWorkflowStepOfADeletedTemplateEndsItsStepAsAClosedGate(): void
    {
        $flow = $this->workflowStepWaiting(WorkflowStep::TYPE_JOB, $this->rule(), $this->launcher);
        $this->deleteTemplateOf($flow['job']);
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
     * A job whose workflow step has ended goes by its run first, whatever
     * became of its template: it is canceled within its run (reason
     * workflow_step_ended), without a notification or a workflow advance.
     */
    public function testAJobOfAnEndedStepOfADeletedTemplateIsCanceledWithinItsRun(): void
    {
        $flow = $this->workflowStepWaiting(WorkflowStep::TYPE_JOB, $this->rule(), $this->launcher);
        WorkflowJob::updateAll(['status' => WorkflowJob::STATUS_CANCELED], ['id' => $flow['run']->id]);
        WorkflowJobStep::updateAll(['status' => WorkflowJobStep::STATUS_FAILED], ['id' => $flow['execution']->id]);
        $this->deleteTemplateOf($flow['job']);

        $this->assertVoteRefused(
            $flow['request'],
            $this->approver,
            ApprovalDecision::DECISION_APPROVED,
            ApprovalDecisionRefusedException::REASON_JOB_NOT_WAITING
        );

        $this->assertSame(Job::STATUS_CANCELED, $this->reloadJob($flow['job'])->status);
        $closed = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', (int)$flow['request']->id);
        $this->assertSame(ApprovalSettlement::REASON_WORKFLOW_STEP_ENDED, $this->contextOf($closed)['reason'] ?? null);
        $canceled = $this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$flow['job']->id);
        $this->assertSame(
            ['reason' => JobCancellationService::REASON_WORKFLOW_CANCELED, 'workflow_job_id' => (int)$flow['run']->id],
            $this->contextOf($canceled)
        );
        $this->assertSame([], $this->notifications->payloadsOf(NotificationTemplate::EVENT_JOB_CANCELED));
        $this->assertSame([], $this->engineCalls);
    }

    /**
     * An approval step's placeholder has no job template: deleting the job
     * template of another step of its workflow changes nothing for it.
     */
    public function testAPlaceholderHasNoTemplateAndStillWaits(): void
    {
        $flow = $this->workflowStepWaiting(WorkflowStep::TYPE_APPROVAL, $this->rule(), $this->launcher);
        $this->assertNull($flow['job']->job_template_id);

        $this->assertTrue($this->approvals()->canUserApprove($flow['request'], (int)$this->approver->id));
    }

    /**
     * The template's deleted state is read after both row locks, before
     * anything is written: the read sees a delete that committed before the
     * locks were granted (see ApprovalSettlement).
     */
    public function testTheTemplateIsReadAfterBothLocksAndBeforeAnyWrite(): void
    {
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$this->approver], 1, $this->launcher);
        $this->deleteTemplateOf($job);

        $statements = $this->statementsOf(
            fn () => $this->voteAs($request, $this->approver, ApprovalDecision::DECISION_APPROVED)
        );

        $savepoint = $this->statementIndex($statements, '~^SAVEPOINT LEVEL\d+$~');
        $requestLock = $this->statementIndex($statements, '~^SELECT \* FROM `approval_request` WHERE `id` = ' . $request->id . ' FOR UPDATE$~');
        $jobLock = $this->statementIndex($statements, '~^SELECT \* FROM `job` WHERE `id` = ' . $job->id . ' FOR UPDATE$~');
        $templateRead = $this->statementIndex($statements, '~^SELECT `deleted_at` FROM `job_template` WHERE `id`=' . $job->job_template_id . '$~');
        $requestUpdate = $this->statementIndex($statements, '~^UPDATE `approval_request` SET .* WHERE \(`id`=' . $request->id . '\) AND \(`status`=\'pending\'\)$~s');
        $jobUpdate = $this->statementIndex($statements, '~^UPDATE `job` SET .* WHERE \(`id`=' . $job->id . '\) AND \(`status`=\'pending_approval\'\)$~s');
        $release = $this->statementIndex($statements, '~^RELEASE SAVEPOINT LEVEL\d+$~');

        $trace = implode("\n", $statements);
        $this->assertSame($savepoint + 1, $requestLock, $trace);
        $this->assertSame($savepoint + 2, $jobLock, $trace);
        $this->assertLessThan($templateRead, $jobLock, 'read after both locks');
        $this->assertLessThan($requestUpdate, $templateRead, 'read before the writes');
        $this->assertLessThan($jobUpdate, $requestUpdate);
        $this->assertLessThan($release, $jobUpdate, 'both writes in the transaction');
    }

    // -- Helpers ----------------------------------------------------------------------

    private function rule(): ApprovalRule
    {
        return $this->listRule((int)$this->launcher->id, [$this->approver]);
    }

    /**
     * Soft-delete $job's template as a delete whose cancel did not run (yet).
     */
    private function deleteTemplateOf(Job $job): void
    {
        $template = JobTemplate::findOne($job->job_template_id);
        $this->assertNotNull($template);
        $this->assertTrue($template->softDelete());
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
}
