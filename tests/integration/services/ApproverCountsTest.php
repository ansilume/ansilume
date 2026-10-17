<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\services\ApproverCounts;
use app\services\ApproverEligibility;
use app\services\ProjectAccessChecker;
use app\tests\integration\CountsQueries;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\rbac\DbManager;
use yii\rbac\Role;

/**
 * The approver counts of the approval rule form (ApproverCounts): of a role
 * rule's approvers (the users assigned the role directly), a team rule's
 * (the team's members) and a users rule's (the users listed), those who may
 * decide: an active account, and a superadmin or holding approval.decide.
 * They equal what ApproverEligibility finds for a launch of a job template
 * whose project no team restricts, by a user who is no approver, and they
 * cost a constant number of queries, none without roles, teams and users.
 */
class ApproverCountsTest extends DbTestCase
{
    use CountsQueries;
    use TeamScopeFixtures;

    private const DECIDE = 'approval.decide';

    public function testARoleCountsTheUsersAssignedItDirectlyWhoMayDecide(): void
    {
        $counted = $this->newRole('counted', true);
        $withoutDecide = $this->newRole('without-decide');
        $wrapper = $this->newRole('wrapper');
        $this->auth()->addChild($wrapper, $counted);
        $unused = $this->newRole('unused', true);
        $this->userWithRole($counted);
        $this->userWithRole($counted);
        $this->inactive($this->userWithRole($counted));
        $this->auth()->assign($counted, (string)$this->deletedUserId());
        $this->userWithRole($wrapper);
        $this->userWithRole($withoutDecide);
        $this->auth()->assign($withoutDecide, (string)$this->superadmin('holder'));
        $this->superadmin('without-roles');

        $counts = $this->counts()->forRuleForm([$wrapper->name, $counted->name, $withoutDecide->name, $unused->name], [], []);

        $this->assertSame(
            [$wrapper->name => 1, $counted->name => 2, $withoutDecide->name => 1, $unused->name => 0],
            $counts['roles'],
            'every role, in the order given: two active holders of approval.decide; the holder through the wrapper'
            . ' counts for the wrapper only; of the role without approval.decide only its superadmin'
        );
        $this->assertSame([], $counts['teams']);
        $this->assertSame([], $counts['users']);
    }

    public function testATeamCountsItsMembersWhoMayDecide(): void
    {
        $owner = (int)$this->createUser('owner')->id;
        $team = (int)$this->createTeam($owner)->id;
        $empty = (int)$this->createTeam($owner)->id;
        foreach (
            [
                $this->operator('member-operator'),
                $this->superadmin('member-superadmin'),
                $this->viewer('member-viewer'),
                $this->inactive($this->operator('member-inactive')),
                (int)$this->createUser('member-plain')->id,
                $this->userWithRole($this->newRole('member-without-decide')),
            ] as $userId
        ) {
            $this->addTeamMember($team, $userId);
        }
        $unknown = $team + 987654;

        $counts = $this->counts()->forRuleForm([], [$empty, $team, $unknown], []);

        $this->assertSame([(string)$empty, (string)$team, (string)$unknown], array_map('strval', array_keys($counts['teams'])));
        $this->assertSame([0, 2, 0], array_values($counts['teams']), 'the operator and the superadmin without roles');
        $this->assertSame(2, $counts['teams'][(string)$team]);
        $this->assertSame([], $counts['roles']);
        $this->assertSame([], $counts['users']);
    }

    public function testUsersAreTheOnesWhoMayDecideEachOnceInTheOrderGiven(): void
    {
        $viewer = $this->viewer('viewer');
        $operator = $this->operator('operator');
        $superadmin = $this->superadmin('without-roles');
        $inactive = $this->inactive($this->operator('inactive'));
        $deleted = $this->deletedUserId();
        $plain = (int)$this->createUser('plain')->id;
        $admin = (int)$this->createUserWithRole('admin', 'admin')->id;

        $counts = $this->counts()->forRuleForm([], [], [$viewer, $admin, $operator, $plain, $superadmin, $inactive, $deleted, $operator, 0]);

        $this->assertSame([$admin, $operator, $superadmin], $counts['users']);
        $this->assertSame(['roles' => [], 'teams' => [], 'users' => [$admin, $operator, $superadmin]], $counts);
    }

    /**
     * The form's counts are what a launch finds for a job template whose
     * project no team restricts, launched by a user who is no approver: on
     * a default install, and under team scoping for the project without a
     * team. Users are compared in the rule's order.
     *
     * @dataProvider installations
     */
    public function testTheCountsEqualTheEligibleApproversOfAnUnrestrictedProject(bool $teamScoping): void
    {
        $template = $teamScoping ? $this->teamScope()['open'] : $this->template();
        /** @var ProjectAccessChecker $projects */
        $projects = \Yii::$app->get('projectAccessChecker');
        $this->assertSame($teamScoping, $projects->hasRestrictedProjects());
        // Without approval.decide of its own: each holder decides by their other roles, or as a superadmin.
        $role = $this->newRole('mixed');
        $owner = (int)$this->createUser('owner')->id;
        $team = (int)$this->createTeam($owner)->id;
        $users = [];
        foreach ($this->usersOfEveryKind() as $userId) {
            $this->auth()->assign($role, (string)$userId);
            $this->addTeamMember($team, $userId);
            $users[] = $userId;
        }
        $this->auth()->assign($role, (string)$this->deletedUserId());

        $counts = $this->counts()->forRuleForm([$role->name], [$team], $users);

        $launcher = $this->operator('launcher');
        $byRole = $this->eligibleFor($template, $launcher, ApprovalRule::APPROVER_TYPE_ROLE, ['role' => $role->name]);
        $byTeam = $this->eligibleFor($template, $launcher, ApprovalRule::APPROVER_TYPE_TEAM, ['team_id' => $team]);
        $byUsers = $this->eligibleFor($template, $launcher, ApprovalRule::APPROVER_TYPE_USERS, ['user_ids' => $users]);
        $this->assertCount(4, $byUsers, 'the operator, the admin and the two superadmins');
        $this->assertCount(4, $byRole);
        $this->assertCount(4, $byTeam);
        $this->assertSame([$role->name => count($byRole)], $counts['roles']);
        $this->assertSame([(string)$team => count($byTeam)], $counts['teams']);
        $this->assertSame($byUsers, $counts['users']);
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function installations(): array
    {
        return ['a default install' => [false], 'team scoping' => [true]];
    }

    /**
     * Holding approval.decide follows the RBAC manager's default roles, as
     * ApproverEligibility follows them.
     */
    public function testHoldingApprovalDecideFollowsTheDefaultRoles(): void
    {
        $auth = $this->auth();
        $viewerRole = $auth->getRole('viewer');
        $decide = $auth->getPermission(self::DECIDE);
        $this->assertNotNull($viewerRole);
        $this->assertNotNull($decide);
        $auth->addChild($viewerRole, $decide);
        $withoutRole = (int)$this->createUser('no-role')->id;

        $saved = $auth->defaultRoles;
        try {
            foreach ([[['viewer'], [$withoutRole]], [[], []]] as [$defaultRoles, $expected]) {
                $auth->defaultRoles = $defaultRoles;
                $this->assertSame($expected, $this->counts()->forRuleForm([], [], [$withoutRole])['users']);
            }
        } finally {
            $auth->defaultRoles = $saved;
        }
    }

    public function testWithoutRolesTeamsAndUsersNothingIsRead(): void
    {
        $counts = null;
        $queries = $this->queriesOf(function () use (&$counts): void {
            $counts = $this->counts()->forRuleForm([], [], []);
        });

        $this->assertSame(0, $queries);
        $this->assertSame(['roles' => [], 'teams' => [], 'users' => []], $counts);
    }

    /**
     * The roles' assignments, the teams' members and the users are read
     * together: 13 roles, teams and users cost what 3 of each cost, with and
     * without team scoping.
     *
     * @dataProvider installations
     */
    public function testThreeOrThirteenRolesTeamsAndUsersCostTheSame(bool $teamScoping): void
    {
        if ($teamScoping) {
            $this->teamScope();
        }
        $few = $this->formInput(3);
        $many = $this->formInput(13);
        // The first use loads table schemas.
        $this->counts()->forRuleForm(...$few);

        $forFew = $this->queriesOf(fn () => $this->assertCount(3, $this->counts()->forRuleForm(...$few)['roles']));
        $forMany = $this->queriesOf(fn () => $this->assertCount(13, $this->counts()->forRuleForm(...$many)['roles']));

        $this->assertSame($forFew, $forMany);
        $counts = $this->counts()->forRuleForm(...$many);
        $this->assertSame(array_fill(0, 13, 1), array_values($counts['roles']));
        $this->assertSame(array_fill(0, 13, 1), array_values($counts['teams']));
        $this->assertSame($many[2], $counts['users']);
    }

    // -- Helpers ----------------------------------------------------------------------

    private function counts(): ApproverCounts
    {
        return new ApproverCounts($this->auth());
    }

    private function auth(): DbManager
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $auth);

        return $auth;
    }

    private function newRole(string $suffix, bool $decides = false): Role
    {
        $auth = $this->auth();
        $role = $auth->createRole('test-' . $suffix . '-' . uniqid());
        $auth->add($role);
        if ($decides) {
            $decide = $auth->getPermission(self::DECIDE);
            $this->assertNotNull($decide);
            $auth->addChild($role, $decide);
        }

        return $role;
    }

    private function userWithRole(Role $role): int
    {
        $userId = (int)$this->createUser('role-holder')->id;
        $this->auth()->assign($role, (string)$userId);

        return $userId;
    }

    private function operator(string $suffix): int
    {
        return (int)$this->createUserWithRole($suffix, 'operator')->id;
    }

    private function viewer(string $suffix): int
    {
        return (int)$this->createUserWithRole($suffix, 'viewer')->id;
    }

    private function superadmin(string $suffix): int
    {
        $user = $this->createUser($suffix);
        $user->is_superadmin = true;
        $user->save(false);

        return (int)$user->id;
    }

    private function inactive(int $userId): int
    {
        $user = User::findOne($userId);
        $this->assertNotNull($user);
        $user->status = User::STATUS_INACTIVE;
        $user->save(false);

        return $userId;
    }

    /**
     * A user deleted without revoking their role, so a role assignment can
     * outlive the account.
     */
    private function deletedUserId(): int
    {
        $user = $this->createUser('deleted');
        $userId = (int)$user->id;
        $user->delete();

        return $userId;
    }

    /**
     * One user of every kind: an operator, a viewer, an admin, a superadmin
     * with the viewer role, a superadmin without roles, an inactive
     * operator, a user without roles. The operator, the admin and the
     * superadmins may decide.
     *
     * @return list<int>
     */
    private function usersOfEveryKind(): array
    {
        $roleSuperadmin = $this->superadmin('with-role');
        $this->assignRole($roleSuperadmin, 'viewer');

        return [
            $this->operator('kind-operator'),
            $this->viewer('kind-viewer'),
            (int)$this->createUserWithRole('kind-admin', 'admin')->id,
            $roleSuperadmin,
            $this->superadmin('kind-without-roles'),
            $this->inactive($this->operator('kind-inactive')),
            (int)$this->createUser('kind-plain')->id,
        ];
    }

    private function template(): JobTemplate
    {
        $owner = (int)$this->createUser('owner')->id;

        return $this->createJobTemplate(
            (int)$this->createProject($owner)->id,
            (int)$this->createInventory($owner)->id,
            (int)$this->createRunnerGroup($owner)->id,
            $owner
        );
    }

    /**
     * The approvers ApproverEligibility finds for a pending request of a
     * job of $template, launched by $launcher, that waits for a rule of
     * $type with $config.
     *
     * @param array<string, mixed> $config
     * @return list<int>
     */
    private function eligibleFor(JobTemplate $template, int $launcher, string $type, array $config): array
    {
        $rule = $this->createApprovalRule((int)$template->created_by, $type, (string)json_encode($config));
        $job = $this->createJob((int)$template->id, $launcher, Job::STATUS_PENDING_APPROVAL);
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = (int)$rule->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->save(false);
        $fresh = ApprovalRequest::findOne($request->id);
        $this->assertNotNull($fresh);
        /** @var ApproverEligibility $eligibility */
        $eligibility = \Yii::$app->get('approverEligibility');

        return $eligibility->eligibleForRequest($fresh);
    }

    /**
     * The form's input for $count roles, teams and users, each role and team
     * with one operator, and $count operators.
     *
     * @return array{0: list<string>, 1: list<int>, 2: list<int>}
     */
    private function formInput(int $count): array
    {
        $owner = (int)$this->createUser('owner')->id;
        $roles = [];
        $teams = [];
        $users = [];
        for ($i = 0; $i < $count; $i++) {
            $role = $this->newRole('cost', true);
            $this->userWithRole($role);
            $roles[] = $role->name;
            $team = (int)$this->createTeam($owner)->id;
            $this->addTeamMember($team, $this->operator('cost-member'));
            $teams[] = $team;
            $users[] = $this->operator('cost-user');
        }

        return [$roles, $teams, $users];
    }
}
