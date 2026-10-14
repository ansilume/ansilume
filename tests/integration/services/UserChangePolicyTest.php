<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Project;
use app\models\TeamProject;
use app\models\User;
use app\services\AssignedRoles;
use app\services\ProjectAccessChecker;
use app\services\RoleGrantPolicy;
use app\services\UserChangePolicy;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\CountsQueries;
use app\tests\integration\TeamScopeFixtures;
use yii\rbac\DbManager;

/**
 * UserChangePolicy decides who may change or delete which user: a
 * superadmin everyone; anyone else a user who is no superadmin and all of
 * whose assigned roles they may assign (RoleGrantPolicy::mayAssign()); nobody
 * without a signed-in user. A user manager who reaches not every project
 * (neither a superadmin nor a holder of the admin role) changes a user only
 * when their own teams give them every project the user's teams give the
 * user, with at least the same role. Run with the default role viewer of the
 * web application (config/web.php), which every signed-in user holds.
 *
 * The help desk of these tests holds a custom role with user.view and
 * user.update, as an admin can build in the role editor: it holds the
 * viewer's permissions through the default role and its own, nothing of
 * the operator's. The controllers' regression tests are
 * UserControllerChangeTest and UsersControllerChangeTest.
 */
final class UserChangePolicyTest extends WebControllerTestCase
{
    use CountsQueries;
    use TeamScopeFixtures;

    /** @var list<string> the default roles of the manager before the test */
    private array $savedDefaultRoles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $auth = $this->auth();
        $this->savedDefaultRoles = array_map('strval', $auth->defaultRoles);
        $auth->defaultRoles = ['viewer'];
    }

    protected function tearDown(): void
    {
        $this->auth()->defaultRoles = $this->savedDefaultRoles;
        parent::tearDown();
    }

    public function testASuperadminMayChangeEveryUser(): void
    {
        $policy = $this->policyOf($this->superadmin('change-superadmin'));
        $users = $this->everyKindOfUser();

        foreach ($users as $kind => $user) {
            $this->assertTrue($policy->mayChange($user), $kind);
        }
        $this->assertSame(array_fill_keys($this->idsOf($users), true), $policy->mayChangeEach(array_values($users)));
    }

    /**
     * An admin holds every permission, so every role is theirs to assign:
     * they may change every user but a superadmin, whatever the
     * superadmin's roles.
     */
    public function testAnAdminWhoIsNoSuperadminMayChangeEveryoneButASuperadmin(): void
    {
        $policy = $this->policyOf($this->userWithRoles('change-admin', ['admin']));

        $expected = [
            'a superadmin with the admin role' => false,
            'a superadmin without a role' => false,
            'an admin' => true,
            'an operator' => true,
            'a viewer' => true,
            'a user without a role' => true,
            'a user with a custom role that launches jobs' => true,
            'a viewer and an operator' => true,
        ];
        $this->assertSame($expected, $this->answersOf($policy, $this->everyKindOfUser()));
    }

    /**
     * The help desk may change a user only when it may assign every role
     * assigned to them: a viewer, a user without a role, itself and a user
     * of its own role, not an operator, an admin, a superadmin, or a viewer
     * who is an operator too.
     */
    public function testAHelpDeskMayChangeOnlyUsersAllOfWhoseRolesItMayAssign(): void
    {
        $helpDeskRole = $this->roleOf(['user.view', 'user.update'], 'help-desk');
        $helpDesk = $this->userWithRoles('change-help-desk', [$helpDeskRole]);
        $policy = $this->policyOf($helpDesk);

        $expected = [
            'a superadmin with the admin role' => false,
            'a superadmin without a role' => false,
            'an admin' => false,
            'an operator' => false,
            'a viewer' => true,
            'a user without a role' => true,
            'a user with a custom role that launches jobs' => false,
            'a viewer and an operator' => false,
        ];
        $this->assertSame($expected, $this->answersOf($policy, $this->everyKindOfUser()));
        $this->assertTrue($policy->mayChange($helpDesk), 'itself');
        $this->assertTrue($policy->mayChange($this->userWithRoles('change-colleague', [$helpDeskRole])), 'a user of its own role');
        $viewerLike = $this->roleOf(['job.view'], 'viewer-like');
        $this->assertTrue($policy->mayChange($this->userWithRoles('change-viewer-like', [$viewerLike])), 'a role granting only what viewer grants');
    }

    public function testNobodyIsChangedWithoutASignedInUser(): void
    {
        $policy = new UserChangePolicy(new RoleGrantPolicy($this->auth(), null), AssignedRoles::fromApp());
        $none = $this->createUser('change-nobody-none');

        $this->assertFalse($policy->mayChange($none), 'not even a user without a role');
        $this->assertFalse($policy->mayChange($this->userWithRoles('change-nobody-viewer', ['viewer'])));
        $this->assertSame([(int)$none->id => false], $policy->mayChangeEach([$none]));
    }

    public function testForCurrentUserIsThePolicyOfTheSignedInUser(): void
    {
        $viewer = $this->userWithRoles('change-current-viewer', ['viewer']);
        $admin = $this->userWithRoles('change-current-admin', ['admin']);
        $this->assertFalse(UserChangePolicy::forCurrentUser()->mayChange($viewer), 'signed out');

        $this->loginAs($this->userWithRoles('change-current-help-desk', [$this->roleOf(['user.view', 'user.update'], 'help-desk')]));

        $this->assertTrue(UserChangePolicy::forCurrentUser()->mayChange($viewer));
        $this->assertFalse(UserChangePolicy::forCurrentUser()->mayChange($admin));
        $grants = RoleGrantPolicy::forCurrentUser();
        $this->assertTrue(UserChangePolicy::of($grants)->mayChange($viewer), 'of() decides for the user of $grants');
        $this->assertFalse(UserChangePolicy::of($grants)->mayChange($admin));
    }

    /**
     * mayChangeEach() answers what mayChange() answers, for every user of
     * the list by ID, in the order given; an empty list answers nothing.
     */
    public function testMayChangeEachAnswersAsMayChangeForEveryUser(): void
    {
        $helpDesk = $this->userWithRoles('change-each-help-desk', [$this->roleOf(['user.view', 'user.update'], 'help-desk')]);
        $users = array_reverse(array_values($this->everyKindOfUser()));
        $expected = [];
        foreach ($users as $user) {
            $expected[(int)$user->id] = $this->policyOf($helpDesk)->mayChange($user);
        }

        $this->assertSame($expected, $this->policyOf($helpDesk)->mayChangeEach($users));
        $this->assertContains(true, $expected);
        $this->assertContains(false, $expected);
        $this->assertSame([], $this->policyOf($helpDesk)->mayChangeEach([]));
    }

    /**
     * The users list asks for every user of a page at once: the roles of
     * all of them cost one query, and each role is decided once, so a list
     * of 5 and one of 30 users holding the same roles cost the same.
     */
    public function testMayChangeEachCostsTheSameFor5And30UsersOfTheSameRoles(): void
    {
        $helpDesk = $this->userWithRoles('change-cost-help-desk', [$this->roleOf(['user.view', 'user.update'], 'help-desk')]);
        $few = $this->usersOf(2, 2, 1);
        $many = $this->usersOf(12, 12, 6);
        $this->policyOf($helpDesk)->mayChangeEach($many);

        $fewQueries = $this->queriesOf(fn () => $this->policyOf($helpDesk)->mayChangeEach($few));

        $this->assertSame($fewQueries, $this->queriesOf(fn () => $this->policyOf($helpDesk)->mayChangeEach($many)));
        $answers = $this->policyOf($helpDesk)->mayChangeEach($many);
        $this->assertCount(30, $answers);
        $this->assertContains(true, $answers, 'precondition: users the help desk may change');
        $this->assertContains(false, $answers, 'and users it may not');
    }

    // -- Team access ----------------------------------------------------------

    /**
     * regression: a help desk {user.view, user.update} in no team could set
     * the password and the email of a viewer whose team has a restricted
     * project, then sign in as them and read the project's job logs, extra
     * vars and inventories, which it cannot see itself (the checker's probe
     * of wave 5c). Its roles allow the change; the teams do not.
     */
    public function testRegressionAHelpDeskInNoTeamMayNotChangeAViewerWhoseTeamHasARestrictedProject(): void
    {
        $scope = $this->teamScope('viewer');
        $member = $scope['member'];
        $member->refresh();
        $helpDesk = $this->userWithRoles('change-team-help-desk', [$this->roleOf(['user.view', 'user.update'], 'help-desk')]);
        $access = \Yii::$app->get('projectAccessChecker');
        $this->assertInstanceOf(ProjectAccessChecker::class, $access);
        $projectId = (int)$scope['own']->project_id;
        $this->assertFalse($access->canView((int)$helpDesk->id, $projectId), 'precondition: the help desk cannot see the project');
        $this->assertTrue($access->canView((int)$member->id, $projectId), 'precondition: the member can');
        $policy = $this->policyOf($helpDesk);

        $this->assertFalse($policy->mayChange($member));
        $this->assertSame(UserChangePolicy::TEAM_ACCESS_REFUSED, $policy->refusalOf($member));
        $this->assertSame([(int)$member->id => false], $policy->mayChangeEach([$member]));
    }

    /**
     * The teams of the member and of the user manager, by project: p1 and
     * p2 are restricted projects; each team is a map of project => role.
     *
     * @return array<string, array{0: list<array<string, string>>, 1: list<array<string, string>>, 2: bool}>
     */
    public static function teamAccessProvider(): array
    {
        $viewer = TeamProject::ROLE_VIEWER;
        $operator = TeamProject::ROLE_OPERATOR;

        return [
            'the user manager is in no team' => [[['p1' => $viewer]], [], false],
            'another team gives them the project with the same role' => [[['p1' => $viewer]], [['p1' => $viewer]], true],
            'another team gives them the project as operator, the member as viewer' => [[['p1' => $viewer]], [['p1' => $operator]], true],
            'as viewer, the member as operator' => [[['p1' => $operator]], [['p1' => $viewer]], false],
            'their team has another project only' => [[['p1' => $viewer]], [['p2' => $operator]], false],
            'one of the two projects of the member' => [[['p1' => $viewer, 'p2' => $viewer]], [['p1' => $operator]], false],
            'both projects of the member, from two teams' => [[['p1' => $viewer, 'p2' => $operator]], [['p1' => $viewer], ['p2' => $operator]], true],
            'the highest role of the member\'s teams counts' => [[['p1' => $viewer], ['p1' => $operator]], [['p1' => $viewer]], false],
            'the highest role of the user manager\'s teams counts' => [[['p1' => $operator]], [['p1' => $viewer], ['p1' => $operator]], true],
            'the member\'s team has no project' => [[[]], [], true],
            'the member is in no team' => [[], [], true],
        ];
    }

    /**
     * A user manager who reaches not every project changes a user whose
     * roles they may assign only when their teams give them every project
     * the user's teams give the user, with at least the same role, as
     * ProjectAccessChecker counts it: the highest role of their teams wins.
     *
     * @param list<array<string, string>> $memberTeams
     * @param list<array<string, string>> $managerTeams
     * @dataProvider teamAccessProvider
     */
    public function testAUserManagerChangesATeamMemberOnlyWithAtLeastTheMembersAccess(array $memberTeams, array $managerTeams, bool $expected): void
    {
        $admin = $this->userWithRoles('change-team-admin', ['admin']);
        $projects = ['p1' => $this->createProject((int)$admin->id), 'p2' => $this->createProject((int)$admin->id)];
        $member = $this->userWithRoles('change-team-member', ['viewer']);
        $helpDesk = $this->userWithRoles('change-team-manager', [$this->roleOf(['user.view', 'user.update'], 'help-desk')]);
        foreach ($memberTeams as $team) {
            $this->joinTeam($member, $team, $projects, (int)$admin->id);
        }
        foreach ($managerTeams as $team) {
            $this->joinTeam($helpDesk, $team, $projects, (int)$admin->id);
        }
        $policy = $this->policyOf($helpDesk);

        $this->assertSame($expected, $policy->mayChange($member));
        $this->assertSame($expected ? null : UserChangePolicy::TEAM_ACCESS_REFUSED, $policy->refusalOf($member));
        $this->assertSame([(int)$member->id => $expected], $this->policyOf($helpDesk)->mayChangeEach([$member]));
    }

    /**
     * A user manager in the member's own team has what the team gives the
     * member, and a user manager changes their own account whatever their
     * teams give them.
     */
    public function testAUserManagerInTheSameTeamMayChangeTheMemberAndThemselves(): void
    {
        $admin = $this->userWithRoles('change-same-admin', ['admin']);
        $project = $this->createProject((int)$admin->id);
        $member = $this->userWithRoles('change-same-member', ['viewer']);
        $helpDesk = $this->userWithRoles('change-same-manager', [$this->roleOf(['user.view', 'user.update'], 'help-desk')]);
        $team = $this->createTeam((int)$admin->id);
        $this->createTeamProject((int)$team->id, (int)$project->id, TeamProject::ROLE_OPERATOR);
        $this->addTeamMember((int)$team->id, (int)$member->id);
        $this->addTeamMember((int)$team->id, (int)$helpDesk->id);
        $policy = $this->policyOf($helpDesk);

        $this->assertNull($policy->refusalOf($member));
        $this->assertNull($policy->refusalOf($helpDesk), 'themselves');
        $this->assertSame([(int)$member->id => true, (int)$helpDesk->id => true], $policy->mayChangeEach([$member, $helpDesk]));
    }

    /**
     * The admin role gives every project, so admins change team members
     * whatever their own teams; so do superadmins.
     */
    public function testAdminsAndSuperadminsChangeTeamMembersWhateverTheirTeams(): void
    {
        $scope = $this->teamScope('viewer');
        $member = $scope['member'];
        $member->refresh();

        foreach (['an admin in no team' => $this->userWithRoles('change-team-any-admin', ['admin']), 'a superadmin' => $this->superadmin('change-team-any-superadmin')] as $kind => $actor) {
            $policy = $this->policyOf($actor);
            $this->assertNull($policy->refusalOf($member), $kind);
            $this->assertSame([(int)$member->id => true], $policy->mayChangeEach([$member]), $kind);
        }
    }

    /**
     * The roles are weighed first: a team member whose role the user
     * manager may not assign is refused for the role, also where the user
     * manager is in the same team.
     */
    public function testTheRolesAreWeighedBeforeTheTeams(): void
    {
        $admin = $this->userWithRoles('change-first-admin', ['admin']);
        $project = $this->createProject((int)$admin->id);
        $operator = $this->userWithRoles('change-first-operator', ['operator']);
        $outsider = $this->userWithRoles('change-first-outsider', ['viewer']);
        $helpDesk = $this->userWithRoles('change-first-manager', [$this->roleOf(['user.view', 'user.update'], 'help-desk')]);
        $this->joinTeam($operator, ['p' => TeamProject::ROLE_VIEWER], ['p' => $project], (int)$admin->id);
        $this->joinTeam($outsider, ['p' => TeamProject::ROLE_VIEWER], ['p' => $project], (int)$admin->id);
        $policy = $this->policyOf($helpDesk);

        $this->assertSame(UserChangePolicy::CHANGE_REFUSED, $policy->refusalOf($operator));
        $this->assertSame(UserChangePolicy::TEAM_ACCESS_REFUSED, $policy->refusalOf($outsider));
        $this->assertSame(UserChangePolicy::CHANGE_REFUSED, (new UserChangePolicy(new RoleGrantPolicy($this->auth(), null), AssignedRoles::fromApp()))->refusalOf($outsider), 'nobody signed in');
    }

    /**
     * regression: the admin role grants every team-restricted project on
     * top of its permissions, but a user who held every permission of the
     * admin role through a custom role, without the admin role, could
     * change an admin, whose password then signed them in with that access.
     */
    public function testRegressionEveryPermissionOfTheAdminRoleWithoutTheRoleChangesNoAdmin(): void
    {
        $policy = $this->policyOf($this->userWithRoles('change-all-of-admin', [$this->allOfAdminRole()]));
        $admin = $this->userWithRoles('change-all-of-admin-target', ['admin']);

        $this->assertFalse($policy->mayChange($admin));
        $this->assertSame(UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED, $policy->refusalOf($admin));
        $this->assertNull($policy->refusalOf($this->userWithRoles('change-all-of-admin-operator', ['operator'])), 'an operator in no team');
    }

    /**
     * regression: the holder of every permission of the admin role, through
     * a role of their own, but not of the admin role, was refused a user who
     * holds the admin role with CHANGE_REFUSED ("they are a superadmin, or
     * a role assigned to them grants permissions you do not hold"), untrue
     * for them: the admin role was the reason (W5e check, issue 2). Where
     * only the admin role refuses (RoleGrantPolicy::refusalOf():
     * ADMIN_ROLE_REFUSED for each role they may not assign), the answer is
     * ADMIN_ROLE_CHANGE_REFUSED whatever the teams: for the admin role, for
     * a role that contains it, beside roles they may assign, with teams
     * theirs cover and with teams theirs do not cover, as the admin role
     * would lift the team rule too. Every other answer stays: a superadmin
     * and a role that grants a permission they do not hold CHANGE_REFUSED,
     * the teams alone TEAM_ACCESS_REFUSED; a help desk and nobody signed in
     * CHANGE_REFUSED for an admin. mayChange() and mayChangeEach() answer
     * as before. Fails on the policy of wave 5e, which answered
     * CHANGE_REFUSED for the first four, and on that of wave 5f, which
     * answered it for the admin whose team has a project the holder's teams
     * do not give (regression of wave 5g; UserChangePolicyAdminRoleTeamsTest
     * holds the whole class of that case).
     */
    public function testRegressionOnlyTheAdminRoleRefusingAnswersAdminRoleChangeRefused(): void
    {
        $creator = $this->userWithRoles('change-why-creator', ['admin']);
        $projects = ['own' => $this->createProject((int)$creator->id), 'beyond' => $this->createProject((int)$creator->id)];
        $holder = $this->userWithRoles('change-why-holder', [$this->allOfAdminRole()]);
        $this->joinTeam($holder, ['own' => TeamProject::ROLE_OPERATOR], $projects, (int)$creator->id);
        $beyondPermission = $this->auth()->createPermission('test.beyond-' . substr(md5(uniqid('', true)), 0, 8));
        $this->auth()->add($beyondPermission);
        $superadmin = $this->userWithRoles('change-why-superadmin', ['admin']);
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $targets = [
            'an admin' => $this->userWithRoles('change-why-admin', ['admin']),
            'a user of a role that contains the admin role' => $this->userWithRoles('change-why-wrapped', [$this->roleContaining('admin', 'wraps-admin')]),
            'an admin and operator' => $this->userWithRoles('change-why-admin-operator', ['admin', 'operator']),
            'an admin whose team the holder\'s teams cover' => $this->userWithRoles('change-why-covered', ['admin']),
            'an admin with a role that grants a permission the holder does not hold' => $this->userWithRoles('change-why-beyond', ['admin', $this->roleOf([$beyondPermission->name], 'beyond')]),
            'a superadmin with the admin role' => $superadmin,
            'an admin whose team has a project the holder\'s teams do not give' => $this->userWithRoles('change-why-team-admin', ['admin']),
            'a viewer whose team has a project the holder\'s teams do not give' => $this->userWithRoles('change-why-team-viewer', ['viewer']),
            'an operator' => $this->userWithRoles('change-why-operator', ['operator']),
        ];
        $this->joinTeam($targets['an admin whose team the holder\'s teams cover'], ['own' => TeamProject::ROLE_VIEWER], $projects, (int)$creator->id);
        $this->joinTeam($targets['an admin whose team has a project the holder\'s teams do not give'], ['beyond' => TeamProject::ROLE_VIEWER], $projects, (int)$creator->id);
        $this->joinTeam($targets['a viewer whose team has a project the holder\'s teams do not give'], ['beyond' => TeamProject::ROLE_VIEWER], $projects, (int)$creator->id);
        $policy = $this->policyOf($holder);

        $expected = [
            'an admin' => UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED,
            'a user of a role that contains the admin role' => UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED,
            'an admin and operator' => UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED,
            'an admin whose team the holder\'s teams cover' => UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED,
            'an admin with a role that grants a permission the holder does not hold' => UserChangePolicy::CHANGE_REFUSED,
            'a superadmin with the admin role' => UserChangePolicy::CHANGE_REFUSED,
            'an admin whose team has a project the holder\'s teams do not give' => UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED,
            'a viewer whose team has a project the holder\'s teams do not give' => UserChangePolicy::TEAM_ACCESS_REFUSED,
            'an operator' => null,
        ];
        $this->assertSame($expected, array_map(static fn (User $user): ?string => $policy->refusalOf($user), $targets));
        $this->assertSame(array_map(static fn (?string $refusal): bool => $refusal === null, $expected), $this->answersOf($policy, $targets));
        $helpDesk = $this->userWithRoles('change-why-help-desk', [$this->roleOf(['user.view', 'user.update'], 'help-desk')]);
        $this->assertSame(UserChangePolicy::CHANGE_REFUSED, $this->policyOf($helpDesk)->refusalOf($targets['an admin']), 'a help desk that lacks permissions of the admin role');
        $nobody = new UserChangePolicy(new RoleGrantPolicy($this->auth(), null), AssignedRoles::fromApp());
        $this->assertSame(UserChangePolicy::CHANGE_REFUSED, $nobody->refusalOf($targets['an admin']), 'nobody signed in');
        $this->assertSame(UserChangePolicy::CHANGE_REFUSED, $nobody->refusalOf($this->createUser('change-why-none')), 'nobody signed in, a user without a role');
    }

    /**
     * The users list asks for a page of team members at once: their team
     * roles and the user manager's cost one query, so a list of 5 and one
     * of 30 members of the same teams cost the same.
     */
    public function testMayChangeEachCostsTheSameFor5And30TeamMembers(): void
    {
        $admin = $this->userWithRoles('change-team-cost-admin', ['admin']);
        $projects = ['p1' => $this->createProject((int)$admin->id), 'p2' => $this->createProject((int)$admin->id)];
        $helpDesk = $this->userWithRoles('change-team-cost-manager', [$this->roleOf(['user.view', 'user.update'], 'help-desk')]);
        $this->joinTeam($helpDesk, ['p1' => TeamProject::ROLE_OPERATOR], $projects, (int)$admin->id);
        $covered = $this->createTeam((int)$admin->id);
        $this->createTeamProject((int)$covered->id, (int)$projects['p1']->id, TeamProject::ROLE_VIEWER);
        $beyond = $this->createTeam((int)$admin->id);
        $this->createTeamProject((int)$beyond->id, (int)$projects['p2']->id, TeamProject::ROLE_VIEWER);
        $members = [];
        for ($i = 0; $i < 30; $i++) {
            $member = $this->userWithRoles('change-team-cost-member', ['viewer']);
            $this->addTeamMember((int)($i % 2 === 0 ? $covered->id : $beyond->id), (int)$member->id);
            $members[] = $member;
        }
        $few = array_slice($members, 0, 5);
        $this->policyOf($helpDesk)->mayChangeEach($members);

        $fewQueries = $this->queriesOf(fn () => $this->policyOf($helpDesk)->mayChangeEach($few));

        $this->assertSame($fewQueries, $this->queriesOf(fn () => $this->policyOf($helpDesk)->mayChangeEach($members)));
        $answers = $this->policyOf($helpDesk)->mayChangeEach($members);
        $this->assertCount(30, $answers);
        $this->assertSame(15, count(array_filter($answers)), 'the members of the team it covers');
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * Puts $user in a new team that has the projects of $team (key of
     * $projects => role).
     *
     * @param array<string, string> $team
     * @param array<string, Project> $projects
     */
    private function joinTeam(User $user, array $team, array $projects, int $createdBy): void
    {
        $created = $this->createTeam($createdBy);
        foreach ($team as $key => $role) {
            $this->createTeamProject((int)$created->id, (int)$projects[$key]->id, $role);
        }
        $this->addTeamMember((int)$created->id, (int)$user->id);
    }

    /**
     * One user of every kind the rule tells apart, by a description.
     *
     * @return array<string, User>
     */
    private function everyKindOfUser(): array
    {
        $superadmin = $this->userWithRoles('change-target-superadmin', ['admin']);
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $bareSuperadmin = $this->createUser('change-target-bare-superadmin');
        $bareSuperadmin->is_superadmin = true;
        $bareSuperadmin->save(false);

        return [
            'a superadmin with the admin role' => $superadmin,
            'a superadmin without a role' => $bareSuperadmin,
            'an admin' => $this->userWithRoles('change-target-admin', ['admin']),
            'an operator' => $this->userWithRoles('change-target-operator', ['operator']),
            'a viewer' => $this->userWithRoles('change-target-viewer', ['viewer']),
            'a user without a role' => $this->createUser('change-target-none'),
            'a user with a custom role that launches jobs' => $this->userWithRoles('change-target-launcher', [$this->roleOf(['job.view', 'job.launch'], 'launcher')]),
            'a viewer and an operator' => $this->userWithRoles('change-target-both', ['viewer', 'operator']),
        ];
    }

    /**
     * @param array<string, User> $users
     * @return array<string, bool>
     */
    private function answersOf(UserChangePolicy $policy, array $users): array
    {
        $answers = array_map(static fn (User $user): bool => $policy->mayChange($user), $users);
        $each = $policy->mayChangeEach(array_values($users));
        foreach ($users as $kind => $user) {
            $this->assertSame($answers[$kind], $each[(int)$user->id], "mayChangeEach() for {$kind}");
        }

        return $answers;
    }

    /**
     * @param array<string, User> $users
     * @return list<int>
     */
    private function idsOf(array $users): array
    {
        return array_values(array_map(static fn (User $user): int => (int)$user->id, $users));
    }

    /**
     * $operators operators, $viewers viewers and $none users without a role.
     *
     * @return list<User>
     */
    private function usersOf(int $operators, int $viewers, int $none): array
    {
        $users = [];
        for ($i = 0; $i < $operators; $i++) {
            $users[] = $this->userWithRoles('change-cost-operator', ['operator']);
        }
        for ($i = 0; $i < $viewers; $i++) {
            $users[] = $this->userWithRoles('change-cost-viewer', ['viewer']);
        }
        for ($i = 0; $i < $none; $i++) {
            $users[] = $this->createUser('change-cost-none');
        }

        return $users;
    }

    private function policyOf(User $actor): UserChangePolicy
    {
        return new UserChangePolicy(new RoleGrantPolicy($this->auth(), $actor), AssignedRoles::fromApp());
    }

    private function superadmin(string $suffix): User
    {
        $user = $this->createUser($suffix);
        $user->is_superadmin = true;
        $user->save(false);

        return $user;
    }

    /**
     * A user assigned $roles, read back as the session reads them.
     *
     * @param list<string> $roles
     */
    private function userWithRoles(string $suffix, array $roles): User
    {
        $user = $this->createUser($suffix);
        foreach ($roles as $role) {
            $this->assignRole($user->id, $role);
        }
        $user->refresh();

        return $user;
    }

    /**
     * A custom role holding every permission of the admin role, but not the
     * admin role itself.
     */
    private function allOfAdminRole(): string
    {
        return $this->roleOf(array_map('strval', array_keys($this->auth()->getPermissionsByRole('admin'))), 'all-of-admin');
    }

    /**
     * A custom role named test-$prefix-<random> whose only child is the
     * role $child.
     */
    private function roleContaining(string $child, string $prefix): string
    {
        $auth = $this->auth();
        $name = $this->roleOf([], $prefix);
        $parent = $auth->getRole($name);
        $childRole = $auth->getRole($child);
        $this->assertNotNull($parent, $name);
        $this->assertNotNull($childRole, $child);
        $auth->addChild($parent, $childRole);

        return $name;
    }

    /**
     * A custom role named test-$prefix-<random> holding $permissions.
     *
     * @param list<string> $permissions
     */
    private function roleOf(array $permissions, string $prefix): string
    {
        $auth = $this->auth();
        $role = $auth->createRole('test-' . $prefix . '-' . substr(md5(uniqid('', true)), 0, 8));
        $auth->add($role);
        foreach ($permissions as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }

        return $role->name;
    }

    private function auth(): DbManager
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $auth);

        return $auth;
    }
}
