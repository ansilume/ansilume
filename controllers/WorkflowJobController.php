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

        return [
            'id' => (int)$model->id,
            'status' => (string)$model->status,
            'status_label' => WorkflowJob::statusLabel($model->status),
            'status_css' => WorkflowJob::statusCssClass($model->status),
            'is_finished' => $model->isFinished(),
            'started_at' => $model->started_at !== null ? (int)$model->started_at : null,
            'started_label' => $this->tsLabel($model->started_at, 'Y-m-d H:i:s'),
            'finished_at' => $model->finished_at !== null ? (int)$model->finished_at : null,
            'finished_label' => $this->tsLabel($model->finished_at, 'Y-m-d H:i:s'),
            'current_step_id' => $model->current_step_id !== null ? (int)$model->current_step_id : null,
            'steps' => $this->serializeSteps($model),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeSteps(WorkflowJob $model): array
    {
        $out = [];
        $currentStepId = $model->current_step_id !== null ? (int)$model->current_step_id : null;
        foreach ($model->stepExecutions as $wjs) {
            $out[] = [
                'workflow_step_id' => (int)$wjs->workflow_step_id,
                'job_id' => $wjs->job_id !== null ? (int)$wjs->job_id : null,
                'is_current' => $currentStepId === (int)$wjs->workflow_step_id,
                'status' => (string)$wjs->status,
                'status_label' => \app\models\WorkflowJobStep::statusLabel($wjs->status),
                'status_css' => \app\models\WorkflowJobStep::statusCssClass($wjs->status),
                'started_at' => $wjs->started_at !== null ? (int)$wjs->started_at : null,
                'started_label' => $this->tsLabel($wjs->started_at, 'H:i:s'),
                'finished_at' => $wjs->finished_at !== null ? (int)$wjs->finished_at : null,
                'finished_label' => $this->tsLabel($wjs->finished_at, 'H:i:s'),
            ];
        }
        return $out;
    }

    private function tsLabel(?int $ts, string $fmt): ?string
    {
        return $ts !== null ? date($fmt, $ts) : null;
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
