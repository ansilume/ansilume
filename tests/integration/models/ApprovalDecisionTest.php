<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\User;
use app\tests\integration\DbTestCase;

class ApprovalDecisionTest extends DbTestCase
{
    public function testTableName(): void
    {
        $this->assertSame('{{%approval_decision}}', ApprovalDecision::tableName());
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
        $request->save(false);

        $decision = new ApprovalDecision();
        $decision->approval_request_id = $request->id;
        $decision->user_id = $user->id;
        $decision->decision = ApprovalDecision::DECISION_APPROVED;
        $decision->comment = 'Looks good';
        $decision->created_at = time();
        $decision->save(false);

        $this->assertNotNull($decision->id);
        $reloaded = ApprovalDecision::findOne($decision->id);
        $this->assertNotNull($reloaded);
        $this->assertSame(ApprovalDecision::DECISION_APPROVED, $reloaded->decision);
        $this->assertSame('Looks good', $reloaded->comment);
        $this->assertSame((int)$request->id, (int)$reloaded->approval_request_id);
        $this->assertSame($user->id, (int)$reloaded->user_id);
    }

    public function testValidationRequiresFields(): void
    {
        $decision = new ApprovalDecision();
        $this->assertFalse($decision->validate());
        $this->assertArrayHasKey('approval_request_id', $decision->errors);
        $this->assertArrayHasKey('user_id', $decision->errors);
        $this->assertArrayHasKey('decision', $decision->errors);
    }

    public function testDecisionValidation(): void
    {
        $user = $this->createUser();
        $decision = new ApprovalDecision();
        $decision->approval_request_id = 1;
        $decision->user_id = $user->id;
        $decision->decision = 'invalid_decision';
        $this->assertFalse($decision->validate());
        $this->assertArrayHasKey('decision', $decision->errors);
    }

    public function testCommentMaxLength(): void
    {
        $user = $this->createUser();
        $decision = new ApprovalDecision();
        $decision->approval_request_id = 1;
        $decision->user_id = $user->id;
        $decision->decision = ApprovalDecision::DECISION_APPROVED;
        $decision->comment = str_repeat('a', 1001);
        $this->assertFalse($decision->validate());
        $this->assertArrayHasKey('comment', $decision->errors);
    }

    public function testApprovalRequestRelation(): void
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
        $request->save(false);

        $decision = new ApprovalDecision();
        $decision->approval_request_id = $request->id;
        $decision->user_id = $user->id;
        $decision->decision = ApprovalDecision::DECISION_REJECTED;
        $decision->created_at = time();
        $decision->save(false);

        $reloaded = ApprovalDecision::findOne($decision->id);
        $this->assertNotNull($reloaded);
        $this->assertInstanceOf(ApprovalRequest::class, $reloaded->approvalRequest);
        $this->assertSame((int)$request->id, (int)$reloaded->approvalRequest->id);
    }

    public function testUserRelation(): void
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
        $request->save(false);

        $decision = new ApprovalDecision();
        $decision->approval_request_id = $request->id;
        $decision->user_id = $user->id;
        $decision->decision = ApprovalDecision::DECISION_APPROVED;
        $decision->created_at = time();
        $decision->save(false);

        $reloaded = ApprovalDecision::findOne($decision->id);
        $this->assertNotNull($reloaded);
        $this->assertInstanceOf(User::class, $reloaded->user);
        $this->assertSame($user->id, $reloaded->user->id);
    }
}
