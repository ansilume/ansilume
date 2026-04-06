<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\services\ApprovalService;
use app\tests\integration\DbTestCase;

class ApprovalServiceTest extends DbTestCase
{
    private function service(): ApprovalService
    {
        /** @var ApprovalService $s */
        $s = \Yii::$app->get('approvalService');
        return $s;
    }

    private function scaffoldJob(): Job
    {
        $user = $this->createUser('approval');
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        return $this->createJob($template->id, $user->id, Job::STATUS_PENDING_APPROVAL);
    }

    public function testCreateRequestSetsStatusPending(): void
    {
        $job = $this->scaffoldJob();
        $rule = $this->createApprovalRule($job->launched_by);

        $request = $this->service()->createRequest($job, $rule);

        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);
        $this->assertSame($job->id, $request->job_id);
        $this->assertSame($rule->id, $request->approval_rule_id);
        $this->assertNotNull($request->requested_at);
    }

    public function testCreateRequestWithTimeoutSetsExpiresAt(): void
    {
        $job = $this->scaffoldJob();
        $rule = $this->createApprovalRule($job->launched_by);
        $rule->timeout_minutes = 30;
        $rule->save(false);

        $request = $this->service()->createRequest($job, $rule);

        $this->assertNotNull($request->expires_at);
        $this->assertGreaterThan(time(), $request->expires_at);
    }

    public function testRecordDecisionCreatesVote(): void
    {
        $job = $this->scaffoldJob();
        $user = $this->createUser('approver');
        $rule = $this->createApprovalRule(
            $job->launched_by,
            ApprovalRule::APPROVER_TYPE_USERS,
            json_encode(['user_ids' => [$user->id]]) ?: '{}'
        );

        $request = $this->service()->createRequest($job, $rule);
        $decision = $this->service()->recordDecision($request, $user->id, 'approved', 'LGTM');

        $this->assertSame('approved', $decision->decision);
        $this->assertSame('LGTM', $decision->comment);
        $this->assertSame($user->id, $decision->user_id);
    }

    public function testApprovalThresholdMetQueuesJob(): void
    {
        $job = $this->scaffoldJob();
        $user = $this->createUser('approver');
        $rule = $this->createApprovalRule(
            $job->launched_by,
            ApprovalRule::APPROVER_TYPE_USERS,
            json_encode(['user_ids' => [$user->id]]) ?: '{}',
            1
        );

        $request = $this->service()->createRequest($job, $rule);
        $this->service()->recordDecision($request, $user->id, 'approved');

        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $request->status);

        $job->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
    }

    public function testRejectionRejectsJob(): void
    {
        $job = $this->scaffoldJob();
        $user = $this->createUser('approver');
        $rule = $this->createApprovalRule(
            $job->launched_by,
            ApprovalRule::APPROVER_TYPE_USERS,
            json_encode(['user_ids' => [$user->id]]) ?: '{}',
            1
        );

        $request = $this->service()->createRequest($job, $rule);
        $this->service()->recordDecision($request, $user->id, 'rejected');

        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_REJECTED, $request->status);

        $job->refresh();
        $this->assertSame(Job::STATUS_REJECTED, $job->status);
    }

    public function testDuplicateVoteThrows(): void
    {
        $job = $this->scaffoldJob();
        $user = $this->createUser('approver');
        $user2 = $this->createUser('approver2b');
        $rule = $this->createApprovalRule(
            $job->launched_by,
            ApprovalRule::APPROVER_TYPE_USERS,
            json_encode(['user_ids' => [$user->id, $user2->id]]) ?: '{}',
            2
        );

        $request = $this->service()->createRequest($job, $rule);
        $this->service()->recordDecision($request, $user->id, 'approved');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already voted');
        $this->service()->recordDecision($request, $user->id, 'approved');
    }

    public function testVoteOnResolvedRequestThrows(): void
    {
        $job = $this->scaffoldJob();
        $user = $this->createUser('approver');
        $user2 = $this->createUser('approver2');
        $rule = $this->createApprovalRule(
            $job->launched_by,
            ApprovalRule::APPROVER_TYPE_USERS,
            json_encode(['user_ids' => [$user->id, $user2->id]]) ?: '{}',
            1
        );

        $request = $this->service()->createRequest($job, $rule);
        $this->service()->recordDecision($request, $user->id, 'approved');

        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $request->status);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already resolved');
        $this->service()->recordDecision($request, $user2->id, 'approved');
    }

    public function testCanUserApproveReturnsTrueForEligible(): void
    {
        $job = $this->scaffoldJob();
        $user = $this->createUser('approver');
        $rule = $this->createApprovalRule(
            $job->launched_by,
            ApprovalRule::APPROVER_TYPE_USERS,
            json_encode(['user_ids' => [$user->id]]) ?: '{}'
        );

        $request = $this->service()->createRequest($job, $rule);
        $this->assertTrue($this->service()->canUserApprove($request, $user->id));
    }

    public function testCanUserApproveReturnsFalseForIneligible(): void
    {
        $job = $this->scaffoldJob();
        $user = $this->createUser('approver');
        $outsider = $this->createUser('outsider');
        $rule = $this->createApprovalRule(
            $job->launched_by,
            ApprovalRule::APPROVER_TYPE_USERS,
            json_encode(['user_ids' => [$user->id]]) ?: '{}'
        );

        $request = $this->service()->createRequest($job, $rule);
        $this->assertFalse($this->service()->canUserApprove($request, $outsider->id));
    }

    public function testCanUserApproveReturnsFalseForResolved(): void
    {
        $job = $this->scaffoldJob();
        $user = $this->createUser('approver');
        $rule = $this->createApprovalRule(
            $job->launched_by,
            ApprovalRule::APPROVER_TYPE_USERS,
            json_encode(['user_ids' => [$user->id]]) ?: '{}',
            1
        );

        $request = $this->service()->createRequest($job, $rule);
        $this->service()->recordDecision($request, $user->id, 'approved');

        $request->refresh();
        $this->assertFalse($this->service()->canUserApprove($request, $user->id));
    }

    public function testProcessTimeoutsRejectsExpiredRequest(): void
    {
        $job = $this->scaffoldJob();
        $rule = $this->createApprovalRule($job->launched_by);
        $rule->timeout_minutes = 1;
        $rule->timeout_action = ApprovalRule::TIMEOUT_ACTION_REJECT;
        $rule->save(false);

        $request = $this->service()->createRequest($job, $rule);

        // Manually expire the request
        $request->expires_at = time() - 60;
        $request->save(false);

        $count = $this->service()->processTimeouts();
        $this->assertSame(1, $count);

        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_TIMED_OUT, $request->status);

        $job->refresh();
        $this->assertSame(Job::STATUS_REJECTED, $job->status);
    }

    public function testProcessTimeoutsApprovesWithApproveAction(): void
    {
        $job = $this->scaffoldJob();
        $rule = $this->createApprovalRule($job->launched_by);
        $rule->timeout_minutes = 1;
        $rule->timeout_action = ApprovalRule::TIMEOUT_ACTION_APPROVE;
        $rule->save(false);

        $request = $this->service()->createRequest($job, $rule);
        $request->expires_at = time() - 60;
        $request->save(false);

        $this->service()->processTimeouts();

        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_TIMED_OUT, $request->status);

        $job->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
    }

    public function testProcessTimeoutsSkipsNonExpired(): void
    {
        $job = $this->scaffoldJob();
        $rule = $this->createApprovalRule($job->launched_by);
        $rule->timeout_minutes = 60;
        $rule->save(false);

        $this->service()->createRequest($job, $rule);

        $count = $this->service()->processTimeouts();
        $this->assertSame(0, $count);
    }

    public function testTeamBasedApproverResolution(): void
    {
        $user = $this->createUser('owner');
        $approver = $this->createUser('team_member');
        $team = $this->createTeam($user->id);
        $this->addTeamMember($team->id, $approver->id);

        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id, Job::STATUS_PENDING_APPROVAL);

        $rule = $this->createApprovalRule(
            $user->id,
            ApprovalRule::APPROVER_TYPE_TEAM,
            json_encode(['team_id' => $team->id]) ?: '{}'
        );

        $request = $this->service()->createRequest($job, $rule);
        $this->assertTrue($this->service()->canUserApprove($request, $approver->id));
        $this->assertFalse($this->service()->canUserApprove($request, $user->id));
    }

    // -- processTimeouts: additional coverage -------------------------------

    public function testProcessTimeoutsReturnsZeroWhenNoExpiredRequests(): void
    {
        $count = $this->service()->processTimeouts();
        $this->assertSame(0, $count);
    }

    public function testProcessTimeoutsSkipsRequestsWithoutExpiresAt(): void
    {
        $job = $this->scaffoldJob();
        $rule = $this->createApprovalRule($job->launched_by);
        // No timeout_minutes set, so expires_at is null
        $this->service()->createRequest($job, $rule);

        $count = $this->service()->processTimeouts();
        $this->assertSame(0, $count);
    }

    public function testProcessTimeoutsHandlesMultipleExpiredRequests(): void
    {
        $job1 = $this->scaffoldJob();
        $job2 = $this->scaffoldJob();

        $rule = $this->createApprovalRule($job1->launched_by);
        $rule->timeout_minutes = 1;
        $rule->timeout_action = ApprovalRule::TIMEOUT_ACTION_REJECT;
        $rule->save(false);

        $request1 = $this->service()->createRequest($job1, $rule);
        $request1->expires_at = time() - 60;
        $request1->save(false);

        $request2 = $this->service()->createRequest($job2, $rule);
        $request2->expires_at = time() - 60;
        $request2->save(false);

        $count = $this->service()->processTimeouts();
        $this->assertSame(2, $count);

        $request1->refresh();
        $request2->refresh();
        $this->assertSame(ApprovalRequest::STATUS_TIMED_OUT, $request1->status);
        $this->assertSame(ApprovalRequest::STATUS_TIMED_OUT, $request2->status);
    }

    public function testProcessTimeoutsWritesAuditLog(): void
    {
        $job = $this->scaffoldJob();
        $rule = $this->createApprovalRule($job->launched_by);
        $rule->timeout_minutes = 1;
        $rule->timeout_action = ApprovalRule::TIMEOUT_ACTION_REJECT;
        $rule->save(false);

        $request = $this->service()->createRequest($job, $rule);
        $request->expires_at = time() - 60;
        $request->save(false);

        $before = \app\models\AuditLog::find()
            ->where(['action' => \app\models\AuditLog::ACTION_APPROVAL_TIMED_OUT])
            ->count();

        $this->service()->processTimeouts();

        $after = \app\models\AuditLog::find()
            ->where(['action' => \app\models\AuditLog::ACTION_APPROVAL_TIMED_OUT])
            ->count();
        $this->assertSame((int)$before + 1, (int)$after);
    }

    // -- evaluateThreshold: auto-reject branch ----------------------------

    public function testAutoRejectWhenRemainingApprovalsCannotMeetThreshold(): void
    {
        $job = $this->scaffoldJob();
        $user1 = $this->createUser('voter1');
        $user2 = $this->createUser('voter2');
        // Require 2 approvals but only 2 eligible users
        $rule = $this->createApprovalRule(
            $job->launched_by,
            ApprovalRule::APPROVER_TYPE_USERS,
            json_encode(['user_ids' => [$user1->id, $user2->id]]) ?: '{}',
            2
        );

        $request = $this->service()->createRequest($job, $rule);

        // First user rejects — now only 1 eligible remains, but 2 required
        $this->service()->recordDecision($request, $user1->id, 'rejected');

        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_REJECTED, $request->status);

        $job->refresh();
        $this->assertSame(Job::STATUS_REJECTED, $job->status);
    }

    public function testThresholdNotMetYetKeepsStatusPending(): void
    {
        $job = $this->scaffoldJob();
        $user1 = $this->createUser('voter1b');
        $user2 = $this->createUser('voter2b');
        $user3 = $this->createUser('voter3b');
        // Require 2 approvals, 3 eligible users
        $rule = $this->createApprovalRule(
            $job->launched_by,
            ApprovalRule::APPROVER_TYPE_USERS,
            json_encode(['user_ids' => [$user1->id, $user2->id, $user3->id]]) ?: '{}',
            2
        );

        $request = $this->service()->createRequest($job, $rule);
        $this->service()->recordDecision($request, $user1->id, 'approved');

        $request->refresh();
        // Only 1 of 2 required approvals — still pending
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);
    }

    // -- createRequest: additional coverage ---------------------------------

    public function testCreateRequestWithoutTimeoutLeavesExpiresAtNull(): void
    {
        $job = $this->scaffoldJob();
        $rule = $this->createApprovalRule($job->launched_by);
        // No timeout_minutes set

        $request = $this->service()->createRequest($job, $rule);
        $this->assertNull($request->expires_at);
    }

    public function testCreateRequestWritesAuditLog(): void
    {
        $job = $this->scaffoldJob();
        $rule = $this->createApprovalRule($job->launched_by);

        $before = \app\models\AuditLog::find()
            ->where(['action' => \app\models\AuditLog::ACTION_APPROVAL_REQUESTED])
            ->count();

        $this->service()->createRequest($job, $rule);

        $after = \app\models\AuditLog::find()
            ->where(['action' => \app\models\AuditLog::ACTION_APPROVAL_REQUESTED])
            ->count();
        $this->assertSame((int)$before + 1, (int)$after);
    }

    // -- recordDecision: additional coverage --------------------------------

    public function testRecordDecisionWritesAuditLog(): void
    {
        $job = $this->scaffoldJob();
        $user = $this->createUser('audit-voter');
        $user2 = $this->createUser('audit-voter2');
        $rule = $this->createApprovalRule(
            $job->launched_by,
            ApprovalRule::APPROVER_TYPE_USERS,
            json_encode(['user_ids' => [$user->id, $user2->id]]) ?: '{}',
            2
        );

        $request = $this->service()->createRequest($job, $rule);

        $before = \app\models\AuditLog::find()
            ->where(['action' => \app\models\AuditLog::ACTION_APPROVAL_DECIDED])
            ->count();

        $this->service()->recordDecision($request, $user->id, 'approved');

        $after = \app\models\AuditLog::find()
            ->where(['action' => \app\models\AuditLog::ACTION_APPROVAL_DECIDED])
            ->count();
        $this->assertSame((int)$before + 1, (int)$after);
    }

    public function testRecordDecisionWithComment(): void
    {
        $job = $this->scaffoldJob();
        $user = $this->createUser('comment-voter');
        $user2 = $this->createUser('comment-voter2');
        $rule = $this->createApprovalRule(
            $job->launched_by,
            ApprovalRule::APPROVER_TYPE_USERS,
            json_encode(['user_ids' => [$user->id, $user2->id]]) ?: '{}',
            2
        );

        $request = $this->service()->createRequest($job, $rule);
        $decision = $this->service()->recordDecision($request, $user->id, 'rejected', 'Not ready');

        $this->assertSame('rejected', $decision->decision);
        $this->assertSame('Not ready', $decision->comment);
    }

    public function testRecordDecisionWithNullComment(): void
    {
        $job = $this->scaffoldJob();
        $user = $this->createUser('null-comment');
        $user2 = $this->createUser('null-comment2');
        $rule = $this->createApprovalRule(
            $job->launched_by,
            ApprovalRule::APPROVER_TYPE_USERS,
            json_encode(['user_ids' => [$user->id, $user2->id]]) ?: '{}',
            2
        );

        $request = $this->service()->createRequest($job, $rule);
        $decision = $this->service()->recordDecision($request, $user->id, 'approved');

        $this->assertNull($decision->comment);
    }
}
