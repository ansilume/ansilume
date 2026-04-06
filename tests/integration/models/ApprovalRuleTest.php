<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\JobTemplate;
use app\models\User;
use app\tests\integration\DbTestCase;

class ApprovalRuleTest extends DbTestCase
{
    public function testTableName(): void
    {
        $this->assertSame('{{%approval_rule}}', ApprovalRule::tableName());
    }

    public function testPersistAndRetrieve(): void
    {
        $user = $this->createUser();
        $rule = $this->createApprovalRule($user->id);

        $this->assertNotNull($rule->id);
        $reloaded = ApprovalRule::findOne($rule->id);
        $this->assertNotNull($reloaded);
        $this->assertSame($rule->name, $reloaded->name);
        $this->assertSame(ApprovalRule::APPROVER_TYPE_ROLE, $reloaded->approver_type);
        $this->assertSame(1, (int)$reloaded->required_approvals);
    }

    public function testBehaviorsIncludesTimestamp(): void
    {
        $rule = new ApprovalRule();
        $behaviors = $rule->behaviors();
        $this->assertNotEmpty($behaviors);
    }

    public function testValidationRequiresNameAndApproverType(): void
    {
        $rule = new ApprovalRule();
        $this->assertFalse($rule->validate());
        $this->assertArrayHasKey('name', $rule->errors);
        $this->assertArrayHasKey('approver_type', $rule->errors);
    }

    public function testInvalidJsonApproverConfig(): void
    {
        $user = $this->createUser();
        $rule = new ApprovalRule();
        $rule->name = 'test-rule';
        $rule->approver_type = ApprovalRule::APPROVER_TYPE_ROLE;
        $rule->approver_config = '{not valid json';
        $rule->required_approvals = 1;
        $rule->timeout_action = ApprovalRule::TIMEOUT_ACTION_REJECT;
        $rule->created_by = $user->id;
        $this->assertFalse($rule->validate());
        $this->assertArrayHasKey('approver_config', $rule->errors);
    }

    public function testValidJsonApproverConfig(): void
    {
        $user = $this->createUser();
        $rule = new ApprovalRule();
        $rule->name = 'test-rule';
        $rule->approver_type = ApprovalRule::APPROVER_TYPE_ROLE;
        $rule->approver_config = '{"role": "admin"}';
        $rule->required_approvals = 1;
        $rule->timeout_action = ApprovalRule::TIMEOUT_ACTION_REJECT;
        $rule->created_by = $user->id;
        $this->assertTrue($rule->validate());
    }

    public function testApproverTypes(): void
    {
        $types = ApprovalRule::approverTypes();
        $this->assertCount(3, $types);
        $this->assertArrayHasKey(ApprovalRule::APPROVER_TYPE_ROLE, $types);
        $this->assertArrayHasKey(ApprovalRule::APPROVER_TYPE_TEAM, $types);
        $this->assertArrayHasKey(ApprovalRule::APPROVER_TYPE_USERS, $types);
    }

    public function testTimeoutActions(): void
    {
        $actions = ApprovalRule::timeoutActions();
        $this->assertCount(2, $actions);
        $this->assertArrayHasKey(ApprovalRule::TIMEOUT_ACTION_REJECT, $actions);
        $this->assertArrayHasKey(ApprovalRule::TIMEOUT_ACTION_APPROVE, $actions);
    }

    public function testGetParsedConfigEmpty(): void
    {
        $user = $this->createUser();
        $rule = $this->createApprovalRule($user->id, ApprovalRule::APPROVER_TYPE_ROLE, '');

        // Empty string config (column is NOT NULL) → getParsedConfig returns []
        $this->assertSame([], $rule->getParsedConfig());
    }

    public function testGetParsedConfigValid(): void
    {
        $user = $this->createUser();
        $rule = $this->createApprovalRule($user->id, ApprovalRule::APPROVER_TYPE_ROLE, '{"role":"operator"}');

        $config = $rule->getParsedConfig();
        $this->assertSame(['role' => 'operator'], $config);
    }

    public function testGetParsedConfigInvalidJson(): void
    {
        $user = $this->createUser();
        $rule = $this->createApprovalRule($user->id, ApprovalRule::APPROVER_TYPE_ROLE, '{"role":"admin"}');
        $rule->approver_config = 'garbage{{{';
        $rule->save(false);

        $reloaded = ApprovalRule::findOne($rule->id);
        $this->assertNotNull($reloaded);
        $this->assertSame([], $reloaded->getParsedConfig());
    }

    public function testGetApproverUserIdsForUsersType(): void
    {
        $user = $this->createUser();
        $rule = $this->createApprovalRule(
            $user->id,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => [1, 2]])
        );

        $ids = $rule->getApproverUserIds();
        $this->assertSame([1, 2], $ids);
    }

    public function testGetApproverUserIdsForUsersTypeMissingKey(): void
    {
        $user = $this->createUser();
        $rule = $this->createApprovalRule(
            $user->id,
            ApprovalRule::APPROVER_TYPE_USERS,
            '{}'
        );

        $this->assertSame([], $rule->getApproverUserIds());
    }

    public function testGetApproverUserIdsForRoleType(): void
    {
        $user = $this->createUser();

        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $roleName = 'test_approver_role_' . uniqid('', true);
        $role = $auth->createRole($roleName);
        $auth->add($role);
        $auth->assign($role, (string)$user->id);

        $rule = $this->createApprovalRule(
            $user->id,
            ApprovalRule::APPROVER_TYPE_ROLE,
            (string)json_encode(['role' => $roleName])
        );

        $ids = $rule->getApproverUserIds();
        $this->assertContains($user->id, $ids);

        // Clean up RBAC artifacts (not rolled back by DB transaction)
        $auth->revoke($role, (string)$user->id);
        $auth->remove($role);
    }

    public function testGetApproverUserIdsForRoleTypeEmptyRole(): void
    {
        $user = $this->createUser();
        $rule = $this->createApprovalRule(
            $user->id,
            ApprovalRule::APPROVER_TYPE_ROLE,
            '{}'
        );

        $this->assertSame([], $rule->getApproverUserIds());
    }

    public function testGetApproverUserIdsForTeamType(): void
    {
        $user = $this->createUser();
        $member = $this->createUser('member');
        $team = $this->createTeam($user->id);
        $this->addTeamMember((int)$team->id, (int)$member->id);

        $rule = $this->createApprovalRule(
            $user->id,
            ApprovalRule::APPROVER_TYPE_TEAM,
            (string)json_encode(['team_id' => $team->id])
        );

        $ids = $rule->getApproverUserIds();
        $this->assertContains((int)$member->id, $ids);
    }

    public function testGetApproverUserIdsForTeamTypeMissingTeamId(): void
    {
        $user = $this->createUser();
        $rule = $this->createApprovalRule(
            $user->id,
            ApprovalRule::APPROVER_TYPE_TEAM,
            '{}'
        );

        $this->assertSame([], $rule->getApproverUserIds());
    }

    public function testGetApproverUserIdsForUnknownType(): void
    {
        $user = $this->createUser();
        $rule = $this->createApprovalRule($user->id, ApprovalRule::APPROVER_TYPE_ROLE, '{}');
        // Force an unknown type bypassing validation
        $rule->approver_type = 'unknown_type';
        $rule->save(false);

        $reloaded = ApprovalRule::findOne($rule->id);
        $this->assertNotNull($reloaded);
        $this->assertSame([], $reloaded->getApproverUserIds());
    }

    public function testCreatorRelation(): void
    {
        $user = $this->createUser();
        $rule = $this->createApprovalRule($user->id);

        $this->assertInstanceOf(User::class, $rule->creator);
        $this->assertSame($user->id, $rule->creator->id);
    }

    public function testJobTemplateRelation(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);

        $rule = $this->createApprovalRule($user->id);
        $rule->job_template_id = $tpl->id;
        $rule->save(false);

        $reloaded = ApprovalRule::findOne($rule->id);
        $this->assertNotNull($reloaded);
        $this->assertInstanceOf(JobTemplate::class, $reloaded->jobTemplate);
        $this->assertSame($tpl->id, $reloaded->jobTemplate->id);
    }

    public function testApprovalRequestsRelation(): void
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

        $reloaded = ApprovalRule::findOne($rule->id);
        $this->assertNotNull($reloaded);
        $requests = $reloaded->approvalRequests;
        $this->assertCount(1, $requests);
        $this->assertInstanceOf(ApprovalRequest::class, $requests[0]);
        $this->assertSame($request->id, $requests[0]->id);
    }
}
