<?php

declare(strict_types=1);

use yii\db\Migration;
use yii\rbac\BaseManager;
use yii\rbac\ManagerInterface;
use yii\rbac\Permission;
use yii\rbac\Role;

/**
 * Runners are managed by admins: the operator role loses runner-group.create
 * and runner-group.update, which m000025 granted it.
 *
 * With runner-group.update a user registers a runner in any runner group (the
 * web UI shows its raw token once), regenerates the token of any runner (the
 * web UI and POST /api/v1/runners/{id}/regenerate-token hand out the new raw
 * token) and so takes the runner over, and moves runners between groups.
 * Runner groups have no team scope. A runner claims every queued job of its
 * group (JobClaimService::claim()) and receives the job's decrypted
 * credentials, inventory and extra vars (JobClaimService::buildExecutionPayload()):
 * the jobs of every team, approved jobs included. Operators keep seeing
 * runner groups and their runners (runner-group.view); runner-group.delete
 * was admin-only already.
 *
 * The admin role inherits operator. Where an admin removed admin's own direct
 * grant through the role editor, admin held a permission only through
 * operator: it is granted to admin directly before operator loses it, so
 * admins never lose it. A permission the admin role does not hold now is not
 * granted to it. Custom roles keep what they hold: a custom role with both
 * permissions lets other users manage runners, and so take over the jobs of
 * every runner group.
 *
 * Only the operator role's own grants are taken: what operator includes
 * keeps what it holds, as an admin decides that. The role editor lets admins
 * give these permissions to viewer, which operator includes and which is the
 * default role of every signed-in user. For each role or permission below
 * operator that still grants one of them directly, a line names it
 * (EVERY_USER_STILL_HOLDS where it is a default role or one includes it,
 * STILL_HELD otherwise), and the closing line that operators no longer
 * manage runners (RUNNERS_FOR_ADMINS) is printed only when operator lost a
 * permission and holds neither any more.
 *
 * Existing runners keep running: their tokens and groups stay as they are.
 *
 * Data only (RBAC rows). A permission or role that does not exist is reported
 * and nothing is changed for it; roles are never created. Running it again
 * changes nothing, and names again what still grants operators a permission.
 * Down gives the operator role both permissions back.
 */
class m000087_000000_restrict_runner_management extends Migration
{
    /** The permissions operators lose. runner-group.delete was admin-only already. */
    public const PERMISSIONS = ['runner-group.create', 'runner-group.update'];

    /** sprintf: the permission. */
    public const PERMISSION_MISSING = 'Permission %s does not exist: nothing to change.';

    /** sprintf: the role (admin or operator). */
    public const ROLE_MISSING = 'Role %s does not exist: nothing to change for it.';

    /** sprintf: the permission. */
    public const GRANTED_TO_ADMIN = 'Granted %s to the admin role directly.';

    /** sprintf: the permission. */
    public const REMOVED_FROM_OPERATOR = 'Removed %s from the operator role.';

    /**
     * After the removals, for each role or permission below the operator role
     * that still grants it a permission of PERMISSIONS directly. sprintf: the
     * permission, the name of that role or permission, and "role" or
     * "permission".
     */
    public const STILL_HELD = 'Operators still hold %1$s through the %2$s %3$s: '
        . 'take it from that %3$s to leave runner management to admins.';

    /**
     * STILL_HELD for a default role of the RBAC manager, which every signed-in
     * user holds (viewer), and for a role or permission that one includes.
     */
    public const EVERY_USER_STILL_HOLDS = 'Every signed-in user still holds %1$s through the %2$s %3$s: '
        . 'take it from that %3$s to leave runner management to admins.';

    /**
     * The closing line, printed when the operator role lost a permission and
     * holds neither of them any more.
     */
    public const RUNNERS_FOR_ADMINS = 'Operators no longer register, move or delete runners, regenerate runner tokens, '
        . 'or create and change runner groups. To let other users do so, give them a custom role with '
        . 'runner-group.create and runner-group.update; with it they can take over the jobs of every runner group.';

    public const NO_AUTH_MANAGER = 'The authManager component is not configured.';

    public function safeUp(): void
    {
        $auth = $this->auth();
        $admin = $this->role($auth, 'admin');
        $operator = $this->role($auth, 'operator');
        if ($operator === null) {
            return;
        }
        $removed = $this->takeAllFromOperator($auth, $operator, $admin);
        $stillHeld = $this->nameWhatStillGrantsThem($auth, $operator);
        if ($removed && !$stillHeld) {
            $this->say(self::RUNNERS_FOR_ADMINS);
        }
    }

    public function safeDown(): void
    {
        $auth = $this->auth();
        $operator = $auth->getRole('operator');
        if ($operator === null) {
            return;
        }
        foreach (self::PERMISSIONS as $name) {
            $permission = $auth->getPermission($name);
            if ($permission !== null && !$auth->hasChild($operator, $permission)) {
                $auth->addChild($operator, $permission);
            }
        }
    }

    /**
     * Takes each permission of PERMISSIONS from the operator role; true when
     * operator lost one. A permission that does not exist is reported.
     */
    private function takeAllFromOperator(ManagerInterface $auth, Role $operator, ?Role $admin): bool
    {
        $removed = false;
        foreach (self::PERMISSIONS as $name) {
            $permission = $auth->getPermission($name);
            if ($permission === null) {
                $this->say(sprintf(self::PERMISSION_MISSING, $name));
                continue;
            }
            $removed = $this->takeFromOperator($auth, $permission, $operator, $admin) || $removed;
        }

        return $removed;
    }

    /**
     * Takes $permission from the operator role; true when operator held it
     * directly and lost it. The admin role gets it directly first where it
     * holds it now without a direct grant, through operator.
     */
    private function takeFromOperator(ManagerInterface $auth, Permission $permission, Role $operator, ?Role $admin): bool
    {
        if (!$auth->hasChild($operator, $permission)) {
            return false;
        }
        if ($admin !== null && $this->holdsOnlyIndirectly($auth, $admin, $permission)) {
            $auth->addChild($admin, $permission);
            $this->say(sprintf(self::GRANTED_TO_ADMIN, $permission->name));
        }
        $auth->removeChild($operator, $permission);
        $this->say(sprintf(self::REMOVED_FROM_OPERATOR, $permission->name));

        return true;
    }

    /**
     * Whether $role holds $permission through one of its child roles but not
     * as a direct child.
     */
    private function holdsOnlyIndirectly(ManagerInterface $auth, Role $role, Permission $permission): bool
    {
        return !$auth->hasChild($role, $permission)
            && isset($auth->getPermissionsByRole($role->name)[$permission->name]);
    }

    /**
     * Names each role or permission below the operator role that still grants
     * it a permission of PERMISSIONS directly, by permission and then by name;
     * true when operator still holds one of them.
     */
    private function nameWhatStillGrantsThem(ManagerInterface $auth, Role $operator): bool
    {
        $held = array_intersect(self::PERMISSIONS, array_keys($auth->getPermissionsByRole($operator->name)));
        if ($held === []) {
            return false;
        }
        $below = $this->itemsBelow($auth, [$operator->name]);
        unset($below[$operator->name]);
        $everyUser = $this->itemsBelow($auth, $this->defaultRoles($auth));
        foreach ($held as $permission) {
            foreach ($this->grantorsOf($permission, $below) as $grantor) {
                $this->say($this->stillHeld($auth, $permission, $grantor, isset($everyUser[$grantor])));
            }
        }

        return true;
    }

    /**
     * The line for $grantor, a role or permission below the operator role that
     * grants $permission directly; $everyUser when it is a default role or one
     * includes it.
     */
    private function stillHeld(ManagerInterface $auth, string $permission, string $grantor, bool $everyUser): string
    {
        return sprintf(
            $everyUser ? self::EVERY_USER_STILL_HOLDS : self::STILL_HELD,
            $permission,
            $grantor,
            $auth->getRole($grantor) === null ? 'permission' : 'role'
        );
    }

    /**
     * The names of the roles and permissions of $items that grant $permission
     * directly, sorted.
     *
     * @param array<string, list<string>> $items the names of each one's direct children, by its name
     * @return list<string>
     */
    private function grantorsOf(string $permission, array $items): array
    {
        $grantors = [];
        foreach ($items as $name => $children) {
            if (in_array($permission, $children, true)) {
                $grantors[] = (string)$name;
            }
        }
        sort($grantors);

        return $grantors;
    }

    /**
     * The roles and permissions that $roots include, directly or through
     * others, and $roots themselves: the names of each one's direct children,
     * by its name.
     *
     * @param list<string> $roots
     * @return array<string, list<string>>
     */
    private function itemsBelow(ManagerInterface $auth, array $roots): array
    {
        $items = [];
        $pending = $roots;
        while ($pending !== []) {
            $name = array_pop($pending);
            if (isset($items[$name])) {
                continue;
            }
            $items[$name] = [];
            foreach ($auth->getChildren($name) as $child) {
                $items[$name][] = $child->name;
                $pending[] = $child->name;
            }
        }

        return $items;
    }

    /**
     * The default roles of the RBAC manager, which every signed-in user holds:
     * config/web.php and config/console.php give viewer.
     *
     * @return list<string>
     */
    private function defaultRoles(ManagerInterface $auth): array
    {
        return $auth instanceof BaseManager ? array_values($auth->getDefaultRoles()) : [];
    }

    /**
     * The role $name; null, with a line that says so, when it does not exist.
     */
    private function role(ManagerInterface $auth, string $name): ?Role
    {
        $role = $auth->getRole($name);
        if ($role === null) {
            $this->say(sprintf(self::ROLE_MISSING, $name));
        }

        return $role;
    }

    private function say(string $line): void
    {
        echo '    > ' . $line . "\n";
    }

    private function auth(): ManagerInterface
    {
        $auth = \Yii::$app->authManager;
        if ($auth === null) {
            throw new \RuntimeException(self::NO_AUTH_MANAGER);
        }

        return $auth;
    }
}
