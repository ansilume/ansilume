<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\WorkflowJob;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class WorkflowJobController extends BaseController
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'], 'allow' => true, 'roles' => ['workflow.view']],
            ['actions' => ['cancel'], 'allow' => true, 'roles' => ['workflow.cancel']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return ['cancel' => ['POST']];
    }

    public function actionIndex(): string
    {
        $dataProvider = new ActiveDataProvider([
            'query' => WorkflowJob::find()
                ->with(['workflowTemplate', 'launcher'])
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

    public function actionCancel(int $id): Response
    {
        $model = $this->findModel($id);

        /** @var \app\services\WorkflowExecutionService $service */
        $service = \Yii::$app->get('workflowExecutionService');
        $service->cancel($model, (int)\Yii::$app->user->id);

        $this->session()->setFlash('success', 'Workflow canceled.');
        return $this->redirect(['view', 'id' => $id]);
    }

    private function findModel(int $id): WorkflowJob
    {
        /** @var WorkflowJob|null $model */
        $model = WorkflowJob::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Workflow job #{$id} not found.");
        }
        return $model;
    }
}
