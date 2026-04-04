<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\AuditLog;
use app\models\NotificationTemplate;
use app\services\NotificationDispatcher;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

/**
 * API v1: Notification Templates
 *
 * GET    /api/v1/notification-templates
 * GET    /api/v1/notification-templates/{id}
 * POST   /api/v1/notification-templates
 * PUT    /api/v1/notification-templates/{id}
 * DELETE /api/v1/notification-templates/{id}
 * POST   /api/v1/notification-templates/{id}/test
 */
class NotificationTemplatesController extends BaseApiController
{
    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function actionIndex(): array
    {
        $dp = new ActiveDataProvider([
            'query' => NotificationTemplate::find()->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 25],
        ]);
        /** @var int $page */
        $page = \Yii::$app->request->get('page', 1);

        return $this->paginated(
            array_map(fn ($m) => $this->serialize($m), $dp->getModels()),
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
        $model = new NotificationTemplate();
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);
        $model->created_by = (int)\Yii::$app->user->id;

        if (!$model->save()) {
            return $this->error($this->firstError($model), 422);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_NOTIFICATION_TEMPLATE_CREATED,
            'notification_template',
            $model->id,
            null,
            ['name' => $model->name]
        );

        return $this->success($this->serialize($model), 201);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionUpdate(int $id): array
    {
        $model = $this->findModel($id);
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);

        if (!$model->save()) {
            return $this->error($this->firstError($model), 422);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_NOTIFICATION_TEMPLATE_UPDATED,
            'notification_template',
            $model->id,
            null,
            ['name' => $model->name]
        );

        return $this->success($this->serialize($model));
    }

    /**
     * @return array{data: mixed}
     */
    public function actionDelete(int $id): array
    {
        $model = $this->findModel($id);
        $name = $model->name;
        $model->delete();

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_NOTIFICATION_TEMPLATE_DELETED,
            'notification_template',
            $id,
            null,
            ['name' => $name]
        );

        return $this->success(['deleted' => true]);
    }

    /**
     * Send a test notification using sample variables.
     *
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionTest(int $id): array
    {
        $model = $this->findModel($id);

        $variables = [
            'job.id' => '0',
            'job.status' => 'successful',
            'job.exit_code' => '0',
            'job.duration' => '42s',
            'job.url' => \Yii::$app->params['appBaseUrl'] ?? 'http://localhost',
            'template.name' => 'Test Template',
            'project.name' => 'Test Project',
            'launched_by' => \Yii::$app->user->identity?->username ?? 'api',
            'timestamp' => date('Y-m-d H:i:s T'),
        ];

        try {
            /** @var NotificationDispatcher $dispatcher */
            $dispatcher = \Yii::$app->get('notificationDispatcher');
            $dispatcher->sendSingle($model, $variables);
            return $this->success(['sent' => true]);
        } catch (\Throwable $e) {
            return $this->error('Test failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyBody(NotificationTemplate $model, array $body): void
    {
        $nullable = ['description', 'config', 'subject_template', 'body_template'];

        foreach (['name', 'description', 'channel', 'config', 'subject_template', 'body_template', 'events'] as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = $body[$field];
            if ($field === 'config' && is_array($value)) {
                $model->$field = (string)json_encode($value);
            } elseif ($value === null && in_array($field, $nullable, true)) {
                $model->$field = null;
            } else {
                $model->$field = (string)$value;
            }
        }
    }

    /**
     * @return array{id: int, name: string, description: string|null, channel: string, config: mixed, events: string[], subject_template: string|null, body_template: string|null, created_by: int, created_at: int, updated_at: int}
     */
    private function serialize(NotificationTemplate $m): array
    {
        return [
            'id' => $m->id,
            'name' => $m->name,
            'description' => $m->description,
            'channel' => $m->channel,
            'config' => $m->getParsedConfig(),
            'events' => $m->getEventList(),
            'subject_template' => $m->subject_template,
            'body_template' => $m->body_template,
            'created_by' => $m->created_by,
            'created_at' => $m->created_at,
            'updated_at' => $m->updated_at,
        ];
    }

    private function findModel(int $id): NotificationTemplate
    {
        /** @var NotificationTemplate|null $model */
        $model = NotificationTemplate::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Notification template #{$id} not found.");
        }
        return $model;
    }

    private function firstError(NotificationTemplate $model): string
    {
        foreach ($model->errors as $errors) {
            return $errors[0] ?? 'Validation failed.';
        }
        return 'Validation failed.';
    }
}
