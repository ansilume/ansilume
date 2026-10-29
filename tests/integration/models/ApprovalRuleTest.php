<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\JobTemplate;
use app\models\User;
use app\tests\integration\ApproverListFixtures;
use app\tests\integration\DbTestCase;

class ApprovalRuleTest extends DbTestCase
{
    use ApproverListFixtures;

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

    /**
     * Regression: the message named the column ("Approver_config must be
     * valid JSON."), not the field's label as every other message does.
     */
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
        $this->assertSame(['Approver Config must be valid JSON.'], $rule->getErrors('approver_config'));
        $this->assertSame(sprintf(ApprovalRule::INVALID_JSON, 'Approver Config'), $rule->getFirstError('approver_config'));
    }

    /**
     * JSON that is no object names no approvers.
     *
     * @return array<string, array{0: string}>
     */
    public static function configThatIsNoObjectProvider(): array
    {
        return [
            'an empty list' => ['[]'],
            'a list of user IDs' => ['[7, 12]'],
            'a number' => ['7'],
            'zero' => ['0'],
            'a string' => ['"operator"'],
            'null' => ['null'],
            'true' => ['true'],
        ];
    }

    /**
     * Regression: an approver config that is valid JSON but no object (a
     * list, a number, a string, null) was accepted, "0" even without any
     * check. Such a rule names no approvers, and the API returned its config
     * as a JSON list (or not at all) although it documents an object.
     *
     * @dataProvider configThatIsNoObjectProvider
     */
    public function testAnApproverConfigThatIsNoJsonObjectIsRefused(string $config): void
    {
        $rule = new ApprovalRule();
        $rule->name = 'test-rule';
        $rule->approver_type = ApprovalRule::APPROVER_TYPE_ROLE;
        $rule->approver_config = $config;

        $this->assertFalse($rule->validate(['approver_config']));
        $this->assertSame(['Approver Config must be a JSON object.'], $rule->getErrors('approver_config'));
        $this->assertSame(sprintf(ApprovalRule::NOT_A_JSON_OBJECT, 'Approver Config'), $rule->getFirstError('approver_config'));
    }

    /**
     * {} is what the web form stores for a role or team rule without a
     * choice; a non-string value is left to the string rule.
     */
    public function testAnEmptyObjectIsAValidApproverConfigAndANonStringIsLeftToTheStringRule(): void
    {
        $rule = new ApprovalRule();
        $rule->name = 'test-rule';
        $rule->approver_type = ApprovalRule::APPROVER_TYPE_ROLE;
        $rule->approver_config = '{}';
        $this->assertTrue($rule->validate(['approver_config']));

        $rule->setAttribute('approver_config', ['role' => 'admin']);
        $rule->validateJson('approver_config');
        $this->assertSame([], $rule->getErrors('approver_config'), 'validateJson adds nothing');
        $this->assertFalse($rule->validate(['approver_config']));
        $this->assertSame(['Approver Config must be a string.'], $rule->getErrors('approver_config'));
    }

    /**
     * A list or another JSON value stored by an older version parses to
     * no config at all, as getParsedConfig() documents (array<string, mixed>).
     */
    public function testGetParsedConfigOfJsonThatIsNoObjectIsEmpty(): void
    {
        $user = $this->createUser();
        foreach (['[7, 12]', '[]', '7', '{}'] as $config) {
            $rule = $this->createApprovalRule((int)$user->id, ApprovalRule::APPROVER_TYPE_ROLE, $config);
            $this->assertSame([], $rule->getParsedConfig(), $config);
            $this->assertSame([], $rule->getApproverUserIds(), $config);
        }
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

    public function testValidateApproverCountRejectsExcessForUsers(): void
    {
        $user = $this->createUser();
        $rule = new ApprovalRule();
        $rule->name = 'test-count-validation';
        $rule->approver_type = ApprovalRule::APPROVER_TYPE_USERS;
        $rule->approver_config = (string)json_encode(['user_ids' => $this->userIds(2)]);
        $rule->required_approvals = 5;
        $rule->timeout_action = ApprovalRule::TIMEOUT_ACTION_REJECT;
        $rule->created_by = $user->id;

        $this->assertFalse($rule->validate());
        $this->assertSame([sprintf(ApprovalRule::TOO_MANY_REQUIRED_APPROVALS, 5, 2)], $rule->getErrors('required_approvals'));
        $this->assertSame(['Required approvals (5) exceeds eligible approvers (2).'], $rule->getErrors('required_approvals'));
    }

    public function testValidateApproverCountAcceptsValidForUsers(): void
    {
        $user = $this->createUser();
        $rule = new ApprovalRule();
        $rule->name = 'test-count-ok';
        $rule->approver_type = ApprovalRule::APPROVER_TYPE_USERS;
        $rule->approver_config = (string)json_encode(['user_ids' => $this->userIds(3)]);
        $rule->required_approvals = 2;
        $rule->timeout_action = ApprovalRule::TIMEOUT_ACTION_REJECT;
        $rule->created_by = $user->id;

        $this->assertTrue($rule->validate());
    }

    /**
     * Regression: the count of a users rule took every entry of user_ids,
     * while its approvers are the users the entries name as
     * ApproverConfigIds reads an ID. So 2 required approvals of a user
     * listed next to a value that is no ID ("abc", true, 12.5), next to
     * the same user again (also as "0" followed by the ID), or next to a
     * user who does not exist passed the check, and every launch the rule
     * gates was then refused as one that could never be approved. The
     * count takes each user once, and only users who exist, as a role or a
     * team rule does.
     *
     * @dataProvider oneUserListedProvider
     */
    public function testRegressionAnEntryThatAddsNoApproverWasCounted(string $list): void
    {
        $rule = $this->usersRule($this->oneUserConfig($list), 2);

        $valid = $rule->validate();

        $this->assertSame([sprintf(ApprovalRule::TOO_MANY_REQUIRED_APPROVALS, 2, 1)], $rule->getErrors('required_approvals'));
        $this->assertFalse($valid);
        $this->assertSame(['required_approvals'], array_keys($rule->getErrors()), 'nothing else is wrong with the rule');
        $this->assertSame(1, $rule->countEligibleApprovers());

        $rule->required_approvals = 1;
        $this->assertTrue($rule->validate(), 'one approval of the one user');
    }

    /**
     * Users who exist count whatever their status, as the members of a
     * team and the holders of a role do: an inactive user may be activated
     * again. A deleted user no longer counts.
     */
    public function testEveryUserWhoExistsCountsOnceAnInactiveOneToo(): void
    {
        [$active, $inactive] = $this->userIds(2);
        User::updateAll(['status' => User::STATUS_INACTIVE], ['id' => $inactive]);
        $gone = $this->createUser('rule-count-gone');
        $goneId = (int)$gone->id;
        $config = sprintf('{"user_ids": [%1$d, %2$d, "%1$d", %3$d, "abc"]}', $active, $inactive, $goneId);
        $rule = $this->usersRule($config, 2);

        $this->assertSame(3, $rule->countEligibleApprovers(), 'before the delete');
        $this->assertSame(1, $gone->delete());
        $this->assertSame(2, $rule->countEligibleApprovers());
        $this->assertTrue($rule->validate());
        $rule->required_approvals = 3;
        $this->assertFalse($rule->validate());
        $this->assertSame([sprintf(ApprovalRule::TOO_MANY_REQUIRED_APPROVALS, 3, 2)], $rule->getErrors('required_approvals'));
    }

    /**
     * A users rule without an entry that is an ID counts no approver, also
     * one that lists nothing.
     */
    public function testAUsersRuleWithoutAnIdCountsNoApprover(): void
    {
        foreach (['{"user_ids": []}', '{"user_ids": ["abc", true, 12.5]}', '{"user_ids": 7}', '{}'] as $config) {
            $rule = $this->usersRule($config, 1);
            $valid = $rule->validate();
            $this->assertSame([sprintf(ApprovalRule::TOO_MANY_REQUIRED_APPROVALS, 1, 0)], $rule->getErrors('required_approvals'), $config);
            $this->assertFalse($valid, $config);
            $this->assertSame(0, $rule->countEligibleApprovers(), $config);
        }
    }

    /**
     * Role/team types skip hard validation (membership is dynamic) — JS warning only.
     */
    public function testValidateApproverCountDoesNotRejectForRole(): void
    {
        $user = $this->createUser();
        $rule = new ApprovalRule();
        $rule->name = 'test-count-role-ok';
        $rule->approver_type = ApprovalRule::APPROVER_TYPE_ROLE;
        $rule->approver_config = (string)json_encode(['role' => 'admin']);
        $rule->required_approvals = 50;
        $rule->timeout_action = ApprovalRule::TIMEOUT_ACTION_REJECT;
        $rule->created_by = $user->id;

        $this->assertTrue($rule->validate());
    }

    public function testValidateApproverCountDoesNotRejectForTeam(): void
    {
        $user = $this->createUser();
        $member = $this->createUser('member');
        $team = $this->createTeam($user->id);
        $this->addTeamMember((int)$team->id, (int)$member->id);

        $rule = new ApprovalRule();
        $rule->name = 'test-count-team-ok';
        $rule->approver_type = ApprovalRule::APPROVER_TYPE_TEAM;
        $rule->approver_config = (string)json_encode(['team_id' => $team->id]);
        $rule->required_approvals = 50;
        $rule->timeout_action = ApprovalRule::TIMEOUT_ACTION_REJECT;
        $rule->created_by = $user->id;

        $this->assertTrue($rule->validate());
    }

    public function testCountEligibleApproversUsersType(): void
    {
        $user = $this->createUser();
        $rule = $this->createApprovalRule(
            $user->id,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => $this->userIds(3)])
        );
        $this->assertSame(3, $rule->countEligibleApprovers());
    }

    public function testCountEligibleApproversRoleType(): void
    {
        $user = $this->createUser();
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $roleName = 'test_count2_role_' . uniqid('', true);
        $role = $auth->createRole($roleName);
        $auth->add($role);
        $auth->assign($role, (string)$user->id);

        $rule = $this->createApprovalRule(
            $user->id,
            ApprovalRule::APPROVER_TYPE_ROLE,
            (string)json_encode(['role' => $roleName])
        );
        $this->assertSame(1, $rule->countEligibleApprovers());

        $auth->revoke($role, (string)$user->id);
        $auth->remove($role);
    }

    public function testCountEligibleApproversTeamType(): void
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
        $this->assertSame(1, $rule->countEligibleApprovers());
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

    /**
     * A users rule with the approver config $config, not saved.
     */
    private function usersRule(string $config, int $requiredApprovals): ApprovalRule
    {
        $rule = new ApprovalRule();
        $rule->name = 'test-count-' . uniqid('', true);
        $rule->approver_type = ApprovalRule::APPROVER_TYPE_USERS;
        $rule->approver_config = $config;
        $rule->required_approvals = $requiredApprovals;
        $rule->timeout_action = ApprovalRule::TIMEOUT_ACTION_REJECT;
        $rule->created_by = (int)$this->createUser('rule-count-creator')->id;

        return $rule;
    }

    /**
     * The IDs of $count new users, who exist, as the count of a users rule
     * takes only users who do.
     *
     * @return list<int>
     */
    private function userIds(int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = (int)$this->createUser('rule-count')->id;
        }

        return $ids;
    }
}
