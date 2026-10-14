<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\models\RoleForm;
use app\models\User;
use app\services\RoleChangePolicy;
use app\services\RoleChangeRefusedException;
use app\services\RoleService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\rbac\DbManager;
use yii\rbac\Role;

/**
 * Who may create, change or delete which role through RoleService
 * (RoleChangePolicy), the service both the Roles pages and the roles API
 * write with. A user who is no superadmin creates a role only with
 * permissions they hold, changes or deletes only roles they may assign, and
 * adds to them only permissions they hold; a refusal writes nothing, not
 * even the audit entry. Run with the default role viewer of the web
 * application (config/web.php).
 */
final class RoleEditingRuleTest extends DbTestCase
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
     * regression: whoever held role.update could give any role, their own
     * included, any permission (the probe of wave 5c: a custom role
     * {role.view, role.update} gave itself user.update and
     * approval-rule.update, and its holder then held both). The refusal
     * names the first permission they lack and writes nothing.
     */
    public function testRegressionARoleEditorCannotGiveTheirOwnRolePermissionsTheyLack(): void
    {
        $role = $this->customRole('rer-editor', ['role.view', 'role.update']);
        $editor = $this->createUserWithRole('rer-editor', $role->name);
        $this->assertFalse($this->auth()->checkAccess($editor->id, 'user.update'));
        $before = $this->auditCount(AuditLog::ACTION_ROLE_UPDATED);

        $form = $this->form($role->name, ['role.view', 'role.update', 'user.update', 'approval-rule.update'], 'escalated');
        $this->assertRefused(
            sprintf(RoleChangePolicy::PERMISSION_REFUSED, 'user.update'),
            fn () => $this->service()->updateRole($role->name, $form, $this->policyOf($editor))
        );

        $this->auth()->invalidateCache();
        $this->assertSame(['role.update', 'role.view'], $this->service()->directPermissions($role->name));
        $this->assertSame('rer test role', $this->service()->getRole($role->name)['description'] ?? null, 'the description stays too');
        $this->assertFalse($this->auth()->checkAccess($editor->id, 'user.update'));
        $this->assertFalse($this->auth()->checkAccess($editor->id, 'approval-rule.update'));
        $this->assertSame($before, $this->auditCount(AuditLog::ACTION_ROLE_UPDATED), 'no audit entry');
    }

    /**
     * regression: whoever held role.create could create a role with any
     * permission.
     */
    public function testRegressionARoleManagerCannotCreateARoleWithAPermissionTheyLack(): void
    {
        $manager = $this->roleManager('rer-create');
        $name = $this->uniqueName();
        $before = $this->auditCount(AuditLog::ACTION_ROLE_CREATED);

        $this->assertRefused(
            sprintf(RoleChangePolicy::PERMISSION_REFUSED, 'approval-rule.update'),
            fn () => $this->service()->createRole(
                $this->form($name, ['role.view', 'approval-rule.update', 'user.update']),
                $this->policyOf($manager)
            )
        );

        $this->assertNull($this->auth()->getRole($name));
        $this->assertSame($before, $this->auditCount(AuditLog::ACTION_ROLE_CREATED), 'no audit entry');
    }

    /**
     * regression: the admin role, and any role with a permission the user
     * lacks, could be changed by whoever held role.update, its description
     * alone too.
     */
    public function testRegressionARoleManagerCannotChangeARoleTheyMayNotAssign(): void
    {
        $manager = $this->roleManager('rer-change');
        $launcher = $this->customRole('rer-launcher', ['job.view', 'job.launch']);
        $before = $this->auditCount(AuditLog::ACTION_ROLE_UPDATED);

        foreach (['admin', 'operator', $launcher->name] as $name) {
            $current = $this->service()->directPermissions($name);
            $description = (string)$this->service()->getRole($name)['description'];
            $this->assertRefused(
                RoleChangePolicy::CHANGE_REFUSED,
                fn () => $this->service()->updateRole($name, $this->form($name, $current, 'changed'), $this->policyOf($manager))
            );
            $this->assertSame($current, $this->service()->directPermissions($name), $name);
            $this->assertSame($description, $this->service()->getRole($name)['description'] ?? null, $name);
        }
        $this->assertSame($before, $this->auditCount(AuditLog::ACTION_ROLE_UPDATED), 'no audit entry');
    }

    /**
     * Removing is no way around it either: a role the user may not assign
     * keeps every permission.
     */
    public function testRegressionARoleManagerCannotRemovePermissionsFromARoleTheyMayNotAssign(): void
    {
        $launcher = $this->customRole('rer-strip', ['job.view', 'job.launch']);

        $this->assertRefused(
            RoleChangePolicy::CHANGE_REFUSED,
            fn () => $this->service()->updateRole($launcher->name, $this->form($launcher->name, []), $this->policyOf($this->roleManager('rer-strip')))
        );

        $this->assertSame(['job.launch', 'job.view'], $this->service()->directPermissions($launcher->name));
    }

    /**
     * regression: whoever held role.delete could delete any custom role,
     * one with permissions they lack too.
     */
    public function testRegressionARoleManagerCannotDeleteARoleTheyMayNotAssign(): void
    {
        $launcher = $this->customRole('rer-delete', ['job.view', 'job.launch']);
        $before = $this->auditCount(AuditLog::ACTION_ROLE_DELETED);

        $this->assertRefused(
            RoleChangePolicy::CHANGE_REFUSED,
            fn () => $this->service()->deleteRole($launcher->name, $this->policyOf($this->roleManager('rer-delete')))
        );

        $this->assertNotNull($this->auth()->getRole($launcher->name));
        $this->assertSame($before, $this->auditCount(AuditLog::ACTION_ROLE_DELETED), 'no audit entry');
    }

    /**
     * The admin role grants more than its permissions (every team-restricted
     * project), so only superadmins and holders of the admin role may assign
     * it (RoleGrantPolicy), and so change it: holding every permission
     * through a role of one's own is not enough. That user may still change
     * the operator role.
     */
    public function testAHolderOfEveryPermissionWhoHoldsNoAdminRoleCannotChangeTheAdminRole(): void
    {
        $everything = $this->customRole('rer-everything', array_keys($this->auth()->getPermissions()));
        $policy = $this->policyOf($this->createUserWithRole('rer-everything', $everything->name));
        $admin = $this->service()->directPermissions('admin');

        $this->assertRefused(
            RoleChangePolicy::CHANGE_REFUSED,
            fn () => $this->service()->updateRole('admin', $this->form('admin', $admin, 'changed'), $policy)
        );
        $this->assertSame($admin, $this->service()->directPermissions('admin'));
        $operator = $this->service()->directPermissions('operator');
        $this->assertTrue($this->service()->updateRole('operator', $this->form('operator', array_merge($operator, ['user.view'])), $policy));
    }

    /**
     * A role manager still adds permissions they hold, through their own
     * role or the default role, to a role they may assign, and removes
     * permissions from it.
     */
    public function testARoleManagerChangesARoleTheyMayAssignWithPermissionsTheyHold(): void
    {
        $manager = $this->roleManager('rer-allowed');
        $role = $this->customRole('rer-allowed', ['project.view', 'inventory.view']);

        $form = $this->form($role->name, ['project.view', 'role.view', 'job.view'], 'changed');
        $this->assertTrue($this->service()->updateRole($role->name, $form, $this->policyOf($manager)));

        $this->assertSame(['job.view', 'project.view', 'role.view'], $this->service()->directPermissions($role->name));
        $log = AuditLog::find()->where(['action' => AuditLog::ACTION_ROLE_UPDATED])->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($log);
        $this->assertSame((int)$manager->id, (int)$log->user_id);
        $metadata = json_decode((string)$log->metadata, true);
        $this->assertSame(['role.view', 'job.view'], $metadata['added']);
        $this->assertSame(['inventory.view'], $metadata['removed']);
    }

    /**
     * Their own role and the default role viewer, which every signed-in
     * user holds, are roles they may assign: they may give them what they
     * hold, and delete a custom one.
     */
    public function testARoleManagerChangesTheirOwnRoleAndViewerAndDeletesARoleTheyMayAssign(): void
    {
        $manager = $this->roleManager('rer-own');
        $own = $this->roleManagerRole()->name;
        $viewer = $this->service()->directPermissions('viewer');
        $policy = $this->policyOf($manager);

        $this->assertTrue($this->service()->updateRole($own, $this->form($own, ['role.view', 'role.update']), $policy));
        $this->assertSame(['role.update', 'role.view'], $this->service()->directPermissions($own));
        $this->assertTrue($this->service()->updateRole('viewer', $this->form('viewer', array_merge($viewer, ['role.view'])), $policy));
        $this->assertContains('role.view', $this->service()->directPermissions('viewer'));

        $mine = $this->customRole('rer-mine', ['project.view']);
        $this->assertTrue($this->service()->deleteRole($mine->name, $policy));
        $this->assertNull($this->auth()->getRole($mine->name));
        $this->assertFalse($this->service()->deleteRole('viewer', $policy), 'system roles are never deleted');
    }

    public function testARoleManagerCreatesARoleWithPermissionsTheyHold(): void
    {
        $manager = $this->roleManager('rer-create-ok');
        $name = $this->uniqueName();

        $this->assertTrue($this->service()->createRole($this->form($name, ['role.view', 'project.view']), $this->policyOf($manager)));

        $this->assertSame(['project.view', 'role.view'], $this->service()->directPermissions($name));
    }

    /**
     * A superadmin may do everything: give the default role an admin's
     * permissions, change the admin role, create and delete a role with
     * every permission.
     */
    public function testASuperadminMayDoEverything(): void
    {
        $superadmin = $this->createUser('rer-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $policy = $this->policyOf($superadmin);
        $all = array_keys($this->auth()->getPermissions());
        sort($all);
        $viewer = $this->service()->directPermissions('viewer');

        $this->assertTrue($this->service()->updateRole('viewer', $this->form('viewer', array_merge($viewer, ['user.update', 'approval-rule.update'])), $policy));
        $this->assertContains('approval-rule.update', $this->service()->directPermissions('viewer'));
        $this->assertTrue($this->service()->updateRole('admin', $this->form('admin', $this->service()->directPermissions('admin'), 'changed'), $policy));
        $this->assertSame('changed', $this->service()->getRole('admin')['description'] ?? null);

        $name = $this->uniqueName();
        $this->assertTrue($this->service()->createRole($this->form($name, $all), $policy));
        $this->assertSame($all, $this->service()->directPermissions($name));
        $this->assertTrue($this->service()->deleteRole($name, $policy));
    }

    /**
     * A body that does not validate is answered first, as before: an
     * unknown permission or a bad name is a validation error, not a refusal.
     */
    public function testValidationComesBeforeTheRefusalOfAPermission(): void
    {
        $manager = $this->roleManager('rer-validate');
        $policy = $this->policyOf($manager);

        $bad = $this->form('Bad Name!', ['user.update']);
        $this->assertFalse($this->service()->createRole($bad, $policy));
        $this->assertTrue($bad->hasErrors('name'));

        $own = $this->roleManagerRole()->name;
        $unknown = $this->form($own, ['role.view', 'no.such-permission']);
        $this->assertFalse($this->service()->updateRole($own, $unknown, $policy));
        $this->assertSame('Unknown permission: no.such-permission', $unknown->getFirstError('permissions'));
    }

    /**
     * A role the user may not change is refused before its body is looked
     * at: the 403 comes before every 422.
     */
    public function testTheRefusalOfARoleComesBeforeValidation(): void
    {
        $this->assertRefused(
            RoleChangePolicy::CHANGE_REFUSED,
            fn () => $this->service()->updateRole('admin', $this->form('admin', ['no.such-permission']), $this->policyOf($this->roleManager('rer-order')))
        );
    }

    public function testAnUnknownRoleIsNeitherChangedNorRefused(): void
    {
        $policy = $this->policyOf($this->roleManager('rer-unknown'));
        $name = 'rer-ghost-' . substr(md5(uniqid('', true)), 0, 8);

        $this->assertFalse($this->service()->updateRole($name, $this->form($name, []), $policy));
        $this->assertFalse($this->service()->deleteRole($name, $policy));
    }

    // -- Helpers --------------------------------------------------------------

    private function service(): RoleService
    {
        /** @var RoleService $service */
        $service = \Yii::$app->get('roleService');

        return $service;
    }

    private function auth(): DbManager
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $auth);

        return $auth;
    }

    private function policyOf(User $user): RoleChangePolicy
    {
        return new RoleChangePolicy($this->auth(), $user);
    }

    private function uniqueName(): string
    {
        return 'rer-' . substr(md5(uniqid('', true)), 0, 8);
    }

    /**
     * @param list<string> $permissions
     */
    private function form(string $name, array $permissions, string $description = 'rer test role'): RoleForm
    {
        $form = new RoleForm();
        $form->name = $name;
        $form->description = $description;
        $form->permissions = $permissions;

        return $form;
    }

    /**
     * A custom role with exactly $permissions, made through the RBAC manager.
     *
     * @param list<string> $permissions
     */
    private function customRole(string $prefix, array $permissions): Role
    {
        $auth = $this->auth();
        $role = $auth->createRole($prefix . '-' . substr(md5(uniqid('', true)), 0, 8));
        $role->description = 'rer test role';
        $auth->add($role);
        foreach ($permissions as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }

        return $role;
    }

    /**
     * The role manager role of these tests: role.view, role.create,
     * role.update and role.delete; one per test.
     */
    private function roleManagerRole(): Role
    {
        $role = $this->auth()->getRole('rer-role-manager');

        return $role ?? $this->namedRole('rer-role-manager', ['role.view', 'role.create', 'role.update', 'role.delete']);
    }

    /**
     * @param list<string> $permissions
     */
    private function namedRole(string $name, array $permissions): Role
    {
        $auth = $this->auth();
        $role = $auth->createRole($name);
        $auth->add($role);
        foreach ($permissions as $permissionName) {
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

    private function auditCount(string $action): int
    {
        return (int)AuditLog::find()->where(['action' => $action])->count();
    }

    /**
     * @param callable(): mixed $call
     */
    private function assertRefused(string $message, callable $call): void
    {
        try {
            $call();
        } catch (RoleChangeRefusedException $e) {
            $this->assertSame($message, $e->getMessage());
            return;
        }
        $this->fail('Expected a RoleChangeRefusedException: ' . $message);
    }
}
