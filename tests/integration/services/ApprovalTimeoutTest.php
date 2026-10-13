<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\NotificationTemplate;
use app\services\ApprovalDecisionRefusedException;
use app\services\ApprovalOutcome;
use app\services\ApprovalService;
use app\services\ApprovalSettlement;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\log\Logger;

/**
 * Approval timeouts (ApprovalService::processTimeouts(), settled one by one
 * by ApprovalSettlement::timeOut() under the request's lock): the oldest
 * expired requests first, a batch limit, a grace period for timeouts that
 * approve, and a failing request that does not stop the others.
 */
class ApprovalTimeoutTest extends DbTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;
    use ApprovalVoteFixtures;

    private RecordingNotificationDispatcher $notifications;

    /** @var array{0: string, 1: mixed}|null the approvalSettlement component a test swapped */
    private ?array $swapped = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = $this->recordNotifications();
    }

    protected function tearDown(): void
    {
        if ($this->swapped !== null) {
            \Yii::$app->set($this->swapped[0], $this->swapped[1]);
            $this->swapped = null;
        }
        $this->restoreNotifications();
        parent::tearDown();
    }

    public function testARejectTimeoutRejectsTheJob(): void
    {
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$this->approverUser()]);
        $this->expireRequest($request, 60, ApprovalRule::TIMEOUT_ACTION_REJECT);
        $this->notifications->events = [];
        $before = time();

        $this->assertSame(1, $this->approvals()->processTimeouts());

        $request = $this->reloadRequest($request);
        $this->assertSame(ApprovalRequest::STATUS_TIMED_OUT, $request->status);
        $this->assertGreaterThanOrEqual($before, (int)$request->resolved_at);
        $job = $this->reloadJob($job);
        $this->assertSame(Job::STATUS_REJECTED, $job->status);
        $this->assertNotNull($job->finished_at);
        $entry = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_TIMED_OUT, 'approval_request', (int)$request->id);
        $this->assertNull($entry->user_id);
        $this->assertSame(
            ['job_id' => (int)$job->id, 'timeout_action' => 'reject', 'applied_action' => 'reject'],
            $this->contextOf($entry)
        );
        $this->assertSame([], $this->notifications->events, 'a timeout notifies nobody, as before');
    }

    public function testAnApproveTimeoutWithinTheGracePeriodQueuesTheJob(): void
    {
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$this->approverUser()]);
        $this->expireRequest($request, 300, ApprovalRule::TIMEOUT_ACTION_APPROVE);

        $this->assertSame(1, $this->approvals()->processTimeouts());

        $this->assertSame(ApprovalRequest::STATUS_TIMED_OUT, $this->reloadRequest($request)->status);
        $job = $this->reloadJob($job);
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
        $this->assertNotNull($job->queued_at);
        $entry = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_TIMED_OUT, 'approval_request', (int)$request->id);
        $this->assertSame(
            ['job_id' => (int)$job->id, 'timeout_action' => 'approve', 'applied_action' => 'approve'],
            $this->contextOf($entry)
        );
    }

    /**
     * Regression: an approve-timeout applied long after expiry approved the
     * job. Stock installations never ran the timeouts, so the first run
     * after the update would have queued jobs whose approval had expired
     * days before; a long outage of the schedule runner does the same.
     */
    public function testRegressionAnApproveTimeoutAppliedLongAfterExpiryApprovedTheJob(): void
    {
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$this->approverUser()]);
        $this->expireRequest($request, 2 * 3600, ApprovalRule::TIMEOUT_ACTION_APPROVE);

        $this->assertSame(1, $this->approvals()->processTimeouts());

        $this->assertSame(ApprovalRequest::STATUS_TIMED_OUT, $this->reloadRequest($request)->status);
        $this->assertSame(Job::STATUS_REJECTED, $this->reloadJob($job)->status);
        $entry = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_TIMED_OUT, 'approval_request', (int)$request->id);
        $this->assertSame([
            'job_id' => (int)$job->id,
            'timeout_action' => 'approve',
            'applied_action' => 'reject',
            'reason' => ApprovalSettlement::REASON_GRACE_PERIOD_EXCEEDED,
        ], $this->contextOf($entry));
    }

    /**
     * The grace period ends APPROVE_TIMEOUT_GRACE_SECONDS after expires_at:
     * up to then the timeout approves, a second later it rejects.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function graceProvider(): array
    {
        return [
            'at the end of the grace period' => [ApprovalSettlement::APPROVE_TIMEOUT_GRACE_SECONDS, Job::STATUS_QUEUED],
            'a second later' => [ApprovalSettlement::APPROVE_TIMEOUT_GRACE_SECONDS + 1, Job::STATUS_REJECTED],
        ];
    }

    /**
     * @dataProvider graceProvider
     */
    public function testTheGracePeriodEndsAfterAnHour(int $after, string $jobStatus): void
    {
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$this->approverUser()]);
        $this->expireRequest($request, 10, ApprovalRule::TIMEOUT_ACTION_APPROVE);

        $outcome = $this->settlement()->timeOut((int)$request->id, (int)$request->expires_at + $after);

        $this->assertInstanceOf(ApprovalOutcome::class, $outcome);
        $this->assertSame(ApprovalRequest::STATUS_TIMED_OUT, $outcome->resolution);
        $this->assertSame($jobStatus, $this->reloadJob($job)->status);
    }

    /**
     * The settlement judges a request by the later of the batch's time and
     * the clock once it holds the request's lock: a stale batch time no
     * longer approves a request whose grace period has ended by then, and
     * the request and its job are stamped with the time the timeout was
     * applied.
     */
    public function testATimeoutIsJudgedAndStampedByTheClockOnceTheRequestIsLocked(): void
    {
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$this->approverUser()]);
        $this->expireRequest($request, ApprovalSettlement::APPROVE_TIMEOUT_GRACE_SECONDS + 5, ApprovalRule::TIMEOUT_ACTION_APPROVE);
        // A batch that selected the request a while ago, within its grace period.
        $batchTime = (int)$request->expires_at + ApprovalSettlement::APPROVE_TIMEOUT_GRACE_SECONDS - 60;
        $before = time();

        $outcome = $this->settlement()->timeOut((int)$request->id, $batchTime);

        $this->assertInstanceOf(ApprovalOutcome::class, $outcome);
        $this->assertSame(Job::STATUS_REJECTED, $this->reloadJob($job)->status);
        $this->assertSame(ApprovalSettlement::REASON_GRACE_PERIOD_EXCEEDED, $outcome->timeout['reason'] ?? null);
        $stored = $this->reloadRequest($request);
        $this->assertGreaterThanOrEqual($before, (int)$stored->resolved_at, 'stamped when applied, not with the batch\'s time');
        $this->assertGreaterThanOrEqual($before, (int)$this->reloadJob($job)->finished_at);
    }

    /**
     * Regression: an approve timeout was applied after its grace period
     * because earlier timeouts of the batch took long. processTimeouts()
     * judged every request of a batch by the time the batch started: a
     * request reached minutes later (slow follow-ups of the requests before
     * it) was still approved, past the end of its grace period, and stamped
     * resolved before it really was. Here the first request of the batch
     * takes two seconds; the second one's grace period ends in between.
     */
    public function testRegressionAnApproveTimeoutWasAppliedAfterItsGracePeriodBecauseEarlierTimeoutsOfTheBatchTookLong(): void
    {
        ['request' => $slow] = $this->waitingRequest([$this->approverUser('grace-slow')]);
        $this->expireRequest($slow, 2 * 3600);
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$this->approverUser('grace-late')]);
        $this->expireRequest($request, ApprovalSettlement::APPROVE_TIMEOUT_GRACE_SECONDS - 1, ApprovalRule::TIMEOUT_ACTION_APPROVE);
        $this->swapSettlement(new class ((int)$slow->id) extends ApprovalSettlement {
            public function __construct(private readonly int $slowId)
            {
                parent::__construct();
            }

            public function timeOut(int $requestId, int $now): ?ApprovalOutcome
            {
                if ($requestId === $this->slowId) {
                    sleep(2);
                }
                return parent::timeOut($requestId, $now);
            }
        });

        $this->assertSame(2, $this->approvals()->processTimeouts());

        $this->assertSame(Job::STATUS_REJECTED, $this->reloadJob($job)->status, 'its grace period had ended when its timeout was applied');
        $entry = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_TIMED_OUT, 'approval_request', (int)$request->id);
        $this->assertSame([
            'job_id' => (int)$job->id,
            'timeout_action' => 'approve',
            'applied_action' => 'reject',
            'reason' => ApprovalSettlement::REASON_GRACE_PERIOD_EXCEEDED,
        ], $this->contextOf($entry));
        $stored = $this->reloadRequest($request);
        $this->assertGreaterThan(
            ApprovalSettlement::APPROVE_TIMEOUT_GRACE_SECONDS,
            (int)$stored->resolved_at - (int)$stored->expires_at,
            'stamped with the time the timeout was applied'
        );
    }

    public function testARequestWhoseRuleWasDeletedTimesOutRejecting(): void
    {
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$this->approverUser()]);
        $this->expireRequest($request, 60, ApprovalRule::TIMEOUT_ACTION_APPROVE);
        ApprovalRequest::updateAll(['approval_rule_id' => null], ['id' => $request->id]);

        $this->assertSame(1, $this->approvals()->processTimeouts());

        $this->assertSame(Job::STATUS_REJECTED, $this->reloadJob($job)->status);
        $entry = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_TIMED_OUT, 'approval_request', (int)$request->id);
        $this->assertSame('reject', $this->contextOf($entry)['timeout_action'] ?? null);
    }

    public function testRequestsThatHaveNotExpiredOrNeverExpireAreLeftAlone(): void
    {
        ['request' => $later] = $this->waitingRequest([$this->approverUser()]);
        $later->expires_at = time() + 600;
        $later->save(false);
        ['request' => $never] = $this->waitingRequest([$this->approverUser()]);

        $this->assertSame(0, $this->approvals()->processTimeouts());

        $this->assertSame(ApprovalRequest::STATUS_PENDING, $this->reloadRequest($later)->status);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $this->reloadRequest($never)->status);
        $this->assertNull($this->settlement()->timeOut((int)$later->id, time()), 'not expired: nothing to do');
        $this->assertNull($this->settlement()->timeOut(0, time()), 'no such request: nothing to do');
    }

    /**
     * A batch takes the requests that expired first (expires_at, then id);
     * the rest wait for the next run.
     */
    public function testTheLimitTakesTheLongestExpiredFirst(): void
    {
        $requests = [];
        foreach ([300, 900, 600, 600] as $index => $secondsAgo) {
            ['request' => $requests[$index]] = $this->waitingRequest([$this->approverUser('batch-' . $index)]);
            $this->expireRequest($requests[$index], $secondsAgo);
        }
        $requests[3]->expires_at = $requests[2]->expires_at;
        $requests[3]->save(false);

        $this->assertSame(2, $this->approvals()->processTimeouts(2));

        $this->assertSame(
            [ApprovalRequest::STATUS_PENDING, ApprovalRequest::STATUS_TIMED_OUT, ApprovalRequest::STATUS_TIMED_OUT, ApprovalRequest::STATUS_PENDING],
            array_map(fn (ApprovalRequest $r): string => $this->reloadRequest($r)->status, $requests),
            'the oldest, then the lower id of two that expired together'
        );
        $this->assertSame(2, $this->approvals()->processTimeouts(), 'without a limit, the rest');
        $this->assertSame(0, $this->approvals()->processTimeouts());
    }

    public function testASecondRunFindsNothingAndAuditsNothing(): void
    {
        ['request' => $request] = $this->waitingRequest([$this->approverUser()]);
        $this->expireRequest($request, 60);

        $this->assertSame(1, $this->approvals()->processTimeouts());
        $this->assertSame(0, $this->approvals()->processTimeouts());

        $this->assertCount(1, $this->auditsOf(AuditLog::ACTION_APPROVAL_TIMED_OUT, 'approval_request', (int)$request->id));
    }

    /**
     * An expired request whose job no longer waits (left behind before
     * cancels closed requests) is closed as canceled; it would otherwise
     * be the first of every batch, for ever.
     */
    public function testAnExpiredRequestWhoseJobNoLongerWaitsIsClosed(): void
    {
        ['job' => $job, 'request' => $request, 'rule' => $rule] = $this->waitingRequest([$this->approverUser()]);
        $this->expireRequest($request, 60, ApprovalRule::TIMEOUT_ACTION_APPROVE);
        Job::updateAll(['status' => Job::STATUS_CANCELED], ['id' => $job->id]);

        $this->assertSame(1, $this->approvals()->processTimeouts());

        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $this->reloadRequest($request)->status);
        $this->assertSame(Job::STATUS_CANCELED, $this->reloadJob($job)->status);
        $entry = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', (int)$request->id);
        $this->assertNull($entry->user_id);
        $this->assertSame(
            ['job_id' => (int)$job->id, 'rule_id' => (int)$rule->id, 'reason' => ApprovalSettlement::REASON_JOB_NOT_WAITING],
            $this->contextOf($entry)
        );
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_APPROVAL_TIMED_OUT, 'approval_request', (int)$request->id));
    }

    /**
     * A request whose job row is gone (only possible without the foreign
     * key) is closed the same way.
     */
    public function testAnExpiredRequestWithoutItsJobIsClosed(): void
    {
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$this->approverUser()]);
        $this->expireRequest($request, 60);
        $db = \Yii::$app->db;
        $db->createCommand('SET FOREIGN_KEY_CHECKS = 0')->execute();
        try {
            $db->createCommand()->delete(Job::tableName(), ['id' => $job->id])->execute();
        } finally {
            $db->createCommand('SET FOREIGN_KEY_CHECKS = 1')->execute();
        }

        $outcome = $this->settlement()->timeOut((int)$request->id, time());

        $this->assertInstanceOf(ApprovalOutcome::class, $outcome);
        $this->assertNull($outcome->job);
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $outcome->resolution);
        $this->assertNull($outcome->workflowDecision());
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $this->reloadRequest($request)->status);
    }

    // -- Locking --------------------------------------------------------------------

    /**
     * The timeout's first two statements lock the request row, then the job
     * row, before any plain read: InnoDB takes the snapshot of the
     * settlement's reads at its first plain read, so the rule, the votes and
     * the job's workflow step are read as committed when the locks were
     * granted. The writes follow in the transaction; the audit entry after
     * its commit.
     */
    public function testTheTimeoutLocksTheRequestThenTheJobBeforeAnyOtherRead(): void
    {
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$this->approverUser()]);
        $this->expireRequest($request, 60);

        $statements = $this->statementsOf(fn () => $this->approvals()->processTimeouts());

        $savepoint = $this->statementIndex($statements, '~^SAVEPOINT LEVEL\d+$~');
        $requestLock = $this->statementIndex($statements, '~^SELECT \* FROM `approval_request` WHERE `id` = ' . $request->id . ' FOR UPDATE$~');
        $jobLock = $this->statementIndex($statements, '~^SELECT \* FROM `job` WHERE `id` = ' . $job->id . ' FOR UPDATE$~');
        $stepRead = $this->statementIndex($statements, '~^SELECT \* FROM `workflow_job_step` WHERE `job_id`=' . $job->id . '$~');
        $requestUpdate = $this->statementIndex($statements, '~^UPDATE `approval_request` SET .* WHERE \(`id`=' . $request->id . '\) AND \(`status`=\'pending\'\)$~s');
        $jobUpdate = $this->statementIndex($statements, '~^UPDATE `job` SET .* WHERE \(`id`=' . $job->id . '\) AND \(`status`=\'pending_approval\'\)$~s');
        $release = $this->statementIndex($statements, '~^RELEASE SAVEPOINT LEVEL\d+$~');
        $audit = $this->statementIndex($statements, '~^INSERT INTO `audit_log`~');

        $trace = implode("\n", $statements);
        $this->assertSame($savepoint + 1, $requestLock, "the request lock is the first statement of the transaction:\n" . $trace);
        $this->assertSame($savepoint + 2, $jobLock, "the job lock is the second:\n" . $trace);
        $this->assertLessThan($stepRead, $jobLock);
        $this->assertLessThan($requestUpdate, $stepRead);
        $this->assertLessThan($jobUpdate, $requestUpdate);
        $this->assertLessThan($release, $jobUpdate);
        $this->assertLessThan($audit, $release);
    }

    // -- Races ----------------------------------------------------------------------

    /**
     * A cancel that committed between the selection of the expired requests
     * and the timeout's lock wins: the request is no longer pending, so the
     * timeout skips it and writes nothing.
     */
    public function testACancelThatCameFirstWins(): void
    {
        ['job' => $job, 'request' => $request, 'launcher' => $launcher] = $this->waitingRequest([$this->approverUser()]);
        $this->expireRequest($request, 60, ApprovalRule::TIMEOUT_ACTION_APPROVE);
        $this->swapSettlement(new class ($job, (int)$launcher->id) extends ApprovalSettlement {
            public function __construct(private readonly Job $job, private readonly int $launcherId)
            {
                parent::__construct();
            }

            public function timeOut(int $requestId, int $now): ?ApprovalOutcome
            {
                /** @var \app\services\JobCancellationService $cancellation */
                $cancellation = \Yii::$app->get('jobCancellationService');
                $cancellation->cancel($this->job, $this->launcherId);
                return parent::timeOut($requestId, $now);
            }
        });

        $this->assertSame(0, $this->approvals()->processTimeouts());

        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $this->reloadRequest($request)->status);
        $this->assertSame(Job::STATUS_CANCELED, $this->reloadJob($job)->status);
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_APPROVAL_TIMED_OUT, 'approval_request', (int)$request->id));
    }

    /**
     * Regression: a vote after the timeout applied flipped the request from
     * timed out to approved (the voter's page still showed it pending).
     */
    public function testRegressionAVoteAfterTheTimeoutFlippedTheRequest(): void
    {
        $approver = $this->approverUser();
        ['job' => $job, 'request' => $stale] = $this->waitingRequest([$approver]);
        $this->expireRequest($stale, 60);
        $stale->expires_at = null; // the page was loaded before the request expired
        $this->assertSame(1, $this->approvals()->processTimeouts());
        $timedOut = $this->reloadRequest($stale)->getAttributes();

        $this->assertVoteRefused(
            $stale,
            $approver,
            ApprovalDecision::DECISION_APPROVED,
            ApprovalDecisionRefusedException::REASON_RESOLVED,
            sprintf(ApprovalDecisionRefusedException::RESOLVED, (int)$stale->id, 'timed out')
        );

        $this->assertSame($timedOut, $this->reloadRequest($stale)->getAttributes());
        $this->assertSame(Job::STATUS_REJECTED, $this->reloadJob($job)->status);
        $this->assertSame([], $this->notifications->payloadsOf(NotificationTemplate::EVENT_APPROVAL_APPROVED));
    }

    // -- Failures -------------------------------------------------------------------

    /**
     * One request whose timeout fails is logged, without values, and left
     * for the next run; the others are still processed.
     */
    public function testAFailingRequestDoesNotStopTheOthers(): void
    {
        ['request' => $failing] = $this->waitingRequest([$this->approverUser('fail-a')]);
        $this->expireRequest($failing, 600);
        ['request' => $fine] = $this->waitingRequest([$this->approverUser('fail-b')]);
        $this->expireRequest($fine, 60);
        $this->swapSettlement(new class ((int)$failing->id) extends ApprovalSettlement {
            public function __construct(private readonly int $failingId)
            {
                parent::__construct();
            }

            public function timeOut(int $requestId, int $now): ?ApprovalOutcome
            {
                if ($requestId === $this->failingId) {
                    throw new \yii\db\Exception('SQLSTATE[40001]: Deadlock found; the SQL with a secret value', ['40001', 1213, 'Deadlock']);
                }
                return parent::timeOut($requestId, $now);
            }
        });
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $resolved = $this->approvals()->processTimeouts();
        } finally {
            \Yii::setLogger($previous);
        }

        $this->assertSame(1, $resolved);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $this->reloadRequest($failing)->status, 'left for the next run');
        $this->assertSame(ApprovalRequest::STATUS_TIMED_OUT, $this->reloadRequest($fine)->status);
        $this->assertSame(
            [sprintf(ApprovalService::TIMEOUT_FAILED, (int)$failing->id, \yii\db\Exception::class, '40001', '1213')],
            $this->errorsOf($logger)
        );
    }

    public function testAFailureWithoutAnSqlStateIsLoggedWithADash(): void
    {
        ['request' => $failing] = $this->waitingRequest([$this->approverUser('fail-c')]);
        $this->expireRequest($failing, 60);
        $this->swapSettlement(new class () extends ApprovalSettlement {
            public function timeOut(int $requestId, int $now): ?ApprovalOutcome
            {
                throw new \yii\db\Exception('Lost connection; the SQL with a value');
            }
        });
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $this->assertSame(0, $this->approvals()->processTimeouts());
        } finally {
            \Yii::setLogger($previous);
        }

        $this->assertSame([sprintf(ApprovalService::TIMEOUT_FAILED, (int)$failing->id, \yii\db\Exception::class, '-', '-')], $this->errorsOf($logger));
    }

    /**
     * Regression: a timeout whose workflow advance failed was logged as a
     * failed timeout. The line said "Approval timeout of request #N failed",
     * as for a settlement that rolled back and is tried again; but the
     * request was settled, nothing tried the advance again, and the run
     * stayed at the step. The request stays resolved and counted, and the
     * failure is logged as TIMEOUT_EFFECTS_FAILED.
     */
    public function testRegressionATimeoutWhoseWorkflowAdvanceFailedWasLoggedAsAFailedTimeout(): void
    {
        $launcher = $this->approverUser('effect-launcher');
        $rule = $this->listRule((int)$launcher->id, [$this->approverUser('effect-approver')]);
        $flow = $this->workflowStepWaiting(\app\models\WorkflowStep::TYPE_APPROVAL, $rule, $launcher);
        $this->expireRequest($flow['request'], 60, ApprovalRule::TIMEOUT_ACTION_APPROVE);
        $previousEngine = \Yii::$app->get('workflowExecutionService');
        \Yii::$app->set('workflowExecutionService', new class () extends \app\services\WorkflowExecutionService {
            public function onApprovalResolved(Job $job, bool $approved): void
            {
                throw new \RuntimeException('the engine failed');
            }
        });
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $resolved = $this->approvals()->processTimeouts();
            $again = $this->approvals()->processTimeouts();
        } finally {
            \Yii::setLogger($previous);
            \Yii::$app->set('workflowExecutionService', $previousEngine);
        }

        $this->assertSame(1, $resolved);
        $this->assertSame(0, $again, 'nothing tries the request, or its advance, again');
        $this->assertSame(ApprovalRequest::STATUS_TIMED_OUT, $this->reloadRequest($flow['request'])->status);
        $this->assertSame(Job::STATUS_SUCCEEDED, $this->reloadJob($flow['job'])->status);
        $errors = $this->errorsOf($logger);
        $this->assertStringNotContainsString('Approval timeout of request', implode("\n", $errors), 'no TIMEOUT_FAILED line');
        $this->assertSame(
            [sprintf(ApprovalService::TIMEOUT_EFFECTS_FAILED, (int)$flow['request']->id, \RuntimeException::class, '-', '-')],
            $errors
        );
        $execution = \app\models\WorkflowJobStep::findOne($flow['execution']->id);
        $this->assertNotNull($execution);
        $this->assertSame(\app\models\WorkflowJobStep::STATUS_RUNNING, $execution->status, 'the run is still at the step');
    }

    // -- Helpers ----------------------------------------------------------------------

    private function swapSettlement(ApprovalSettlement $settlement): void
    {
        $this->swapped = ['approvalSettlement', \Yii::$app->get('approvalSettlement')];
        \Yii::$app->set('approvalSettlement', $settlement);
    }

    /**
     * @return list<string> the error messages ApprovalService logged
     */
    private function errorsOf(Logger $logger): array
    {
        $errors = [];
        foreach ($logger->messages as $message) {
            if ($message[1] === Logger::LEVEL_ERROR && $message[2] === ApprovalService::class && is_string($message[0])) {
                $errors[] = $message[0];
            }
        }

        return $errors;
    }
}
