<?php

declare(strict_types=1);

namespace app\services;

use app\models\JobTemplate;
use app\models\TeamProject;
use app\models\User;
use yii\base\Component;
use yii\db\Query;

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
 *
 * Child resources (job templates, inventories, jobs, schedules) inherit
 * access from their parent project via project_id foreign keys.
 *
 * The team restriction is looked up before the user's RBAC roles, which
 * cost several queries: on installations without teams it decides alone.
 */
class ProjectAccessChecker extends Component
{
    /**
     * Matches no row. A bare ['0=1'] is not a valid Yii condition: the query
     * builder reads it as an operator without operands and throws.
     */
    public const DENY_ALL = ['and', '0=1'];

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
     * Check view access for a child resource linked to a project.
     * Returns true if projectId is null (global/unlinked resource).
     */
    public function canViewChildResource(int $userId, ?int $projectId): bool
    {
        if ($projectId === null) {
            return true;
        }
        return $this->canView($userId, $projectId);
    }

    /**
     * Check operator access for a child resource linked to a project.
     * Returns true if projectId is null (global/unlinked resource) AND user has RBAC permission.
     */
    public function canOperateChildResource(int $userId, ?int $projectId): bool
    {
        if ($projectId === null) {
            return true;
        }
        return $this->canOperate($userId, $projectId);
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

        // Superadmins get unrestricted operator access.
        if ($user->is_superadmin) {
            return TeamProject::ROLE_OPERATOR;
        }

        // Check if the project has any team-project entries.
        $hasAnyTeamAccess = TeamProject::find()
            ->where(['project_id' => $projectId])
            ->exists();

        // If the project is not restricted to any team, all authenticated users
        // with an active account have full access. Controller-level RBAC rules
        // (accessRules) already gate which actions a user can invoke.
        if (!$hasAnyTeamAccess) {
            return TeamProject::ROLE_OPERATOR;
        }

        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;

        // RBAC admins get unrestricted operator access.
        if ($auth->checkAccess($userId, 'admin')) {
            return TeamProject::ROLE_OPERATOR;
        }

        // Project is team-restricted — look for a matching team assignment.
        $rows = TeamProject::find()
            ->innerJoinWith('team.teamMembers', false)
            ->where([
                'team_project.project_id' => $projectId,
                'team_member.user_id' => $userId,
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
     *
     * @return array<int|string, mixed>|null
     */
    public function buildProjectFilter(?int $userId): ?array
    {
        if ($userId === null) {
            return self::DENY_ALL;
        }

        $allRestrictedIds = $this->getRestrictedProjectIds();
        if (empty($allRestrictedIds)) {
            return null;
        }

        if ($this->isUnrestricted($userId)) {
            return null;
        }

        $teamProjectIds = $this->getAccessibleProjectIds($userId);

        return ['or',
            ['not in', 'id', $allRestrictedIds],
            ['in', 'id', $teamProjectIds],
        ];
    }

    /**
     * Build a query condition for tables with a project_id column
     * (job_template, inventory, etc.).
     *
     * Handles nullable project_id: rows with NULL project_id are considered
     * global and always pass the filter.
     *
     * @return array<int|string, mixed>|null
     */
    public function buildChildResourceFilter(?int $userId, string $projectIdColumn): ?array
    {
        if ($userId === null) {
            return self::DENY_ALL;
        }

        $allRestrictedIds = $this->getRestrictedProjectIds();
        if (empty($allRestrictedIds)) {
            return null;
        }

        if ($this->isUnrestricted($userId)) {
            return null;
        }

        $teamProjectIds = $this->getAccessibleProjectIds($userId);

        // Allow: global (project_id IS NULL) OR open project OR team-accessible project
        return ['or',
            [$projectIdColumn => null],
            ['not in', $projectIdColumn, $allRestrictedIds],
            ['in', $projectIdColumn, $teamProjectIds],
        ];
    }

    /**
     * Like buildChildResourceFilter(), but for operate access: global rows,
     * open projects, and restricted projects where the user holds the
     * operator role in a team.
     *
     * @return array<int|string, mixed>|null
     */
    public function buildChildOperateFilter(?int $userId, string $projectIdColumn): ?array
    {
        if ($userId === null) {
            return self::DENY_ALL;
        }
        $allRestrictedIds = $this->getRestrictedProjectIds();
        if (empty($allRestrictedIds)) {
            return null;
        }
        if ($this->isUnrestricted($userId)) {
            return null;
        }

        return ['or',
            [$projectIdColumn => null],
            ['not in', $projectIdColumn, $allRestrictedIds],
            ['in', $projectIdColumn, $this->getAccessibleProjectIds($userId, TeamProject::ROLE_OPERATOR)],
        ];
    }

    /**
     * Build a query condition for the job table, filtering via job_template.project_id.
     *
     * Uses a subquery to find accessible job_template IDs rather than
     * materializing all IDs in memory.
     *
     * @param string $column the job_template_id column to filter, e.g. 'j.job_template_id'
     * @return array<int|string, mixed>|null
     */
    public function buildJobFilter(?int $userId, string $column = 'job_template_id'): ?array
    {
        if ($userId === null) {
            return self::DENY_ALL;
        }

        $accessibleTemplateIds = $this->templateIdSubquery($userId);
        if ($accessibleTemplateIds === null) {
            return null;
        }

        return ['in', $column, $accessibleTemplateIds];
    }

    /**
     * Subquery selecting the IDs of the job templates whose project the user
     * may view, or operate when $operate is true. Soft-deleted templates count
     * with their project, so their job history keeps its visibility.
     *
     * Returns null when no restriction applies: admins, superadmins, and
     * installations without team-restricted projects.
     */
    public function templateIdSubquery(int $userId, bool $operate = false): ?Query
    {
        $allRestrictedIds = $this->getRestrictedProjectIds();
        if (empty($allRestrictedIds)) {
            return null;
        }

        if ($this->isUnrestricted($userId)) {
            return null;
        }

        $role = $operate ? TeamProject::ROLE_OPERATOR : null;

        return (new Query())
            ->select('jt.id')
            ->from(['jt' => JobTemplate::tableName()])
            ->where(['or',
                ['not in', 'jt.project_id', $allRestrictedIds],
                ['in', 'jt.project_id', $this->getAccessibleProjectIds($userId, $role)],
            ]);
    }

    /**
     * Whether any project is restricted to a team. Without one, team scoping
     * restricts no user.
     */
    public function hasRestrictedProjects(): bool
    {
        return $this->getRestrictedProjectIds() !== [];
    }

    /**
     * Check if user is admin/superadmin (unrestricted access).
     */
    private function isUnrestricted(int $userId): bool
    {
        /** @var User|null $user */
        $user = User::findOne($userId);
        if ($user === null) {
            return false;
        }

        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        return $user->is_superadmin || $auth->checkAccess($userId, 'admin');
    }

    /**
     * Get IDs of all projects that have team_project entries (restricted projects).
     *
     * @return int[]
     */
    private function getRestrictedProjectIds(): array
    {
        return array_map('intval', TeamProject::find()
            ->select('project_id')
            ->distinct()
            ->column());
    }

    /**
     * Get IDs of projects accessible to a user via team membership, optionally
     * only those where the team holds the given role.
     *
     * @return int[]
     */
    private function getAccessibleProjectIds(int $userId, ?string $role = null): array
    {
        $query = TeamProject::find()
            ->innerJoinWith('team.teamMembers', false)
            ->where(['team_member.user_id' => $userId])
            ->select('team_project.project_id')
            ->distinct();
        if ($role !== null) {
            $query->andWhere(['team_project.role' => $role]);
        }

        return array_map('intval', $query->column());
    }
}
