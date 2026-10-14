<?php

declare(strict_types=1);

namespace app\services;

use app\models\User;

/**
 * The roles assigned to a user and their superadmin flag, at one moment:
 * what user.created and user.updated record as {from, to} when it changes,
 * from the Users pages and through the users API alike.
 */
final class UserPrivileges
{
    /**
     * @param list<string> $roles the names of the roles assigned to the user, sorted by name (AssignedRoles)
     */
    public function __construct(public readonly array $roles, public readonly bool $superadmin)
    {
    }

    /**
     * The roles assigned to $user, as stored, and the superadmin flag of the
     * model.
     */
    public static function of(User $user): self
    {
        return new self(AssignedRoles::fromApp()->of((int)$user->id), (bool)$user->is_superadmin);
    }

    /**
     * The audit context of the change from $before to these: "roles" and
     * "is_superadmin" as {from, to}, each only when it changed. For a user
     * just created, $before is null, and both are given, from null.
     *
     * @return array{roles?: array{from: list<string>|null, to: list<string>}, is_superadmin?: array{from: bool|null, to: bool}}
     */
    public function changesSince(?self $before): array
    {
        $changes = [];
        if ($before === null || $before->roles !== $this->roles) {
            $changes['roles'] = ['from' => $before?->roles, 'to' => $this->roles];
        }
        if ($before === null || $before->superadmin !== $this->superadmin) {
            $changes['is_superadmin'] = ['from' => $before?->superadmin, 'to' => $this->superadmin];
        }

        return $changes;
    }
}
