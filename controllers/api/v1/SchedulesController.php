<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\AuditLog;
use app\models\Schedule;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\web\NotFoundHttpException;

/**
 * API v1: Schedules
 *
 * GET    /api/v1/schedules
 * GET    /api/v1/schedules/{id}
 * POST   /api/v1/schedules
 * PUT    /api/v1/schedules/{id}
 * DELETE /api/v1/schedules/{id}
 * POST   /api/v1/schedules/{id}/toggle
 */
class SchedulesController extends BaseApiController
{
    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function actionIndex(): array
    {
        $dp = new ActiveDataProvider([
            'query' => Schedule::find()->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 25],
        ]);
        /** @var int $page */
        $page = \Yii::$app->request->get('page', 1);

        return $this->paginated(
            array_map(fn ($s) => $this->serialize($s), $dp->getModels()),
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
        if (!$user->can('job.launch')) {
            return $this->error('Forbidden.', 403);
        }

        $model = new Schedule();
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);
        $model->created_by = (int)$user->id;

        if (!$model->validate()) {
            return $this->error($this->firstError($model), 422);
        }
        if (!$model->save(false)) {
            return $this->error('Failed to save schedule.', 422);
        }
        $model->computeNextRunAt();

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_SCHEDULE_CREATED,
            'schedule',
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
        if (!$user->can('job.launch')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);

        if (!$model->validate()) {
            return $this->error($this->firstError($model), 422);
        }
        if (!$model->save(false)) {
            return $this->error('Failed to save schedule.', 422);
        }
        $model->computeNextRunAt();

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_SCHEDULE_UPDATED,
            'schedule',
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
        if (!$user->can('job.launch')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $name = $model->name;
        $model->delete();

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_SCHEDULE_DELETED,
            'schedule',
            $id,
            null,
            ['name' => $name, 'source' => 'api']
        );

        return $this->success(['deleted' => true]);
    }

    /**
     * @return array{data: mixed}
     */
    public function actionToggle(int $id): array
    {
        $schedule = $this->findModel($id);
        $schedule->enabled = !$schedule->enabled;
        if ($schedule->enabled) {
            $schedule->computeNextRunAt();
        } else {
            $schedule->next_run_at = null;
        }
        $schedule->save(false, ['enabled', 'next_run_at', 'updated_at']);
        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_SCHEDULE_TOGGLED,
            'schedule',
            $schedule->id,
            null,
            ['name' => $schedule->name, 'enabled' => $schedule->enabled]
        );
        return $this->success($this->serialize($schedule));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyBody(Schedule $model, array $body): void
    {
        foreach (['name', 'cron_expression', 'timezone', 'extra_vars'] as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = $body[$field];
            if ($value === null && $field === 'extra_vars') {
                $model->$field = null;
            } else {
                $model->$field = (string)$value;
            }
        }
        if (array_key_exists('job_template_id', $body)) {
            $model->job_template_id = (int)$body['job_template_id'];
        }
        if (array_key_exists('enabled', $body)) {
            $model->enabled = (bool)$body['enabled'];
        }
    }

    /**
     * @return array{id: int, name: string, job_template_id: int, cron_expression: string, timezone: string, enabled: bool, last_run_at: int|null, next_run_at: int|null, created_at: int, updated_at: int}
     */
    private function serialize(Schedule $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'job_template_id' => $s->job_template_id,
            'cron_expression' => $s->cron_expression,
            'timezone' => $s->timezone,
            'enabled' => (bool)$s->enabled,
            'last_run_at' => $s->last_run_at,
            'next_run_at' => $s->next_run_at,
            'created_at' => $s->created_at,
            'updated_at' => $s->updated_at,
        ];
    }

    private function findModel(int $id): Schedule
    {
        /** @var Schedule|null $schedule */
        $schedule = Schedule::findOne($id);
        if ($schedule === null) {
            throw new NotFoundHttpException("Schedule #{$id} not found.");
        }
        return $schedule;
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
