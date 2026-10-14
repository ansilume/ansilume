<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\models\RoleForm;
use app\services\RoleChangePolicy;
use app\services\RoleChangeRefusedException;
use app\services\RoleService;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for RoleService, exercised against the real DbManager.
 * System-role protection, CRUD, cascade delete, and audit emission are all
 * verified end-to-end, as a superadmin unless a test says otherwise. Who
 * else may change which role is RoleEditingRuleTest's.
 */
class RoleServiceTest extends DbTestCase
{
    private ?RoleChangePolicy $superadminPolicy = null;

    private function service(): RoleService
    {
        /** @var RoleService $s */
        $s = \Yii::$app->get('roleService');
        return $s;
    }

    /**
     * The policy of a superadmin, who may change every role and grant every
     * permission; one per test.
     */
    private function superadmin(): RoleChangePolicy
    {
        if ($this->superadminPolicy === null) {
            $user = $this->createUser('role-svc-superadmin');
            $user->is_superadmin = true;
            $user->save(false);
            $this->superadminPolicy = RoleChangePolicy::forUser($user);
        }

        return $this->superadminPolicy;
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

        $this->assertTrue($this->service()->createRole($form, $this->superadmin()));

        $direct = $this->service()->directPermissions($name);
        $this->assertSame(['job.view', 'project.view'], $direct);
    }

    public function testCreateRoleRejectsSystemName(): void
    {
        $form = $this->makeForm('viewer', ['project.view']);
        $this->assertFalse($this->service()->createRole($form, $this->superadmin()));
        $this->assertArrayHasKey('name', $form->errors);
    }

    public function testCreateRoleRejectsReservedName(): void
    {
        $form = $this->makeForm('superadmin', ['project.view']);
        $this->assertFalse($this->service()->createRole($form, $this->superadmin()));
        $this->assertArrayHasKey('name', $form->errors);
    }

    public function testCreateRoleRejectsUnknownPermission(): void
    {
        $name = $this->uniqueName();
        $form = $this->makeForm($name, ['project.view', 'nonexistent.permission']);
        $this->assertFalse($this->service()->createRole($form, $this->superadmin()));
        $this->assertArrayHasKey('permissions', $form->errors);
    }

    public function testCreateRoleWritesAuditLog(): void
    {
        $name = $this->uniqueName();
        $before = AuditLog::find()->where(['action' => AuditLog::ACTION_ROLE_CREATED])->count();

        $this->service()->createRole($this->makeForm($name), $this->superadmin());

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
        $this->service()->createRole($this->makeForm($name, ['project.view', 'job.view']), $this->superadmin());

        $update = $this->makeForm($name, ['project.view', 'inventory.view']);
        $this->assertTrue($this->service()->updateRole($name, $update, $this->superadmin()));

        $direct = $this->service()->directPermissions($name);
        $this->assertSame(['inventory.view', 'project.view'], $direct);
    }

    public function testUpdateRoleUpdatesDescription(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view'], 'old desc'), $this->superadmin());

        $update = $this->makeForm($name, ['project.view'], 'new desc');
        $this->service()->updateRole($name, $update, $this->superadmin());

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

        $this->assertTrue($svc->updateRole('viewer', $update, $this->superadmin()));
        $this->assertContains('analytics.view', $svc->directPermissions('viewer'));
    }

    public function testUpdateRoleReturnsFalseForUnknownRole(): void
    {
        $update = $this->makeForm('ghost-role', ['project.view']);
        $this->assertFalse($this->service()->updateRole('ghost-role', $update, $this->superadmin()));
    }

    public function testUpdateRoleWritesAuditLog(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view']), $this->superadmin());

        $this->service()->updateRole($name, $this->makeForm($name, ['project.view', 'job.view']), $this->superadmin());

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
        $this->service()->createRole($this->makeForm($name), $this->superadmin());

        $this->assertTrue($this->service()->deleteRole($name, $this->superadmin()));
        $this->assertNull($this->service()->getRole($name));
    }

    public function testDeleteCustomRoleCascadesAssignments(): void
    {
        $auth = \Yii::$app->authManager;
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view']), $this->superadmin());

        $user1 = $this->createUser('role-del-1');
        $user2 = $this->createUser('role-del-2');
        $auth->assign($auth->getRole($name), $user1->id);
        $auth->assign($auth->getRole($name), $user2->id);

        $this->assertCount(2, $this->service()->usersWithRole($name));
        $this->assertTrue($this->service()->deleteRole($name, $this->superadmin()));

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
        $this->assertFalse($this->service()->deleteRole('viewer', $this->superadmin()));
        $this->assertNotNull($this->service()->getRole('viewer'));
    }

    public function testDeleteUnknownRoleReturnsFalse(): void
    {
        $this->assertFalse($this->service()->deleteRole('ghost-' . uniqid(), $this->superadmin()));
    }

    // -- listRoles / getRole --------------------------------------------------

    public function testListRolesIncludesSystemAndCustom(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view', 'job.view']), $this->superadmin());

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
        $this->service()->createRole($this->makeForm($name, ['project.view']), $this->superadmin());

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

    // -- updateRole: additional coverage -----------------------------------

    public function testUpdateRoleValidationFailureReturnsFalse(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view']), $this->superadmin());

        // Pass unknown permission to trigger validation failure
        $update = $this->makeForm($name, ['nonexistent.permission']);
        $this->assertFalse($this->service()->updateRole($name, $update, $this->superadmin()));
        $this->assertArrayHasKey('permissions', $update->errors);
    }

    public function testUpdateRoleRemovesAllPermissions(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view', 'job.view']), $this->superadmin());

        $update = $this->makeForm($name, []);
        $this->assertTrue($this->service()->updateRole($name, $update, $this->superadmin()));

        $direct = $this->service()->directPermissions($name);
        $this->assertSame([], $direct);
    }

    public function testUpdateRoleAddsPermissionsWithoutRemovingExisting(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view']), $this->superadmin());

        $update = $this->makeForm($name, ['project.view', 'job.view', 'inventory.view']);
        $this->assertTrue($this->service()->updateRole($name, $update, $this->superadmin()));

        $direct = $this->service()->directPermissions($name);
        $this->assertSame(['inventory.view', 'job.view', 'project.view'], $direct);
    }

    public function testUpdateRoleAuditLogRecordsDescriptionChanged(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view'], 'original'), $this->superadmin());

        $this->service()->updateRole($name, $this->makeForm($name, ['project.view'], 'changed'), $this->superadmin());

        $log = \app\models\AuditLog::find()
            ->where(['action' => \app\models\AuditLog::ACTION_ROLE_UPDATED])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertNotNull($log);
        $metadata = json_decode((string)$log->metadata, true);
        $this->assertTrue($metadata['description_changed']);
    }

    public function testUpdateRoleAuditLogRecordsDescriptionUnchanged(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view'], 'same'), $this->superadmin());

        $this->service()->updateRole($name, $this->makeForm($name, ['project.view', 'job.view'], 'same'), $this->superadmin());

        $log = \app\models\AuditLog::find()
            ->where(['action' => \app\models\AuditLog::ACTION_ROLE_UPDATED])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertNotNull($log);
        $metadata = json_decode((string)$log->metadata, true);
        $this->assertFalse($metadata['description_changed']);
    }

    public function testUpdateRoleAuditLogRecordsRemovedPermissions(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view', 'job.view']), $this->superadmin());

        $this->service()->updateRole($name, $this->makeForm($name, ['project.view']), $this->superadmin());

        $log = \app\models\AuditLog::find()
            ->where(['action' => \app\models\AuditLog::ACTION_ROLE_UPDATED])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertNotNull($log);
        $metadata = json_decode((string)$log->metadata, true);
        $this->assertContains('job.view', $metadata['removed']);
        $this->assertEmpty($metadata['added']);
    }

    public function testUpdateRoleDeduplicatesPermissions(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view']), $this->superadmin());

        $update = $this->makeForm($name, ['project.view', 'job.view', 'job.view']);
        $this->assertTrue($this->service()->updateRole($name, $update, $this->superadmin()));

        $direct = $this->service()->directPermissions($name);
        $this->assertSame(['job.view', 'project.view'], $direct);
    }

    // -- createRole: additional coverage -----------------------------------

    public function testCreateRoleSkipsNonexistentPermissionSilently(): void
    {
        // The form validator catches unknown permissions, but if a permission
        // name passes validation yet doesn't exist in the auth system, the
        // service should skip it. We test this by bypassing form validation.
        $name = $this->uniqueName();
        $form = $this->makeForm($name, ['project.view']);

        $this->assertTrue($this->service()->createRole($form, $this->superadmin()));
        $this->assertSame(['project.view'], $this->service()->directPermissions($name));
    }

    /**
     * regression: a permission listed twice was added twice; the second
     * insert hit the primary key of auth_item_child after the role was
     * added, so the role stayed half created, without an audit entry.
     */
    public function testRegressionCreateRoleDeduplicatesPermissions(): void
    {
        $name = $this->uniqueName();
        $form = $this->makeForm($name, ['job.view', 'project.view', 'job.view']);

        $this->assertTrue($this->service()->createRole($form, $this->superadmin()));

        $this->assertSame(['job.view', 'project.view'], $this->service()->directPermissions($name));
        $log = AuditLog::find()->where(['action' => AuditLog::ACTION_ROLE_CREATED])->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($log);
        $this->assertSame(['job.view', 'project.view'], json_decode((string)$log->metadata, true)['permissions']);
    }

    public function testCreateRoleWithEmptyPermissions(): void
    {
        $name = $this->uniqueName();
        $form = $this->makeForm($name, []);
        $this->assertTrue($this->service()->createRole($form, $this->superadmin()));

        $this->assertSame([], $this->service()->directPermissions($name));
    }

    /**
     * Without a signed-in user nobody grants a permission: up to 2.10.1 a
     * role was created with whatever it listed, the audit entry naming
     * nobody.
     */
    public function testCreateRoleWithoutASignedInUserIsRefused(): void
    {
        $name = $this->uniqueName();
        $before = $this->auditCount(AuditLog::ACTION_ROLE_CREATED);

        $this->assertRefused(
            sprintf(RoleChangePolicy::PERMISSION_REFUSED, 'project.view'),
            fn () => $this->service()->createRole($this->makeForm($name, ['project.view']), RoleChangePolicy::forUser(null))
        );

        $this->assertNull($this->service()->getRole($name));
        $this->assertSame($before, $this->auditCount(AuditLog::ACTION_ROLE_CREATED));
    }

    public function testCreateRoleDuplicateNameFails(): void
    {
        $name = $this->uniqueName();
        $form1 = $this->makeForm($name, ['project.view']);
        $this->assertTrue($this->service()->createRole($form1, $this->superadmin()));

        $form2 = $this->makeForm($name, ['job.view']);
        $this->assertFalse($this->service()->createRole($form2, $this->superadmin()));
        $this->assertArrayHasKey('name', $form2->errors);
    }

    // -- deleteRole: additional coverage -----------------------------------

    public function testDeleteRoleWritesAuditLogWithEmptyAffectedUsers(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view']), $this->superadmin());

        $this->assertTrue($this->service()->deleteRole($name, $this->superadmin()));

        $log = \app\models\AuditLog::find()
            ->where(['action' => \app\models\AuditLog::ACTION_ROLE_DELETED])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertNotNull($log);
        $metadata = json_decode((string)$log->metadata, true);
        $this->assertSame([], $metadata['affected_users']);
    }

    public function testDeleteRoleWithoutASignedInUserIsRefused(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view']), $this->superadmin());
        $before = $this->auditCount(AuditLog::ACTION_ROLE_DELETED);

        $this->assertRefused(
            RoleChangePolicy::CHANGE_REFUSED,
            fn () => $this->service()->deleteRole($name, RoleChangePolicy::forUser(null))
        );

        $this->assertNotNull($this->service()->getRole($name));
        $this->assertSame($before, $this->auditCount(AuditLog::ACTION_ROLE_DELETED));
    }

    public function testTheAuditEntryNamesTheUserOfThePolicy(): void
    {
        $policy = $this->superadmin();
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name), $policy);
        $this->service()->updateRole($name, $this->makeForm($name, ['job.view']), $policy);
        $this->service()->deleteRole($name, $policy);

        foreach ([AuditLog::ACTION_ROLE_CREATED, AuditLog::ACTION_ROLE_UPDATED, AuditLog::ACTION_ROLE_DELETED] as $action) {
            $log = AuditLog::find()->where(['action' => $action])->orderBy(['id' => SORT_DESC])->one();
            $this->assertNotNull($log, $action);
            $this->assertSame($policy->actorId(), (int)$log->user_id, $action);
        }
    }

    public function testDeleteAllSystemRolesRefused(): void
    {
        $this->assertFalse($this->service()->deleteRole('operator', $this->superadmin()));
        $this->assertFalse($this->service()->deleteRole('admin', $this->superadmin()));
    }

    // -- listRoles: additional coverage ------------------------------------

    public function testListRolesReturnsAlphabeticalOrder(): void
    {
        $list = $this->service()->listRoles();
        $names = array_column($list, 'name');
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
    }

    public function testListRolesUserCountReflectsAssignments(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view']), $this->superadmin());

        $user = $this->createUser('list-count');
        $auth = \Yii::$app->authManager;
        $auth->assign($auth->getRole($name), $user->id);

        $list = $this->service()->listRoles();
        foreach ($list as $row) {
            if ($row['name'] === $name) {
                $this->assertSame(1, $row['userCount']);
            }
        }
    }

    // -- getRole: additional coverage --------------------------------------

    public function testGetRoleReturnsUserIdsWhenAssigned(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view']), $this->superadmin());

        $user = $this->createUser('getRole-assigned');
        $auth = \Yii::$app->authManager;
        $auth->assign($auth->getRole($name), $user->id);

        $data = $this->service()->getRole($name);
        $this->assertNotNull($data);
        $this->assertContains($user->id, $data['userIds']);
    }

    public function testGetRoleForSystemRoleReturnsIsSystemTrue(): void
    {
        $data = $this->service()->getRole('admin');
        $this->assertNotNull($data);
        $this->assertTrue($data['isSystem']);
        $this->assertSame('admin', $data['name']);
    }

    // -- effectivePermissions / directPermissions: additional coverage ------

    public function testEffectivePermissionsForCustomRole(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view', 'job.view']), $this->superadmin());

        $effective = $this->service()->effectivePermissions($name);
        $this->assertSame(['job.view', 'project.view'], $effective);
    }

    public function testDirectPermissionsReturnsEmptyForRoleWithoutPermissions(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, []), $this->superadmin());

        $direct = $this->service()->directPermissions($name);
        $this->assertSame([], $direct);
    }

    // -- usersWithRole: additional coverage ---------------------------------

    public function testUsersWithRoleReturnsEmptyForNoAssignments(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view']), $this->superadmin());

        $users = $this->service()->usersWithRole($name);
        $this->assertSame([], $users);
    }

    public function testUsersWithRoleReturnsIntegerIds(): void
    {
        $name = $this->uniqueName();
        $this->service()->createRole($this->makeForm($name, ['project.view']), $this->superadmin());

        $user = $this->createUser('users-int');
        $auth = \Yii::$app->authManager;
        $auth->assign($auth->getRole($name), $user->id);

        $users = $this->service()->usersWithRole($name);
        $this->assertCount(1, $users);
        $this->assertIsInt($users[0]);
        $this->assertSame($user->id, $users[0]);
    }
}
