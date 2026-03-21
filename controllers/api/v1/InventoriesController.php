<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\Inventory;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

/**
 * API v1: Inventories (read-only)
 *
 * GET /api/v1/inventories
 * GET /api/v1/inventories/{id}
 */
class InventoriesController extends BaseApiController
{
    public function actionIndex(): array
    {
        $dp   = new ActiveDataProvider([
            'query' => Inventory::find()->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 25],
        ]);
        $page = (int)\Yii::$app->request->get('page', 1);

        return $this->paginated(
            array_map(fn($inv) => $this->serialize($inv), $dp->getModels()),
            (int)$dp->totalCount,
            $page,
            25
        );
    }

    public function actionView(int $id): array
    {
        $inventory = Inventory::findOne($id);
        if ($inventory === null) {
            throw new NotFoundHttpException("Inventory #{$id} not found.");
        }
        return $this->success($this->serialize($inventory));
    }

    private function serialize(Inventory $inv): array
    {
        return [
            'id'             => $inv->id,
            'name'           => $inv->name,
            'description'    => $inv->description,
            'inventory_type' => $inv->inventory_type,
            'project_id'     => $inv->project_id,
            'created_at'     => $inv->created_at,
            'updated_at'     => $inv->updated_at,
            // inventory content and source_path are intentionally excluded:
            // content may contain host lists; source_path reveals filesystem layout.
        ];
    }
}
