<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\AuditLog;
use app\models\JobTemplate;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\web\NotFoundHttpException;

/**
 * API v1: Job Templates
 *
 * GET    /api/v1/job-templates
 * GET    /api/v1/job-templates/{id}
 * POST   /api/v1/job-templates
 * PUT    /api/v1/job-templates/{id}
 * DELETE /api/v1/job-templates/{id}
 */
class JobTemplatesController extends BaseApiController
{
    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function actionIndex(): array
    {
        $dp = new ActiveDataProvider([
            'query' => JobTemplate::find()->with(['project', 'inventory'])->orderBy(['id' => SORT_DESC]),
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
        if (!$user->can('job-template.create')) {
            return $this->error('Forbidden.', 403);
        }

        $model = new JobTemplate();
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);
        $model->created_by = (int)$user->id;

        if (!$model->validate()) {
            return $this->error($this->firstError($model), 422);
        }
        if (!$model->save(false)) {
            return $this->error('Failed to save template.', 422);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_TEMPLATE_CREATED,
            'job_template',
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
        if (!$user->can('job-template.update')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);

        if (!$model->validate()) {
            return $this->error($this->firstError($model), 422);
        }
        if (!$model->save(false)) {
            return $this->error('Failed to save template.', 422);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_TEMPLATE_UPDATED,
            'job_template',
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
        if (!$user->can('job-template.delete')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $name = $model->name;
        $model->delete();

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_TEMPLATE_DELETED,
            'job_template',
            $id,
            null,
            ['name' => $name, 'source' => 'api']
        );

        return $this->success(['deleted' => true]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyBody(JobTemplate $model, array $body): void
    {
        $this->applyStringFields($model, $body);
        $this->applyIntFields($model, $body);
        if (array_key_exists('become', $body)) {
            $model->become = (bool)$body['become'];
        }
        if (array_key_exists('survey_fields', $body)) {
            $model->survey_fields = $body['survey_fields'] !== null
                ? (is_string($body['survey_fields']) ? $body['survey_fields'] : (string)json_encode($body['survey_fields']))
                : null;
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyStringFields(JobTemplate $model, array $body): void
    {
        $fields = ['name', 'description', 'playbook', 'become_method', 'become_user', 'limit', 'tags', 'skip_tags', 'extra_vars'];
        $nullable = ['description', 'limit', 'tags', 'skip_tags', 'extra_vars'];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            if ($body[$field] === null && in_array($field, $nullable, true)) {
                $model->$field = null;
            } else {
                $model->$field = (string)$body[$field];
            }
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyIntFields(JobTemplate $model, array $body): void
    {
        $fields = ['project_id', 'inventory_id', 'credential_id', 'verbosity', 'forks', 'timeout_minutes', 'runner_group_id', 'approval_rule_id'];
        $nullable = ['credential_id', 'runner_group_id', 'approval_rule_id'];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            if ($body[$field] === null && in_array($field, $nullable, true)) {
                $model->$field = null;
            } else {
                $model->$field = (int)$body[$field];
            }
        }
    }

    /**
     * @return array{id: int, name: string, description: string|null, project_id: int|null, project_name: string|null, inventory_id: int|null, inventory_name: string|null, credential_id: int|null, playbook: string, verbosity: int, forks: int, become: bool, become_method: string|null, become_user: string|null, limit: string|null, tags: string|null, skip_tags: string|null, has_survey: bool, created_at: int, updated_at: int}
     */
    private function serialize(JobTemplate $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'project_id' => $t->project_id,
            'project_name' => $t->project->name ?? null,
            'inventory_id' => $t->inventory_id,
            'inventory_name' => $t->inventory->name ?? null,
            'credential_id' => $t->credential_id,
            'playbook' => $t->playbook,
            'verbosity' => $t->verbosity,
            'forks' => $t->forks,
            'become' => (bool)$t->become,
            'become_method' => $t->become_method,
            'become_user' => $t->become_user,
            'limit' => $t->limit,
            'tags' => $t->tags,
            'skip_tags' => $t->skip_tags,
            'has_survey' => $t->hasSurvey(),
            'created_at' => $t->created_at,
            'updated_at' => $t->updated_at,
        ];
    }

    private function findModel(int $id): JobTemplate
    {
        /** @var JobTemplate|null $t */
        $t = JobTemplate::findOne($id);
        if ($t === null) {
            throw new NotFoundHttpException("Template #{$id} not found.");
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
