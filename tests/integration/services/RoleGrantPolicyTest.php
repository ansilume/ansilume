<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\User;
use app\services\RoleGrantPolicy;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\CountsQueries;
use app\tests\integration\TeamScopeFixtures;
use yii\rbac\DbManager;
use yii\rbac\Permission;
use yii\rbac\Role;

/**
 * RoleGrantPolicy decides who may give a user a role or the superadmin flag:
 * a superadmin everything; anyone else a role only when they hold every
 * permission the role grants, through its child roles too, with the default
 * roles counting as they count wherever access is checked; nobody without a
 * signed-in user. The admin role grants every team-restricted project on top
 * of its permissions, so it, and a role that contains it, is given only by a
 * superadmin or a holder of the admin role. Run with the default role viewer
 * of the web application (config/web.php) unless a test says otherwise.
 */
final class RoleGrantPolicyTest extends WebControllerTestCase
{
    use CountsQueries;
    use TeamScopeFixtures;

    /** The permissions of the help desk role of these tests: managing accounts, nothing else. */
    private const HELP_DESK_PERMISSIONS = ['user.view', 'user.update'];

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

    public function testASuperadminMayAssignEveryRoleAndChangeTheFlag(): void
    {
        $superadmin = $this->createUser('grant-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $policy = new RoleGrantPolicy($this->auth(), $superadmin);

        $this->assertTrue($policy->isSuperadmin());
        foreach (['viewer', 'operator', 'admin', $this->helpDeskRole()->name] as $role) {
            $this->assertTrue($policy->mayAssign($role), $role);
        }
    }

    /**
     * A help desk role (user.view, user.update) holds the viewer's
     * permissions through the default role, and its own: it may assign
     * viewer and itself, neither operator nor admin. (Up to 2.10.1 whoever
     * held user.update could assign every role: the regression tests are
     * UserControllerActionTest's and UsersControllerTest's.)
     */
    public function testAnyoneElseMayAssignOnlyARoleWhosePermissionsTheyAllHold(): void
    {
        $helpDesk = $this->helpDeskRole();
        $policy = new RoleGrantPolicy($this->auth(), $this->userWithRole('grant-help-desk', $helpDesk->name));

        $this->assertFalse($policy->isSuperadmin());
        $this->assertTrue($policy->mayAssign('viewer'), 'viewer: held through the default role');
        $this->assertTrue($policy->mayAssign($helpDesk->name), 'the help desk role: held through the assignment');
        $this->assertFalse($policy->mayAssign('operator'), 'operator grants job.launch, which the help desk lacks');
        $this->assertFalse($policy->mayAssign('admin'));
    }

    public function testAnAdminHoldsEveryPermissionAndMayAssignEveryRole(): void
    {
        $policy = new RoleGrantPolicy($this->auth(), $this->userWithRole('grant-admin', 'admin'));

        $this->assertFalse($policy->isSuperadmin(), 'an admin is no superadmin');
        foreach (['viewer', 'operator', 'admin', $this->helpDeskRole()->name] as $role) {
            $this->assertTrue($policy->mayAssign($role), $role);
        }
    }

    /**
     * The permissions of a role's child roles count: a role that only
     * contains operator grants every operator permission.
     */
    public function testThePermissionsOfChildRolesCount(): void
    {
        $auth = $this->auth();
        $wrapsOperator = $this->newRole('wraps-operator');
        $auth->addChild($wrapsOperator, $this->role('operator'));
        $wrapsViewer = $this->newRole('wraps-viewer');
        $auth->addChild($wrapsViewer, $this->role('viewer'));
        $policy = new RoleGrantPolicy($auth, $this->userWithRole('grant-children', $this->helpDeskRole()->name));

        $this->assertFalse($policy->mayAssign($wrapsOperator->name), 'grants job.launch through operator');
        $this->assertTrue($policy->mayAssign($wrapsViewer->name), 'grants only what viewer grants');
    }

    /**
     * The default roles count for the user who assigns as they count
     * wherever access is checked: without the default role viewer, the help
     * desk holds none of the viewer's permissions.
     */
    public function testTheDefaultRolesCountForTheUserWhoAssigns(): void
    {
        $actor = $this->userWithRole('grant-default-role', $this->helpDeskRole()->name);
        $this->assertTrue((new RoleGrantPolicy($this->auth(), $actor))->mayAssign('viewer'));

        $this->auth()->defaultRoles = [];
        $this->assertFalse((new RoleGrantPolicy($this->auth(), $actor))->mayAssign('viewer'));
    }

    /**
     * A permission is held as checkAccess() decides: one the user holds only
     * through a role whose business rule does not pass for them is not held.
     */
    public function testAPermissionBehindABusinessRuleThatFailsIsNotHeld(): void
    {
        $auth = $this->auth();
        $actor = $this->userWithRole('grant-rule', $this->helpDeskRole()->name);
        $rule = new ListedUsersRbacRule();
        $rule->name = 'test-grant-listed-users-' . uniqid();
        $rule->userIds = [];
        $auth->add($rule);
        $ruled = $this->newRole('ruled');
        $ruled->ruleName = $rule->name;
        $auth->update($ruled->name, $ruled);
        $auth->addChild($ruled, $this->permission('job.launch'));
        $auth->assign($ruled, (string)$actor->id);
        $needsLaunch = $this->newRole('needs-launch');
        $auth->addChild($needsLaunch, $this->permission('job.launch'));

        $this->assertFalse((new RoleGrantPolicy($auth, $actor))->mayAssign($needsLaunch->name));
    }

    /**
     * A role without permissions grants nothing the user who assigns lacks.
     */
    public function testARoleWithoutPermissionsMayBeAssignedByAnyoneSignedIn(): void
    {
        $empty = $this->newRole('empty');
        $policy = new RoleGrantPolicy($this->auth(), $this->userWithRole('grant-empty', $this->helpDeskRole()->name));

        $this->assertTrue($policy->mayAssign($empty->name));
    }

    public function testWithoutAUserNobodyMayAssignAnything(): void
    {
        $empty = $this->newRole('nobody');
        $policy = new RoleGrantPolicy($this->auth(), null);

        $this->assertFalse($policy->hasActor());
        $this->assertTrue((new RoleGrantPolicy($this->auth(), $this->userWithRole('grant-actor', 'viewer')))->hasActor());
        $this->assertFalse($policy->isSuperadmin());
        foreach (['viewer', 'operator', 'admin', $empty->name] as $role) {
            $this->assertFalse($policy->mayAssign($role), $role);
        }
        $this->assertSame([], $policy->assignable(['viewer' => 'Viewer', $empty->name => $empty->name], null));
    }

    public function testARoleTheInstanceDoesNotHaveIsNeverAssignable(): void
    {
        $superadmin = $this->createUser('grant-unknown');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);

        $this->assertFalse((new RoleGrantPolicy($this->auth(), $superadmin))->mayAssign('no-such-role-' . uniqid()));
    }

    /**
     * assignable() keeps the order of the options and the role a form shows
     * as the user's, also where the user who assigns may not assign it.
     */
    public function testAssignableKeepsTheOrderAndTheKeptRole(): void
    {
        $helpDesk = $this->helpDeskRole();
        $options = ['viewer' => 'Viewer', 'operator' => 'Operator', 'admin' => 'Admin', $helpDesk->name => 'Help desk'];
        $policy = new RoleGrantPolicy($this->auth(), $this->userWithRole('grant-choices', $helpDesk->name));

        $this->assertSame(['viewer' => 'Viewer', $helpDesk->name => 'Help desk'], $policy->assignable($options, null));
        $this->assertSame(
            ['viewer' => 'Viewer', 'operator' => 'Operator', $helpDesk->name => 'Help desk'],
            $policy->assignable($options, 'operator'),
            'the user\'s role stays, in its place'
        );
        $superadmin = $this->createUser('grant-choices-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $this->assertSame($options, (new RoleGrantPolicy($this->auth(), $superadmin))->assignable($options, null));
    }

    public function testForCurrentUserIsThePolicyOfTheSignedInUser(): void
    {
        $helpDesk = $this->helpDeskRole();
        $this->loginAs($this->userWithRole('grant-current', $helpDesk->name));

        $policy = RoleGrantPolicy::forCurrentUser();

        $this->assertFalse($policy->isSuperadmin());
        $this->assertTrue($policy->mayAssign($helpDesk->name));
        $this->assertFalse($policy->mayAssign('admin'));

        $superadmin = $this->createUser('grant-current-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $this->loginAs($superadmin);
        $this->assertTrue(RoleGrantPolicy::forCurrentUser()->isSuperadmin());
    }

    /**
     * Signed out, or in an application without a user component, such as a
     * console command, the policy is nobody's.
     */
    public function testForCurrentUserWithoutASignedInUserIsNobodys(): void
    {
        $this->assertFalse(RoleGrantPolicy::forCurrentUser()->mayAssign('viewer'), 'signed out');

        \Yii::$app->set('user', null);
        $this->assertFalse(\Yii::$app->has('user'));
        $this->assertFalse(RoleGrantPolicy::forCurrentUser()->mayAssign('viewer'), 'no user component');
    }

    // -- The fast path: a role the actor holds ---------------------------------

    /**
     * regression: an admin who is no superadmin had every permission of a
     * role checked one by one, a few queries each: the Edit User page, which
     * asks for every role, cost 184 queries with the stock roles. Whoever
     * holds a role holds every permission it grants, so a role the admin
     * holds costs the same however many permissions it grants.
     */
    public function testRegressionARoleTheActorHoldsCostsTheSameHoweverManyPermissionsItGrants(): void
    {
        $admin = $this->userWithRole('grant-fast-admin', 'admin');
        $ask = fn (): bool => (new RoleGrantPolicy($this->auth(), $admin))->mayAssign('operator');
        $ask();
        $before = $this->queriesOf($ask);

        $this->addPermissionsTo('viewer', 20);
        $this->assertTrue($ask(), 'operator grants the new permissions through viewer');

        $this->assertSame($before, $this->queriesOf($ask));
    }

    /**
     * Whoever holds a role may give it, also where a business rule keeps one
     * of its permissions from them: the role grants nothing they do not hold
     * themselves. Who holds the permission only through another role whose
     * rule fails still may not (testAPermissionBehindABusinessRuleThatFailsIsNotHeld).
     */
    public function testARoleTheActorHoldsIsAssignableWhereARuleKeepsOneOfItsPermissionsFromThem(): void
    {
        $auth = $this->auth();
        $rule = new ListedUsersRbacRule();
        $rule->name = 'test-grant-listed-users-' . uniqid();
        $rule->userIds = [];
        $auth->add($rule);
        $ruled = $this->newPermission('ruled');
        $ruled->ruleName = $rule->name;
        $auth->update($ruled->name, $ruled);
        $holds = $this->newRole('holds-ruled');
        $auth->addChild($holds, $ruled);
        $actor = $this->userWithRole('grant-rule-holder', $holds->name);

        $this->assertFalse($auth->checkAccess((string)$actor->id, $ruled->name), 'precondition: the rule keeps the permission from them');
        $this->assertTrue((new RoleGrantPolicy($auth, $actor))->mayAssign($holds->name));
    }

    /**
     * Every answer is kept: a list that asks for the same role once per user
     * costs the queries of the first question only.
     */
    public function testEachRoleIsDecidedOnce(): void
    {
        $policy = new RoleGrantPolicy($this->auth(), $this->userWithRole('grant-once', $this->helpDeskRole()->name));
        $this->assertFalse($policy->mayAssign('operator'));
        $this->assertTrue($policy->mayAssign('viewer'));

        $this->assertSame(0, $this->queriesOf(fn () => [$policy->mayAssign('operator'), $policy->mayAssign('viewer')]));
        $this->assertSame(0, $this->queriesOf(fn () => $policy->assignable(['viewer' => 'Viewer', 'operator' => 'Operator'], null)));
    }

    // -- The admin role --------------------------------------------------------

    /**
     * regression: a user who held every permission of the admin role
     * through a custom role, but not the admin role, could assign the admin
     * role, and with it every team-restricted project, which they do not
     * reach themselves; a role that contains the admin role too. Refused now
     * with ADMIN_ROLE_REFUSED; the other roles stay theirs to assign.
     */
    public function testRegressionEveryPermissionOfTheAdminRoleWithoutTheRoleDoesNotAssignIt(): void
    {
        $wrapsAdmin = $this->roleWrapping('admin', 'wraps-admin');
        $policy = new RoleGrantPolicy($this->auth(), $this->userWithRole('grant-all-of-admin', $this->allOfAdminRole()->name));

        $this->assertFalse($policy->mayAssign('admin'));
        $this->assertFalse($policy->mayAssign($wrapsAdmin->name), 'a role that contains it');
        $this->assertFalse($policy->reachesEveryProject(), 'no admin role');
        $this->assertSame(sprintf(RoleGrantPolicy::ADMIN_ROLE_REFUSED, 'admin'), $policy->refusalOf('admin'));
        $this->assertSame(sprintf(RoleGrantPolicy::ADMIN_ROLE_REFUSED, $wrapsAdmin->name), $policy->refusalOf($wrapsAdmin->name));
        $this->assertTrue($policy->mayAssign('operator'));
        $this->assertNull($policy->refusalOf('operator'));
        $options = ['viewer' => 'Viewer', 'operator' => 'Operator', 'admin' => 'Admin', $wrapsAdmin->name => 'Wraps admin'];
        $this->assertSame(['viewer' => 'Viewer', 'operator' => 'Operator'], $policy->assignable($options, null));
        $this->assertSame(['viewer' => 'Viewer', 'operator' => 'Operator', 'admin' => 'Admin'], $policy->assignable($options, 'admin'), 'the role a form shows stays');
    }

    /**
     * The admin role counts at any depth: a role that contains a role that
     * contains it.
     */
    public function testARoleThatContainsTheAdminRoleAtAnyDepthIsRefused(): void
    {
        $deep = $this->roleWrapping($this->roleWrapping('admin', 'wraps-admin')->name, 'wraps-wraps-admin');
        $wrapsOperator = $this->roleWrapping('operator', 'wraps-operator');
        $policy = new RoleGrantPolicy($this->auth(), $this->userWithRole('grant-deep', $this->allOfAdminRole()->name));

        $this->assertSame(sprintf(RoleGrantPolicy::ADMIN_ROLE_REFUSED, $deep->name), $policy->refusalOf($deep->name));
        $this->assertNull($policy->refusalOf($wrapsOperator->name), 'a role that contains operator only');
    }

    /**
     * Holders of the admin role, through an assignment or a role that
     * contains it, and superadmins reach every project and assign the
     * admin role and the roles that contain it.
     */
    public function testHoldersOfTheAdminRoleAndSuperadminsAssignIt(): void
    {
        $wrapsAdmin = $this->roleWrapping('admin', 'wraps-admin');
        $superadmin = $this->createUser('grant-admin-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $actors = [
            'an admin' => $this->userWithRole('grant-admin-holder', 'admin'),
            'a holder of a role that contains it' => $this->userWithRole('grant-wraps-holder', $wrapsAdmin->name),
            'a superadmin without a role' => $superadmin,
        ];

        foreach ($actors as $kind => $actor) {
            $policy = new RoleGrantPolicy($this->auth(), $actor);
            $this->assertTrue($policy->reachesEveryProject(), $kind);
            $this->assertNull($policy->refusalOf('admin'), $kind);
            $this->assertNull($policy->refusalOf($wrapsAdmin->name), $kind);
        }
    }

    /**
     * Who reaches every project: neither a help desk nor a user without a
     * signed-in user; the default roles count as wherever access is checked.
     */
    public function testWhoReachesEveryProject(): void
    {
        $helpDesk = $this->userWithRole('grant-reach-help-desk', $this->helpDeskRole()->name);
        $this->assertFalse((new RoleGrantPolicy($this->auth(), $helpDesk))->reachesEveryProject());
        $this->assertFalse((new RoleGrantPolicy($this->auth(), null))->reachesEveryProject());

        $this->auth()->defaultRoles = ['admin'];
        $this->assertTrue((new RoleGrantPolicy($this->auth(), $helpDesk))->reachesEveryProject(), 'admin as a default role');
    }

    /**
     * Why a role is refused: ROLE_REFUSED wherever a permission is missing,
     * also for the admin role; ADMIN_ROLE_REFUSED only where the admin role
     * alone keeps it; ROLE_REFUSED for a role the instance does not have and
     * without a signed-in user.
     */
    public function testTheRefusalSaysWhy(): void
    {
        $helpDesk = new RoleGrantPolicy($this->auth(), $this->userWithRole('grant-why-help-desk', $this->helpDeskRole()->name));
        $superadmin = $this->createUser('grant-why-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $unknown = 'no-such-role-' . uniqid();

        $this->assertNull($helpDesk->refusalOf('viewer'));
        $this->assertSame(sprintf(RoleGrantPolicy::ROLE_REFUSED, 'operator'), $helpDesk->refusalOf('operator'));
        $this->assertSame(sprintf(RoleGrantPolicy::ROLE_REFUSED, 'admin'), $helpDesk->refusalOf('admin'), 'it lacks permissions of the admin role');
        $this->assertSame(sprintf(RoleGrantPolicy::ROLE_REFUSED, $unknown), $helpDesk->refusalOf($unknown));
        $this->assertSame(sprintf(RoleGrantPolicy::ROLE_REFUSED, $unknown), (new RoleGrantPolicy($this->auth(), $superadmin))->refusalOf($unknown));
        $this->assertSame(sprintf(RoleGrantPolicy::ROLE_REFUSED, 'admin'), (new RoleGrantPolicy($this->auth(), null))->refusalOf('admin'), 'nobody signed in');
    }

    /**
     * An instance without the admin role assigns no role of that name, and
     * says so with ROLE_REFUSED: a superadmin, who holds everything, too.
     */
    public function testWithoutTheAdminRoleTheNameIsNoRole(): void
    {
        $auth = $this->auth();
        $admin = $this->role('admin');
        $auth->remove($admin);
        $superadmin = $this->createUser('grant-no-admin-role');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);

        $this->assertSame(sprintf(RoleGrantPolicy::ROLE_REFUSED, 'admin'), (new RoleGrantPolicy($auth, $superadmin))->refusalOf('admin'));
    }

    public function testActorIdIsTheIdOfTheUserWhoAssigns(): void
    {
        $actor = $this->userWithRole('grant-actor-id', 'viewer');

        $this->assertSame((int)$actor->id, (new RoleGrantPolicy($this->auth(), $actor))->actorId());
        $this->assertNull((new RoleGrantPolicy($this->auth(), null))->actorId());
    }

    /**
     * Whether a role contains the admin role is decided once per role, and
     * whether the actor holds the admin role once: a page that asks again
     * costs no query.
     */
    public function testTheAdminRoleIsLookedForOncePerRole(): void
    {
        $wrapsViewer = $this->roleWrapping('viewer', 'wraps-viewer');
        $policy = new RoleGrantPolicy($this->auth(), $this->userWithRole('grant-admin-once', $this->allOfAdminRole()->name));
        $ask = fn (): array => [$policy->mayAssign('viewer'), $policy->refusalOf('admin'), $policy->assignable(['viewer' => 'Viewer', $wrapsViewer->name => 'Wraps viewer', 'admin' => 'Admin'], null)];
        $first = $ask();

        $this->assertSame(0, $this->queriesOf($ask));
        $this->assertSame($first, $ask());
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * A custom role holding every permission of the admin role, but not
     * the admin role itself.
     */
    private function allOfAdminRole(): Role
    {
        $role = $this->newRole('all-of-admin');
        foreach (array_keys($this->auth()->getPermissionsByRole('admin')) as $permission) {
            $this->auth()->addChild($role, $this->permission((string)$permission));
        }

        return $role;
    }

    /**
     * A custom role whose only child is the role $child.
     */
    private function roleWrapping(string $child, string $prefix): Role
    {
        $role = $this->newRole($prefix);
        $this->auth()->addChild($role, $this->role($child));

        return $role;
    }

    /**
     * Adds $count new permissions to $roleName, inside the test's transaction.
     */
    private function addPermissionsTo(string $roleName, int $count): void
    {
        $role = $this->role($roleName);
        for ($i = 0; $i < $count; $i++) {
            $this->auth()->addChild($role, $this->newPermission('extra'));
        }
    }

    private function newPermission(string $prefix): Permission
    {
        $auth = $this->auth();
        $permission = $auth->createPermission('test.' . $prefix . '-' . substr(md5(uniqid('', true)), 0, 8));
        $auth->add($permission);

        return $permission;
    }

    /**
     * A custom role holding exactly HELP_DESK_PERMISSIONS, as an admin can
     * build in the role editor.
     */
    private function helpDeskRole(): Role
    {
        $role = $this->newRole('help-desk');
        foreach (self::HELP_DESK_PERMISSIONS as $permission) {
            $this->auth()->addChild($role, $this->permission($permission));
        }

        return $role;
    }

    private function userWithRole(string $suffix, string $role): User
    {
        $user = $this->createUserWithRole($suffix, $role);
        $user->refresh();

        return $user;
    }

    private function newRole(string $prefix): Role
    {
        $auth = $this->auth();
        $role = $auth->createRole('test-' . $prefix . '-' . substr(md5(uniqid('', true)), 0, 8));
        $auth->add($role);

        return $role;
    }

    private function role(string $name): Role
    {
        $role = $this->auth()->getRole($name);
        $this->assertNotNull($role, $name);

        return $role;
    }

    private function permission(string $name): Permission
    {
        $permission = $this->auth()->getPermission($name);
        $this->assertNotNull($permission, $name);

        return $permission;
    }

    private function auth(): DbManager
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $auth);

        return $auth;
    }
}
