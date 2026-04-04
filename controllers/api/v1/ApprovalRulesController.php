<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\ApprovalRule;
use app\models\AuditLog;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

/**
 * API v1: Approval Rules CRUD.
 */
class ApprovalRulesController extends BaseApiController
{
    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function actionIndex(): array
    {
        $dp = new ActiveDataProvider([
            'query' => ApprovalRule::find()->orderBy(['id' => SORT_DESC]),
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
        $model = new ApprovalRule();
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);
        $model->created_by = (int)\Yii::$app->user->id;

        if (!$model->save()) {
            return $this->error($this->firstError($model), 422);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_APPROVAL_RULE_CREATED,
            'approval_rule',
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
            AuditLog::ACTION_APPROVAL_RULE_UPDATED,
            'approval_rule',
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
            AuditLog::ACTION_APPROVAL_RULE_DELETED,
            'approval_rule',
            $id,
            null,
            ['name' => $name]
        );

        return $this->success(['deleted' => true]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyBody(ApprovalRule $model, array $body): void
    {
        $this->applyStringFields($model, $body);
        $this->applyTypedFields($model, $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyStringFields(ApprovalRule $model, array $body): void
    {
        foreach (['name', 'timeout_action', 'approver_type'] as $f) {
            if (array_key_exists($f, $body)) {
                $model->$f = (string)$body[$f];
            }
        }
        if (array_key_exists('description', $body)) {
            $model->description = $body['description'] === null ? null : (string)$body['description'];
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyTypedFields(ApprovalRule $model, array $body): void
    {
        if (array_key_exists('required_approvals', $body)) {
            $model->required_approvals = (int)$body['required_approvals'];
        }
        if (array_key_exists('timeout_minutes', $body)) {
            $v = $body['timeout_minutes'];
            $model->timeout_minutes = $v === null ? null : (int)$v;
        }
        if (array_key_exists('job_template_id', $body)) {
            $v = $body['job_template_id'];
            $model->job_template_id = $v === null ? null : (int)$v;
        }
        if (array_key_exists('approver_config', $body)) {
            $v = $body['approver_config'];
            $model->approver_config = is_array($v) ? (string)json_encode($v) : (string)$v;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ApprovalRule $m): array
    {
        return [
            'id' => $m->id,
            'name' => $m->name,
            'description' => $m->description,
            'required_approvals' => $m->required_approvals,
            'timeout_minutes' => $m->timeout_minutes,
            'timeout_action' => $m->timeout_action,
            'approver_type' => $m->approver_type,
            'approver_config' => $m->getParsedConfig(),
            'job_template_id' => $m->job_template_id,
            'created_by' => $m->created_by,
            'created_at' => $m->created_at,
            'updated_at' => $m->updated_at,
        ];
    }

    private function findModel(int $id): ApprovalRule
    {
        /** @var ApprovalRule|null $model */
        $model = ApprovalRule::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Approval rule #{$id} not found.");
        }
        return $model;
    }

    private function firstError(ApprovalRule $model): string
    {
        foreach ($model->errors as $errors) {
            return $errors[0] ?? 'Validation failed.';
        }
        return 'Validation failed.';
    }
}
