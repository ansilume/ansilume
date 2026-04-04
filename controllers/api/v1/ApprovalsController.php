<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\services\ApprovalService;
use yii\data\ActiveDataProvider;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * API v1: Approvals — list, view, approve, reject.
 */
class ApprovalsController extends BaseApiController
{
    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function actionIndex(): array
    {
        $dp = new ActiveDataProvider([
            'query' => ApprovalRequest::find()->orderBy(['id' => SORT_DESC]),
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
    public function actionApprove(int $id): array
    {
        return $this->decide($id, ApprovalDecision::DECISION_APPROVED);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionReject(int $id): array
    {
        return $this->decide($id, ApprovalDecision::DECISION_REJECTED);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    private function decide(int $id, string $decision): array
    {
        $model = $this->findModel($id);
        $userId = (int)\Yii::$app->user->id;

        /** @var ApprovalService $service */
        $service = \Yii::$app->get('approvalService');

        if (!$service->canUserApprove($model, $userId)) {
            throw new ForbiddenHttpException('You are not eligible to decide on this request.');
        }

        $body = (array)\Yii::$app->request->bodyParams;
        $comment = isset($body['comment']) ? (string)$body['comment'] : null;

        try {
            $service->recordDecision($model, $userId, $decision, $comment);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $model->refresh();
        return $this->success($this->serialize($model));
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ApprovalRequest $m): array
    {
        return [
            'id' => $m->id,
            'job_id' => $m->job_id,
            'approval_rule_id' => $m->approval_rule_id,
            'status' => $m->status,
            'requested_at' => $m->requested_at,
            'resolved_at' => $m->resolved_at,
            'expires_at' => $m->expires_at,
            'approval_count' => $m->approvalCount(),
            'rejection_count' => $m->rejectionCount(),
        ];
    }

    private function findModel(int $id): ApprovalRequest
    {
        /** @var ApprovalRequest|null $model */
        $model = ApprovalRequest::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Approval request #{$id} not found.");
        }
        return $model;
    }
}
