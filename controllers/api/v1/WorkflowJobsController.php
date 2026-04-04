<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\services\WorkflowExecutionService;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

/**
 * API v1: Workflow Jobs — list, view, cancel.
 */
class WorkflowJobsController extends BaseApiController
{
    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function actionIndex(): array
    {
        $dp = new ActiveDataProvider([
            'query' => WorkflowJob::find()->orderBy(['id' => SORT_DESC]),
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
        return $this->success($this->serializeDetailed($this->findModel($id)));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionCancel(int $id): array
    {
        $model = $this->findModel($id);

        try {
            /** @var WorkflowExecutionService $service */
            $service = \Yii::$app->get('workflowExecutionService');
            $service->cancel($model, (int)\Yii::$app->user->id);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $model->refresh();
        return $this->success($this->serialize($model));
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(WorkflowJob $m): array
    {
        return [
            'id' => $m->id,
            'workflow_template_id' => $m->workflow_template_id,
            'status' => $m->status,
            'launched_by' => $m->launched_by,
            'started_at' => $m->started_at,
            'finished_at' => $m->finished_at,
            'created_at' => $m->created_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDetailed(WorkflowJob $m): array
    {
        $data = $this->serialize($m);
        $data['steps'] = array_map(fn (WorkflowJobStep $s) => [
            'id' => $s->id,
            'workflow_step_id' => $s->workflow_step_id,
            'job_id' => $s->job_id,
            'status' => $s->status,
            'started_at' => $s->started_at,
            'finished_at' => $s->finished_at,
        ], $m->stepExecutions);
        return $data;
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
