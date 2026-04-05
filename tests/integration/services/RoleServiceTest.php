<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\models\RoleForm;
use app\services\RoleService;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for RoleService, exercised against the real DbManager.
 * System-role protection, CRUD, cascade delete, and audit emission are all
 * verified end-to-end.
 */
class RoleServiceTest extends DbTestCase
{
    private function service(): RoleService
    {
        /** @var RoleService $s */
        $s = \Yii::$app->get('roleService');
        return $s;
    }

    private function uniqueName(string $prefix = 'custom'): string
    {
        return $prefix . '-' . substr(md5(uniqid('', true)), 0, 8);
    }

    private function makeForm(string $name, array $permissions = ['project.view'], string $description = 'test'): RoleForm
    {
        $form = new RoleForm();
        $form->name = $name;
        $form->description = $description;
        $form->permissions = $permissions;
        return $form;
    }

    // -- isSystemRole ---------------------------------------------------------

    public function testIsSystemRole(): void
    {
        $svc = $this->service();
        $this->assertTrue($svc->isSystemRole('viewer'));
        $this->assertTrue($svc->isSystemRole('operator'));
        $this->assertTrue($svc->isSystemRole('admin'));
        $this->assertFalse($svc->isSystemRole('something-else'));
    }

    // -- createRole -----------------------------------------------------------

    public function testCreateRoleAttachesPermissions(): void
    {
        $name = $this->uniqueName();
        $form = $this->makeForm($name, ['project.view', 'job.view']);

        $this->assertTrue($this->service()->createRole($form, 1));

        $direct = $this->service()->directPermissions($name);
        $this->assertSame(['job.view', 'project.view'], $direct);
    }

    public function testCreateRoleRejectsSystemName(): void
    {
        $form = $this->makeForm('viewer', ['project.view']);
        $this->assertFalse($this->service()->createRole($form, 1));
        $this->assertArrayHasKey('name', $form->errors);
    }

    public function testCreateRoleRejectsReservedName(): void
    {
        $form = $this->makeForm('superadmin', ['project.view']);
        $this->assertFalse($this->service()->createRole($form, 1));
        $this->assertArrayHasKey('name', $form->errors);
    }

    public function testCreateRoleRejectsUnknownPermission(): void
    {
        $name = $this->uniqueName();
        $form = $this->makeForm($name, ['project.view', 'nonexistent.permission']);
        $this->assertFalse($this->service()->createRole($form, 1));
        $this->assertArrayHasKey('permissions', $form->errors);
    }

    public function testCreateRoleWritesAuditLog(): void
    {
        $name = $this->uniqueName();
        $before = AuditLog::find()->where(['action' => AuditLog::ACTION_ROLE_CREATED])->count();

        $this->service()->createRole($this->makeForm($name), 1);

        $after = AuditLog::find()->where(['action' => AuditLog::ACTION_ROLE_CREATED])->count();
        $this->assertSame((int)$before + 1, (int)$after);

        $log = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_ROLE_CREATED])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertNotNull($log);
        $this->assertSame('role', $log->object_type);
        $metadata = json_decode((string)$log->metadata, true);
        $this->assertSame($name, $metadata['name']);
    }

    // -- updateRole -----------------------------------------------------------

    public function testUpdateRoleDiffsPermissions(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view', 'job.view']), 1);

        $update = $this->makeForm($name, ['project.view', 'inventory.view']);
        $this->assertTrue($this->service()->updateRole($name, $update, 1));

        $direct = $this->service()->directPermissions($name);
        $this->assertSame(['inventory.view', 'project.view'], $direct);
    }

    public function testUpdateRoleUpdatesDescription(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view'], 'old desc'), 1);

        $update = $this->makeForm($name, ['project.view'], 'new desc');
        $this->service()->updateRole($name, $update, 1);

        $role = $this->service()->getRole($name);
        $this->assertNotNull($role);
        $this->assertSame('new desc', $role['description']);
    }

    public function testUpdateRoleOnSystemRoleAllowsPermissionChanges(): void
    {
        $svc = $this->service();
        $originalDirect = $svc->directPermissions('viewer');

        $update = new RoleForm();
        $update->name = 'viewer';
        $update->description = 'updated viewer';
        $update->permissions = array_merge($originalDirect, ['analytics.view']);
        $update->isSystemRole = true;

        $this->assertTrue($svc->updateRole('viewer', $update, 1));
        $this->assertContains('analytics.view', $svc->directPermissions('viewer'));
    }

    public function testUpdateRoleReturnsFalseForUnknownRole(): void
    {
        $update = $this->makeForm('ghost-role', ['project.view']);
        $this->assertFalse($this->service()->updateRole('ghost-role', $update, 1));
    }

    public function testUpdateRoleWritesAuditLog(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view']), 1);

        $this->service()->updateRole($name, $this->makeForm($name, ['project.view', 'job.view']), 1);

        $log = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_ROLE_UPDATED])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertNotNull($log);
        $metadata = json_decode((string)$log->metadata, true);
        $this->assertContains('job.view', $metadata['added']);
    }

    // -- deleteRole -----------------------------------------------------------

    public function testDeleteCustomRoleRemovesIt(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name), 1);

        $this->assertTrue($this->service()->deleteRole($name, 1));
        $this->assertNull($this->service()->getRole($name));
    }

    public function testDeleteCustomRoleCascadesAssignments(): void
    {
        $auth = \Yii::$app->authManager;
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view']), 1);

        $user1 = $this->createUser('role-del-1');
        $user2 = $this->createUser('role-del-2');
        $auth->assign($auth->getRole($name), $user1->id);
        $auth->assign($auth->getRole($name), $user2->id);

        $this->assertCount(2, $this->service()->usersWithRole($name));
        $this->assertTrue($this->service()->deleteRole($name, 1));

        $log = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_ROLE_DELETED])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertNotNull($log);
        $metadata = json_decode((string)$log->metadata, true);
        $this->assertCount(2, $metadata['affected_users']);
    }

    public function testDeleteSystemRoleIsRefused(): void
    {
        $this->assertFalse($this->service()->deleteRole('viewer', 1));
        $this->assertNotNull($this->service()->getRole('viewer'));
    }

    public function testDeleteUnknownRoleReturnsFalse(): void
    {
        $this->assertFalse($this->service()->deleteRole('ghost-' . uniqid(), 1));
    }

    // -- listRoles / getRole --------------------------------------------------

    public function testListRolesIncludesSystemAndCustom(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view', 'job.view']), 1);

        $list = $this->service()->listRoles();
        $names = array_column($list, 'name');
        $this->assertContains('viewer', $names);
        $this->assertContains('operator', $names);
        $this->assertContains('admin', $names);
        $this->assertContains($name, $names);

        foreach ($list as $row) {
            if ($row['name'] === $name) {
                $this->assertFalse($row['isSystem']);
                $this->assertSame(2, $row['permissionCount']);
            }
            if ($row['name'] === 'viewer') {
                $this->assertTrue($row['isSystem']);
            }
        }
    }

    public function testGetRoleReturnsNullForUnknown(): void
    {
        $this->assertNull($this->service()->getRole('not-a-real-role'));
    }

    public function testGetRoleReturnsFullShape(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view']), 1);

        $data = $this->service()->getRole($name);
        $this->assertNotNull($data);
        $this->assertSame($name, $data['name']);
        $this->assertFalse($data['isSystem']);
        $this->assertSame(['project.view'], $data['directPermissions']);
        $this->assertContains('project.view', $data['effectivePermissions']);
        $this->assertSame([], $data['userIds']);
    }

    public function testEffectivePermissionsForSystemRoleWalksHierarchy(): void
    {
        // admin inherits from operator which inherits from viewer
        $effective = $this->service()->effectivePermissions('admin');
        $this->assertContains('project.view', $effective);  // from viewer
        $this->assertContains('project.create', $effective); // from operator
        $this->assertContains('user.create', $effective);    // admin itself
    }
}
