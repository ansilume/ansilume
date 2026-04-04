<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\services\ApprovalService;
use yii\data\ActiveDataProvider;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

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
        $dataProvider = new ActiveDataProvider([
            'query' => ApprovalRequest::find()
                ->with(['job', 'approvalRule'])
                ->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 20],
        ]);
        return $this->render('index', ['dataProvider' => $dataProvider]);
    }

    public function actionView(int $id): string
    {
        $model = $this->findModel($id);
        return $this->render('view', ['model' => $model]);
    }

    public function actionApprove(int $id): Response
    {
        $model = $this->findModel($id);
        $userId = (int)\Yii::$app->user->id;
        $comment = (string)(\Yii::$app->request->post('comment') ?? '');

        /** @var ApprovalService $service */
        $service = \Yii::$app->get('approvalService');

        if (!$service->canUserApprove($model, $userId)) {
            throw new ForbiddenHttpException('You are not eligible to approve this request.');
        }

        $service->recordDecision($model, $userId, ApprovalDecision::DECISION_APPROVED, $comment ?: null);
        $this->session()->setFlash('success', 'Approval recorded.');
        return $this->redirect(['view', 'id' => $id]);
    }

    public function actionReject(int $id): Response
    {
        $model = $this->findModel($id);
        $userId = (int)\Yii::$app->user->id;
        $comment = (string)(\Yii::$app->request->post('comment') ?? '');

        /** @var ApprovalService $service */
        $service = \Yii::$app->get('approvalService');

        if (!$service->canUserApprove($model, $userId)) {
            throw new ForbiddenHttpException('You are not eligible to decide on this request.');
        }

        $service->recordDecision($model, $userId, ApprovalDecision::DECISION_REJECTED, $comment ?: null);
        $this->session()->setFlash('success', 'Rejection recorded.');
        return $this->redirect(['view', 'id' => $id]);
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
