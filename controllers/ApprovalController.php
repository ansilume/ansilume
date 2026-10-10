<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\User;
use app\services\ApprovalService;
use app\services\WorkflowAccessChecker;
use yii\data\ActiveDataProvider;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Approval requests. Team scoping applies: a request is visible to users who
 * may view the job's project (or, for a workflow approval step, the
 * workflow), and only visible requests can be decided on.
 */
class ApprovalController extends BaseController
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'], 'allow' => true, 'roles' => ['approval.view']],
            ['actions' => ['approve', 'reject'], 'allow' => true, 'roles' => ['approval.decide']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return [
            'approve' => ['POST'],
            'reject' => ['POST'],
        ];
    }

    public function actionIndex(): string
    {
        $query = ApprovalRequest::find()
            ->with(['job', 'approvalRule'])
            ->orderBy(['id' => SORT_DESC]);
        $filter = $this->access()->buildApprovalRequestFilter($this->currentUserId());
        if ($filter !== null) {
            $query->andWhere($filter);
        }

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 20],
        ]);
        return $this->render('index', ['dataProvider' => $dataProvider]);
    }

    public function actionView(int $id): string
    {
        $model = $this->findVisibleModel($id);
        return $this->render('view', [
            'model' => $model,
            'canDecide' => $this->mayDecide($model),
        ]);
    }

    public function actionApprove(int $id): Response
    {
        return $this->decide($id, ApprovalDecision::DECISION_APPROVED);
    }

    public function actionReject(int $id): Response
    {
        return $this->decide($id, ApprovalDecision::DECISION_REJECTED);
    }

    /**
     * Visibility first (403), then a request someone already resolved
     * (flash), then eligibility (403). A decision the service refuses, e.g. a
     * second vote, is flashed instead of surfacing as a server error.
     */
    private function decide(int $id, string $decision): Response
    {
        $model = $this->findVisibleModel($id);
        if ($model->isResolved()) {
            $this->session()->setFlash('warning', "Approval request #{$id} is already resolved.");
            return $this->redirect(['view', 'id' => $id]);
        }

        $userId = (int)\Yii::$app->user->id;
        $service = $this->approvals();
        if (!$service->canUserApprove($model, $userId)) {
            throw new ForbiddenHttpException('You are not eligible to decide on this request.');
        }

        $comment = (string)(\Yii::$app->request->post('comment') ?? '');
        try {
            $service->recordDecision($model, $userId, $decision, $comment !== '' ? $comment : null);
        } catch (\RuntimeException $e) {
            $this->session()->setFlash('danger', 'Your decision was not recorded: ' . $e->getMessage());
            return $this->redirect(['view', 'id' => $id]);
        }

        $recorded = $decision === ApprovalDecision::DECISION_APPROVED ? 'Approval recorded.' : 'Rejection recorded.';
        $this->session()->setFlash('success', $recorded);
        return $this->redirect(['view', 'id' => $id]);
    }

    /**
     * Whether the view offers Approve and Reject: the user holds
     * approval.decide and is an eligible approver of the pending request.
     */
    private function mayDecide(ApprovalRequest $model): bool
    {
        $user = \Yii::$app->user;
        $identity = $user->identity;
        $superadmin = $identity instanceof User && (bool)$identity->is_superadmin;
        if (!$superadmin && !$user->can('approval.decide')) {
            return false;
        }

        return $this->approvals()->canUserApprove($model, (int)$user->id);
    }

    /**
     * 404 when the request does not exist, 403 when the user may not see it.
     */
    private function findVisibleModel(int $id): ApprovalRequest
    {
        /** @var ApprovalRequest|null $model */
        $model = ApprovalRequest::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Approval request #{$id} not found.");
        }
        $userId = $this->currentUserId();
        if ($userId === null || !$this->access()->canViewApprovalRequest($userId, $model)) {
            throw new ForbiddenHttpException('You do not have access to this resource.');
        }
        return $model;
    }

    private function currentUserId(): ?int
    {
        return \Yii::$app->user->isGuest ? null : (int)\Yii::$app->user->id;
    }

    private function approvals(): ApprovalService
    {
        /** @var ApprovalService $service */
        $service = \Yii::$app->get('approvalService');
        return $service;
    }

    private function access(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $checker */
        $checker = \Yii::$app->get('workflowAccessChecker');
        return $checker;
    }
}
