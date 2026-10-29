<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eApproverConfigSeeder;
use app\commands\E2eTeardownHelper;
use app\components\ApproverConfigIds;
use app\models\ApprovalRule;
use app\tests\integration\DbTestCase;

/**
 * The fixtures of the E2E specs for approval rules whose approver config
 * names its approvers by a JSON number beyond the int range
 * (E2eApproverConfigSeeder, tests/e2e/tests/approval-rules/crud.spec.ts and
 * rbac.spec.ts). The specs find them by these names; the test database holds
 * no "e2e-" rows outside a test, and the rollback removes them.
 */
class E2eApproverConfigSeederTest extends DbTestCase
{
    private int $adminId;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->createUser('admin');
        $admin->username = 'e2e-admin';
        $admin->save(false);
        $this->adminId = (int)$admin->id;
        $this->log = [];
    }

    /**
     * Each rule holds the config text as a client sends it, which the
     * parser reads as a float that names nobody. The rule form and the API
     * accept the team rule. The users rule names nobody, so it requires
     * more approvals than its users: they refuse it
     * (ApprovalRule::countEligibleApprovers()), where 2.13.1 and older
     * stored it.
     */
    public function testSeedStoresATeamRuleAndAUsersRuleWhoseApproversAreNumbersBeyondTheIntRange(): void
    {
        $this->seed();

        $team = $this->rule(E2eApproverConfigSeeder::TEAM_RULE);
        $this->assertSame(ApprovalRule::APPROVER_TYPE_TEAM, $team->approver_type);
        $this->assertSame('{"team_id": 1e30}', $team->approver_config);
        $this->assertIsFloat($team->getParsedConfig()['team_id']);
        $this->assertNull(ApproverConfigIds::teamOf($team->getParsedConfig()), 'no team');

        $users = $this->rule(E2eApproverConfigSeeder::USERS_RULE);
        $this->assertSame(ApprovalRule::APPROVER_TYPE_USERS, $users->approver_type);
        $this->assertSame('{"user_ids": [1e30]}', $users->approver_config);
        $userIds = $users->getParsedConfig()['user_ids'];
        $this->assertIsArray($userIds);
        $this->assertIsFloat($userIds[0]);
        $this->assertSame([], ApproverConfigIds::usersOf($users->getParsedConfig()), 'no user');

        $this->assertTrue($team->validate(), "the rule form and the API store {$team->name}: " . json_encode($team->errors));
        $this->assertFalse($users->validate(), "the rule form and the API refuse {$users->name}");
        $this->assertSame([sprintf(ApprovalRule::TOO_MANY_REQUIRED_APPROVALS, 1, 0)], $users->getErrors('required_approvals'));
        foreach ([$team, $users] as $rule) {
            $this->assertSame([], $rule->getApproverUserIds(), "{$rule->name} names nobody");
            $this->assertSame(1, (int)$rule->required_approvals);
            $this->assertNull($rule->timeout_minutes);
            $this->assertSame(ApprovalRule::TIMEOUT_ACTION_REJECT, $rule->timeout_action);
            $this->assertSame($this->adminId, (int)$rule->created_by);
        }
        $this->assertSame([
            sprintf(
                "  Restored approver config fixtures: e2e-team-beyond-int-rule (ID %d) and e2e-users-beyond-int-rule (ID %d), whose approvers are numbers beyond the int range.\n",
                $team->id,
                $users->id
            ),
        ], $this->log);
    }

    /**
     * A rule that was changed since, through its edit form or the API, is
     * restored in place.
     */
    public function testSeedingAgainRestoresTheRulesInPlace(): void
    {
        $this->seed();
        $teamId = (int)$this->rule(E2eApproverConfigSeeder::TEAM_RULE)->id;
        $usersId = (int)$this->rule(E2eApproverConfigSeeder::USERS_RULE)->id;
        ApprovalRule::updateAll(
            ['approver_type' => ApprovalRule::APPROVER_TYPE_ROLE, 'approver_config' => '{"role": "admin"}', 'required_approvals' => 3, 'timeout_minutes' => 5],
            ['id' => [$teamId, $usersId]]
        );

        $this->seed();

        $team = $this->rule(E2eApproverConfigSeeder::TEAM_RULE);
        $users = $this->rule(E2eApproverConfigSeeder::USERS_RULE);
        $this->assertSame([$teamId, $usersId], [(int)$team->id, (int)$users->id], 'restored in place');
        $this->assertSame([ApprovalRule::APPROVER_TYPE_TEAM, E2eApproverConfigSeeder::TEAM_CONFIG], [$team->approver_type, $team->approver_config]);
        $this->assertSame([ApprovalRule::APPROVER_TYPE_USERS, E2eApproverConfigSeeder::USERS_CONFIG], [$users->approver_type, $users->approver_config]);
        $this->assertSame([1, 1], [(int)$team->required_approvals, (int)$users->required_approvals]);
        $this->assertSame([null, null], [$team->timeout_minutes, $users->timeout_minutes]);
        foreach ([E2eApproverConfigSeeder::TEAM_RULE, E2eApproverConfigSeeder::USERS_RULE] as $name) {
            $this->assertSame(1, (int)ApprovalRule::find()->where(['name' => $name])->count(), $name);
        }
        $this->assertCount(2, $this->log);
    }

    /**
     * The specs find the rules by the names of tests/e2e/lib/approvals.ts: a
     * change of one without the other fails here, not only in a browser run.
     */
    public function testTheE2eSpecsFindTheRulesByTheseNames(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/tests/e2e/lib/approvals.ts');
        $this->assertIsString($source, 'tests/e2e/lib/approvals.ts cannot be read.');

        $this->assertStringContainsString("export const BEYOND_INT_TEAM_RULE = '" . E2eApproverConfigSeeder::TEAM_RULE . "';", $source);
        $this->assertStringContainsString("export const BEYOND_INT_USERS_RULE = '" . E2eApproverConfigSeeder::USERS_RULE . "';", $source);
    }

    public function testTheTeardownRemovesTheRules(): void
    {
        $this->seed();

        $problems = (new E2eTeardownHelper('e2e-', static function (string $msg): void {
        }))->teardownDatabase();

        $this->assertSame([], $problems);
        $this->assertFalse(ApprovalRule::find()->where(['name' => [E2eApproverConfigSeeder::TEAM_RULE, E2eApproverConfigSeeder::USERS_RULE]])->exists());
    }

    private function seed(): void
    {
        (new E2eApproverConfigSeeder(function (string $msg): void {
            $this->log[] = $msg;
        }))->seed($this->adminId);
    }

    private function rule(string $name): ApprovalRule
    {
        $rule = ApprovalRule::findOne(['name' => $name]);
        $this->assertInstanceOf(ApprovalRule::class, $rule, "{$name} is stored");

        return $rule;
    }
}
