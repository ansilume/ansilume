<?php

declare(strict_types=1);

namespace app\services;

use app\models\AuditLog;
use app\models\RoleForm;
use yii\base\Component;
use yii\rbac\Item;
use yii\rbac\Permission;
use yii\rbac\Role;

/**
 * Business logic for managing RBAC roles through the custom-role UI.
 *
 * Wraps Yii2's DbManager so the rest of the app doesn't have to know about
 * authManager internals, adds system-role protection, and emits audit events
 * for every mutation.
 *
 * System roles (viewer/operator/admin) may have their direct permissions
 * edited but cannot be renamed or deleted. Custom roles are flat — they
 * carry permissions only, never child roles.
 */
class RoleService extends Component
{
    /** @var string[] Built-in roles that cannot be renamed or deleted. */
    public const SYSTEM_ROLES = ['viewer', 'operator', 'admin'];

    public function isSystemRole(string $name): bool
    {
        return in_array($name, self::SYSTEM_ROLES, true);
    }

    /**
     * List all roles with summary info for the index view.
     *
     * @return array<int, array{name: string, description: string, isSystem: bool, permissionCount: int, userCount: int}>
     */
    public function listRoles(): array
    {
        $auth = $this->auth();
        $out = [];
        foreach ($auth->getRoles() as $role) {
            /** @var Role $role */
            $out[] = [
                'name' => $role->name,
                'description' => (string)$role->description,
                'isSystem' => $this->isSystemRole($role->name),
                'permissionCount' => count($auth->getPermissionsByRole($role->name)),
                'userCount' => count($auth->getUserIdsByRole($role->name)),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * Fetch a single role with direct + effective permissions and assigned users.
     *
     * @return array{name: string, description: string, isSystem: bool, directPermissions: string[], effectivePermissions: string[], userIds: int[]}|null
     */
    public function getRole(string $name): ?array
    {
        $auth = $this->auth();
        $role = $auth->getRole($name);
        if ($role === null) {
            return null;
        }
        return [
            'name' => $role->name,
            'description' => (string)$role->description,
            'isSystem' => $this->isSystemRole($role->name),
            'directPermissions' => $this->directPermissions($name),
            'effectivePermissions' => array_keys($auth->getPermissionsByRole($name)),
            'userIds' => array_map('intval', $auth->getUserIdsByRole($name)),
        ];
    }

    /**
     * Direct child permissions of the role (does not walk nested roles).
     *
     * @return string[]
     */
    public function directPermissions(string $name): array
    {
        $auth = $this->auth();
        $children = $auth->getChildren($name);
        $perms = [];
        foreach ($children as $child) {
            if ($child->type === Item::TYPE_PERMISSION) {
                $perms[] = $child->name;
            }
        }
        sort($perms);
        return $perms;
    }

    /**
     * All permissions the role grants, recursively walking child roles.
     *
     * @return string[]
     */
    public function effectivePermissions(string $name): array
    {
        $auth = $this->auth();
        $names = array_keys($auth->getPermissionsByRole($name));
        sort($names);
        return $names;
    }

    /**
     * User IDs that have this role directly assigned.
     *
     * @return int[]
     */
    public function usersWithRole(string $name): array
    {
        return array_map('intval', $this->auth()->getUserIdsByRole($name));
    }

    /**
     * Create a new custom role with the given permissions.
     */
    public function createRole(RoleForm $form, ?int $actorId): bool
    {
        if (!$form->validate()) {
            return false;
        }

        $auth = $this->auth();
        $role = $auth->createRole($form->name);
        $role->description = $form->description;
        $auth->add($role);

        foreach ($form->permissions as $permName) {
            $perm = $auth->getPermission($permName);
            if ($perm !== null) {
                $auth->addChild($role, $perm);
            }
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_ROLE_CREATED,
            'role',
            null,
            $actorId,
            [
                'name' => $form->name,
                'description' => $form->description,
                'permissions' => $form->permissions,
            ]
        );

        return true;
    }

    /**
     * Update a role: description + direct permissions. Name is immutable for
     * both system and custom roles via this service (simpler and avoids
     * cascading rename concerns).
     */
    public function updateRole(string $name, RoleForm $form, ?int $actorId): bool
    {
        $auth = $this->auth();
        $role = $auth->getRole($name);
        if ($role === null) {
            return false;
        }

        // Name field is read-only in the form for system roles; for custom
        // roles we also keep it fixed here and reject any mismatch, so the
        // caller must re-create if they want to rename.
        $form->originalName = $name;
        $form->name = $name;
        $form->isSystemRole = $this->isSystemRole($name);

        if (!$form->validate()) {
            return false;
        }

        $oldDescription = (string)$role->description;
        $role->description = $form->description;
        $auth->update($name, $role);

        $current = $this->directPermissions($name);
        $target = array_values(array_unique($form->permissions));

        $toAdd = array_values(array_diff($target, $current));
        $toRemove = array_values(array_diff($current, $target));

        foreach ($toRemove as $permName) {
            $perm = $auth->getPermission($permName);
            if ($perm !== null) {
                $auth->removeChild($role, $perm);
            }
        }
        foreach ($toAdd as $permName) {
            $perm = $auth->getPermission($permName);
            if ($perm !== null) {
                $auth->addChild($role, $perm);
            }
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_ROLE_UPDATED,
            'role',
            null,
            $actorId,
            [
                'name' => $name,
                'description_changed' => $oldDescription !== $form->description,
                'added' => $toAdd,
                'removed' => $toRemove,
            ]
        );

        return true;
    }

    /**
     * Delete a custom role. System roles cannot be deleted. Users that held
     * the role keep their account; their assignment row is cascaded away by
     * the auth_assignment FK and they are left without a role.
     *
     * @return bool true if deleted, false if not found or refused (system role)
     */
    public function deleteRole(string $name, ?int $actorId): bool
    {
        if ($this->isSystemRole($name)) {
            return false;
        }

        $auth = $this->auth();
        $role = $auth->getRole($name);
        if ($role === null) {
            return false;
        }

        $affectedUsers = $this->usersWithRole($name);

        if (!$auth->remove($role)) {
            return false;
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_ROLE_DELETED,
            'role',
            null,
            $actorId,
            [
                'name' => $name,
                'affected_users' => $affectedUsers,
            ]
        );

        return true;
    }

    private function auth(): \yii\rbac\ManagerInterface
    {
        $auth = \Yii::$app->authManager;
        if ($auth === null) {
            throw new \RuntimeException('authManager component is not configured.');
        }
        return $auth;
    }
}
