<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\User;
use app\models\UserForm;
use app\services\AssignedRoles;
use app\services\RoleGrantPolicy;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\rbac\DbManager;

/**
 * What the user form lets the user who saves give (RoleGrantPolicy): the
 * roles they may assign, the role a user has, and the superadmin flag only
 * from a superadmin. Run with the default role viewer of the web application
 * (config/web.php). The help desk of these tests holds a custom role with
 * user.view and user.update, as an admin can build in the role editor.
 */
final class UserFormGrantTest extends DbTestCase
{
    use TeamScopeFixtures;

    /** @var list<string> the default roles of the manager before the test */
    private array $savedDefaultRoles = [];

    private string $helpDeskRole = '';

    protected function setUp(): void
    {
        parent::setUp();
        $auth = $this->auth();
        $this->savedDefaultRoles = array_map('strval', $auth->defaultRoles);
        $auth->defaultRoles = ['viewer'];
        $role = $auth->createRole('test-help-desk-' . substr(md5(uniqid('', true)), 0, 8));
        $auth->add($role);
        foreach (['user.view', 'user.update'] as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }
        $this->helpDeskRole = $role->name;
    }

    protected function tearDown(): void
    {
        $this->auth()->defaultRoles = $this->savedDefaultRoles;
        parent::tearDown();
    }

    /**
     * regression: the form gave any role to whoever held user.update, so a
     * help desk made itself an admin. The role is refused with an error on
     * the field, and nothing is saved.
     */
    public function testRegressionARoleTheUserWhoSavesMayNotAssignIsRefused(): void
    {
        $helpDesk = $this->storedUserWithRole('grant-form-self', $this->helpDeskRole);
        $email = $helpDesk->email;
        $form = UserForm::fromUser($helpDesk, $this->grantsOf($helpDesk));
        $this->assertTrue($form->load(['UserForm' => ['role' => 'admin', 'email' => 'raised-' . uniqid('', true) . '@example.com']]));

        $this->assertFalse($form->save());

        $this->assertSame([sprintf(RoleGrantPolicy::ROLE_REFUSED, 'admin')], $form->getErrors('role'));
        $this->assertSame([$this->helpDeskRole], AssignedRoles::fromApp()->of((int)$helpDesk->id));
        $helpDesk->refresh();
        $this->assertSame($email, $helpDesk->email, 'nothing is saved');
        $this->assertSame([], $form->privilegeChanges());
    }

    /**
     * regression: the form took the posted superadmin flag from anyone, so a
     * help desk made itself, or a user it created, a superadmin. The field
     * is ignored for anyone but a superadmin.
     */
    public function testRegressionTheFlagIsIgnoredForAnyoneButASuperadmin(): void
    {
        $helpDesk = $this->storedUserWithRole('grant-form-flag', $this->helpDeskRole);
        $form = UserForm::fromUser($helpDesk, $this->grantsOf($helpDesk));
        $this->assertTrue($form->load(['UserForm' => ['is_superadmin' => '1']]));
        $this->assertFalse($form->is_superadmin, 'not loaded');
        $form->is_superadmin = true;

        $this->assertTrue($form->save(), implode(', ', $form->getFirstErrors()));
        $helpDesk->refresh();
        $this->assertFalse((bool)$helpDesk->is_superadmin, 'not saved, even when set on the form');
        $this->assertSame([], $form->privilegeChanges());

        $created = new UserForm($this->grantsOf($helpDesk));
        $username = 'grant-form-new-' . uniqid();
        $this->assertTrue($created->load(['UserForm' => [
            'username' => $username,
            'email' => $username . '@example.com',
            'password' => 'GrantForm1!',
            'role' => 'viewer',
            'is_superadmin' => '1',
        ]]));
        $this->assertTrue($created->save(), implode(', ', $created->getFirstErrors()));
        $user = $created->getUser();
        $this->assertNotNull($user);
        $user->refresh();
        $this->assertFalse((bool)$user->is_superadmin);
        $this->assertSame(['viewer'], AssignedRoles::fromApp()->of((int)$user->id));
    }

    public function testASuperadminSetsAndClearsTheFlag(): void
    {
        $superadmin = $this->createUser('grant-form-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $target = $this->storedUserWithRole('grant-form-target', 'operator');

        $form = UserForm::fromUser($target, $this->grantsOf($superadmin));
        $this->assertTrue($form->canSetSuperadmin());
        $this->assertTrue($form->load(['UserForm' => ['is_superadmin' => '1']]));
        $this->assertTrue($form->save(), implode(', ', $form->getFirstErrors()));
        $target->refresh();
        $this->assertTrue((bool)$target->is_superadmin);
        $this->assertSame(['is_superadmin' => ['from' => false, 'to' => true]], $form->privilegeChanges());

        $form = UserForm::fromUser($target, $this->grantsOf($superadmin));
        $this->assertTrue($form->load(['UserForm' => ['is_superadmin' => '0']]));
        $this->assertTrue($form->save(), implode(', ', $form->getFirstErrors()));
        $target->refresh();
        $this->assertFalse((bool)$target->is_superadmin);
    }

    /**
     * The help desk may save a user whose role it could not assign, as long
     * as it keeps that role.
     */
    public function testARoleTheUserHasNeedsNoGrant(): void
    {
        $helpDesk = $this->storedUserWithRole('grant-form-keep', $this->helpDeskRole);
        $operator = $this->storedUserWithRole('grant-form-operator', 'operator');
        $form = UserForm::fromUser($operator, $this->grantsOf($helpDesk));
        $form->email = 'grant-form-keep-' . uniqid('', true) . '@example.com';

        $this->assertTrue($form->save(), implode(', ', $form->getFirstErrors()));
        $this->assertSame(['operator'], AssignedRoles::fromApp()->of((int)$operator->id));
    }

    /**
     * The Role field offers the roles the user who saves may assign, and the
     * user's role, also where they may not assign it.
     */
    public function testTheRoleChoicesAreTheAssignableRolesAndTheUsersRole(): void
    {
        $helpDesk = $this->storedUserWithRole('grant-form-choices', $this->helpDeskRole);
        $operator = $this->storedUserWithRole('grant-form-choices-operator', 'operator');

        $this->assertSame(['viewer', $this->helpDeskRole], array_keys((new UserForm($this->grantsOf($helpDesk)))->roleChoices()));
        $this->assertSame(
            ['viewer', 'operator', $this->helpDeskRole],
            array_keys(UserForm::fromUser($operator, $this->grantsOf($helpDesk))->roleChoices())
        );
        $superadmin = $this->createUser('grant-form-choices-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $this->assertSame(
            array_keys(UserForm::roleOptions()),
            array_keys((new UserForm($this->grantsOf($superadmin)))->roleChoices()),
            'a superadmin may assign every role'
        );
    }

    /**
     * user.created records the roles and the flag from null; user.updated
     * what changed.
     */
    public function testThePrivilegeChangesOfACreatedUser(): void
    {
        $admin = $this->storedUserWithRole('grant-form-admin', 'admin');
        $form = new UserForm($this->grantsOf($admin));
        $username = 'grant-form-created-' . uniqid();
        $this->assertTrue($form->load(['UserForm' => [
            'username' => $username,
            'email' => $username . '@example.com',
            'password' => 'GrantForm1!',
            'role' => 'operator',
        ]]));

        $this->assertTrue($form->save(), implode(', ', $form->getFirstErrors()));

        $this->assertSame(
            ['roles' => ['from' => null, 'to' => ['operator']], 'is_superadmin' => ['from' => null, 'to' => false]],
            $form->privilegeChanges()
        );
    }

    /**
     * regression: a user who held every permission of the admin role
     * through a custom role, without the admin role, gave a viewer the admin
     * role, and with it every team-restricted project. The form neither
     * offers it nor saves it, and says why: ADMIN_ROLE_REFUSED.
     */
    public function testRegressionTheAdminRoleIsGivenOnlyByWhoHoldsIt(): void
    {
        $auth = $this->auth();
        $allOfAdmin = $auth->createRole('test-all-of-admin-' . substr(md5(uniqid('', true)), 0, 8));
        $auth->add($allOfAdmin);
        foreach ($auth->getPermissionsByRole('admin') as $permission) {
            $auth->addChild($allOfAdmin, $permission);
        }
        $actor = $this->storedUserWithRole('grant-form-all-of-admin', $allOfAdmin->name);
        $viewer = $this->storedUserWithRole('grant-form-all-of-admin-viewer', 'viewer');
        $form = UserForm::fromUser($viewer, $this->grantsOf($actor));

        $this->assertArrayNotHasKey('admin', $form->roleChoices());
        $this->assertArrayHasKey('operator', $form->roleChoices());
        $this->assertTrue($form->load(['UserForm' => ['role' => 'admin']]));
        $this->assertFalse($form->save());

        $this->assertSame([sprintf(RoleGrantPolicy::ADMIN_ROLE_REFUSED, 'admin')], $form->getErrors('role'));
        $this->assertSame(['viewer'], AssignedRoles::fromApp()->of((int)$viewer->id));
    }

    private function grantsOf(User $actor): RoleGrantPolicy
    {
        return new RoleGrantPolicy($this->auth(), $actor);
    }

    private function storedUserWithRole(string $suffix, string $role): User
    {
        $user = $this->createUserWithRole($suffix, $role);
        $user->refresh();

        return $user;
    }

    private function auth(): DbManager
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $auth);

        return $auth;
    }
}
