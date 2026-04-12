<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\AuditLog;
use app\models\Team;
use app\models\TeamMember;
use app\models\TeamProject;
use app\models\User;
use app\models\Project;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\web\NotFoundHttpException;

/**
 * API v1: Teams
 *
 * GET    /api/v1/teams
 * GET    /api/v1/teams/{id}
 * POST   /api/v1/teams
 * PUT    /api/v1/teams/{id}
 * DELETE /api/v1/teams/{id}
 * POST   /api/v1/teams/{id}/members
 * DELETE /api/v1/teams/{id}/members/{userId}
 * POST   /api/v1/teams/{id}/projects
 * DELETE /api/v1/teams/{id}/projects/{projectId}
 */
class TeamsController extends BaseApiController
{
    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}|array{error: array{message: string}}
     */
    public function actionIndex(): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('admin')) {
            return $this->error('Forbidden.', 403);
        }

        $dp = new ActiveDataProvider([
            'query' => Team::find()->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 25],
        ]);
        /** @var int $page */
        $page = \Yii::$app->request->get('page', 1);

        return $this->paginated(
            array_map(fn ($t) => $this->serialize($t), $dp->getModels()),
            (int)$dp->totalCount,
            $page,
            25
        );
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionView(int $id): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('admin')) {
            return $this->error('Forbidden.', 403);
        }
        return $this->success($this->serializeDetail($this->findModel($id)));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionCreate(): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('admin')) {
            return $this->error('Forbidden.', 403);
        }

        $model = new Team();
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);
        $model->created_by = (int)$user->id;

        if (!$model->validate()) {
            return $this->error($this->firstError($model), 422);
        }
        if (!$model->save(false)) {
            return $this->error('Failed to save team.', 422);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_TEAM_CREATED,
            'team',
            $model->id,
            null,
            ['name' => $model->name, 'source' => 'api']
        );

        return $this->success($this->serializeDetail($model), 201);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionUpdate(int $id): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('admin')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);

        if (!$model->validate()) {
            return $this->error($this->firstError($model), 422);
        }
        if (!$model->save(false)) {
            return $this->error('Failed to save team.', 422);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_TEAM_UPDATED,
            'team',
            $model->id,
            null,
            ['name' => $model->name, 'source' => 'api']
        );

        return $this->success($this->serializeDetail($model));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionDelete(int $id): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('admin')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $name = $model->name;
        TeamProject::deleteAll(['team_id' => $id]);
        TeamMember::deleteAll(['team_id' => $id]);
        $model->delete();

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_TEAM_DELETED,
            'team',
            $id,
            null,
            ['name' => $name, 'source' => 'api']
        );

        return $this->success(['deleted' => true]);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionAddMember(int $id): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('admin')) {
            return $this->error('Forbidden.', 403);
        }

        $team = $this->findModel($id);
        $body = (array)\Yii::$app->request->bodyParams;
        $userId = (int)($body['user_id'] ?? 0);

        if (User::findOne($userId) === null) {
            return $this->error("User #{$userId} not found.", 404);
        }
        if (TeamMember::find()->where(['team_id' => $id, 'user_id' => $userId])->exists()) {
            return $this->error('User is already a member of this team.', 422);
        }

        $member = new TeamMember();
        $member->team_id = $id;
        $member->user_id = $userId;
        $member->created_at = time();
        $member->save(false);

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_TEAM_MEMBER_ADDED,
            'team',
            $id,
            null,
            ['team' => $team->name, 'user_id' => $userId, 'source' => 'api']
        );

        return $this->success($this->serializeDetail($team), 201);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionRemoveMember(int $id, int $userId): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('admin')) {
            return $this->error('Forbidden.', 403);
        }

        $team = $this->findModel($id);
        $deleted = TeamMember::deleteAll(['team_id' => $id, 'user_id' => $userId]);
        if ($deleted === 0) {
            return $this->error('User is not a member of this team.', 404);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_TEAM_MEMBER_REMOVED,
            'team',
            $id,
            null,
            ['team' => $team->name, 'user_id' => $userId, 'source' => 'api']
        );

        return $this->success($this->serializeDetail($team));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionAddProject(int $id): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('admin')) {
            return $this->error('Forbidden.', 403);
        }

        $team = $this->findModel($id);
        $body = (array)\Yii::$app->request->bodyParams;
        $projectId = (int)($body['project_id'] ?? 0);
        $role = (string)($body['role'] ?? 'viewer');

        if (Project::findOne($projectId) === null) {
            return $this->error("Project #{$projectId} not found.", 404);
        }
        if (TeamProject::find()->where(['team_id' => $id, 'project_id' => $projectId])->exists()) {
            return $this->error('Project is already assigned to this team.', 422);
        }

        $tp = new TeamProject();
        $tp->team_id = $id;
        $tp->project_id = $projectId;
        $tp->role = $role;
        $tp->created_at = time();
        $tp->save(false);

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_TEAM_PROJECT_ADDED,
            'team',
            $id,
            null,
            ['team' => $team->name, 'project_id' => $projectId, 'role' => $role, 'source' => 'api']
        );

        return $this->success($this->serializeDetail($team), 201);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionRemoveProject(int $id, int $projectId): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('admin')) {
            return $this->error('Forbidden.', 403);
        }

        $team = $this->findModel($id);
        $deleted = TeamProject::deleteAll(['team_id' => $id, 'project_id' => $projectId]);
        if ($deleted === 0) {
            return $this->error('Project is not assigned to this team.', 404);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_TEAM_PROJECT_REMOVED,
            'team',
            $id,
            null,
            ['team' => $team->name, 'project_id' => $projectId, 'source' => 'api']
        );

        return $this->success($this->serializeDetail($team));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyBody(Team $model, array $body): void
    {
        foreach (['name', 'description'] as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = $body[$field];
            if ($value === null && $field === 'description') {
                $model->$field = null;
            } else {
                $model->$field = (string)$value;
            }
        }
    }

    /**
     * @return array{id: int, name: string, description: string|null, member_count: int, project_count: int, created_at: int}
     */
    private function serialize(Team $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'member_count' => (int)$t->getTeamMembers()->count(),
            'project_count' => (int)$t->getTeamProjects()->count(),
            'created_at' => $t->created_at,
        ];
    }

    /**
     * @return array{id: int, name: string, description: string|null, members: list<array{user_id: int, username: string}>, projects: list<array{project_id: int, name: string, role: string}>, created_at: int}
     */
    private function serializeDetail(Team $t): array
    {
        $members = [];
        foreach ($t->teamMembers as $tm) {
            $members[] = [
                'user_id' => $tm->user_id,
                'username' => $tm->user->username ?? 'unknown',
            ];
        }
        $projects = [];
        foreach ($t->teamProjects as $tp) {
            $projects[] = [
                'project_id' => $tp->project_id,
                'name' => $tp->project->name ?? 'unknown',
                'role' => $tp->role,
            ];
        }
        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'members' => $members,
            'projects' => $projects,
            'created_at' => $t->created_at,
        ];
    }

    private function findModel(int $id): Team
    {
        /** @var Team|null $t */
        $t = Team::findOne($id);
        if ($t === null) {
            throw new NotFoundHttpException("Team #{$id} not found.");
        }
        return $t;
    }

    /**
     * @param ActiveRecord $model
     */
    private function firstError($model): string
    {
        foreach ($model->errors as $errors) {
            return $errors[0] ?? 'Validation failed.';
        }
        return 'Validation failed.';
    }
}
