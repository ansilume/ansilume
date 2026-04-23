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
            ['actions' => ['index', 'view', 'status'], 'allow' => true, 'roles' => ['workflow.view']],
            ['actions' => ['cancel'], 'allow' => true, 'roles' => ['workflow.cancel']],
            ['actions' => ['resume'], 'allow' => true, 'roles' => ['workflow.launch']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return ['cancel' => ['POST'], 'resume' => ['POST']];
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

    /**
     * GET /workflow-job/status?id=N
     *
     * JSON snapshot of a workflow job for the detail page's polling loop.
     * Returns the overall status + per-step status so the client can
     * update badges / the "current step" highlight in place without a
     * full page reload.
     *
     * @return array<string, mixed>
     */
    public function actionStatus(int $id): array
    {
        $model = $this->findModel($id);
        \Yii::$app->response->format = Response::FORMAT_JSON;

        $steps = [];
        foreach ($model->stepExecutions as $wjs) {
            $steps[] = [
                'workflow_step_id' => (int)$wjs->workflow_step_id,
                'job_id' => $wjs->job_id !== null ? (int)$wjs->job_id : null,
                'is_current' => (int)$model->current_step_id === (int)$wjs->workflow_step_id,
                'status' => (string)$wjs->status,
                'status_label' => \app\models\WorkflowJobStep::statusLabel($wjs->status),
                'status_css' => \app\models\WorkflowJobStep::statusCssClass($wjs->status),
                'started_at' => $wjs->started_at !== null ? (int)$wjs->started_at : null,
                'started_label' => $wjs->started_at !== null ? date('H:i:s', (int)$wjs->started_at) : null,
                'finished_at' => $wjs->finished_at !== null ? (int)$wjs->finished_at : null,
                'finished_label' => $wjs->finished_at !== null ? date('H:i:s', (int)$wjs->finished_at) : null,
            ];
        }

        return [
            'id' => (int)$model->id,
            'status' => (string)$model->status,
            'status_label' => WorkflowJob::statusLabel($model->status),
            'status_css' => WorkflowJob::statusCssClass($model->status),
            'is_finished' => $model->isFinished(),
            'started_at' => $model->started_at !== null ? (int)$model->started_at : null,
            'started_label' => $model->started_at !== null ? date('Y-m-d H:i:s', (int)$model->started_at) : null,
            'finished_at' => $model->finished_at !== null ? (int)$model->finished_at : null,
            'finished_label' => $model->finished_at !== null ? date('Y-m-d H:i:s', (int)$model->finished_at) : null,
            'current_step_id' => $model->current_step_id !== null ? (int)$model->current_step_id : null,
            'steps' => $steps,
        ];
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

    public function actionResume(int $id): Response
    {
        $model = $this->findModel($id);

        /** @var \app\services\WorkflowExecutionService $service */
        $service = \Yii::$app->get('workflowExecutionService');
        try {
            $service->resume($model, (int)\Yii::$app->user->id);
            $this->session()->setFlash('success', 'Paused step resumed.');
        } catch (\RuntimeException $e) {
            $this->session()->setFlash('danger', $e->getMessage());
        }

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
