<?php

declare(strict_types=1);

namespace app\services;

use app\models\TeamProject;
use app\models\User;
use yii\base\Component;

/**
 * Evaluates whether a user has access to a project and with what level.
 *
 * Access is granted if ANY of the following is true:
 *   1. The user is a superadmin (bypasses all checks).
 *   2. The user has the 'admin' RBAC role.
 *   3. The user belongs to a team that has been granted access to the project.
 *
 * When team-based access applies, the effective role is the highest role
 * among all teams the user belongs to that have access to the project.
 *
 * When no team_project rows exist for a project at all, the project is
 * considered "open" and all authenticated users with appropriate RBAC
 * permissions can access it (backward-compatible behaviour).
 */
class ProjectAccessChecker extends Component
{
    /**
     * Returns true if the user can view the given project.
     */
    public function canView(int $userId, int $projectId): bool
    {
        return $this->resolveRole($userId, $projectId) !== null;
    }

    /**
     * Returns true if the user can launch jobs / modify resources in the project.
     * Requires operator-level team access (or admin/superadmin).
     */
    public function canOperate(int $userId, int $projectId): bool
    {
        $role = $this->resolveRole($userId, $projectId);
        return $role === TeamProject::ROLE_OPERATOR;
    }

    /**
     * Resolve the effective role (viewer|operator|null) for a user in a project.
     *
     * Returns null if the user has no access at all.
     */
    public function resolveRole(int $userId, int $projectId): ?string
    {
        /** @var User $user */
        $user = User::findOne($userId);
        if ($user === null) {
            return null;
        }

        // Superadmins and RBAC admins get unrestricted operator access.
        if ($user->is_superadmin || \Yii::$app->authManager->checkAccess($userId, 'admin')) {
            return TeamProject::ROLE_OPERATOR;
        }

        // Check if the project has any team-project entries.
        $hasAnyTeamAccess = TeamProject::find()
            ->where(['project_id' => $projectId])
            ->exists();

        // If the project is not restricted to any team, fall back to RBAC-only.
        if (!$hasAnyTeamAccess) {
            return \Yii::$app->authManager->checkAccess($userId, 'project.view')
                ? TeamProject::ROLE_OPERATOR   // RBAC operator-or-above can do everything
                : null;
        }

        // Project is team-restricted — look for a matching team assignment.
        $rows = TeamProject::find()
            ->innerJoinWith('team.teamMembers', false)
            ->where([
                'team_project.project_id' => $projectId,
                'team_member.user_id'     => $userId,
            ])
            ->select(['team_project.role'])
            ->asArray()
            ->all();

        if (empty($rows)) {
            return null;
        }

        // Highest role wins: operator > viewer
        foreach ($rows as $row) {
            if ($row['role'] === TeamProject::ROLE_OPERATOR) {
                return TeamProject::ROLE_OPERATOR;
            }
        }

        return TeamProject::ROLE_VIEWER;
    }

    /**
     * Build a query condition that restricts a project query to only rows
     * accessible by the given user.
     *
     * Returns null when no restriction is needed (admin/superadmin).
     * Otherwise returns a Yii2 condition array for use with andWhere().
     *
     * Logic:
     *   - Projects with NO team_project rows are open to all authenticated users.
     *   - Projects WITH team_project rows require team membership.
     */
    public function buildProjectFilter(int $userId): ?array
    {
        /** @var User $user */
        $user = User::findOne($userId);
        if ($user === null) {
            return ['0=1']; // No access
        }

        if ($user->is_superadmin || \Yii::$app->authManager->checkAccess($userId, 'admin')) {
            return null; // No filter — see everything
        }

        // IDs of restricted projects the user can access via team membership
        $teamProjectIds = array_map('intval', TeamProject::find()
            ->innerJoinWith('team.teamMembers', false)
            ->where(['team_member.user_id' => $userId])
            ->select('team_project.project_id')
            ->distinct()
            ->column());

        // IDs of ALL restricted projects (may not all be accessible)
        $allRestrictedIds = array_map('intval', TeamProject::find()
            ->select('project_id')
            ->distinct()
            ->column());

        // User can see: open projects (not restricted) OR projects accessible via team
        // We express this as: project.id NOT IN (restricted) OR project.id IN (accessible)
        if (empty($allRestrictedIds)) {
            return null; // No restrictions exist at all
        }

        return ['or',
            ['not in', 'id', $allRestrictedIds],
            ['in',     'id', $teamProjectIds],
        ];
    }
}
