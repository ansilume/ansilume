<?php

declare(strict_types=1);

namespace app\services;

use app\models\User;
use yii\rbac\ManagerInterface;

/**
 * Who may create, change or delete which role: the Roles pages
 * (RoleController) and the roles API (RolesController), both through
 * RoleService. Changing a role changes what every user who holds it may do,
 * so it needs what assigning the role needs (RoleGrantPolicy).
 *
 * A superadmin may do everything; the system roles keep their protection
 * (RoleService). Anyone else:
 * - creates a role only with permissions they hold;
 * - changes a role (its description and permissions) or deletes it only
 *   when they may assign it as it is (RoleGrantPolicy::mayAssign());
 * - adds to it only permissions they hold, and may remove any.
 * A permission is held as wherever access is checked: checkAccess(), the
 * default roles included. Without a signed-in user nobody may change or
 * delete a role or grant a permission.
 *
 * Up to 2.10.1 whoever held role.create or role.update could give any role,
 * their own and the default role viewer included, every permission, and so
 * take user.update or approval-rule.update.
 *
 * Every decision is kept for the life of the instance, so a page asks for
 * each permission once, and a save decides as the page that offered it.
 */
final class RoleChangePolicy
{
    /**
     * Why a permission is not granted: the error of the Permissions field of
     * the role form and the 403 of POST and PUT /api/v1/roles, before
     * anything is written. %s: the permission's name, the first one of the
     * request the user does not hold.
     */
    public const PERMISSION_REFUSED = 'You cannot grant the permission "%s": you do not hold it.';

    /**
     * Why a role is not changed: the 403 of the Edit Role page, opened or
     * saved, and of Delete, and of PUT and DELETE /api/v1/roles/{name}; after
     * the 404 of a role that does not exist and before every other check.
     */
    public const CHANGE_REFUSED = 'You cannot change or delete this role: you may not assign it, as it grants more than you hold.';

    private readonly RoleGrantPolicy $grants;

    /** The ID of the user who changes roles; null when nobody is signed in. */
    private readonly ?int $actorId;

    private readonly bool $superadmin;

    /** @var array<string, bool> permission name => whether the actor holds it, as checkAccess() answered */
    private array $held = [];

    public function __construct(private readonly ManagerInterface $auth, ?User $actor)
    {
        $this->grants = new RoleGrantPolicy($auth, $actor);
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

        return self::forUser($identity instanceof User ? $identity : null);
    }

    /**
     * The policy of $actor, over the application's RBAC manager; of nobody
     * for null.
     */
    public static function forUser(?User $actor): self
    {
        /** @var ManagerInterface $auth */
        $auth = \Yii::$app->authManager;

        return new self($auth, $actor);
    }

    /**
     * The user who changes roles, as the audit log names them; null when
     * nobody is signed in.
     */
    public function actorId(): ?int
    {
        return $this->actorId;
    }

    /**
     * Whether the user may change or delete the role $roleName: when they
     * may assign it as it is. Never a role the instance does not have.
     */
    public function mayChange(string $roleName): bool
    {
        return $this->grants->mayAssign($roleName);
    }

    /**
     * Whether the user may give a role the permission $permission: a
     * superadmin always, nobody without a signed-in user, anyone else when
     * they hold it.
     */
    public function mayGrant(string $permission): bool
    {
        if ($this->superadmin) {
            return true;
        }
        if ($this->actorId === null) {
            return false;
        }

        return $this->held[$permission] ??= $this->auth->checkAccess($this->actorId, $permission);
    }

    /**
     * Whether the user may grant each of $permissions, as the role form
     * offers them.
     *
     * @param array<int, string> $permissions
     * @return array<string, bool> permission name => whether the user may grant it
     */
    public function grantable(array $permissions): array
    {
        $grantable = [];
        foreach ($permissions as $permission) {
            $grantable[$permission] = $this->mayGrant($permission);
        }

        return $grantable;
    }

    /**
     * The first of $permissions, in their order, that the user may not
     * grant; null when they may grant them all, also when there are none.
     *
     * @param array<int, string> $permissions
     */
    public function firstRefused(array $permissions): ?string
    {
        foreach ($permissions as $permission) {
            if (!$this->mayGrant($permission)) {
                return $permission;
            }
        }

        return null;
    }
}
