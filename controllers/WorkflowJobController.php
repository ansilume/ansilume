<?php

declare(strict_types=1);

namespace app\controllers;

use app\controllers\traits\TeamScopingTrait;
use app\models\WorkflowJob;
use app\services\WorkflowAccessDeniedException;
use yii\data\ActiveDataProvider;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Workflow runs.
 *
 * Team scoping follows the run's workflow template, by its
 * workflow_template_id (deleted templates included): seeing a run needs view
 * access to the project of every job step, canceling or resuming it operator
 * access to every one.
 */
class WorkflowJobController extends BaseController
{
    use TeamScopingTrait;

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
        $query = WorkflowJob::find()
            ->with(['workflowTemplate', 'launcher'])
            ->orderBy(['workflow_job.id' => SORT_DESC]);
        $filter = $this->workflowChecker()->buildWorkflowTemplateFilter(
            $this->currentUserId(),
            'workflow_job.workflow_template_id'
        );
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
        $model = $this->findModel($id);
        $this->requireWorkflowView((int)$model->workflow_template_id);
        return $this->render('view', [
            'model' => $model,
            'canOperate' => $this->workflowChecker()->canOperateWorkflowTemplate(
                (int)$this->currentUserId(),
                (int)$model->workflow_template_id
            ),
        ]);
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
        $this->requireWorkflowView((int)$model->workflow_template_id);
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
        $now = time();
        $index = 1;
        foreach ($model->stepExecutions as $wjs) {
            $duration = $this->stepDurationSeconds($wjs, $now);
            $out[] = [
                'workflow_step_id' => (int)$wjs->workflow_step_id,
                // step_name + step_index let the polling JS build a brand-new
                // row when a workflow advances and a previously-unrendered
                // step starts running. Without these the client could only
                // update existing rows and operators had to reload.
                'step_name' => (string)($wjs->workflowStep?->name ?? '—'),
                'step_index' => $index++,
                'job_id' => $wjs->job_id !== null ? (int)$wjs->job_id : null,
                'is_current' => $currentStepId === (int)$wjs->workflow_step_id,
                'status' => (string)$wjs->status,
                'status_label' => \app\models\WorkflowJobStep::statusLabel($wjs->status),
                'status_css' => \app\models\WorkflowJobStep::statusCssClass($wjs->status),
                'started_at' => $wjs->started_at !== null ? (int)$wjs->started_at : null,
                'started_label' => $this->tsLabel($wjs->started_at, 'H:i:s'),
                'finished_at' => $wjs->finished_at !== null ? (int)$wjs->finished_at : null,
                'finished_label' => $this->tsLabel($wjs->finished_at, 'H:i:s'),
                'duration_seconds' => $duration,
                'duration_label' => $this->durationLabel($duration, $wjs->finished_at !== null),
                // Why a step failed without a job, e.g. no access to its job template.
                'error_message' => $wjs->error_message,
            ];
        }
        return $out;
    }

    private function tsLabel(?int $ts, string $fmt): ?string
    {
        return $ts !== null ? date($fmt, $ts) : null;
    }

    /**
     * Step elapsed time. Finished steps return finished-started; in-flight
     * steps return now-started so the UI shows a live-updating duration.
     * Steps that never started return null.
     */
    private function stepDurationSeconds(\app\models\WorkflowJobStep $wjs, int $now): ?int
    {
        if ($wjs->started_at === null) {
            return null;
        }
        $end = $wjs->finished_at !== null ? (int)$wjs->finished_at : $now;
        return max(0, $end - (int)$wjs->started_at);
    }

    /**
     * Human-readable duration ("2m 13s" / "running 0m 47s"). Kept on the
     * server side so the polling JS can drop the value straight into the
     * cell without re-implementing formatting.
     */
    private function durationLabel(?int $seconds, bool $finished): ?string
    {
        if ($seconds === null) {
            return null;
        }
        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;
        $core = $minutes > 0 ? sprintf('%dm %02ds', $minutes, $remaining) : sprintf('%ds', $remaining);
        return $finished ? $core : 'running ' . $core;
    }

    public function actionCancel(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireWorkflowOperate((int)$model->workflow_template_id);

        /** @var \app\services\WorkflowExecutionService $service */
        $service = \Yii::$app->get('workflowExecutionService');
        try {
            $service->cancel($model, (int)\Yii::$app->user->id);
        } catch (\RuntimeException $e) {
            $this->rethrowDenied($e);
            // e.g. the run finished meanwhile: a message, not a server error.
            $this->session()->setFlash('danger', $e->getMessage());
            return $this->redirect(['view', 'id' => $id]);
        }

        $this->session()->setFlash('success', 'Workflow canceled.');
        return $this->redirect(['view', 'id' => $id]);
    }

    public function actionResume(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireWorkflowOperate((int)$model->workflow_template_id);

        /** @var \app\services\WorkflowExecutionService $service */
        $service = \Yii::$app->get('workflowExecutionService');
        try {
            $service->resume($model, (int)\Yii::$app->user->id);
            $this->session()->setFlash('success', 'Paused step resumed.');
        } catch (\RuntimeException $e) {
            $this->rethrowDenied($e);
            $this->session()->setFlash('danger', $e->getMessage());
        }

        return $this->redirect(['view', 'id' => $id]);
    }

    /**
     * A refusal by team scoping (a RuntimeException too) answers 403.
     *
     * @throws ForbiddenHttpException
     */
    private function rethrowDenied(\RuntimeException $e): void
    {
        if ($e instanceof WorkflowAccessDeniedException) {
            throw new ForbiddenHttpException($e->getMessage(), 0, $e);
        }
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
