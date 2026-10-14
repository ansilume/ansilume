<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\Job;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\services\ApprovalDecisionRefusedException;
use app\services\ApprovalSettlement;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\db\BaseActiveRecord;

/**
 * Votes (ApprovalService::recordDecision() through ApprovalSettlement): one
 * transaction that locks the request row first, then the job row, checks
 * the vote in a fixed order, records it and settles the request; audit
 * entries, notifications and the workflow advance follow after the commit.
 */
class ApprovalVoteTest extends DbTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;
    use ApprovalVoteFixtures;

    private RecordingNotificationDispatcher $notifications;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = $this->recordNotifications();
    }

    protected function tearDown(): void
    {
        $this->restoreNotifications();
        parent::tearDown();
    }

    // -- Recorded votes -------------------------------------------------------------

    public function testAnApprovalThatMeetsTheThresholdQueuesTheJob(): void
    {
        $approver = $this->approverUser();
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$approver]);
        $before = time();

        $vote = $this->approvals()->recordDecision($request, (int)$approver->id, ApprovalDecision::DECISION_APPROVED, 'LGTM');

        $this->assertSame(ApprovalDecision::DECISION_APPROVED, $vote->decision);
        $this->assertSame('LGTM', $vote->comment);
        $this->assertSame((int)$approver->id, (int)$vote->user_id);
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $request->status, 'the caller\'s model is refreshed');
        $this->assertGreaterThanOrEqual($before, (int)$request->resolved_at);
        $job = $this->reloadJob($job);
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
        $this->assertGreaterThanOrEqual($before, (int)$job->queued_at);
        $this->assertNull($job->finished_at);

        $decided = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_DECIDED, 'approval_request', (int)$request->id);
        $this->assertSame((int)$approver->id, (int)$decided->user_id);
        $this->assertSame(['decision' => 'approved', 'job_id' => (int)$job->id], $this->contextOf($decided));
        $approved = $this->notifications->payloadsOf(NotificationTemplate::EVENT_APPROVAL_APPROVED);
        $this->assertCount(1, $approved);
        $this->assertSame(
            ['id' => (string)$request->id, 'status' => 'approved', 'rule_id' => (string)$request->approval_rule_id, 'rule_name' => (string)$request->approvalRule?->name],
            $approved[0]['approval']
        );
        $this->assertSame(['id' => (string)$job->id, 'status' => 'queued', 'template_id' => (string)$job->job_template_id], $approved[0]['job']);
        $this->assertSame([], $this->notifications->payloadsOf(NotificationTemplate::EVENT_APPROVAL_REJECTED));
    }

    public function testARejectionRejectsTheJob(): void
    {
        $approver = $this->approverUser();
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$approver]);

        $this->approvals()->recordDecision($request, (int)$approver->id, ApprovalDecision::DECISION_REJECTED, 'Not now');

        $this->assertSame(ApprovalRequest::STATUS_REJECTED, $request->status);
        $job = $this->reloadJob($job);
        $this->assertSame(Job::STATUS_REJECTED, $job->status);
        $this->assertNotNull($job->finished_at);
        $this->assertCount(1, $this->notifications->payloadsOf(NotificationTemplate::EVENT_APPROVAL_REJECTED));
        $this->assertSame([], $this->notifications->payloadsOf(NotificationTemplate::EVENT_APPROVAL_APPROVED));
    }

    public function testAVoteThatDecidesNothingKeepsTheRequestPending(): void
    {
        $approvers = [$this->approverUser('a'), $this->approverUser('b'), $this->approverUser('c')];
        ['job' => $job, 'request' => $request] = $this->waitingRequest($approvers, 2);

        $this->approvals()->recordDecision($request, (int)$approvers[0]->id, ApprovalDecision::DECISION_REJECTED);

        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status, 'two approvals are still possible');
        $this->assertNull($request->resolved_at);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $this->reloadJob($job)->status);
        $this->assertCount(1, $this->auditsOf(AuditLog::ACTION_APPROVAL_DECIDED, 'approval_request', (int)$request->id));
        $this->assertNoDecisionNotification();
    }

    /**
     * Approvers who may not decide (a viewer, an inactive user) are no
     * possible votes: once only they remain, the threshold is out of reach.
     */
    public function testARejectionAutoRejectsWhenOnlyApproversWhoMayNotDecideRemain(): void
    {
        $approver = $this->approverUser();
        $viewer = $this->createUserWithRole('vote-viewer', 'viewer');
        $inactive = $this->approverUser('vote-inactive');
        $inactive->status = User::STATUS_INACTIVE;
        $inactive->save(false);
        ['request' => $request] = $this->waitingRequest([$approver, $viewer, $inactive], 2);

        $this->approvals()->recordDecision($request, (int)$approver->id, ApprovalDecision::DECISION_APPROVED);

        $this->assertSame(ApprovalRequest::STATUS_REJECTED, $request->status);
    }

    // -- The launcher ---------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function launcherProvider(): array
    {
        return [
            'an operator' => ['operator'],
            'an admin' => ['admin'],
            'a superadmin' => ['superadmin'],
        ];
    }

    /**
     * Regression: the launcher could approve their own job. A rule that
     * lists the launcher let them decide on their own launch, admins and
     * superadmins included.
     *
     * @dataProvider launcherProvider
     */
    public function testRegressionTheLauncherCouldApproveTheirOwnJob(string $kind): void
    {
        $launcher = $this->launcherOfKind($kind);
        $other = $this->approverUser();
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$launcher, $other], 1, $launcher);

        $refusal = $this->assertVoteRefused(
            $request,
            $launcher,
            ApprovalDecision::DECISION_APPROVED,
            ApprovalDecisionRefusedException::REASON_LAUNCHER,
            ApprovalDecisionRefusedException::LAUNCHER_REFUSED
        );

        $this->assertSame(403, $refusal->httpStatus());
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $this->reloadRequest($request)->status);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $this->reloadJob($job)->status);
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_APPROVAL_DECIDED, 'approval_request', (int)$request->id));
        $this->assertNoDecisionNotification();
        $this->assertFalse($this->approvals()->canUserApprove($request, (int)$launcher->id));
        $this->assertTrue($this->approvals()->canUserApprove($request, (int)$other->id));
    }

    public function testTheLauncherCannotRejectEither(): void
    {
        $launcher = $this->approverUser('vote-self');
        ['request' => $request] = $this->waitingRequest([$launcher, $this->approverUser()], 1, $launcher);

        $this->assertVoteRefused(
            $request,
            $launcher,
            ApprovalDecision::DECISION_REJECTED,
            ApprovalDecisionRefusedException::REASON_LAUNCHER,
            ApprovalDecisionRefusedException::LAUNCHER_REFUSED
        );
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $this->reloadRequest($request)->status);
    }

    /**
     * Votes the launcher cast before the update stay in the history but
     * count for nothing: with the other approver's approval the request still
     * needs one more; the other approver's rejection then rejects it.
     */
    public function testTheLaunchersEarlierVotesCountForNothing(): void
    {
        $launcher = $this->approverUser('vote-legacy');
        $first = $this->approverUser('vote-first');
        $second = $this->approverUser('vote-second');
        ['request' => $request] = $this->waitingRequest([$launcher, $first, $second], 2, $launcher);
        $this->legacyVote($request, $launcher, ApprovalDecision::DECISION_APPROVED);

        $this->approvals()->recordDecision($request, (int)$first->id, ApprovalDecision::DECISION_APPROVED);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status, 'the launcher\'s approval does not count');

        $this->approvals()->recordDecision($request, (int)$second->id, ApprovalDecision::DECISION_REJECTED);
        $this->assertSame(ApprovalRequest::STATUS_REJECTED, $request->status, 'one approval is all that is left');
    }

    /**
     * The launcher's own refusal comes before "already voted", also for a
     * vote they cast before the update.
     */
    public function testALauncherWithAnEarlierVoteIsRefusedAsTheLauncher(): void
    {
        $launcher = $this->approverUser('vote-legacy2');
        ['request' => $request] = $this->waitingRequest([$launcher, $this->approverUser()], 1, $launcher);
        $this->legacyVote($request, $launcher, ApprovalDecision::DECISION_APPROVED);

        $this->assertVoteRefused($request, $launcher, ApprovalDecision::DECISION_APPROVED, ApprovalDecisionRefusedException::REASON_LAUNCHER);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function workflowStepProvider(): array
    {
        return [
            'the placeholder of an approval step' => [WorkflowStep::TYPE_APPROVAL],
            'the job of a gated job step' => [WorkflowStep::TYPE_JOB],
        ];
    }

    /**
     * Regression: the launcher of a workflow run was told "You launched this
     * job". They started the run, and the run created the job that waits:
     * the placeholder of an approval step, or the job of a job step whose
     * template is gated. A vote and the check behind the request page's note
     * (ApprovalSettlement::check() through ApprovalService::decisionRefusal())
     * now name the run; the request stays for the other approver.
     *
     * @dataProvider workflowStepProvider
     */
    public function testRegressionTheLauncherOfAWorkflowRunWasToldYouLaunchedThisJob(string $stepType): void
    {
        $launcher = $this->approverUser('run-launcher');
        $other = $this->approverUser('run-other');
        $rule = $this->listRule((int)$launcher->id, [$launcher, $other]);
        ['job' => $job, 'request' => $request] = $this->workflowStepWaiting($stepType, $rule, $launcher);

        foreach ([ApprovalDecision::DECISION_APPROVED, ApprovalDecision::DECISION_REJECTED] as $decision) {
            $refusal = $this->assertVoteRefused(
                $request,
                $launcher,
                $decision,
                ApprovalDecisionRefusedException::REASON_LAUNCHER,
                ApprovalDecisionRefusedException::LAUNCHER_REFUSED_RUN
            );
            $this->assertSame('You launched this workflow run; another approver must decide.', $refusal->getMessage());
            $this->assertSame(403, $refusal->httpStatus());
            $this->assertSame(ApprovalRequest::STATUS_PENDING, $refusal->requestStatus());
        }

        $checked = $this->settlement()->check($request, $request->job, (int)$launcher->id, time());
        $this->assertNotNull($checked);
        $this->assertSame(ApprovalDecisionRefusedException::REASON_LAUNCHER, $checked->reason());
        $this->assertSame(ApprovalDecisionRefusedException::LAUNCHER_REFUSED_RUN, $checked->getMessage());
        $this->assertSame(
            ApprovalDecisionRefusedException::LAUNCHER_REFUSED_RUN,
            $this->approvals()->decisionRefusal($request, (int)$launcher->id)?->getMessage(),
            'the note on the request page'
        );

        $this->assertSame(ApprovalRequest::STATUS_PENDING, $this->reloadRequest($request)->status);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $this->reloadJob($job)->status);
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_APPROVAL_DECIDED, 'approval_request', (int)$request->id));
        $this->assertNull($this->settlement()->check($request, $request->job, (int)$other->id, time()), 'the other approver decides');
    }

    /**
     * The launcher of a job outside a workflow keeps the job's text, on the
     * request page as in a vote.
     */
    public function testTheLauncherOfAJobOutsideAWorkflowIsToldTheyLaunchedTheJob(): void
    {
        $launcher = $this->approverUser('plain-launcher');
        ['request' => $request] = $this->waitingRequest([$launcher, $this->approverUser()], 1, $launcher);

        $checked = $this->settlement()->check($request, $request->job, (int)$launcher->id, time());

        $this->assertNotNull($checked);
        $this->assertSame(ApprovalDecisionRefusedException::REASON_LAUNCHER, $checked->reason());
        $this->assertSame(ApprovalDecisionRefusedException::LAUNCHER_REFUSED, $checked->getMessage());
        $this->assertSame('You launched this job; another approver must decide.', $checked->getMessage());
        $this->assertSame(ApprovalDecisionRefusedException::LAUNCHER_REFUSED, ApprovalDecisionRefusedException::launcher($request)->getMessage());
    }

    // -- The request's state ----------------------------------------------------------

    /**
     * Regression: a vote after expires_at was counted. The request is still
     * pending until the maintenance run applies its timeout, and a vote in
     * between decided it.
     */
    public function testRegressionAVoteAfterExpiresAtWasCounted(): void
    {
        $approver = $this->approverUser();
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$approver]);
        $this->expireRequest($request, 60);

        $refusal = $this->assertVoteRefused(
            $request,
            $approver,
            ApprovalDecision::DECISION_APPROVED,
            ApprovalDecisionRefusedException::REASON_EXPIRED,
            sprintf(ApprovalDecisionRefusedException::EXPIRED, (int)$request->id)
        );

        $this->assertSame(409, $refusal->httpStatus());
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $refusal->requestStatus());
        // No promise of "within a minute": a backlog, a run's time budget or an own cron setup can take longer.
        $this->assertSame(
            sprintf('Approval request #%d has expired and takes no more votes; its timeout is applied by the next maintenance run.', (int)$request->id),
            $refusal->getMessage()
        );
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $this->reloadRequest($request)->status, 'the timeout is the maintenance run\'s');
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $this->reloadJob($job)->status);
        $this->assertFalse($this->approvals()->canUserApprove($request, (int)$approver->id));
    }

    public function testARequestExpiresAtItsExpiresAtAndNotBefore(): void
    {
        $approver = $this->approverUser();
        ['request' => $request] = $this->waitingRequest([$approver]);
        $request->expires_at = time() + 3600;
        $request->save(false);
        $now = time();

        $this->assertNull($this->settlement()->check($request, $request->job, (int)$approver->id, (int)$request->expires_at - 1));
        $this->assertSame(
            ApprovalDecisionRefusedException::REASON_EXPIRED,
            $this->settlement()->check($request, $request->job, (int)$approver->id, (int)$request->expires_at)?->reason()
        );
        $this->assertTrue($request->hasExpired((int)$request->expires_at));
        $this->assertFalse($request->hasExpired($now));
    }

    /**
     * Regression: a stale vote overwrote a canceled request. A vote with the
     * request as an approver's page loaded it before the job was canceled
     * passed the "resolved" check on that stale model, was stored, and
     * rewrote the request from canceled to approved.
     */
    public function testRegressionAStaleVoteOverwroteACanceledRequest(): void
    {
        $approver = $this->approverUser();
        ['job' => $job, 'request' => $stale, 'launcher' => $launcher] = $this->waitingRequest([$approver]);
        $this->assertTrue($this->jobCancellation()->cancel($job, (int)$launcher->id));
        $closed = $this->reloadRequest($stale);
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $closed->status);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $stale->status, 'the page\'s model is stale');
        $this->notifications->events = [];

        $refusal = $this->assertVoteRefused(
            $stale,
            $approver,
            ApprovalDecision::DECISION_APPROVED,
            ApprovalDecisionRefusedException::REASON_RESOLVED,
            sprintf(ApprovalDecisionRefusedException::RESOLVED, (int)$stale->id, 'canceled')
        );

        $this->assertSame(409, $refusal->httpStatus());
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $refusal->requestStatus());
        $this->assertSame($closed->getAttributes(), $this->reloadRequest($stale)->getAttributes(), 'the request stays canceled');
        $this->assertSame(Job::STATUS_CANCELED, $this->reloadJob($job)->status);
        $this->assertNoDecisionNotification();
    }

    /**
     * Regression: a vote on a stale page after another approver had decided
     * was stored, the request's status rewritten and approval.approved sent
     * a second time.
     */
    public function testRegressionALateVoteWasStoredAndNotifiedAgain(): void
    {
        $first = $this->approverUser('vote-early');
        $late = $this->approverUser('vote-late');
        ['request' => $stale] = $this->waitingRequest([$first, $late]);
        $fresh = $this->reloadRequest($stale);
        $this->approvals()->recordDecision($fresh, (int)$first->id, ApprovalDecision::DECISION_APPROVED);
        $resolved = $this->reloadRequest($stale)->getAttributes();

        $this->assertVoteRefused(
            $stale,
            $late,
            ApprovalDecision::DECISION_REJECTED,
            ApprovalDecisionRefusedException::REASON_RESOLVED,
            sprintf(ApprovalDecisionRefusedException::RESOLVED, (int)$stale->id, 'approved')
        );

        $this->assertSame($resolved, $this->reloadRequest($stale)->getAttributes());
        $this->assertSame(1, $this->decisionCount($stale));
        $this->assertCount(1, $this->notifications->payloadsOf(NotificationTemplate::EVENT_APPROVAL_APPROVED));
        $this->assertSame([], $this->notifications->payloadsOf(NotificationTemplate::EVENT_APPROVAL_REJECTED));
    }

    /**
     * A request whose job no longer waits for approval (left behind by code
     * before the cancel closed requests) cannot be decided: it is closed as
     * canceled, audited, and the vote answers 409.
     */
    public function testAVoteOnARequestWhoseJobNoLongerWaitsClosesIt(): void
    {
        $approver = $this->approverUser();
        ['job' => $job, 'request' => $request, 'rule' => $rule] = $this->waitingRequest([$approver]);
        Job::updateAll(['status' => Job::STATUS_CANCELED, 'finished_at' => time()], ['id' => $job->id]);
        $before = time();
        $this->assertFalse($this->approvals()->canUserApprove($request, (int)$approver->id), 'no buttons');
        $this->assertSame(
            ApprovalDecisionRefusedException::REASON_JOB_NOT_WAITING,
            $this->approvals()->decisionRefusal($request, (int)$approver->id)?->reason()
        );

        $refusal = $this->assertVoteRefused(
            $request,
            $approver,
            ApprovalDecision::DECISION_APPROVED,
            ApprovalDecisionRefusedException::REASON_JOB_NOT_WAITING,
            sprintf(ApprovalDecisionRefusedException::JOB_NOT_WAITING, (int)$request->id, (int)$job->id)
        );

        $this->assertSame(409, $refusal->httpStatus());
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $refusal->requestStatus());
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status, 'the caller\'s model is refreshed');
        $this->assertGreaterThanOrEqual($before, (int)$request->resolved_at);
        $entry = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', (int)$request->id);
        $this->assertSame((int)$approver->id, (int)$entry->user_id);
        $this->assertSame(
            ['job_id' => (int)$job->id, 'rule_id' => (int)$rule->id, 'reason' => ApprovalSettlement::REASON_JOB_NOT_WAITING],
            $this->contextOf($entry)
        );
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_APPROVAL_DECIDED, 'approval_request', (int)$request->id));
        $this->assertNoDecisionNotification();
        $this->assertSame(Job::STATUS_CANCELED, $this->reloadJob($job)->status);
    }

    /**
     * A request whose job row is gone (only possible without the foreign
     * key) is closed the same way.
     */
    public function testAVoteOnARequestWithoutItsJobClosesIt(): void
    {
        $approver = $this->approverUser();
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$approver]);
        $db = \Yii::$app->db;
        $db->createCommand('SET FOREIGN_KEY_CHECKS = 0')->execute();
        try {
            $db->createCommand()->delete(Job::tableName(), ['id' => $job->id])->execute();
        } finally {
            $db->createCommand('SET FOREIGN_KEY_CHECKS = 1')->execute();
        }

        $this->assertVoteRefused($request, $approver, ApprovalDecision::DECISION_APPROVED, ApprovalDecisionRefusedException::REASON_JOB_NOT_WAITING);

        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $this->reloadRequest($request)->status);
    }

    public function testAVoteOnARequestThatNoLongerExistsIs404(): void
    {
        $approver = $this->approverUser();
        ['request' => $request] = $this->waitingRequest([$approver]);
        ApprovalRequest::deleteAll(['id' => $request->id]);

        $refusal = $this->voteAs($request, $approver, ApprovalDecision::DECISION_APPROVED);

        $this->assertNotNull($refusal);
        $this->assertSame(ApprovalDecisionRefusedException::REASON_GONE, $refusal->reason());
        $this->assertSame(sprintf(ApprovalDecisionRefusedException::GONE, (int)$request->id), $refusal->getMessage());
        $this->assertSame(404, $refusal->httpStatus());
        $this->assertSame('', $refusal->requestStatus());
    }

    // -- The voter ------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function notEligibleProvider(): array
    {
        return [
            'not on the list' => ['unlisted'],
            'a listed viewer' => ['viewer'],
            'a listed user without a role' => ['plain'],
            'a listed inactive operator' => ['inactive'],
        ];
    }

    /**
     * @dataProvider notEligibleProvider
     */
    public function testAVoterWhoIsNoEligibleApproverIsRefused(string $kind): void
    {
        $voter = match ($kind) {
            'viewer' => $this->createUserWithRole('vote-viewer', 'viewer'),
            'plain' => $this->createUser('vote-plain'),
            default => $this->approverUser('vote-' . $kind),
        };
        if ($kind === 'inactive') {
            $voter->status = User::STATUS_INACTIVE;
            $voter->save(false);
        }
        $listed = $kind === 'unlisted' ? [$this->approverUser()] : [$voter, $this->approverUser()];
        ['request' => $request] = $this->waitingRequest($listed);

        $refusal = $this->assertVoteRefused(
            $request,
            $voter,
            ApprovalDecision::DECISION_APPROVED,
            ApprovalDecisionRefusedException::REASON_NOT_ELIGIBLE,
            ApprovalDecisionRefusedException::NOT_ELIGIBLE
        );
        $this->assertSame(403, $refusal->httpStatus());
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $this->reloadRequest($request)->status);
    }

    public function testASecondVoteOfTheSameUserIsRefused(): void
    {
        $approver = $this->approverUser();
        ['request' => $request] = $this->waitingRequest([$approver, $this->approverUser('vote-other')], 2);
        $this->approvals()->recordDecision($request, (int)$approver->id, ApprovalDecision::DECISION_APPROVED);
        $this->assertFalse($this->approvals()->canUserApprove($request, (int)$approver->id), 'no buttons after the own vote');

        $refusal = $this->assertVoteRefused(
            $request,
            $approver,
            ApprovalDecision::DECISION_REJECTED,
            ApprovalDecisionRefusedException::REASON_ALREADY_VOTED,
            ApprovalDecisionRefusedException::ALREADY_VOTED
        );
        $this->assertSame(422, $refusal->httpStatus());
    }

    /**
     * Regression: a double submit of the same vote ended in a server error.
     * Both requests passed the check for an earlier vote, and the second
     * INSERT hit the unique index with an IntegrityException, which no
     * caller handled. The duplicate is injected right before the INSERT, as
     * the other request commits it in production.
     */
    public function testRegressionADoubleSubmitEndedInAServerError(): void
    {
        $approver = $this->approverUser();
        ['request' => $request] = $this->waitingRequest([$approver, $this->approverUser('vote-other')], 2);
        $injected = false;
        $duplicate = static function (Event $event) use (&$injected): void {
            /** @var ApprovalDecision $vote */
            $vote = $event->sender;
            if ($injected) {
                return;
            }
            $injected = true;
            ApprovalDecision::getDb()->createCommand()->insert(ApprovalDecision::tableName(), [
                'approval_request_id' => $vote->approval_request_id,
                'user_id' => $vote->user_id,
                'decision' => ApprovalDecision::DECISION_APPROVED,
                'created_at' => time(),
            ])->execute();
        };
        Event::on(ApprovalDecision::class, BaseActiveRecord::EVENT_BEFORE_INSERT, $duplicate);
        try {
            $refusal = $this->voteAs($request, $approver, ApprovalDecision::DECISION_APPROVED);
        } finally {
            Event::off(ApprovalDecision::class, BaseActiveRecord::EVENT_BEFORE_INSERT, $duplicate);
        }

        $this->assertTrue($injected);
        $this->assertNotNull($refusal);
        $this->assertSame(ApprovalDecisionRefusedException::REASON_ALREADY_VOTED, $refusal->reason());
        $this->assertSame(422, $refusal->httpStatus());
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $this->reloadRequest($request)->status);
        // In production the duplicate is the other request's committed vote;
        // here it went with this vote's rollback (one connection).
        $this->assertSame(0, $this->decisionCount($request));
    }

    public function testACommentThatIsTooLongIsRefused(): void
    {
        $approver = $this->approverUser();
        ['request' => $request] = $this->waitingRequest([$approver]);

        $refusal = $this->voteAs($request, $approver, ApprovalDecision::DECISION_REJECTED, str_repeat('x', 1001));

        $this->assertNotNull($refusal);
        $this->assertSame(ApprovalDecisionRefusedException::REASON_INVALID, $refusal->reason());
        $this->assertSame(
            sprintf(ApprovalDecisionRefusedException::INVALID_DECISION, 'Comment should contain at most 1,000 characters.'),
            $refusal->getMessage()
        );
        $this->assertSame(422, $refusal->httpStatus());
        $this->assertSame(0, $this->decisionCount($request));
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $this->reloadRequest($request)->status);
    }

    // -- The order of the checks ------------------------------------------------------

    /**
     * The request's state comes first (a launcher or a stranger learns that
     * it is resolved, expired or no longer waits), then the voter (the
     * launcher before eligibility), then the vote.
     */
    public function testTheChecksRunInTheirOrder(): void
    {
        $launcher = $this->approverUser('order-launcher');
        $approver = $this->approverUser('order-approver');
        $stranger = $this->approverUser('order-stranger');

        ['request' => $resolved] = $this->waitingRequest([$launcher, $approver], 1, $launcher);
        $this->approvals()->recordDecision($resolved, (int)$approver->id, ApprovalDecision::DECISION_APPROVED);
        $this->assertVoteRefused($resolved, $launcher, ApprovalDecision::DECISION_APPROVED, ApprovalDecisionRefusedException::REASON_RESOLVED);
        $this->assertVoteRefused($resolved, $stranger, ApprovalDecision::DECISION_APPROVED, ApprovalDecisionRefusedException::REASON_RESOLVED);

        ['request' => $expired] = $this->waitingRequest([$launcher, $approver], 1, $launcher);
        $this->expireRequest($expired, 30);
        $this->assertVoteRefused($expired, $launcher, ApprovalDecision::DECISION_APPROVED, ApprovalDecisionRefusedException::REASON_EXPIRED);

        ['request' => $voted] = $this->waitingRequest([$launcher, $approver, $this->approverUser('order-third')], 2, $launcher);
        $this->approvals()->recordDecision($voted, (int)$approver->id, ApprovalDecision::DECISION_APPROVED);
        $this->assertVoteRefused($voted, $stranger, ApprovalDecision::DECISION_APPROVED, ApprovalDecisionRefusedException::REASON_NOT_ELIGIBLE);
        $this->assertVoteRefused($voted, $approver, ApprovalDecision::DECISION_APPROVED, ApprovalDecisionRefusedException::REASON_ALREADY_VOTED);
    }

    // -- Locking --------------------------------------------------------------------

    /**
     * The request row is locked first, then the job row, before any plain
     * read: InnoDB takes the snapshot of the settlement's reads at its first
     * plain read, so the rule, the votes and the job's workflow step are
     * read as committed when the locks were granted. The vote, the request
     * and the job are written inside the transaction, and the audit entry
     * follows its commit.
     */
    public function testTheVoteLocksTheRequestBeforeTheJobAndAuditsAfterTheCommit(): void
    {
        $approver = $this->approverUser();
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$approver]);

        $statements = $this->statementsOf(
            fn () => $this->approvals()->recordDecision($request, (int)$approver->id, ApprovalDecision::DECISION_APPROVED)
        );

        $savepoint = $this->statementIndex($statements, '~^SAVEPOINT LEVEL\d+$~');
        $requestLock = $this->statementIndex($statements, '~^SELECT \* FROM `approval_request` WHERE `id` = ' . $request->id . ' FOR UPDATE$~');
        $jobLock = $this->statementIndex($statements, '~^SELECT \* FROM `job` WHERE `id` = ' . $job->id . ' FOR UPDATE$~');
        $stepRead = $this->statementIndex($statements, '~^SELECT \* FROM `workflow_job_step` WHERE `job_id`=' . $job->id . '$~');
        $insert = $this->statementIndex($statements, '~^INSERT INTO `approval_decision`~');
        $tally = $this->statementIndex($statements, '~FROM `approval_decision` WHERE `approval_request_id` = ' . $request->id . ' LOCK IN SHARE MODE$~');
        $requestUpdate = $this->statementIndex($statements, '~^UPDATE `approval_request` SET .* WHERE \(`id`=' . $request->id . '\) AND \(`status`=\'pending\'\)$~s');
        $jobUpdate = $this->statementIndex($statements, '~^UPDATE `job` SET .* WHERE \(`id`=' . $job->id . '\) AND \(`status`=\'pending_approval\'\)$~s');
        $release = $this->statementIndex($statements, '~^RELEASE SAVEPOINT LEVEL\d+$~');
        $audit = $this->statementIndex($statements, '~^INSERT INTO `audit_log`~');

        $trace = implode("\n", $statements);
        $this->assertSame($savepoint + 1, $requestLock, "the request lock is the first statement of the transaction:\n" . $trace);
        $this->assertSame($savepoint + 2, $jobLock, "the job lock is the second, before any plain read:\n" . $trace);
        $this->assertLessThan($stepRead, $jobLock, 'the job\'s workflow step is read after both locks');
        $this->assertLessThan($insert, $stepRead);
        $this->assertLessThan($tally, $insert);
        $this->assertLessThan($requestUpdate, $tally);
        $this->assertLessThan($jobUpdate, $requestUpdate);
        $this->assertLessThan($release, $jobUpdate);
        $this->assertLessThan($audit, $release);
    }

    /**
     * A refused vote writes nothing and rolls its transaction back.
     */
    public function testARefusedVoteRollsBack(): void
    {
        $launcher = $this->approverUser('rollback-launcher');
        ['request' => $request] = $this->waitingRequest([$launcher, $this->approverUser()], 1, $launcher);

        $statements = $this->statementsOf(fn () => $this->voteAs($request, $launcher, ApprovalDecision::DECISION_APPROVED));

        $this->statementIndex($statements, '~^ROLLBACK TO SAVEPOINT LEVEL\d+$~');
        foreach ($statements as $statement) {
            $this->assertDoesNotMatchRegularExpression('~^(INSERT|UPDATE|DELETE)~', $statement);
        }
    }

    // -- Helpers ----------------------------------------------------------------------

    private function assertNoDecisionNotification(): void
    {
        $this->assertSame([], $this->notifications->payloadsOf(NotificationTemplate::EVENT_APPROVAL_APPROVED));
        $this->assertSame([], $this->notifications->payloadsOf(NotificationTemplate::EVENT_APPROVAL_REJECTED));
    }

    private function launcherOfKind(string $kind): User
    {
        if ($kind !== 'superadmin') {
            return $this->createUserWithRole('vote-launcher-' . $kind, $kind);
        }
        $superadmin = $this->createUser('vote-launcher-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);

        return $superadmin;
    }

    /**
     * A vote as stored before the launcher was refused: written directly.
     */
    private function legacyVote(ApprovalRequest $request, User $voter, string $decision): void
    {
        $vote = new ApprovalDecision();
        $vote->approval_request_id = (int)$request->id;
        $vote->user_id = (int)$voter->id;
        $vote->decision = $decision;
        $vote->created_at = time() - 3600;
        $vote->save(false);
    }
}
