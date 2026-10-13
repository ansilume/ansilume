<?php

declare(strict_types=1);

namespace app\services;

use app\models\Team;
use app\models\TeamMember;
use app\models\TeamProject;
use app\models\User;
use yii\db\Query;

/**
 * Narrows candidate approvers down to the users who may decide:
 * - an active account,
 * - a superadmin, or holding approval.decide (directly, through a role, or
 *   through a default role),
 * - in the audience, that is, allowed to see what is to be approved. Team
 *   scoping applies only while some project is restricted to a team; then
 *   superadmins and admins see everything, other users what their teams'
 *   projects allow.
 *
 * The answers equal, user by user, those of
 * WorkflowAccessChecker::isActiveWithPermission($id, 'approval.decide') and
 * the view checks of ProjectAccessChecker and WorkflowAccessChecker, but they
 * cost a constant number of queries however many candidates there are.
 *
 * One instance reads roles and team restrictions once: create one per
 * decision.
 */
final class ApproverFilter
{
    public const DECIDE_PERMISSION = 'approval.decide';

    /** The role whose holders team scoping does not restrict, as in ProjectAccessChecker. */
    private const ADMIN_ROLE = 'admin';

    /** @var array<int, true>|null IDs of the projects restricted to a team */
    private ?array $restrictedProjects = null;

    public function __construct(private readonly PermissionHolders $holders)
    {
    }

    /**
     * @param list<int> $candidates distinct user IDs
     * @param \Closure(): ApproverAudience $audience who may see it; asked for only when team scoping applies
     * @return list<int> the candidates who may decide, in the order given
     */
    public function mayDecide(array $candidates, \Closure $audience): array
    {
        if ($candidates === []) {
            return [];
        }
        $superadmins = [];
        $others = [];
        foreach ($this->activeUsers($candidates) as $userId => $isSuperadmin) {
            if ($isSuperadmin) {
                $superadmins[$userId] = true;
            } else {
                $others[] = $userId;
            }
        }
        $deciders = array_merge(array_keys($superadmins), $this->holders->among(self::DECIDE_PERMISSION, $others));
        $allowed = array_flip($this->inAudience($deciders, $superadmins, $audience));

        return array_values(array_filter(
            $candidates,
            static fn (int $userId): bool => isset($allowed[$userId])
        ));
    }

    /**
     * @param list<int> $userIds
     * @param array<int, true> $superadmins
     * @param \Closure(): ApproverAudience $audience
     * @return list<int>
     */
    private function inAudience(array $userIds, array $superadmins, \Closure $audience): array
    {
        if ($userIds === []) {
            return [];
        }
        $restricted = $this->restrictedProjects();
        if ($restricted === []) {
            return $userIds;
        }
        $resolved = $audience();
        if ($resolved->admits(static fn (int $projectId): bool => !isset($restricted[$projectId]))) {
            return $userIds;
        }
        $scoped = array_values(array_filter(
            $userIds,
            static fn (int $userId): bool => !isset($superadmins[$userId])
        ));
        $seeAll = $superadmins + array_fill_keys($this->holders->among(self::ADMIN_ROLE, $scoped), true);
        $teamProjects = $this->teamProjects(
            array_values(array_filter($scoped, static fn (int $userId): bool => !isset($seeAll[$userId]))),
            $resolved->projectIds()
        );

        return array_values(array_filter(
            $userIds,
            static fn (int $userId): bool => isset($seeAll[$userId])
                || self::admitsMember($resolved, $restricted, $teamProjects[$userId] ?? [])
        ));
    }

    /**
     * Whether a user whom team scoping restricts belongs to the audience:
     * they may view a project that is not restricted, or one of their teams'.
     *
     * @param array<int, true> $restricted
     * @param array<int, true> $teamProjects
     */
    private static function admitsMember(ApproverAudience $audience, array $restricted, array $teamProjects): bool
    {
        return $audience->admits(
            static fn (int $projectId): bool => !isset($restricted[$projectId]) || isset($teamProjects[$projectId])
        );
    }

    /**
     * @param list<int> $userIds
     * @return array<int, bool> active user ID => whether the user is a superadmin
     */
    private function activeUsers(array $userIds): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = (new Query())
            ->select(['id', 'is_superadmin'])
            ->from(User::tableName())
            ->where(['id' => $userIds, 'status' => User::STATUS_ACTIVE])
            ->all();
        $users = [];
        foreach ($rows as $row) {
            $users[(int)$row['id']] = (bool)$row['is_superadmin'];
        }

        return $users;
    }

    /**
     * The projects of $projectIds that each user may view through a team.
     *
     * @param list<int> $userIds
     * @param list<int> $projectIds
     * @return array<int, array<int, true>> user ID => project IDs
     */
    private function teamProjects(array $userIds, array $projectIds): array
    {
        if ($userIds === [] || $projectIds === []) {
            return [];
        }
        /** @var array<int, array<string, mixed>> $rows */
        $rows = (new Query())
            ->select(['tm.user_id', 'tp.project_id'])
            ->distinct()
            ->from(['tp' => TeamProject::tableName()])
            ->innerJoin(['t' => Team::tableName()], '[[t.id]] = [[tp.team_id]]')
            ->innerJoin(['tm' => TeamMember::tableName()], '[[tm.team_id]] = [[t.id]]')
            ->where(['tm.user_id' => $userIds, 'tp.project_id' => $projectIds])
            ->all();
        $projects = [];
        foreach ($rows as $row) {
            $projects[(int)$row['user_id']][(int)$row['project_id']] = true;
        }

        return $projects;
    }

    /**
     * @return array<int, true>
     */
    private function restrictedProjects(): array
    {
        if ($this->restrictedProjects === null) {
            $ids = array_map('intval', TeamProject::find()->select('project_id')->distinct()->column());
            $this->restrictedProjects = array_fill_keys($ids, true);
        }

        return $this->restrictedProjects;
    }
}
