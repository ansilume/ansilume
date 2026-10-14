<?php

declare(strict_types=1);

namespace app\services;

use app\models\TeamMember;
use app\models\TeamProject;
use app\models\User;
use yii\db\Query;

/**
 * Who may change or delete which user: whatever writes another user's
 * account, on the Users pages (UserController: the Edit User form, opened
 * or saved, the status toggle and Delete) and through the users API
 * (UsersController: PUT and DELETE /api/v1/users/{id}).
 *
 * A superadmin may change every user. Anyone else may change a user only
 * when that user is no superadmin and they may assign every role assigned
 * to that user (RoleGrantPolicy::mayAssign() over AssignedRoles): they hold
 * every permission those roles grant (CHANGE_REFUSED otherwise) and, for
 * the admin role or a role that contains it, the admin role. Where the
 * admin role is all they lack, the answer says so
 * (ADMIN_ROLE_CHANGE_REFUSED), whatever the teams: holding the admin role
 * would lift the team rule below as well. So a user without an assigned
 * role who is no superadmin may be changed by every user manager, and
 * nobody may be changed without a signed-in user.
 *
 * Teams give access to team-restricted projects beyond any role. So a user
 * manager who is neither a superadmin nor a holder of the admin role (who
 * reaches not every project: RoleGrantPolicy::reachesEveryProject()) may
 * change a user only when, for every project the user's teams give the
 * user, their own teams give them at least the same role, as
 * ProjectAccessChecker counts it: the highest role of their teams on the
 * project wins, operator over viewer (TEAM_ACCESS_REFUSED otherwise, where
 * the roles allow the change). Whoever may change a user's password or
 * email may sign in as them.
 *
 * Up to 2.10.1, whoever held user.update, such as a custom help desk role or
 * an admin who is no superadmin, could set the password and the email of
 * any user, a superadmin included, and then sign in as them; whoever held
 * user.delete could deactivate or delete them. The checks that come after
 * this rule (the only superadmin, one's own account, the auth source, the
 * role and the superadmin flag) keep their texts.
 */
final class UserChangePolicy
{
    /**
     * Why a user is not changed: the 403 of the Edit User form, the status
     * toggle and Delete, and of PUT and DELETE /api/v1/users/{id}, which
     * comes after the 404 of a user that does not exist and before every
     * other check.
     */
    public const CHANGE_REFUSED = 'You cannot change or delete this user: they are a superadmin, or a role assigned to them grants permissions you do not hold.';

    /**
     * Why a user whose roles do not stop the change is not changed: their
     * teams give them a project that the signed-in user's teams do not, or
     * with a lower role. The 403 of the same pages and requests as
     * CHANGE_REFUSED, in its place.
     */
    public const TEAM_ACCESS_REFUSED = 'You cannot change or delete this user: their teams give them more access to a project than you have.';

    /**
     * Why a user who holds the admin role, or a role that contains it, is
     * not changed by a signed-in user who holds every permission the user's
     * roles grant but is neither a superadmin nor a holder of the admin role
     * (RoleGrantPolicy::ADMIN_ROLE_REFUSED for each such role), where the
     * user is no superadmin, whatever the teams: the admin role, which gives
     * every team-restricted project (RoleGrantPolicy::reachesEveryProject()),
     * would lift the team rule too, so it is all they lack also where the
     * user's teams give the user a project theirs do not. The 403 of the
     * same pages and requests as CHANGE_REFUSED, in its place:
     * CHANGE_REFUSED would tell them they lack permissions they hold.
     */
    public const ADMIN_ROLE_CHANGE_REFUSED = 'You cannot change or delete this user: a role assigned to them is or contains the admin role, which only a superadmin or an admin may assign.';

    public function __construct(private readonly RoleGrantPolicy $grants, private readonly AssignedRoles $assignedRoles)
    {
    }

    /**
     * The policy of the user signed in to the application's user component
     * (RoleGrantPolicy::forCurrentUser()), over the roles assigned to users
     * as the application's RBAC manager keeps them.
     */
    public static function forCurrentUser(): self
    {
        return self::of(RoleGrantPolicy::forCurrentUser());
    }

    /**
     * The policy of the user $grants decides for, sharing its decisions: a
     * page that also offers roles asks for each role once.
     */
    public static function of(RoleGrantPolicy $grants): self
    {
        return new self($grants, AssignedRoles::fromApp());
    }

    /**
     * Whether the signed-in user may change or delete $user.
     */
    public function mayChange(User $user): bool
    {
        return $this->refusalOf($user) === null;
    }

    /**
     * Why the signed-in user may not change or delete $user; null when they
     * may. The roles are weighed first: CHANGE_REFUSED for a superadmin, a
     * role they may not assign or nobody signed in, but
     * ADMIN_ROLE_CHANGE_REFUSED wherever only the admin role keeps every
     * such role from them (onlyTheAdminRoleRefuses()), whatever the teams,
     * as the admin role would lift the team rule too. Only where the roles
     * allow the change are the teams weighed: TEAM_ACCESS_REFUSED for a
     * project the user's teams give the user beyond what the signed-in
     * user's teams give them.
     */
    public function refusalOf(User $user): ?string
    {
        $userId = (int)$user->id;
        $roles = $this->assignedRoles->of($userId);
        if ($this->mayChangeByRoles($user, $roles)) {
            return $this->teamAccessOf([$userId])[$userId] ? null : self::TEAM_ACCESS_REFUSED;
        }

        return $this->onlyTheAdminRoleRefuses($user, $roles)
            ? self::ADMIN_ROLE_CHANGE_REFUSED
            : self::CHANGE_REFUSED;
    }

    /**
     * Whether the signed-in user may change or delete each of $users, with
     * their roles read in one query and each role decided once, and the
     * team roles of all of them, and of the signed-in user, in one more.
     *
     * @param list<User> $users
     * @return array<int, bool> the ID of every user of $users => whether they may be changed
     */
    public function mayChangeEach(array $users): array
    {
        $roles = $this->assignedRoles->ofUsers(array_map(static fn (User $user): int => (int)$user->id, $users));
        $mayChange = [];
        foreach ($users as $user) {
            $mayChange[(int)$user->id] = $this->mayChangeByRoles($user, $roles[(int)$user->id] ?? []);
        }

        return array_replace($mayChange, $this->teamAccessOf(array_keys(array_filter($mayChange))));
    }

    /**
     * The rule of the roles, for $user with the roles $roles assigned to them.
     *
     * @param list<string> $roles
     */
    private function mayChangeByRoles(User $user, array $roles): bool
    {
        if ($this->grants->isSuperadmin()) {
            return true;
        }
        if ((bool)$user->is_superadmin || !$this->grants->hasActor()) {
            return false;
        }
        foreach ($roles as $role) {
            if (!$this->grants->mayAssign($role)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether only the admin role keeps the signed-in user from changing
     * $user, whose roles stop the change: $user is no superadmin, a user is
     * signed in, and each role of $roles they may not assign is refused for
     * the admin role alone, as they hold every permission it grants
     * (RoleGrantPolicy::refusalOf(): ADMIN_ROLE_REFUSED, not ROLE_REFUSED).
     * The teams are not asked: the admin role would lift their rule as well.
     *
     * @param list<string> $roles
     */
    private function onlyTheAdminRoleRefuses(User $user, array $roles): bool
    {
        if ((bool)$user->is_superadmin || !$this->grants->hasActor()) {
            return false;
        }
        foreach ($roles as $role) {
            $refusal = $this->grants->refusalOf($role);
            if ($refusal !== null && $refusal !== sprintf(RoleGrantPolicy::ADMIN_ROLE_REFUSED, $role)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The rule of the teams, for each of $userIds: whether the signed-in
     * user's teams give them every project the user's teams give the user,
     * with at least the same role. At once for a signed-in user who reaches
     * every project; otherwise from the team roles of all of them and of the
     * signed-in user, read in one query.
     *
     * @param list<int> $userIds
     * @return array<int, bool> every ID of $userIds => whether the signed-in user's teams cover that user's
     */
    private function teamAccessOf(array $userIds): array
    {
        $actorId = $this->grants->actorId();
        if ($userIds === [] || $actorId === null || $this->grants->reachesEveryProject()) {
            return array_fill_keys($userIds, $actorId !== null);
        }
        $teamRoles = $this->teamRolesOf([...$userIds, $actorId]);
        $covered = [];
        foreach ($userIds as $userId) {
            $covered[$userId] = self::covers($teamRoles[$actorId] ?? [], $teamRoles[$userId] ?? []);
        }

        return $covered;
    }

    /**
     * The role the teams of each of $userIds give that user on each project:
     * the highest when several of their teams have the project, operator
     * over viewer, as ProjectAccessChecker::resolveRole() counts it.
     *
     * @param non-empty-list<int> $userIds
     * @return array<int, array<int, string>> user ID => project ID => role; a user whose teams have no project is missing
     */
    private function teamRolesOf(array $userIds): array
    {
        /** @var list<array{user_id: int|string, project_id: int|string, role: string}> $rows */
        $rows = (new Query())
            ->select(['tm.user_id', 'tp.project_id', 'tp.role'])
            ->from(['tm' => TeamMember::tableName()])
            ->innerJoin(['tp' => TeamProject::tableName()], 'tp.team_id = tm.team_id')
            ->where(['tm.user_id' => array_values(array_unique($userIds))])
            ->all();
        $roles = [];
        foreach ($rows as $row) {
            $userId = (int)$row['user_id'];
            $projectId = (int)$row['project_id'];
            if (($roles[$userId][$projectId] ?? null) !== TeamProject::ROLE_OPERATOR) {
                $roles[$userId][$projectId] = (string)$row['role'];
            }
        }

        return $roles;
    }

    /**
     * Whether $held, the roles the signed-in user's teams give them by
     * project, gives every project of $needed, with at least its role.
     *
     * @param array<int, string> $held project ID => role
     * @param array<int, string> $needed project ID => role
     */
    private static function covers(array $held, array $needed): bool
    {
        foreach ($needed as $projectId => $role) {
            if (self::rank($held[$projectId] ?? null) < self::rank($role)) {
                return false;
            }
        }

        return true;
    }

    /**
     * How much a team role on a project gives, as ProjectAccessChecker
     * counts it: operator most, any other role of a team viewer, no team
     * nothing.
     */
    private static function rank(?string $role): int
    {
        if ($role === null) {
            return 0;
        }

        return $role === TeamProject::ROLE_OPERATOR ? 2 : 1;
    }
}
