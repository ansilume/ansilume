<?php

declare(strict_types=1);

namespace app\commands;

use app\models\User;
use yii\rbac\ManagerInterface;
use yii\rbac\Role;

/**
 * The users of tests/e2e/tests/users/admin-role.spec.ts, for the admin-role
 * rule: the admin role grants more than its permissions, every
 * team-restricted project, so only superadmins and holders of the admin role
 * assign it (app\services\RoleGrantPolicy), change a user who holds it
 * (UserChangePolicy) or change the role itself (RoleChangePolicy).
 *
 * - USERNAME holds only ROLE, which holds every permission of the admin role
 *   as its own, those of its child roles included, but neither the admin
 *   role nor a role that contains it. So it may not assign the admin role,
 *   change TARGET or change the admin role.
 * - TARGET holds only the admin role and is no superadmin. It is inactive,
 *   and every seed makes it inactive again: inactive users never approve
 *   (app\services\ApproverFilter), so the approval specs keep their
 *   approvers. It never signs in, so its password is random.
 *
 * Restored on every seed: ROLE with exactly the permissions the admin role
 * grants at that moment, both users (no superadmins; USERNAME active with the
 * fixture password of tests/e2e/lib/session.ts, TARGET inactive) and exactly
 * their roles. Teardown removes them all (users and custom roles named
 * e2e-*).
 */
final class E2eAdminRoleSeeder
{
    /** The role of USERNAME: every permission of the admin role as its own, without the role. */
    public const ROLE = 'e2e-allperm-role';

    /** An active user who holds only ROLE. */
    public const USERNAME = 'e2e-allperm-user';

    /** An inactive user who holds only the admin role. */
    public const TARGET = 'e2e-dormant-chief';

    /** What a seed says of ROLE: its name, how many permissions it holds and the role they are the permissions of. */
    public const ROLE_RESTORED = "  Restored role '%s' with the %d permissions of the role '%s', without that role.\n";

    /** What a seed says of USERNAME: their name, ID and roles, joined with "', '". */
    public const HOLDER_RESTORED = "  Restored user '%s' (ID %d, active) with the roles '%s'.\n";

    /** What a seed says of TARGET: their name, ID and roles, joined with "', '". */
    public const TARGET_RESTORED = "  Restored user '%s' (ID %d, inactive) with the roles '%s'.\n";

    /** What a seed says of a user it could not save: their name and the validation errors as JSON. */
    public const NOT_RESTORED = "  Failed to restore user '%s': %s\n";

    /** The admin role (app\services\RoleGrantPolicy::ADMIN_ROLE): what ROLE copies and TARGET holds. */
    private const ADMIN_ROLE = 'admin';

    /** The description of ROLE, as the role pages and the Role field show it. */
    private const ROLE_DESCRIPTION = 'E2E: each permission of the full-access role, granted directly, without that role';

    /** The fixture password of USERNAME in tests/e2e/lib/session.ts; not a secret. */
    private const FIXTURE_PASSWORD = 'E2eAllpermUserPass1!';

    /** @var callable(string): void */
    private $logger;

    /**
     * @param callable(string): void $logger
     */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(): void
    {
        /** @var ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        $role = $this->roleWithTheAdminPermissions($auth);

        $holder = $this->restoredUser(self::USERNAME, User::STATUS_ACTIVE);
        $holder->setPassword(self::FIXTURE_PASSWORD);
        $this->saveWithRoles($auth, $holder, [$role], self::HOLDER_RESTORED);

        $target = $this->restoredUser(self::TARGET, User::STATUS_INACTIVE);
        if ($target->isNewRecord) {
            $target->setPassword(\Yii::$app->security->generateRandomString(24));
        }
        $adminRoles = array_values(array_filter([$auth->getRole(self::ADMIN_ROLE)]));
        $this->saveWithRoles($auth, $target, $adminRoles, self::TARGET_RESTORED);
    }

    /**
     * ROLE with exactly the permissions the admin role grants now, as its
     * own children: those of the admin role's child roles too, and no role.
     */
    private function roleWithTheAdminPermissions(ManagerInterface $auth): Role
    {
        $role = $auth->getRole(self::ROLE);
        if ($role === null) {
            $role = $auth->createRole(self::ROLE);
            $role->description = self::ROLE_DESCRIPTION;
            $auth->add($role);
        }
        $auth->removeChildren($role);
        $permissions = $auth->getPermissionsByRole(self::ADMIN_ROLE);
        foreach ($permissions as $permission) {
            $auth->addChild($role, $permission);
        }
        ($this->logger)(sprintf(self::ROLE_RESTORED, self::ROLE, count($permissions), self::ADMIN_ROLE));

        return $role;
    }

    /**
     * The user $name, stored or new, as the spec expects it: $status, no
     * superadmin; not saved yet.
     */
    private function restoredUser(string $name, int $status): User
    {
        $user = User::findOne(['username' => $name]) ?? new User();
        $user->username = $name;
        $user->email = $name . '@example.com';
        $user->status = $status;
        $user->is_superadmin = false;
        if ($user->isNewRecord) {
            $user->generateAuthKey();
        }

        return $user;
    }

    /**
     * Saves $user with exactly $roles and says so with $restored
     * (HOLDER_RESTORED or TARGET_RESTORED); a user that cannot be saved gets
     * no role, and the seed says why (NOT_RESTORED).
     *
     * @param list<Role> $roles
     */
    private function saveWithRoles(ManagerInterface $auth, User $user, array $roles, string $restored): void
    {
        if (!$user->save()) {
            ($this->logger)(sprintf(self::NOT_RESTORED, $user->username, (string)json_encode($user->errors)));
            return;
        }
        $auth->revokeAll((int)$user->id);
        foreach ($roles as $role) {
            $auth->assign($role, (int)$user->id);
        }
        $names = implode("', '", array_map(static fn (Role $role): string => $role->name, $roles));

        ($this->logger)(sprintf($restored, $user->username, (int)$user->id, $names));
    }
}
