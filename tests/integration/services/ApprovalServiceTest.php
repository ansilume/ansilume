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
}
