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
 *
 * Every write is made as the user of a RoleChangePolicy, who must be allowed
 * to: a role is created only with permissions they hold, and changed or
 * deleted only when they may assign it, adding only permissions they hold.
 * A refusal throws RoleChangeRefusedException before anything is written.
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
     * Create a new custom role with the given permissions, as $policy's user
     * may: only with permissions they hold (RoleChangePolicy). False when the
     * form does not validate.
     *
     * @throws RoleChangeRefusedException when the form lists a permission the
     *     user may not grant (RoleChangePolicy::PERMISSION_REFUSED); nothing
     *     is written
     */
    public function createRole(RoleForm $form, RoleChangePolicy $policy): bool
    {
        if (!$form->validate()) {
            return false;
        }
        // Each permission once: a second addChild() of one would hit the
        // primary key of auth_item_child after the role was added.
        $permissions = array_values(array_unique($form->permissions));
        $this->refuseUngrantable($policy, $permissions);

        $auth = $this->auth();
        $role = $auth->createRole($form->name);
        $role->description = $form->description;
        $auth->add($role);
        $this->addPermissions($role, $permissions);

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_ROLE_CREATED,
            'role',
            null,
            $policy->actorId(),
            [
                'name' => $form->name,
                'description' => $form->description,
                'permissions' => $permissions,
            ]
        );

        return true;
    }

    /**
     * Update a role: description + direct permissions. Name is immutable for
     * both system and custom roles via this service (simpler and avoids
     * cascading rename concerns). $policy's user must be allowed to assign
     * the role as it is, and to grant every permission the change adds
     * (RoleChangePolicy); removing permissions needs no more. False when the
     * role does not exist or the form does not validate.
     *
     * @throws RoleChangeRefusedException when the user may not change the
     *     role (RoleChangePolicy::CHANGE_REFUSED, before the form is
     *     validated) or grant a permission it adds (PERMISSION_REFUSED);
     *     nothing is written
     */
    public function updateRole(string $name, RoleForm $form, RoleChangePolicy $policy): bool
    {
        $auth = $this->auth();
        $role = $auth->getRole($name);
        if ($role === null) {
            return false;
        }
        $this->refuseChange($policy, $name);

        // Name field is read-only in the form for system roles; for custom
        // roles we also keep it fixed here and reject any mismatch, so the
        // caller must re-create if they want to rename.
        $form->originalName = $name;
        $form->name = $name;
        $form->isSystemRole = $this->isSystemRole($name);

        if (!$form->validate()) {
            return false;
        }

        $current = $this->directPermissions($name);
        $target = array_values(array_unique($form->permissions));
        $toAdd = array_values(array_diff($target, $current));
        $toRemove = array_values(array_diff($current, $target));
        $this->refuseUngrantable($policy, $toAdd);

        $oldDescription = (string)$role->description;
        $role->description = $form->description;
        $auth->update($name, $role);
        $this->removePermissions($role, $toRemove);
        $this->addPermissions($role, $toAdd);

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_ROLE_UPDATED,
            'role',
            null,
            $policy->actorId(),
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
     * the auth_assignment FK and they are left without a role. $policy's
     * user must be allowed to assign the role as it is (RoleChangePolicy).
     *
     * @return bool true if deleted, false if not found or refused (system role)
     * @throws RoleChangeRefusedException when the user may not change the
     *     role (RoleChangePolicy::CHANGE_REFUSED); nothing is written
     */
    public function deleteRole(string $name, RoleChangePolicy $policy): bool
    {
        if ($this->isSystemRole($name)) {
            return false;
        }

        $auth = $this->auth();
        $role = $auth->getRole($name);
        if ($role === null) {
            return false;
        }
        $this->refuseChange($policy, $name);

        $affectedUsers = $this->usersWithRole($name);

        if (!$auth->remove($role)) {
            return false;
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_ROLE_DELETED,
            'role',
            null,
            $policy->actorId(),
            [
                'name' => $name,
                'affected_users' => $affectedUsers,
            ]
        );

        return true;
    }

    /**
     * @throws RoleChangeRefusedException (RoleChangePolicy::CHANGE_REFUSED)
     *     when $policy's user may not change the role $name
     */
    private function refuseChange(RoleChangePolicy $policy, string $name): void
    {
        if (!$policy->mayChange($name)) {
            throw new RoleChangeRefusedException(RoleChangePolicy::CHANGE_REFUSED);
        }
    }

    /**
     * @param array<int, string> $permissions what a save would grant
     * @throws RoleChangeRefusedException (RoleChangePolicy::PERMISSION_REFUSED)
     *     naming the first of $permissions $policy's user may not grant
     */
    private function refuseUngrantable(RoleChangePolicy $policy, array $permissions): void
    {
        $refused = $policy->firstRefused($permissions);
        if ($refused !== null) {
            throw new RoleChangeRefusedException(sprintf(RoleChangePolicy::PERMISSION_REFUSED, $refused));
        }
    }

    /**
     * @param array<int, string> $permissions
     */
    private function addPermissions(Role $role, array $permissions): void
    {
        $auth = $this->auth();
        foreach ($permissions as $permName) {
            $perm = $auth->getPermission($permName);
            if ($perm !== null) {
                $auth->addChild($role, $perm);
            }
        }
    }

    /**
     * @param array<int, string> $permissions
     */
    private function removePermissions(Role $role, array $permissions): void
    {
        $auth = $this->auth();
        foreach ($permissions as $permName) {
            $perm = $auth->getPermission($permName);
            if ($perm !== null) {
                $auth->removeChild($role, $perm);
            }
        }
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
