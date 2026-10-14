<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eAdminRoleSeeder;
use app\commands\E2eTeardownHelper;
use app\models\User;
use app\models\UserForm;
use app\services\AssignedRoles;
use app\services\RoleChangePolicy;
use app\services\RoleGrantPolicy;
use app\services\UserChangePolicy;
use app\tests\integration\DbTestCase;
use yii\db\Query;
use yii\rbac\DbManager;
use yii\rbac\Role;

/**
 * The users of tests/e2e/tests/users/admin-role.spec.ts
 * (E2eAdminRoleSeeder): the holder of every permission of the admin role,
 * through a role of their own, and an inactive user who holds the admin
 * role. The holder neither assigns the admin role, nor changes that user,
 * nor changes the role: only superadmins and holders of the admin role do.
 * The rollback removes the users and the role.
 *
 * The regression tests are what the spec relies on, one per test of the
 * spec; each fails on the code before the admin-role rule, which offered
 * and gave the holder the admin role, and let them change an admin and the
 * admin role.
 */
class E2eAdminRoleSeederTest extends DbTestCase
{
    /** The fixture password of tests/e2e/lib/session.ts; not a secret. */
    private const FIXTURE_PASSWORD = 'E2eAllpermUserPass1!';

    /** @var list<string> */
    private array $log = [];

    /** @var list<string> the default roles of the manager before the test */
    private array $savedDefaultRoles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = [];
        $auth = $this->auth();
        $this->savedDefaultRoles = array_map('strval', $auth->defaultRoles);
        // As config/web.php, which the spec runs against.
        $auth->defaultRoles = ['viewer'];
    }

    protected function tearDown(): void
    {
        $this->auth()->defaultRoles = $this->savedDefaultRoles;
        parent::tearDown();
    }

    /**
     * regression: the New User form of the holder offered the admin role,
     * and saved a user with it. It offers the roles the holder may assign
     * and refuses the admin role with ADMIN_ROLE_REFUSED; nothing is saved.
     */
    public function testRegressionTheHoldersFormOffersNoAdminRoleAndRefusesASaveWithIt(): void
    {
        $this->seed();
        $form = new UserForm(new RoleGrantPolicy($this->auth(), $this->user(E2eAdminRoleSeeder::USERNAME)));

        $choices = array_keys($form->roleChoices());
        $this->assertNotContains('admin', $choices);
        foreach (['viewer', 'operator', E2eAdminRoleSeeder::ROLE] as $assignable) {
            $this->assertContains($assignable, $choices);
        }
        $username = 'e2e-ar-refused-' . substr(md5(uniqid('', true)), 0, 8);
        $this->assertTrue($form->load(['UserForm' => [
            'username' => $username,
            'email' => $username . '@example.com',
            'password' => 'E2eArRefusedPass1!',
            'role' => 'admin',
        ]]));

        $this->assertFalse($form->save());

        $this->assertSame([sprintf(RoleGrantPolicy::ADMIN_ROLE_REFUSED, 'admin')], $form->getErrors('role'));
        $this->assertNull(User::findOne(['username' => $username]));
    }

    /**
     * regression: the holder could change the inactive admin, in the users
     * list, on the user page and on the Edit User page. Changing them is
     * refused with ADMIN_ROLE_CHANGE_REFUSED, which names the admin role:
     * CHANGE_REFUSED said the holder lacked permissions they hold
     * (regression of wave 5f); the holder's own account stays theirs.
     */
    public function testRegressionTheHolderMayNotChangeTheInactiveAdmin(): void
    {
        $this->seed();
        $holder = $this->user(E2eAdminRoleSeeder::USERNAME);
        $target = $this->user(E2eAdminRoleSeeder::TARGET);
        $users = new UserChangePolicy(new RoleGrantPolicy($this->auth(), $holder), AssignedRoles::fromApp());

        $this->assertFalse($users->mayChange($target));
        $this->assertSame(UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED, $users->refusalOf($target));
        $this->assertSame(
            [(int)$target->id => false, (int)$holder->id => true],
            $users->mayChangeEach([$target, $holder]),
            'the users list offers Edit for the holder only'
        );
    }

    /**
     * regression: the holder could change the admin role. Changing it is
     * refused (RoleChangePolicy::CHANGE_REFUSED); the holder's own role,
     * which they may assign, stays theirs to change.
     */
    public function testRegressionTheHolderMayNotChangeTheAdminRole(): void
    {
        $this->seed();
        $roles = new RoleChangePolicy($this->auth(), $this->user(E2eAdminRoleSeeder::USERNAME));

        $this->assertFalse($roles->mayChange('admin'));
        $this->assertTrue($roles->mayChange(E2eAdminRoleSeeder::ROLE));
    }

    /**
     * The holder holds every permission of the admin role, through ROLE,
     * whose children are exactly those permissions, but not the admin role.
     * The inactive user holds only the admin role.
     */
    public function testTheHolderHoldsEveryPermissionOfTheAdminRoleButNotTheRole(): void
    {
        $this->seed();

        $auth = $this->auth();
        $holder = $this->user(E2eAdminRoleSeeder::USERNAME);
        $adminPermissions = array_keys($auth->getPermissionsByRole('admin'));
        $this->assertNotSame([], $adminPermissions);
        $this->assertEqualsCanonicalizing($adminPermissions, array_keys($auth->getChildren(E2eAdminRoleSeeder::ROLE)));
        $this->assertSame([], array_filter($auth->getChildren(E2eAdminRoleSeeder::ROLE), static fn ($child): bool => $child instanceof Role), 'no child role');
        $this->assertSame([E2eAdminRoleSeeder::ROLE], AssignedRoles::fromApp()->of((int)$holder->id));
        foreach ($adminPermissions as $permission) {
            $this->assertTrue($auth->checkAccess((int)$holder->id, $permission), $permission);
        }
        $this->assertFalse($auth->checkAccess((int)$holder->id, 'admin'));
        $this->assertSame(['admin'], AssignedRoles::fromApp()->of((int)$this->user(E2eAdminRoleSeeder::TARGET)->id));
    }

    public function testTheHolderIsActiveWithTheFixturePasswordAndTheTargetInactiveNeitherASuperadmin(): void
    {
        $this->seed();

        $holder = $this->user(E2eAdminRoleSeeder::USERNAME);
        $target = $this->user(E2eAdminRoleSeeder::TARGET);
        $this->assertSame(User::STATUS_ACTIVE, (int)$holder->status);
        $this->assertSame(User::STATUS_INACTIVE, (int)$target->status);
        $this->assertFalse((bool)$holder->is_superadmin);
        $this->assertFalse((bool)$target->is_superadmin);
        $this->assertSame(E2eAdminRoleSeeder::USERNAME . '@example.com', $holder->email);
        $this->assertSame(E2eAdminRoleSeeder::TARGET . '@example.com', $target->email);
        $this->assertTrue($holder->validatePassword(self::FIXTURE_PASSWORD));
        $this->assertFalse($target->validatePassword(self::FIXTURE_PASSWORD), 'a random password');
        $count = count($this->auth()->getPermissionsByRole('admin'));
        $this->assertSame(
            [
                "  Restored role 'e2e-allperm-role' with the {$count} permissions of the role 'admin', without that role.\n",
                "  Restored user 'e2e-allperm-user' (ID {$holder->id}, active) with the roles 'e2e-allperm-role'.\n",
                "  Restored user 'e2e-dormant-chief' (ID {$target->id}, inactive) with the roles 'admin'.\n",
            ],
            $this->log
        );
    }

    /**
     * A spec, or a person, may have changed the fixtures: a seed restores
     * the users, their roles and the role's permissions, makes the target
     * inactive again, and keeps the IDs and the target's password.
     */
    public function testSeedingAgainRestoresTheUsersAndTheRole(): void
    {
        $this->seed();
        $auth = $this->auth();
        $holder = $this->user(E2eAdminRoleSeeder::USERNAME);
        $target = $this->user(E2eAdminRoleSeeder::TARGET);
        $passwordHash = $target->password_hash;
        $holder->status = User::STATUS_INACTIVE;
        $holder->is_superadmin = true;
        $holder->setPassword('Changed-by-a-spec-1!');
        $holder->save(false);
        $target->status = User::STATUS_ACTIVE;
        $target->is_superadmin = true;
        $target->save(false);
        $admin = $auth->getRole('admin');
        $operator = $auth->getRole('operator');
        $role = $auth->getRole(E2eAdminRoleSeeder::ROLE);
        $launch = $auth->getPermission('job.launch');
        $this->assertNotNull($admin);
        $this->assertNotNull($operator);
        $this->assertNotNull($role);
        $this->assertNotNull($launch);
        $auth->assign($admin, (int)$holder->id);
        $auth->assign($operator, (int)$target->id);
        $auth->removeChild($role, $launch);
        $auth->addChild($role, $admin);

        $this->seed();

        $restoredHolder = $this->user(E2eAdminRoleSeeder::USERNAME);
        $restoredTarget = $this->user(E2eAdminRoleSeeder::TARGET);
        $this->assertSame((int)$holder->id, (int)$restoredHolder->id);
        $this->assertSame((int)$target->id, (int)$restoredTarget->id);
        $this->assertSame(User::STATUS_ACTIVE, (int)$restoredHolder->status);
        $this->assertSame(User::STATUS_INACTIVE, (int)$restoredTarget->status, 'inactive again: it approves nothing');
        $this->assertFalse((bool)$restoredHolder->is_superadmin);
        $this->assertFalse((bool)$restoredTarget->is_superadmin);
        $this->assertTrue($restoredHolder->validatePassword(self::FIXTURE_PASSWORD));
        $this->assertSame($passwordHash, $restoredTarget->password_hash, 'the random password stays');
        $this->assertSame([E2eAdminRoleSeeder::ROLE], AssignedRoles::fromApp()->of((int)$holder->id));
        $this->assertSame(['admin'], AssignedRoles::fromApp()->of((int)$target->id));
        $this->assertEqualsCanonicalizing(
            array_keys($auth->getPermissionsByRole('admin')),
            array_keys($auth->getChildren(E2eAdminRoleSeeder::ROLE))
        );
        $this->assertFalse($auth->checkAccess((int)$holder->id, 'admin'));
        $this->assertSame(1, (int)User::find()->where(['username' => E2eAdminRoleSeeder::USERNAME])->count());
        $this->assertSame(1, (int)User::find()->where(['username' => E2eAdminRoleSeeder::TARGET])->count());
    }

    /**
     * The role follows the admin role: a permission the admin role grants
     * through its child role operator, added since the last seed, is the
     * role's own after the next, so the holder holds it.
     */
    public function testTheRoleTakesThePermissionsTheAdminRoleGrantsAtTheSeed(): void
    {
        $this->seed();
        $auth = $this->auth();
        $added = $auth->createPermission('test-admin-role-seeder-' . substr(md5(uniqid('', true)), 0, 8));
        $auth->add($added);
        $operator = $auth->getRole('operator');
        $this->assertNotNull($operator);
        $auth->addChild($operator, $added);
        $holderId = (int)$this->user(E2eAdminRoleSeeder::USERNAME)->id;
        $this->assertFalse($auth->checkAccess($holderId, $added->name), 'not yet');

        $this->seed();

        $this->assertArrayHasKey($added->name, $auth->getChildren(E2eAdminRoleSeeder::ROLE));
        $this->assertTrue($auth->checkAccess($holderId, $added->name));
    }

    public function testAUserThatCannotBeSavedIsReportedAndGetsNoRole(): void
    {
        // The e-mail addresses are taken by other users.
        foreach ([E2eAdminRoleSeeder::USERNAME, E2eAdminRoleSeeder::TARGET] as $name) {
            $other = $this->createUser('other');
            $other->email = $name . '@example.com';
            $other->save(false);
        }

        $this->seed();

        $this->assertNull(User::findOne(['username' => E2eAdminRoleSeeder::USERNAME]));
        $this->assertNull(User::findOne(['username' => E2eAdminRoleSeeder::TARGET]));
        $this->assertCount(3, $this->log);
        $this->assertSame(
            sprintf(E2eAdminRoleSeeder::NOT_RESTORED, E2eAdminRoleSeeder::USERNAME, '{"email":["Email \\"e2e-allperm-user@example.com\\" has already been taken."]}'),
            $this->log[1]
        );
        $this->assertSame(
            sprintf(E2eAdminRoleSeeder::NOT_RESTORED, E2eAdminRoleSeeder::TARGET, '{"email":["Email \\"e2e-dormant-chief@example.com\\" has already been taken."]}'),
            $this->log[2]
        );
        $this->assertStringNotContainsString(self::FIXTURE_PASSWORD, implode('', $this->log));
        $this->assertNotNull($this->auth()->getRole(E2eAdminRoleSeeder::ROLE), 'the role is restored first');
    }

    /**
     * yii e2e/teardown removes users and custom roles named with the prefix
     * "e2e-": the role with its permissions and both users with their roles
     * go, and the admin role stays. The teardown runs here with the names'
     * own prefixes, so it touches nothing else.
     */
    public function testTheTeardownRemovesTheRoleItsPermissionsAndBothUsers(): void
    {
        $this->seed();
        $holderId = (int)$this->user(E2eAdminRoleSeeder::USERNAME)->id;
        $targetId = (int)$this->user(E2eAdminRoleSeeder::TARGET)->id;
        $adminChildren = array_keys($this->auth()->getChildren('admin'));
        foreach ([E2eAdminRoleSeeder::ROLE, E2eAdminRoleSeeder::USERNAME, E2eAdminRoleSeeder::TARGET] as $name) {
            $this->assertStringStartsWith('e2e-', $name);
        }

        foreach (['e2e-allperm-', 'e2e-dormant-'] as $prefix) {
            $this->assertSame([], (new E2eTeardownHelper($prefix, static function (string $message): void {
            }))->teardownDatabase(), $prefix);
        }

        $auth = $this->auth();
        $this->assertNull($auth->getRole(E2eAdminRoleSeeder::ROLE));
        $this->assertSame(0, (int)(new Query())->from('{{%auth_item_child}}')->where(['parent' => E2eAdminRoleSeeder::ROLE])->count());
        $this->assertNull(User::findOne($holderId));
        $this->assertNull(User::findOne($targetId));
        $this->assertSame([], $auth->getAssignments($holderId));
        $this->assertSame([], $auth->getAssignments($targetId));
        $this->assertEqualsCanonicalizing($adminChildren, array_keys($auth->getChildren('admin')), 'the admin role stays');
    }

    /**
     * Other specs pick rows of the users list, the roles list and the teams
     * list by text they contain (users/crud.spec.ts, ldap/*.spec.ts,
     * roles/crud.spec.ts, teams/*.spec.ts): the names, e-mail addresses and
     * the role's description must contain none of it.
     */
    public function testNoTextOfTheFixturesContainsTextOtherSpecsPickRowsBy(): void
    {
        $this->seed();

        $texts = [
            E2eAdminRoleSeeder::ROLE,
            (string)$this->auth()->getRole(E2eAdminRoleSeeder::ROLE)?->description,
            E2eAdminRoleSeeder::USERNAME,
            $this->user(E2eAdminRoleSeeder::USERNAME)->email,
            E2eAdminRoleSeeder::TARGET,
            $this->user(E2eAdminRoleSeeder::TARGET)->email,
        ];
        $picked = ['admin', 'e2e-team', 'e2e-viewer', 'e2e-newuser', 'e2e-ldap-user', 'e2e-referenced-user', 'e2e-crud-role', 'e2e-custom-role'];
        foreach ($texts as $text) {
            foreach ($picked as $needle) {
                $this->assertStringNotContainsString($needle, $text);
            }
        }
    }

    private function seed(): void
    {
        (new E2eAdminRoleSeeder(function (string $message): void {
            $this->log[] = $message;
        }))->seed();
    }

    private function user(string $name): User
    {
        $user = User::findOne(['username' => $name]);
        $this->assertInstanceOf(User::class, $user, $name);

        return $user;
    }

    private function auth(): DbManager
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $auth);

        return $auth;
    }
}
