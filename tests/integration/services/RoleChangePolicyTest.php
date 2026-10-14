<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\User;
use app\services\RoleChangePolicy;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\rbac\DbManager;
use yii\rbac\Role;

/**
 * RoleChangePolicy decides who may create, change or delete which role: a
 * superadmin everything; anyone else a role only with permissions they hold,
 * and changes or deletes only roles they may assign (RoleGrantPolicy),
 * adding only permissions they hold; nobody without a signed-in user. Run
 * with the default role viewer of the web application (config/web.php).
 */
final class RoleChangePolicyTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /** A role manager's permissions: managing roles, nothing else. */
    private const ROLE_MANAGER_PERMISSIONS = ['role.view', 'role.create', 'role.update', 'role.delete'];

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

    public function testASuperadminMayChangeEveryRoleAndGrantEveryPermission(): void
    {
        $superadmin = $this->createUser('rcp-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $policy = new RoleChangePolicy($this->auth(), $superadmin);

        $this->assertSame((int)$superadmin->id, $policy->actorId());
        foreach (['viewer', 'operator', 'admin', $this->roleManagerRole()->name] as $role) {
            $this->assertTrue($policy->mayChange($role), $role);
        }
        $all = array_keys($this->auth()->getPermissions());
        $this->assertSame(array_fill_keys($all, true), $policy->grantable($all));
        $this->assertNull($policy->firstRefused($all));
    }

    /**
     * A role manager holds the viewer's permissions through the default role
     * and the role.* permissions through their own role: they may change
     * viewer and their own role, not operator or admin, and grant only what
     * they hold.
     */
    public function testARoleManagerMayChangeOnlyRolesTheyMayAssignAndGrantOnlyWhatTheyHold(): void
    {
        $manager = $this->roleManager('rcp-manager');
        $policy = new RoleChangePolicy($this->auth(), $manager);

        $this->assertSame((int)$manager->id, $policy->actorId());
        $this->assertTrue($policy->mayChange('viewer'), 'viewer: held through the default role');
        $this->assertTrue($policy->mayChange($this->roleManagerRole()->name), 'their own role');
        $this->assertFalse($policy->mayChange('operator'), 'operator grants job.launch, which they lack');
        $this->assertFalse($policy->mayChange('admin'));

        $this->assertTrue($policy->mayGrant('role.update'), 'held through their role');
        $this->assertTrue($policy->mayGrant('project.view'), 'held through the default role');
        $this->assertFalse($policy->mayGrant('user.update'));
        $this->assertFalse($policy->mayGrant('approval-rule.update'));
        $this->assertSame(
            ['role.view' => true, 'user.update' => false, 'job.view' => true, 'approval-rule.update' => false],
            $policy->grantable(['role.view', 'user.update', 'job.view', 'approval-rule.update'])
        );
    }

    /**
     * The refusal names the first permission of the request, in its order,
     * that the user does not hold; none for a request they may grant, also
     * an empty one.
     */
    public function testFirstRefusedNamesTheFirstPermissionTheUserDoesNotHold(): void
    {
        $policy = new RoleChangePolicy($this->auth(), $this->roleManager('rcp-first'));

        $this->assertSame('user.update', $policy->firstRefused(['role.view', 'user.update', 'approval-rule.update']));
        $this->assertSame('approval-rule.update', $policy->firstRefused(['approval-rule.update', 'user.update']));
        $this->assertNull($policy->firstRefused(['role.view', 'project.view']));
        $this->assertNull($policy->firstRefused([]));
    }

    public function testAnAdminMayChangeTheAdminRoleAndGrantEveryPermission(): void
    {
        $policy = new RoleChangePolicy($this->auth(), $this->createUserWithRole('rcp-admin', 'admin'));

        $this->assertTrue($policy->mayChange('admin'));
        $this->assertNull($policy->firstRefused(array_keys($this->auth()->getPermissions())));
    }

    public function testWithoutASignedInUserNothingMayBeChangedOrGranted(): void
    {
        foreach ([new RoleChangePolicy($this->auth(), null), RoleChangePolicy::forUser(null)] as $policy) {
            $this->assertNull($policy->actorId());
            $this->assertFalse($policy->mayChange('viewer'));
            $this->assertFalse($policy->mayGrant('project.view'));
            $this->assertSame('project.view', $policy->firstRefused(['project.view']));
        }
    }

    public function testNobodyMayChangeARoleTheInstanceDoesNotHave(): void
    {
        $superadmin = $this->createUser('rcp-unknown');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);

        $this->assertFalse((new RoleChangePolicy($this->auth(), $superadmin))->mayChange('no-such-role-' . uniqid()));
    }

    /**
     * A decision is kept for the life of the instance: a save decides as the
     * page that offered the permissions, and a page asks once per
     * permission. A new instance sees the change.
     */
    public function testDecisionsAreKeptForTheLifeOfTheInstance(): void
    {
        $manager = $this->roleManager('rcp-memo');
        $policy = new RoleChangePolicy($this->auth(), $manager);
        $this->assertFalse($policy->mayGrant('user.update'));

        $auth = $this->auth();
        $permission = $auth->getPermission('user.update');
        $this->assertNotNull($permission);
        $auth->addChild($this->roleManagerRole(), $permission);

        $this->assertFalse($policy->mayGrant('user.update'), 'the first answer stays');
        $this->assertTrue((new RoleChangePolicy($auth, $manager))->mayGrant('user.update'));
    }

    public function testForCurrentUserIsThePolicyOfTheSignedInUser(): void
    {
        $manager = $this->roleManager('rcp-current');
        $this->loginAs($manager);

        $policy = RoleChangePolicy::forCurrentUser();

        $this->assertSame((int)$manager->id, $policy->actorId());
        $this->assertTrue($policy->mayGrant('role.update'));
        $this->assertFalse($policy->mayGrant('user.update'));
    }

    public function testForCurrentUserIsThePolicyOfNobodyWithoutASignedInUser(): void
    {
        $policy = RoleChangePolicy::forCurrentUser();

        $this->assertNull($policy->actorId());
        $this->assertFalse($policy->mayGrant('project.view'));
    }

    public function testForCurrentUserIsThePolicyOfNobodyWithoutAUserComponent(): void
    {
        $app = \Yii::$app;
        $user = $app->get('user');
        $app->set('user', null);
        try {
            $this->assertFalse($app->has('user'));
            $this->assertNull(RoleChangePolicy::forCurrentUser()->actorId());
        } finally {
            $app->set('user', $user);
        }
    }

    // -- Helpers --------------------------------------------------------------

    private function auth(): DbManager
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $auth);

        return $auth;
    }

    /**
     * The role manager role of these tests, with exactly
     * ROLE_MANAGER_PERMISSIONS; created once per test (the rollback removes it).
     */
    private function roleManagerRole(): Role
    {
        $auth = $this->auth();
        $name = 'rcp-role-manager';
        $role = $auth->getRole($name);
        if ($role !== null) {
            return $role;
        }
        $role = $auth->createRole($name);
        $auth->add($role);
        foreach (self::ROLE_MANAGER_PERMISSIONS as $permissionName) {
            $permission = $auth->getPermission($permissionName);
            $this->assertNotNull($permission, $permissionName);
            $auth->addChild($role, $permission);
        }

        return $role;
    }

    private function roleManager(string $suffix): User
    {
        return $this->createUserWithRole($suffix, $this->roleManagerRole()->name);
    }
}
