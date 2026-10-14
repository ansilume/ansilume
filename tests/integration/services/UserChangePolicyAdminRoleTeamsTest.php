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
use app\tests\integration\TeamScopeFixtures;
use yii\rbac\DbManager;

/**
 * The refusal of a user whose roles only the admin role keeps from the
 * signed-in user, where the user's teams refuse the change as well.
 *
 * regression (wave 5g): UserChangePolicy::refusalOf() answered
 * CHANGE_REFUSED ("they are a superadmin, or a role assigned to them grants
 * permissions you do not hold") where the roles refused for the admin role
 * alone and the team rule refused too: to a holder of every permission of
 * the admin role, through a role of their own, for an admin whose teams
 * give them a project the holder's teams do not give, or give only with a
 * lower role. Neither half of that text is true for them. The admin role is
 * all they lack: holding it would lift the team rule as well
 * (RoleGrantPolicy::reachesEveryProject()), so the answer is
 * ADMIN_ROLE_CHANGE_REFUSED wherever only the admin role keeps the user's
 * roles from them, whatever the teams. Every other answer stays, and so
 * does the order: the roles are weighed before the teams.
 *
 * The holders of these tests hold a custom role with every permission of
 * the admin role, but not the admin role; one is in a team that views the
 * project "held", the other in no team. Every user they are asked about is
 * in a team that has the project "beyond", which no holder's teams give, or
 * operates "held". Run with the default role viewer of the web application
 * (config/web.php). UserChangePolicyTest holds the rest of the rule;
 * UserChangeAdminRoleTeamsTest the pages and the API.
 */
final class UserChangePolicyAdminRoleTeamsTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /** @var list<string> the default roles of the manager before the test */
    private array $savedDefaultRoles = [];

    /** An admin who creates the projects and the teams. */
    private User $creator;

    /** @var array{held: Project, beyond: Project} the team-restricted projects of the tests */
    private array $projects;

    protected function setUp(): void
    {
        parent::setUp();
        $auth = $this->auth();
        $this->savedDefaultRoles = array_map('strval', $auth->defaultRoles);
        $auth->defaultRoles = ['viewer'];
        $this->creator = $this->userWithRoles('admin-teams-creator', ['admin']);
        $this->projects = [
            'held' => $this->createProject((int)$this->creator->id),
            'beyond' => $this->createProject((int)$this->creator->id),
        ];
    }

    protected function tearDown(): void
    {
        $this->auth()->defaultRoles = $this->savedDefaultRoles;
        parent::tearDown();
    }

    /**
     * regression: for a user whose roles only the admin role keeps from the
     * holder, the policy answered CHANGE_REFUSED where the user's teams
     * refused as well, ADMIN_ROLE_CHANGE_REFUSED only where they did not.
     * It answers ADMIN_ROLE_CHANGE_REFUSED for the admin role and for a
     * role that contains it, beside a role the holder may assign, whether
     * the user's team has a project the holder's teams do not give or
     * operates one they give at most as viewer, and whether the holder is
     * in a team or not. A superadmin and a role that grants a permission the
     * holder does not hold still answer CHANGE_REFUSED, and a viewer in the
     * same kind of team, whom the teams alone refuse, TEAM_ACCESS_REFUSED.
     * mayChange() and mayChangeEach() refuse each of them, as before. Fails
     * on the policy from before, which answered CHANGE_REFUSED for the
     * first four.
     */
    public function testRegressionOnlyTheAdminRoleRefusingAnswersAdminRoleChangeRefusedWhateverTheTeams(): void
    {
        $inTeam = $this->userWithRoles('admin-teams-holder', [$this->allOfAdminRole()]);
        $this->joinTeam($inTeam, ['held' => TeamProject::ROLE_VIEWER]);
        $holders = [
            'a holder whose team views a project' => $inTeam,
            'a holder in no team' => $this->userWithRoles('admin-teams-lone-holder', [$this->allOfAdminRole()]),
        ];
        $adminRoleTargets = $this->adminRoleTargets();
        $targets = $adminRoleTargets + $this->otherTargets();
        $expected = array_fill_keys(array_keys($adminRoleTargets), UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED) + [
            'an admin with a role that grants a permission the holder does not hold, in such a team' => UserChangePolicy::CHANGE_REFUSED,
            'a superadmin with the admin role in such a team' => UserChangePolicy::CHANGE_REFUSED,
            'a viewer whose team has a project the holder\'s teams do not give' => UserChangePolicy::TEAM_ACCESS_REFUSED,
            'a viewer whose team operates a project the holder\'s teams give at most as viewer' => UserChangePolicy::TEAM_ACCESS_REFUSED,
        ];
        $access = \Yii::$app->get('projectAccessChecker');
        $this->assertInstanceOf(ProjectAccessChecker::class, $access);

        foreach ($holders as $who => $holder) {
            $this->assertFalse($access->canView((int)$holder->id, (int)$this->projects['beyond']->id), "{$who}: precondition, the holder cannot see the project beyond");
            $policy = $this->policyOf($holder);
            $this->assertSame($expected, $this->refusalsOf($policy, $targets), $who);
            $this->assertSame(array_fill_keys(array_keys($targets), false), array_map(static fn (User $user): bool => $policy->mayChange($user), $targets), "{$who}: mayChange()");
            $this->assertSame(array_fill_keys($this->idsOf($targets), false), $this->policyOf($holder)->mayChangeEach(array_values($targets)), "{$who}: mayChangeEach()");
        }
    }

    /**
     * What ADMIN_ROLE_CHANGE_REFUSED tells the holder is true for those
     * users: an admin who is no superadmin and in no team, as the text
     * names, may change each of them, as the admin role lifts the team rule.
     * A help desk that lacks permissions of the admin role, and nobody
     * signed in, are refused with CHANGE_REFUSED.
     */
    public function testTheAdminRoleTheRefusalNamesWouldLetThemChangeEachOfThoseUsers(): void
    {
        $targets = $this->adminRoleTargets();
        $admin = $this->userWithRoles('admin-teams-admin', ['admin']);
        $helpDesk = $this->userWithRoles('admin-teams-help-desk', [$this->roleOf(['user.view', 'user.update'], 'help-desk')]);
        $nobody = new UserChangePolicy(new RoleGrantPolicy($this->auth(), null), AssignedRoles::fromApp());

        $this->assertSame(array_fill_keys(array_keys($targets), null), $this->refusalsOf($this->policyOf($admin), $targets), 'an admin in no team');
        $refused = array_fill_keys(array_keys($targets), UserChangePolicy::CHANGE_REFUSED);
        $this->assertSame($refused, $this->refusalsOf($this->policyOf($helpDesk), $targets), 'a help desk that lacks permissions of the admin role');
        $this->assertSame($refused, $this->refusalsOf($nobody, $targets), 'nobody signed in');
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * The users whose roles only the admin role keeps from the holders, each
     * in a team the holders' teams do not cover, by a description.
     *
     * @return array<string, User>
     */
    private function adminRoleTargets(): array
    {
        $beyond = ['beyond' => TeamProject::ROLE_VIEWER];

        return [
            'an admin whose team has a project the holder\'s teams do not give' => $this->member('admin-teams-admin-beyond', ['admin'], $beyond),
            'an admin whose team operates a project the holder\'s teams give at most as viewer' => $this->member('admin-teams-admin-held', ['admin'], ['held' => TeamProject::ROLE_OPERATOR]),
            'a user of a role that contains the admin role, in such a team' => $this->member('admin-teams-wrapped', [$this->roleContaining('admin', 'wraps-admin')], $beyond),
            'an admin and operator in such a team' => $this->member('admin-teams-admin-operator', ['admin', 'operator'], $beyond),
        ];
    }

    /**
     * The users in such teams whom the holders are refused for more than
     * the admin role, or for their teams alone, by a description.
     *
     * @return array<string, User>
     */
    private function otherTargets(): array
    {
        $auth = $this->auth();
        $beyondPermission = $auth->createPermission('test.beyond-' . substr(md5(uniqid('', true)), 0, 8));
        $auth->add($beyondPermission);
        $beyond = ['beyond' => TeamProject::ROLE_VIEWER];
        $superadmin = $this->member('admin-teams-superadmin', ['admin'], $beyond);
        $superadmin->is_superadmin = true;
        $superadmin->save(false);

        return [
            'an admin with a role that grants a permission the holder does not hold, in such a team' => $this->member('admin-teams-beyond-perm', ['admin',$this->roleOf([$beyondPermission->name], 'beyond')], $beyond),
            'a superadmin with the admin role in such a team' => $superadmin,
            'a viewer whose team has a project the holder\'s teams do not give' => $this->member('admin-teams-viewer-beyond', ['viewer'], $beyond),
            'a viewer whose team operates a project the holder\'s teams give at most as viewer' => $this->member('admin-teams-viewer-held', ['viewer'], ['held' => TeamProject::ROLE_OPERATOR]),
        ];
    }

    /**
     * A user assigned $roles, in a new team that has the projects of $team.
     *
     * @param list<string> $roles
     * @param array<string, string> $team key of the projects => role
     */
    private function member(string $suffix, array $roles, array $team): User
    {
        $user = $this->userWithRoles($suffix, $roles);
        $this->joinTeam($user, $team);

        return $user;
    }

    /**
     * Puts $user in a new team that has the projects of $team (key of the
     * projects => role).
     *
     * @param array<string, string> $team
     */
    private function joinTeam(User $user, array $team): void
    {
        $created = $this->createTeam((int)$this->creator->id);
        foreach ($team as $key => $role) {
            $this->createTeamProject((int)$created->id, (int)$this->projects[$key]->id, $role);
        }
        $this->addTeamMember((int)$created->id, (int)$user->id);
    }

    /**
     * @param array<string, User> $users
     * @return array<string, string|null> what refusalOf() answers for each of $users
     */
    private function refusalsOf(UserChangePolicy $policy, array $users): array
    {
        return array_map(static fn (User $user): ?string => $policy->refusalOf($user), $users);
    }

    /**
     * @param array<string, User> $users
     * @return list<int>
     */
    private function idsOf(array $users): array
    {
        return array_values(array_map(static fn (User $user): int => (int)$user->id, $users));
    }

    private function policyOf(User $actor): UserChangePolicy
    {
        return new UserChangePolicy(new RoleGrantPolicy($this->auth(), $actor), AssignedRoles::fromApp());
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
