<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\AuditLog;
use app\models\Webhook;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\web\NotFoundHttpException;

/**
 * API v1: Webhooks
 *
 * GET    /api/v1/webhooks
 * GET    /api/v1/webhooks/{id}
 * POST   /api/v1/webhooks
 * PUT    /api/v1/webhooks/{id}
 * DELETE /api/v1/webhooks/{id}
 */
class WebhooksController extends BaseApiController
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
            'query' => Webhook::find()->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 25],
        ]);
        /** @var int $page */
        $page = \Yii::$app->request->get('page', 1);

        return $this->paginated(
            array_map(fn ($w) => $this->serialize($w), $dp->getModels()),
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
        return $this->success($this->serialize($this->findModel($id)));
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

        $model = new Webhook();
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);
        $model->created_by = (int)$user->id;

        if (!$model->validate()) {
            return $this->error($this->firstError($model), 422);
        }
        if (!$model->save(false)) {
            return $this->error('Failed to save webhook.', 422);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_WEBHOOK_CREATED,
            'webhook',
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
            return $this->error('Failed to save webhook.', 422);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_WEBHOOK_UPDATED,
            'webhook',
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
        if (!$user->can('admin')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $name = $model->name;
        $model->delete();

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_WEBHOOK_DELETED,
            'webhook',
            $id,
            null,
            ['name' => $name, 'source' => 'api']
        );

        return $this->success(['deleted' => true]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyBody(Webhook $model, array $body): void
    {
        foreach (['name', 'url', 'secret', 'events'] as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = $body[$field];
            if ($value === null && in_array($field, ['secret'], true)) {
                $model->$field = null;
            } elseif ($field === 'events' && is_array($value)) {
                $model->events = implode(',', $value);
            } else {
                $model->$field = (string)$value;
            }
        }
        if (array_key_exists('enabled', $body)) {
            $model->enabled = (bool)$body['enabled'];
        }
    }

    /**
     * @return array{id: int, name: string, url: string, events: string, enabled: bool, created_at: int, updated_at: int}
     */
    private function serialize(Webhook $w): array
    {
        return [
            'id' => $w->id,
            'name' => $w->name,
            'url' => $w->url,
            'events' => $w->events,
            'enabled' => (bool)$w->enabled,
            'created_at' => $w->created_at,
            'updated_at' => $w->updated_at,
        ];
    }

    private function findModel(int $id): Webhook
    {
        /** @var Webhook|null $w */
        $w = Webhook::findOne($id);
        if ($w === null) {
            throw new NotFoundHttpException("Webhook #{$id} not found.");
        }
        return $w;
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
