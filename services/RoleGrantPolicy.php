<?php

declare(strict_types=1);

namespace app\services;

use app\models\User;
use yii\rbac\ManagerInterface;
use yii\rbac\Role;

/**
 * Who may give a user a role or the superadmin flag: on the Users pages
 * (UserForm) and through the users API (UsersController).
 *
 * A superadmin may assign every role and set or clear the superadmin flag.
 * Anyone else may assign a role only when they hold every permission the
 * role grants, the permissions of its child roles included; the default
 * roles count for them as they count wherever access is checked
 * (checkAccess()). They never change the superadmin flag. Up to 2.10.1,
 * whoever held user.update or user.create, such as a custom help desk role,
 * could make any user, themselves included, an admin or a superadmin, who
 * bypasses every permission, team scope and approval gate.
 *
 * The admin role (ADMIN_ROLE) grants more than its permissions: every
 * team-restricted project (ProjectAccessChecker). So the admin role, and a
 * role that contains it, is assigned only by a superadmin or by a user who
 * holds the admin role, not by one who holds every permission it grants
 * through a role of their own (ADMIN_ROLE_REFUSED).
 *
 * Keeping a role assigns nothing: saving the Edit User form with the role it
 * shows, or sending the API a "role" the user already has, leaves the user's
 * roles as they are, whoever saves them. Without a signed-in user nobody may
 * assign a role.
 *
 * Whoever holds a role holds every permission it grants, so a role the user
 * who assigns holds (checkAccess() on the role) is decided at once, without
 * a check per permission: for an admin, the Edit User page no longer costs
 * a few queries for every permission of the instance. Every decision is kept
 * for the life of the instance, so the users list decides once per role, not
 * once per user.
 *
 * Who may change or delete which user builds on this (UserChangePolicy).
 */
final class RoleGrantPolicy
{
    /**
     * The admin role: beyond its permissions it grants every team-restricted
     * project (ProjectAccessChecker::resolveRole()).
     */
    public const ADMIN_ROLE = 'admin';

    /**
     * Why a role is not assigned: the error of the Role field of the user
     * form and the 403 of POST and PUT /api/v1/users. %s: the role's name.
     */
    public const ROLE_REFUSED = 'You cannot assign the role "%s": it grants permissions you do not hold.';

    /**
     * Why the admin role, or a role that contains it, is not assigned by a
     * user who holds every permission it grants, through a role of their
     * own, but neither is a superadmin nor holds the admin role: the error
     * of the Role field of the user form and the 403 of POST and PUT
     * /api/v1/users, in place of ROLE_REFUSED. %s: the role's name.
     */
    public const ADMIN_ROLE_REFUSED = 'You cannot assign the role "%s": it is or contains the admin role, which only superadmins and admins may assign.';

    /**
     * The 403 of POST and PUT /api/v1/users whose is_superadmin would change
     * the flag, for a caller who is no superadmin. The user form ignores the
     * field for them.
     */
    public const SUPERADMIN_REFUSED = 'Only a superadmin can set or clear is_superadmin.';

    /** The ID of the user who assigns; null when nobody is signed in. */
    private readonly ?int $actorId;

    private readonly bool $superadmin;

    /** Whether the user who assigns holds the admin role; null until asked. */
    private ?bool $holdsAdminRole = null;

    /** @var array<string, bool> permission name => whether the actor holds it, as checkAccess() answered */
    private array $held = [];

    /** @var array<string, bool> role name => whether the actor holds every permission the role grants */
    private array $heldRoles = [];

    /** @var array<string, bool> role name => whether the role is the admin role or contains it */
    private array $adminRoles = [];

    /** @var array<string, bool> role name => whether the instance has the role */
    private array $roles = [];

    /** @var array<string, bool> role name => what mayAssign() answered */
    private array $assignableRoles = [];

    public function __construct(private readonly ManagerInterface $auth, ?User $actor)
    {
        $this->actorId = $actor !== null ? (int)$actor->id : null;
        $this->superadmin = $actor !== null && (bool)$actor->is_superadmin;
    }

    /**
     * The policy of the user signed in to the application's user component;
     * of nobody when nobody is signed in or the application has no user
     * component, as a console application.
     */
    public static function forCurrentUser(): self
    {
        $component = \Yii::$app->has('user') ? \Yii::$app->get('user') : null;
        $identity = $component instanceof \yii\web\User ? $component->getIdentity() : null;
        /** @var ManagerInterface $auth */
        $auth = \Yii::$app->authManager;

        return new self($auth, $identity instanceof User ? $identity : null);
    }

    /**
     * Whether the user who assigns is a superadmin: who alone sets or clears
     * the superadmin flag.
     */
    public function isSuperadmin(): bool
    {
        return $this->superadmin;
    }

    /**
     * Whether a user assigns: false when nobody is signed in, who may give
     * nothing and change nobody.
     */
    public function hasActor(): bool
    {
        return $this->actorId !== null;
    }

    /**
     * The ID of the user who assigns; null when nobody is signed in.
     */
    public function actorId(): ?int
    {
        return $this->actorId;
    }

    /**
     * Whether the user who assigns reaches every team-restricted project, as
     * ProjectAccessChecker decides it: a superadmin, or a user who holds the
     * admin role (checkAccess() on ADMIN_ROLE: assigned, through a role that
     * contains it or through the default roles). Only they assign the admin
     * role or a role that contains it; nobody without a signed-in user.
     */
    public function reachesEveryProject(): bool
    {
        if ($this->superadmin || $this->actorId === null) {
            return $this->superadmin;
        }

        return $this->holdsAdminRole ??= $this->auth->checkAccess($this->actorId, self::ADMIN_ROLE);
    }

    /**
     * Whether the user who assigns may assign $roleName: never a role the
     * instance does not have.
     */
    public function mayAssign(string $roleName): bool
    {
        return $this->assignableRoles[$roleName] ??= $this->exists($roleName) && $this->grants($roleName);
    }

    /**
     * Why the user who assigns may not assign $roleName; null when they may
     * (mayAssign()). ADMIN_ROLE_REFUSED when they hold every permission of
     * the role, which is the admin role or contains it, and only the admin
     * role keeps it from them; ROLE_REFUSED otherwise. Both name the role.
     */
    public function refusalOf(string $roleName): ?string
    {
        if ($this->mayAssign($roleName)) {
            return null;
        }
        $onlyTheAdminRoleRefuses = $this->holdsAllOf($roleName) && $this->containsTheAdminRole($roleName)
            && $this->exists($roleName);

        return sprintf($onlyTheAdminRoleRefuses ? self::ADMIN_ROLE_REFUSED : self::ROLE_REFUSED, $roleName);
    }

    /**
     * The roles of $options the user who assigns may assign, in their order,
     * and $kept, the role a form shows as the user's, also where they may not
     * assign it: saving it unchanged assigns nothing.
     *
     * @param array<string, string> $options role name => label, as UserForm::roleOptions() lists them
     * @return array<string, string>
     */
    public function assignable(array $options, ?string $kept): array
    {
        $assignable = [];
        foreach ($options as $name => $label) {
            if ((string)$name === $kept || $this->grants((string)$name)) {
                $assignable[$name] = $label;
            }
        }

        return $assignable;
    }

    /**
     * Whether the user who assigns may give $roleName, a role of the
     * instance: they hold every permission it grants, and, for the admin
     * role or a role that contains it, reach every project.
     */
    private function grants(string $roleName): bool
    {
        return $this->holdsAllOf($roleName)
            && ($this->reachesEveryProject() || !$this->containsTheAdminRole($roleName));
    }

    /**
     * Whether the user who assigns holds every permission $roleName grants:
     * a superadmin always, nobody without a signed-in user.
     */
    private function holdsAllOf(string $roleName): bool
    {
        if ($this->superadmin) {
            return true;
        }
        if ($this->actorId === null) {
            return false;
        }

        return $this->heldRoles[$roleName] ??= $this->holdsEveryPermissionOf($this->actorId, $roleName);
    }

    /**
     * Whether $actorId holds every permission $roleName grants: at once when
     * they hold the role itself, through an assignment, a role that contains
     * it or the default roles; otherwise permission by permission.
     */
    private function holdsEveryPermissionOf(int $actorId, string $roleName): bool
    {
        if ($this->auth->checkAccess($actorId, $roleName)) {
            return true;
        }
        foreach (array_keys($this->auth->getPermissionsByRole($roleName)) as $permission) {
            if (!$this->holds($actorId, (string)$permission)) {
                return false;
            }
        }

        return true;
    }

    private function holds(int $actorId, string $permission): bool
    {
        return $this->held[$permission] ??= $this->auth->checkAccess($actorId, $permission);
    }

    private function exists(string $roleName): bool
    {
        return $this->roles[$roleName] ??= $this->auth->getRole($roleName) !== null;
    }

    /**
     * Whether $roleName is the admin role or contains it among its child
     * roles, at any depth. The RBAC manager refuses loops, so the search
     * ends.
     */
    private function containsTheAdminRole(string $roleName): bool
    {
        if ($roleName === self::ADMIN_ROLE) {
            return true;
        }

        return $this->adminRoles[$roleName] ??= $this->aChildRoleContainsTheAdminRole($roleName);
    }

    private function aChildRoleContainsTheAdminRole(string $roleName): bool
    {
        foreach ($this->auth->getChildren($roleName) as $child) {
            if ($child instanceof Role && $this->containsTheAdminRole($child->name)) {
                return true;
            }
        }

        return false;
    }
}
