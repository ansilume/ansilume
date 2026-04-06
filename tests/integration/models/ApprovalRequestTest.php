<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\tests\integration\DbTestCase;

class ApprovalRequestTest extends DbTestCase
{
    public function testTableName(): void
    {
        $this->assertSame('{{%approval_request}}', ApprovalRequest::tableName());
    }

    public function testPersistAndRetrieve(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);
        $job = $this->createJob((int)$tpl->id, $user->id);
        $rule = $this->createApprovalRule($user->id);

        $request = new ApprovalRequest();
        $request->job_id = $job->id;
        $request->approval_rule_id = $rule->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $this->assertTrue($request->save(false));
        $this->assertNotNull($request->id);

        $reloaded = ApprovalRequest::findOne($request->id);
        $this->assertNotNull($reloaded);
        $this->assertSame($job->id, $reloaded->job_id);
        $this->assertSame($rule->id, $reloaded->approval_rule_id);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $reloaded->status);
    }

    public function testStatuses(): void
    {
        $statuses = ApprovalRequest::statuses();
        $this->assertCount(4, $statuses);
        $this->assertContains(ApprovalRequest::STATUS_PENDING, $statuses);
        $this->assertContains(ApprovalRequest::STATUS_APPROVED, $statuses);
        $this->assertContains(ApprovalRequest::STATUS_REJECTED, $statuses);
        $this->assertContains(ApprovalRequest::STATUS_TIMED_OUT, $statuses);
    }

    public function testIsResolvedWhenPending(): void
    {
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_PENDING);
        $this->assertFalse($request->isResolved());
    }

    public function testIsResolvedWhenApproved(): void
    {
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_APPROVED);
        $this->assertTrue($request->isResolved());
    }

    public function testIsResolvedWhenRejected(): void
    {
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_REJECTED);
        $this->assertTrue($request->isResolved());
    }

    public function testIsResolvedWhenTimedOut(): void
    {
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_TIMED_OUT);
        $this->assertTrue($request->isResolved());
    }

    public function testApprovalCount(): void
    {
        $user = $this->createUser();
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_PENDING);

        $decision = new ApprovalDecision();
        $decision->approval_request_id = $request->id;
        $decision->user_id = $user->id;
        $decision->decision = 'approved';
        $decision->created_at = time();
        $decision->save(false);

        $this->assertSame(1, $request->approvalCount());
    }

    public function testRejectionCount(): void
    {
        $user = $this->createUser();
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_PENDING);

        $decision = new ApprovalDecision();
        $decision->approval_request_id = $request->id;
        $decision->user_id = $user->id;
        $decision->decision = 'rejected';
        $decision->created_at = time();
        $decision->save(false);

        $this->assertSame(1, $request->rejectionCount());
    }

    public function testApprovalCountZero(): void
    {
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_PENDING);
        $this->assertSame(0, $request->approvalCount());
    }

    public function testStatusLabelForAllStatuses(): void
    {
        $this->assertSame('Pending', ApprovalRequest::statusLabel(ApprovalRequest::STATUS_PENDING));
        $this->assertSame('Approved', ApprovalRequest::statusLabel(ApprovalRequest::STATUS_APPROVED));
        $this->assertSame('Rejected', ApprovalRequest::statusLabel(ApprovalRequest::STATUS_REJECTED));
        $this->assertSame('Timed Out', ApprovalRequest::statusLabel(ApprovalRequest::STATUS_TIMED_OUT));
        $this->assertSame('unknown_status', ApprovalRequest::statusLabel('unknown_status'));
    }

    public function testStatusCssClassForAllStatuses(): void
    {
        $this->assertSame('warning', ApprovalRequest::statusCssClass(ApprovalRequest::STATUS_PENDING));
        $this->assertSame('success', ApprovalRequest::statusCssClass(ApprovalRequest::STATUS_APPROVED));
        $this->assertSame('danger', ApprovalRequest::statusCssClass(ApprovalRequest::STATUS_REJECTED));
        $this->assertSame('secondary', ApprovalRequest::statusCssClass(ApprovalRequest::STATUS_TIMED_OUT));
        $this->assertSame('secondary', ApprovalRequest::statusCssClass('unknown_status'));
    }

    public function testJobRelation(): void
    {
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_PENDING);
        $this->assertInstanceOf(Job::class, $request->job);
        $this->assertSame($request->job_id, $request->job->id);
    }

    public function testApprovalRuleRelation(): void
    {
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_PENDING);
        $this->assertInstanceOf(ApprovalRule::class, $request->approvalRule);
        $this->assertSame($request->approval_rule_id, $request->approvalRule->id);
    }

    public function testDecisionsRelation(): void
    {
        $user = $this->createUser();
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_PENDING);

        $decision = new ApprovalDecision();
        $decision->approval_request_id = $request->id;
        $decision->user_id = $user->id;
        $decision->decision = 'approved';
        $decision->created_at = time();
        $decision->save(false);

        $reloaded = ApprovalRequest::findOne($request->id);
        $this->assertNotNull($reloaded);
        $decisions = $reloaded->decisions;
        $this->assertCount(1, $decisions);
        $this->assertInstanceOf(ApprovalDecision::class, $decisions[0]);
        $this->assertSame($decision->id, $decisions[0]->id);
    }

    /**
     * Helper to create a persisted ApprovalRequest with the given status.
     */
    private function createApprovalRequest(string $status): ApprovalRequest
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);
        $job = $this->createJob((int)$tpl->id, $user->id);
        $rule = $this->createApprovalRule($user->id);

        $request = new ApprovalRequest();
        $request->job_id = $job->id;
        $request->approval_rule_id = $rule->id;
        $request->status = $status;
        $request->requested_at = time();
        $request->save(false);

        return $request;
    }
}
