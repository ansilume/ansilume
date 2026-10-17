<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\components\ApiBodyValues;
use app\components\SubmittedId;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\controllers\api\v1\traits\ApiTeamScopingTrait;
use app\services\InventoryUsageService;
use app\services\InventoryWriteRefusedException;
use app\services\InventoryWriteService;
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
 *
 * The list leaves out content and source_path; GET /{id}, the 201 of POST
 * and the 200 of PUT answer the detail with them, for the audience of the
 * inventory's page: users who may see the inventory's project. GET /{id}
 * also answers used_by, the job templates that use the inventory as the
 * page's Job templates card shows them to the caller
 * (InventoryUsageService::forInventory()).
 *
 * Writes go through InventoryWriteService. A field of BODY_FIELDS that holds
 * a list or an object answers 422 (ApiBodyValues::NOT_A_VALUE) before the
 * body reaches the model. Only admins (approval-rule.update) assign or remove
 * approval_rule_id, change the hosts of an inventory with a rule, or delete
 * it (403); an inventory that job templates use is not deleted (409 with
 * used_by); a validation error answers 422 with the first error. A body that
 * sends "" for a field that may be empty means null, as the form's empty
 * field does: no description, content, source path or project.
 */
class InventoriesController extends BaseApiController
{
    use ApiTeamScopingTrait;

    /** The fields of a request body that the endpoints read: each takes a single value. */
    public const BODY_FIELDS = [
        'name',
        'description',
        'inventory_type',
        'content',
        'source_path',
        'project_id',
        'approval_rule_id',
    ];

    /** The fields applyBody() sets on the model; the write service reads approval_rule_id itself. */
    private const MODEL_FIELDS = ['name', 'description', 'inventory_type', 'content', 'source_path', 'project_id'];

    /** The fields applyBody() sets to null for a null value or "": null and "" are the same (no project). */
    private const NULLABLE_FIELDS = ['description', 'content', 'source_path', 'project_id'];

    protected function apiAccessRules(): array
    {
        return [
            'index' => 'inventory.view',
            'view' => 'inventory.view',
            'create' => 'inventory.create',
            'update' => 'inventory.update',
            'delete' => 'inventory.delete',
        ];
    }

    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function actionIndex(): array
    {
        $query = Inventory::find()->with('approvalRule')->orderBy(['id' => SORT_DESC]);
        $filter = $this->checker()->buildChildResourceFilter($this->currentUserId(), 'inventory.project_id');
        if ($filter !== null) {
            $query->andWhere($filter);
        }

        $dp = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 25],
        ]);
        $page = $this->requestedPage();

        return $this->paginated(
            array_map(fn (Inventory $inv): array => $this->listItem($inv), $dp->getModels()),
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
        $model = $this->findModel($id);
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canViewChildResource($userId, $model->project_id)) {
            return $this->error('Forbidden.', 403);
        }

        return $this->success($this->detail($model) + [
            'used_by' => $this->usage()->forInventory($model, $userId)->toArray(),
        ]);
    }

    /**
     * @return array{data: mixed}|array{error: array<string, mixed>}
     */
    public function actionCreate(): array
    {
        if (!$this->userCan('inventory.create')) {
            return $this->error('Forbidden.', 403);
        }
        $body = (array)\Yii::$app->request->bodyParams;
        $listValue = ApiBodyValues::listValueRefusal($body, self::BODY_FIELDS);
        if ($listValue !== null) {
            return $this->error($listValue, 422);
        }

        $model = new Inventory();
        $this->applyBody($model, $body);
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canOperateChildResource($userId, $model->project_id)) {
            return $this->error('Forbidden.', 403);
        }

        return $this->written(
            $model,
            fn (): bool => $this->writer()->create($model, $body, $userId, InventoryWriteService::SOURCE_API),
            201
        );
    }

    /**
     * @return array{data: mixed}|array{error: array<string, mixed>}
     */
    public function actionUpdate(int $id): array
    {
        if (!$this->userCan('inventory.update')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canOperateChildResource($userId, $model->project_id)) {
            return $this->error('Forbidden.', 403);
        }
        $body = (array)\Yii::$app->request->bodyParams;
        $listValue = ApiBodyValues::listValueRefusal($body, self::BODY_FIELDS);
        if ($listValue !== null) {
            return $this->error($listValue, 422);
        }
        $this->applyBody($model, $body);
        // The project it moves to as well, not only the one it comes from.
        if (!$this->checker()->canOperateChildResource($userId, $model->project_id)) {
            return $this->error('Forbidden.', 403);
        }

        return $this->written(
            $model,
            fn (): bool => $this->writer()->update($model, $body, $userId, InventoryWriteService::SOURCE_API),
            200
        );
    }

    /**
     * @return array{data: mixed}|array{error: array<string, mixed>}
     */
    public function actionDelete(int $id): array
    {
        if (!$this->userCan('inventory.delete')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canOperateChildResource($userId, $model->project_id)) {
            return $this->error('Forbidden.', 403);
        }
        try {
            $this->writer()->delete($model, $userId, InventoryWriteService::SOURCE_API);
        } catch (InventoryWriteRefusedException $e) {
            return $this->errorWithDetails($e->getMessage(), $e->status, $e->details);
        }

        return $this->success(['deleted' => true]);
    }

    /**
     * Runs $write (InventoryWriteService::create() or update()) and answers
     * $status with the inventory's detail; 422 with the first error when it
     * did not save; the refusal's status, message and details when the
     * service refused.
     *
     * @param callable(): bool $write
     * @return array{data: mixed}|array{error: array<string, mixed>}
     */
    private function written(Inventory $model, callable $write, int $status): array
    {
        try {
            $saved = $write();
        } catch (InventoryWriteRefusedException $e) {
            return $this->errorWithDetails($e->getMessage(), $e->status, $e->details);
        }

        return $saved ? $this->success($this->detail($model), $status) : $this->error($this->firstError($model), 422);
    }

    /**
     * Sets the MODEL_FIELDS that $body holds, cast to the model's types; null
     * or "" for a NULLABLE_FIELDS field is null, so that "" is no project
     * rather than project 0, and the same as a stored null.
     *
     * @param array<array-key, mixed> $body
     */
    private function applyBody(Inventory $model, #[\SensitiveParameter] array $body): void
    {
        foreach (self::MODEL_FIELDS as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = $body[$field];
            if (($value === null || $value === '') && in_array($field, self::NULLABLE_FIELDS, true)) {
                $model->$field = null;
            } elseif ($field === 'project_id') {
                $model->project_id = (int)$value;
            } else {
                $model->$field = (string)$value;
            }
        }
    }

    /**
     * An inventory as the list shows it: without its content and source path.
     *
     * @return array{id: int, name: string, description: string|null, inventory_type: string, project_id: int|null, approval_rule_id: int|null, approval_rule_name: string|null, created_at: int, updated_at: int}
     */
    private function listItem(Inventory $inv): array
    {
        $ruleId = SubmittedId::of($inv->getAttribute('approval_rule_id'));
        /** @var ApprovalRule|null $rule */
        $rule = $ruleId === null ? null : $inv->approvalRule;

        return [
            'id' => (int)$inv->id,
            'name' => (string)$inv->name,
            'description' => $inv->description,
            'inventory_type' => (string)$inv->inventory_type,
            'project_id' => $inv->project_id,
            'approval_rule_id' => $ruleId,
            'approval_rule_name' => $rule?->name,
            'created_at' => (int)$inv->created_at,
            'updated_at' => (int)$inv->updated_at,
        ];
    }

    /**
     * An inventory as GET /{id}, POST and PUT answer it: the list item with
     * its content and source path.
     *
     * @return array<string, mixed>
     */
    private function detail(Inventory $inv): array
    {
        return $this->listItem($inv) + ['content' => $inv->content, 'source_path' => $inv->source_path];
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

    private function writer(): InventoryWriteService
    {
        /** @var InventoryWriteService $writer */
        $writer = \Yii::$app->get('inventoryWriteService');

        return $writer;
    }

    private function usage(): InventoryUsageService
    {
        /** @var InventoryUsageService $usage */
        $usage = \Yii::$app->get('inventoryUsageService');

        return $usage;
    }
}
