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
 * UserForm::fromUser() fills the edit form of a user, its Role with the role
 * assigned to the user: run with the default role viewer of the web
 * application (config/web.php), which every user holds and
 * DbManager::getRolesByUser() lists first. Saved with that role, the form
 * keeps every role assigned to the user; another role replaces them all.
 * The forms are nobody's (no signed-in user) unless a test gives them an
 * admin's: keeping a role needs no grant (RoleGrantPolicy).
 */
final class UserFormAssignedRoleTest extends DbTestCase
{
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

    /**
     * regression: the Edit User form preselected the default role viewer for
     * every user, the first role DbManager::getRolesByUser() lists.
     */
    public function testRegressionTheFormPreselectsTheAssignedRoleNotTheDefaultRole(): void
    {
        foreach (['operator', 'admin', 'viewer'] as $role) {
            $user = $this->storedUserWithRole('form-' . $role, $role);

            $this->assertSame($role, UserForm::fromUser($user)->role, "the form of a user assigned {$role}");
        }
    }

    /**
     * regression: saving the form of an operator unchanged made them a
     * viewer, as the form showed viewer and saving gives the role shown.
     */
    public function testRegressionSavingTheFormUnchangedKeepsTheRole(): void
    {
        $operator = $this->storedUserWithRole('form-unchanged', 'operator');
        $form = UserForm::fromUser($operator);
        $form->email = 'form-unchanged-' . uniqid('', true) . '@example.com';

        $this->assertTrue($form->save(), implode(', ', $form->getFirstErrors()));
        $this->assertSame(['operator'], AssignedRoles::fromApp()->of((int)$operator->id));
    }

    public function testOfSeveralAssignedRolesTheFirstByNameIsPreselected(): void
    {
        $user = $this->storedUserWithRole('form-several', 'viewer');
        $this->assignRole((int)$user->id, 'operator');

        $this->assertSame('operator', UserForm::fromUser($user)->role);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function secondRoleProvider(): array
    {
        return [
            'a custom role sorted before operator, which the form shows' => ['a-approvers'],
            'a custom role sorted after operator' => ['z-approvers'],
        ];
    }

    /**
     * regression: saving the form of a user with several assigned roles
     * unchanged, for example after changing only the email, dropped all but
     * the first by name, the one the form showed: an operator who was also
     * an approver lost operator, or the approver role (R-SEC-4, R12-3).
     *
     * @dataProvider secondRoleProvider
     */
    public function testRegressionSavingTheFormOfAUserWithSeveralRolesUnchangedKeepsThemAll(string $prefix): void
    {
        $custom = $this->newRole($prefix);
        $user = $this->storedUserWithRole('form-multi', 'operator');
        $this->assignRole((int)$user->id, $custom);
        $both = AssignedRoles::fromApp()->of((int)$user->id);
        $this->assertCount(2, $both);
        $form = UserForm::fromUser($user);
        $this->assertSame($both[0], $form->role, 'precondition: the form shows the first by name');
        $form->email = 'form-multi-' . uniqid('', true) . '@example.com';

        $this->assertTrue($form->save(), implode(', ', $form->getFirstErrors()));

        $this->assertSame($both, AssignedRoles::fromApp()->of((int)$user->id));
        $this->assertTrue($this->auth()->checkAccess((string)$user->id, 'job.launch'), 'still an operator');
        $this->assertSame([], $form->privilegeChanges(), 'no role changed');
    }

    /**
     * Another role replaces every role assigned to the user, also one of
     * their other assigned roles: the form shows one.
     */
    public function testAnotherRoleReplacesEveryAssignedRole(): void
    {
        $custom = $this->newRole('a-replaced');
        $user = $this->storedUserWithRole('form-replace', 'operator');
        $this->assignRole((int)$user->id, $custom);

        $form = UserForm::fromUser($user, $this->adminGrants());
        $form->role = 'viewer';
        $this->assertTrue($form->save(), implode(', ', $form->getFirstErrors()));
        $this->assertSame(['viewer'], AssignedRoles::fromApp()->of((int)$user->id));
        $this->assertSame(['roles' => ['from' => [$custom, 'operator'], 'to' => ['viewer']]], $form->privilegeChanges());

        $this->assignRole((int)$user->id, $custom);
        $form = UserForm::fromUser($user, $this->adminGrants());
        $this->assertSame($custom, $form->role);
        $form->role = 'viewer';
        $this->assertTrue($form->save(), implode(', ', $form->getFirstErrors()));
        $this->assertSame(['viewer'], AssignedRoles::fromApp()->of((int)$user->id), 'viewer was assigned but not shown');
    }

    /**
     * The viewer choice of a user without an assigned role is no role they
     * have: saving it assigns viewer, as a role chosen anew.
     */
    public function testSavingTheViewerChoiceOfAUserWithoutARoleAssignsViewer(): void
    {
        $user = $this->createUser('form-none-save');
        $user->refresh();

        $nobodys = UserForm::fromUser($user);
        $this->assertFalse($nobodys->save(), 'assigning viewer needs a grant');
        $this->assertSame([sprintf(RoleGrantPolicy::ROLE_REFUSED, 'viewer')], $nobodys->getErrors('role'));

        $form = UserForm::fromUser($user, $this->adminGrants());
        $this->assertTrue($form->save(), implode(', ', $form->getFirstErrors()));
        $this->assertSame(['viewer'], AssignedRoles::fromApp()->of((int)$user->id));
        $this->assertSame(['roles' => ['from' => [], 'to' => ['viewer']]], $form->privilegeChanges());
    }

    /**
     * The Role field says that a user has several roles and that saving
     * with the role shown keeps them all.
     */
    public function testTheRoleHintNamesSeveralAssignedRoles(): void
    {
        $custom = $this->newRole('a-hinted');
        $several = $this->storedUserWithRole('form-hint', 'operator');
        $this->assignRole((int)$several->id, $custom);

        $this->assertSame(sprintf(UserForm::SEVERAL_ROLES_HINT, $custom . ', operator'), UserForm::fromUser($several)->roleHint());
        $this->assertNull(UserForm::fromUser($this->storedUserWithRole('form-hint-one', 'operator'))->roleHint(), 'one role');
        $none = $this->createUser('form-hint-none');
        $none->refresh();
        $this->assertNull(UserForm::fromUser($none)->roleHint(), 'no role');
        $this->assertNull((new UserForm())->roleHint(), 'a new user');
    }

    public function testAUserWithoutAnAssignedRoleGetsTheViewerChoice(): void
    {
        $user = $this->createUser('form-none');
        $user->refresh();

        $this->assertSame('viewer', UserForm::fromUser($user)->role);
    }

    public function testTheFieldsOfTheUserAreFilledIn(): void
    {
        $user = $this->storedUserWithRole('form-fields', 'operator');

        $form = UserForm::fromUser($user);

        $this->assertSame($user, $form->getUser());
        $this->assertSame($user->username, $form->username);
        $this->assertSame($user->email, $form->email);
        $this->assertSame(User::STATUS_ACTIVE, $form->status);
        $this->assertFalse($form->is_superadmin);
        $this->assertSame(User::AUTH_SOURCE_LOCAL, $form->auth_source);
        $this->assertSame('', $form->ldap_dn);
        $this->assertSame('', $form->ldap_uid);
        $this->assertSame('', $form->password, 'the password is never filled in');
    }

    public function testTheDirectoryFieldsOfAnLdapUserAreFilledIn(): void
    {
        $user = $this->createUser('form-ldap');
        $user->markAsLdapManaged();
        $user->ldap_dn = 'uid=form-ldap,dc=test';
        $user->ldap_uid = 'guid-1';
        $user->is_superadmin = true;
        $user->save(false);
        $user->refresh();

        $form = UserForm::fromUser($user);

        $this->assertSame(User::AUTH_SOURCE_LDAP, $form->auth_source);
        $this->assertSame('uid=form-ldap,dc=test', $form->ldap_dn);
        $this->assertSame('guid-1', $form->ldap_uid);
        $this->assertTrue($form->is_superadmin);
    }

    /**
     * A user assigned $role, as the database stores it (with the defaults
     * of its columns, such as auth_source).
     */
    private function storedUserWithRole(string $suffix, string $role): User
    {
        $user = $this->createUserWithRole($suffix, $role);
        $user->refresh();

        return $user;
    }

    /**
     * A custom role named $prefix-<random>, which sorts by $prefix among
     * the roles.
     */
    private function newRole(string $prefix): string
    {
        $auth = $this->auth();
        $role = $auth->createRole($prefix . '-' . substr(md5(uniqid('', true)), 0, 8));
        $auth->add($role);

        return $role->name;
    }

    /**
     * What an admin may give, who holds every permission but is no
     * superadmin.
     */
    private function adminGrants(): RoleGrantPolicy
    {
        return new RoleGrantPolicy($this->auth(), $this->storedUserWithRole('form-actor', 'admin'));
    }

    private function auth(): DbManager
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $auth);

        return $auth;
    }
}
