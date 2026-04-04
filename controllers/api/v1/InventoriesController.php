<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\AuditLog;
use app\models\Inventory;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

/**
 * API v1: Inventories
 *
 * GET    /api/v1/inventories
 * GET    /api/v1/inventories/{id}
 * POST   /api/v1/inventories
 * PUT    /api/v1/inventories/{id}
 * DELETE /api/v1/inventories/{id}
 */
class InventoriesController extends BaseApiController
{
    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function actionIndex(): array
    {
        $dp = new ActiveDataProvider([
            'query' => Inventory::find()->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 25],
        ]);
        /** @var int $page */
        $page = \Yii::$app->request->get('page', 1);

        return $this->paginated(
            array_map(fn ($inv) => $this->serialize($inv), $dp->getModels()),
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
        if (!$user->can('inventory.create')) {
            return $this->error('Forbidden.', 403);
        }

        $model = new Inventory();
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);
        $model->created_by = (int)$user->id;

        if (!$model->save()) {
            return $this->error($this->firstError($model), 422);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_INVENTORY_CREATED,
            'inventory',
            $model->id,
            null,
            ['name' => $model->name, 'type' => $model->inventory_type]
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
        if (!$user->can('inventory.update')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);

        if (!$model->save()) {
            return $this->error($this->firstError($model), 422);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_INVENTORY_UPDATED,
            'inventory',
            $model->id,
            null,
            ['name' => $model->name]
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
        if (!$user->can('inventory.delete')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $name = $model->name;
        $model->delete();

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_INVENTORY_DELETED,
            'inventory',
            $id,
            null,
            ['name' => $name]
        );

        return $this->success(['deleted' => true]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyBody(Inventory $model, array $body): void
    {
        $nullable = ['description', 'content', 'source_path', 'project_id'];

        foreach (['name', 'description', 'inventory_type', 'content', 'source_path', 'project_id'] as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = $body[$field];
            if ($value === null && in_array($field, $nullable, true)) {
                $model->$field = null;
            } elseif ($field === 'project_id') {
                $model->project_id = (int)$value;
            } else {
                $model->$field = (string)$value;
            }
        }
    }

    /**
     * @return array{id: int, name: string, description: string|null, inventory_type: string, project_id: int|null, created_at: int, updated_at: int}
     */
    private function serialize(Inventory $inv): array
    {
        return [
            'id' => $inv->id,
            'name' => $inv->name,
            'description' => $inv->description,
            'inventory_type' => $inv->inventory_type,
            'project_id' => $inv->project_id,
            'created_at' => $inv->created_at,
            'updated_at' => $inv->updated_at,
            // inventory content and source_path are intentionally excluded:
            // content may contain host lists; source_path reveals filesystem layout.
        ];
    }

    private function findModel(int $id): Inventory
    {
        /** @var Inventory|null $inventory */
        $inventory = Inventory::findOne($id);
        if ($inventory === null) {
            throw new NotFoundHttpException("Inventory #{$id} not found.");
        }
        return $inventory;
    }

    private function firstError(Inventory $model): string
    {
        foreach ($model->errors as $errors) {
            return $errors[0] ?? 'Validation failed.';
        }
        return 'Validation failed.';
    }
}
