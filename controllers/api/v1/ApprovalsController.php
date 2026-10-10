<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\services\ApprovalService;
use app\services\WorkflowAccessChecker;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

/**
 * API v1: Approvals — list, view, approve, reject.
 *
 * Team scoping applies: the list holds only the requests the caller may see
 * (view access to the job's project or, for a workflow approval step, the
 * workflow); other requests answer 403.
 */
class ApprovalsController extends BaseApiController
{
    protected function apiAccessRules(): array
    {
        return [
            'index' => 'approval.view',
            'view' => 'approval.view',
            'approve' => 'approval.decide',
            'reject' => 'approval.decide',
        ];
    }

    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function actionIndex(): array
    {
        $query = ApprovalRequest::find()->orderBy(['id' => SORT_DESC]);
        $filter = $this->access()->buildApprovalRequestFilter($this->currentUserId());
        if ($filter !== null) {
            $query->andWhere($filter);
        }
        $dp = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 25],
        ]);
        $page = $this->requestedPage();

        return $this->paginated(
            array_map(fn ($m) => $this->serialize($m), $dp->getModels()),
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
        if (!$this->mayView($model)) {
            return $this->error('Forbidden.', 403);
        }
        return $this->success($this->serialize($model));
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
     * Visibility first, then eligibility: both answer 403.
     *
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    private function decide(int $id, string $decision): array
    {
        $model = $this->findModel($id);
        if (!$this->mayView($model)) {
            return $this->error('Forbidden.', 403);
        }

        $userId = (int)\Yii::$app->user->id;
        /** @var ApprovalService $service */
        $service = \Yii::$app->get('approvalService');
        if (!$service->canUserApprove($model, $userId)) {
            return $this->error('You are not eligible to decide on this request.', 403);
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

    private function mayView(ApprovalRequest $model): bool
    {
        $userId = $this->currentUserId();
        return $userId !== null && $this->access()->canViewApprovalRequest($userId, $model);
    }

    private function currentUserId(): ?int
    {
        return \Yii::$app->user->isGuest ? null : (int)\Yii::$app->user->id;
    }

    private function access(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $checker */
        $checker = \Yii::$app->get('workflowAccessChecker');
        return $checker;
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
