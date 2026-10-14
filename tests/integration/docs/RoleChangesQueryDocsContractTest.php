<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\models\AuditLog;
use app\models\RoleForm;
use app\models\User;
use app\services\RoleChangePolicy;
use app\services\RoleService;
use app\tests\integration\DbTestCase;
use app\tests\unit\docs\ReadsOperatorDocs;

/**
 * The read-only query that docs/updating.md gives under **Security: who
 * may change roles** runs on the schema of this release, in the test's
 * transaction, and lists what its text says: the role.created,
 * role.updated and role.deleted entries of users who are no superadmin, by
 * ID, with the role's name, and the metadata whose permissions, added and
 * removed the item names, as RoleService writes them. A user who no longer
 * exists counts as no superadmin. Not listed: the changes of a superadmin
 * and the entries of other actions. Of the built-in roles only admin holds
 * role.create, role.update and role.delete, as the item says.
 */
class RoleChangesQueryDocsContractTest extends DbTestCase
{
    use ReadsOperatorDocs;
    use RunsTheUpdateSectionQueries;

    /** The comment the query starts with. */
    private const COMMENT = '-- Roles created, changed or deleted by users who are no superadmin';

    /** The columns the query returns, in its order. */
    private const COLUMNS = ['id', 'changed_at', 'action', 'changed_by', 'role_name', 'metadata'];

    /** The permissions that change roles. */
    private const ROLE_PERMISSIONS = ['role.create', 'role.update', 'role.delete'];

    public function testTheQueryListsTheRoleChangesOfUsersWhoAreNoSuperadminWithWhatTheyGave(): void
    {
        $manager = $this->userWith($this->roleManagerRole());
        $superadmin = $this->createUser('docs-roles-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $managerPolicy = RoleChangePolicy::forUser($manager);
        $name = 'docs-rq-' . substr(md5(uniqid('', true)), 0, 8);

        $before = $this->lastAuditId();
        $this->assertTrue($this->service()->createRole($this->form($name, ['role.view', 'role.create']), $managerPolicy));
        $this->assertTrue($this->service()->updateRole($name, $this->form($name, ['role.view', 'role.update']), $managerPolicy));
        $this->assertTrue($this->service()->deleteRole($name, $managerPolicy));
        [$created, $updated, $deleted] = $this->auditIdsSince($before);

        $byThem = 'docs-rq-super-' . substr(md5(uniqid('', true)), 0, 8);
        $this->assertTrue($this->service()->createRole($this->form($byThem, ['role.view']), RoleChangePolicy::forUser($superadmin)));
        $bySuperadmin = $this->lastAuditId();
        $gone = $this->entry(AuditLog::ACTION_ROLE_UPDATED, (int)(User::find()->max('id')) + 1000, ['name' => $byThem]);
        $other = $this->entry(AuditLog::ACTION_USER_UPDATED, (int)$manager->id, ['name' => $byThem]);

        $rows = $this->rowsWhere(self::COMMENT, 'id', [$created, $updated, $deleted, $bySuperadmin, $gone, $other]);
        $this->assertSame([$created, $updated, $deleted, $gone], array_map(static fn (array $row): int => (int)$row['id'], $rows), 'The changes of the role manager and of a user who no longer exists, by ID.');
        $this->assertSame(self::COLUMNS, array_keys($rows[0]));
        $this->assertSame(
            [[AuditLog::ACTION_ROLE_CREATED, (int)$manager->id, $name], [AuditLog::ACTION_ROLE_UPDATED, (int)$manager->id, $name], [AuditLog::ACTION_ROLE_DELETED, (int)$manager->id, $name]],
            array_map(static fn (array $row): array => [$row['action'], (int)$row['changed_by'], $row['role_name']], array_slice($rows, 0, 3))
        );
        $this->assertSame(['role.view', 'role.create'], $this->metadataOf($rows[0])['permissions'] ?? null, 'role.created lists the permissions of the role.');
        $this->assertSame(['role.update'], $this->metadataOf($rows[1])['added'] ?? null, 'role.updated lists what it added.');
        $this->assertSame(['role.create'], $this->metadataOf($rows[1])['removed'] ?? null, 'role.updated lists what it removed.');
    }

    public function testOfTheBuiltInRolesOnlyAdminChangesRoles(): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        foreach (['viewer' => [], 'operator' => [], 'admin' => self::ROLE_PERMISSIONS] as $role => $held) {
            $this->assertSame(
                $held,
                array_values(array_intersect(self::ROLE_PERMISSIONS, array_keys($auth->getPermissionsByRole($role)))),
                "The role permissions {$role} holds."
            );
        }
    }

    private function service(): RoleService
    {
        /** @var RoleService $service */
        $service = \Yii::$app->get('roleService');

        return $service;
    }

    /**
     * @param list<string> $permissions
     */
    private function form(string $name, array $permissions): RoleForm
    {
        $form = new RoleForm();
        $form->name = $name;
        $form->description = 'docs role';
        $form->permissions = $permissions;

        return $form;
    }

    /**
     * A role manager's role: the role permissions and role.view; the test
     * config has no default role, so they hold nothing else.
     */
    private function roleManagerRole(): string
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('docs-role-manager-' . substr(md5(uniqid('', true)), 0, 8));
        $auth->add($role);
        foreach (['role.view', ...self::ROLE_PERMISSIONS] as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }

        return $role->name;
    }

    private function userWith(string $role): User
    {
        $user = $this->createUser('docs-role-manager');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $item = $auth->getRole($role);
        $this->assertNotNull($item, $role);
        $auth->assign($item, (string)$user->id);

        return $user;
    }

    private function lastAuditId(): int
    {
        return (int)AuditLog::find()->max('id');
    }

    /**
     * The IDs of the audit entries after $id, in order.
     *
     * @return list<int>
     */
    private function auditIdsSince(int $id): array
    {
        return array_map('intval', AuditLog::find()->select('id')->where(['>', 'id', $id])->orderBy(['id' => SORT_ASC])->column());
    }

    /**
     * An audit entry of $action on a role by $userId; its ID.
     *
     * @param array<string, mixed> $metadata
     */
    private function entry(string $action, int $userId, array $metadata): int
    {
        $db = \Yii::$app->db;
        $db->createCommand()->insert('{{%audit_log}}', [
            'user_id' => $userId,
            'action' => $action,
            'object_type' => 'role',
            'object_id' => null,
            'metadata' => json_encode($metadata),
            'created_at' => 1760000000,
        ])->execute();

        return (int)$db->getLastInsertID();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function metadataOf(array $row): array
    {
        $metadata = json_decode((string)$row['metadata'], true);
        $this->assertIsArray($metadata);

        return $metadata;
    }
}
