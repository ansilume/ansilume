<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\AuditLog;
use app\models\RunnerGroup;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\web\NotFoundHttpException;

/**
 * API v1: Runner Groups
 *
 * GET    /api/v1/runner-groups
 * GET    /api/v1/runner-groups/{id}
 * POST   /api/v1/runner-groups
 * PUT    /api/v1/runner-groups/{id}
 * DELETE /api/v1/runner-groups/{id}
 */
class RunnerGroupsController extends BaseApiController
{
    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function actionIndex(): array
    {
        $dp = new ActiveDataProvider([
            'query' => RunnerGroup::find()->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 25],
        ]);
        /** @var int $page */
        $page = \Yii::$app->request->get('page', 1);

        return $this->paginated(
            array_map(fn ($g) => $this->serialize($g), $dp->getModels()),
            (int)$dp->totalCount,
            $page,
            25
        );
    }

    /**
     * @return array{data: mixed}
     */
    public function actionView(int $id): array
    {
        return $this->success($this->serialize($this->findModel($id)));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionCreate(): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('runner-group.create')) {
            return $this->error('Forbidden.', 403);
        }

        $model = new RunnerGroup();
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);
        $model->created_by = (int)$user->id;

        if (!$model->validate()) {
            return $this->error($this->firstError($model), 422);
        }
        if (!$model->save(false)) {
            return $this->error('Failed to save runner group.', 422);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_RUNNER_GROUP_CREATED,
            'runner_group',
            $model->id,
            null,
            ['name' => $model->name, 'source' => 'api']
        );

        return $this->success($this->serialize($model), 201);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionUpdate(int $id): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('runner-group.update')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);

        if (!$model->validate()) {
            return $this->error($this->firstError($model), 422);
        }
        if (!$model->save(false)) {
            return $this->error('Failed to save runner group.', 422);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_RUNNER_GROUP_UPDATED,
            'runner_group',
            $model->id,
            null,
            ['name' => $model->name, 'source' => 'api']
        );

        return $this->success($this->serialize($model));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionDelete(int $id): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('runner-group.delete')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $runnerCount = $model->getRunners()->count();
        if ($runnerCount > 0) {
            return $this->error(
                "Cannot delete \"{$model->name}\": {$runnerCount} runner(s) still belong to this group.",
                422
            );
        }

        $templateCount = $model->getJobTemplates()->count();
        if ($templateCount > 0) {
            return $this->error(
                "Cannot delete \"{$model->name}\": {$templateCount} job template(s) reference this group.",
                422
            );
        }

        $name = $model->name;
        $model->delete();

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_RUNNER_GROUP_DELETED,
            'runner_group',
            $id,
            null,
            ['name' => $name, 'source' => 'api']
        );

        return $this->success(['deleted' => true]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyBody(RunnerGroup $model, array $body): void
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
     * @return array{id: int, name: string, description: string|null, runner_count: int, created_at: int}
     */
    private function serialize(RunnerGroup $g): array
    {
        return [
            'id' => $g->id,
            'name' => $g->name,
            'description' => $g->description,
            'runner_count' => (int)$g->getRunners()->count(),
            'created_at' => $g->created_at,
        ];
    }

    private function findModel(int $id): RunnerGroup
    {
        /** @var RunnerGroup|null $g */
        $g = RunnerGroup::findOne($id);
        if ($g === null) {
            throw new NotFoundHttpException("Runner group #{$id} not found.");
        }
        return $g;
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
