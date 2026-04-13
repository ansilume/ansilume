<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\AuditLog;
use app\models\Project;
use app\controllers\api\v1\traits\ApiTeamScopingTrait;
use app\services\ProjectService;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\web\NotFoundHttpException;

/**
 * API v1: Projects
 *
 * GET    /api/v1/projects
 * GET    /api/v1/projects/{id}
 * POST   /api/v1/projects
 * PUT    /api/v1/projects/{id}
 * DELETE /api/v1/projects/{id}
 * POST   /api/v1/projects/{id}/sync
 */
class ProjectsController extends BaseApiController
{
    use ApiTeamScopingTrait;

    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function actionIndex(): array
    {
        $query = Project::find()->orderBy(['id' => SORT_DESC]);
        $filter = $this->checker()->buildProjectFilter($this->currentUserId());
        if ($filter !== null) {
            $query->andWhere($filter);
        }

        $dp = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 25],
        ]);
        /** @var int $page */
        $page = \Yii::$app->request->get('page', 1);

        return $this->paginated(
            array_map(fn ($p) => $this->serialize($p), $dp->getModels()),
            (int)$dp->totalCount,
            $page,
            25
        );
    }

    /**
     * @return array{data: mixed}
     */
    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionView(int $id): array
    {
        $model = $this->findModel($id);
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canView($userId, $model->id)) {
            return $this->error('Forbidden.', 403);
        }
        return $this->success($this->serialize($model));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionCreate(): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('project.create')) {
            return $this->error('Forbidden.', 403);
        }

        $model = new Project();
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);
        $model->created_by = (int)$user->id;
        $model->status = 'new';

        if (!$model->validate()) {
            return $this->error($this->firstError($model), 422);
        }
        if (!$model->save(false)) {
            return $this->error('Failed to save project.', 422);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_PROJECT_CREATED,
            'project',
            $model->id,
            null,
            ['name' => $model->name, 'scm_type' => $model->scm_type, 'source' => 'api']
        );

        if ($model->scm_type === Project::SCM_TYPE_GIT) {
            /** @var ProjectService $svc */
            $svc = \Yii::$app->get('projectService');
            $svc->queueSync($model);
        }

        return $this->success($this->serialize($model), 201);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionUpdate(int $id): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('project.update')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canOperate($userId, $model->id)) {
            return $this->error('Forbidden.', 403);
        }
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);

        if (!$model->validate()) {
            return $this->error($this->firstError($model), 422);
        }
        if (!$model->save(false)) {
            return $this->error('Failed to save project.', 422);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_PROJECT_UPDATED,
            'project',
            $model->id,
            null,
            ['name' => $model->name, 'source' => 'api']
        );

        if ($model->scm_type === Project::SCM_TYPE_GIT) {
            /** @var ProjectService $svc */
            $svc = \Yii::$app->get('projectService');
            $svc->queueSync($model);
        }

        return $this->success($this->serialize($model));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionDelete(int $id): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('project.delete')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canOperate($userId, $model->id)) {
            return $this->error('Forbidden.', 403);
        }
        $templateCount = $model->getJobTemplates()->count();
        if ($templateCount > 0) {
            return $this->error(
                "Cannot delete \"{$model->name}\": {$templateCount} job template(s) still reference this project.",
                422
            );
        }

        $name = $model->name;
        $model->delete();

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_PROJECT_DELETED,
            'project',
            $id,
            null,
            ['name' => $name, 'source' => 'api']
        );

        return $this->success(['deleted' => true]);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionSync(int $id): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('project.update')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canOperate($userId, $model->id)) {
            return $this->error('Forbidden.', 403);
        }
        if ($model->scm_type !== Project::SCM_TYPE_GIT) {
            return $this->error('This project has no SCM configured.', 422);
        }

        /** @var ProjectService $svc */
        $svc = \Yii::$app->get('projectService');
        $svc->queueSync($model);

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_PROJECT_SYNCED,
            'project',
            $model->id,
            null,
            ['name' => $model->name, 'source' => 'api']
        );

        return $this->success(['synced' => true]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyBody(Project $model, array $body): void
    {
        foreach (['name', 'description', 'scm_type', 'scm_url', 'scm_branch'] as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = $body[$field];
            if ($value === null && in_array($field, ['description', 'scm_url'], true)) {
                $model->$field = null;
            } else {
                $model->$field = (string)$value;
            }
        }
        if (array_key_exists('scm_credential_id', $body)) {
            $model->scm_credential_id = $body['scm_credential_id'] !== null ? (int)$body['scm_credential_id'] : null;
        }
    }

    /**
     * @return array{id: int, name: string, description: string|null, scm_type: string, scm_url: string|null, scm_branch: string, status: string, last_synced_at: int|null, created_at: int}
     */
    private function serialize(Project $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'scm_type' => $p->scm_type,
            'scm_url' => $p->scm_url,
            'scm_branch' => $p->scm_branch,
            'status' => $p->status,
            'last_synced_at' => $p->last_synced_at,
            'created_at' => $p->created_at,
        ];
    }

    private function findModel(int $id): Project
    {
        /** @var Project|null $p */
        $p = Project::findOne($id);
        if ($p === null) {
            throw new NotFoundHttpException("Project #{$id} not found.");
        }
        return $p;
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
