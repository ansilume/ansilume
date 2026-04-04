<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\AuditLog;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowExecutionService;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

/**
 * API v1: Workflow Templates CRUD + launch.
 */
class WorkflowTemplatesController extends BaseApiController
{
    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function actionIndex(): array
    {
        $dp = new ActiveDataProvider([
            'query' => WorkflowTemplate::find()->orderBy(['id' => SORT_DESC]),
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
    public function actionCreate(): array
    {
        $model = new WorkflowTemplate();
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);
        $model->created_by = (int)\Yii::$app->user->id;

        if (!$model->save()) {
            return $this->error($this->firstError($model), 422);
        }

        if (isset($body['steps']) && is_array($body['steps'])) {
            $this->saveSteps($model, $body['steps']);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_WORKFLOW_TEMPLATE_CREATED,
            'workflow_template',
            $model->id,
            null,
            ['name' => $model->name]
        );

        return $this->success($this->serializeDetailed($model), 201);
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

        if (isset($body['steps']) && is_array($body['steps'])) {
            WorkflowStep::deleteAll(['workflow_template_id' => $model->id]);
            $this->saveSteps($model, $body['steps']);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_WORKFLOW_TEMPLATE_UPDATED,
            'workflow_template',
            $model->id,
            null,
            ['name' => $model->name]
        );

        return $this->success($this->serializeDetailed($model));
    }

    /**
     * @return array{data: mixed}
     */
    public function actionDelete(int $id): array
    {
        $model = $this->findModel($id);
        $name = $model->name;
        $model->softDelete();

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_WORKFLOW_TEMPLATE_DELETED,
            'workflow_template',
            $id,
            null,
            ['name' => $name]
        );

        return $this->success(['deleted' => true]);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionLaunch(int $id): array
    {
        $model = $this->findModel($id);

        try {
            /** @var WorkflowExecutionService $service */
            $service = \Yii::$app->get('workflowExecutionService');
            $wfJob = $service->launch($model, (int)\Yii::$app->user->id);
            return $this->success(['workflow_job_id' => $wfJob->id], 201);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyBody(WorkflowTemplate $model, array $body): void
    {
        foreach (['name', 'description'] as $field) {
            if (array_key_exists($field, $body)) {
                $value = $body[$field];
                if ($field === 'name') {
                    $model->$field = (string)$value;
                } else {
                    $model->$field = $value === null ? null : (string)$value;
                }
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     */
    private function saveSteps(WorkflowTemplate $model, array $steps): void
    {
        $order = 0;
        foreach ($steps as $stepData) {
            $step = new WorkflowStep();
            $step->workflow_template_id = $model->id;
            $step->name = (string)($stepData['name'] ?? 'Step ' . $order);
            $step->step_order = $order++;
            $step->step_type = (string)($stepData['step_type'] ?? WorkflowStep::TYPE_JOB);
            if (isset($stepData['job_template_id'])) {
                $step->job_template_id = (int)$stepData['job_template_id'];
            }
            if (isset($stepData['approval_rule_id'])) {
                $step->approval_rule_id = (int)$stepData['approval_rule_id'];
            }
            if (isset($stepData['extra_vars_template'])) {
                $step->extra_vars_template = is_array($stepData['extra_vars_template'])
                    ? (string)json_encode($stepData['extra_vars_template'])
                    : (string)$stepData['extra_vars_template'];
            }
            $step->save(false);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(WorkflowTemplate $m): array
    {
        return [
            'id' => $m->id,
            'name' => $m->name,
            'description' => $m->description,
            'created_by' => $m->created_by,
            'created_at' => $m->created_at,
            'updated_at' => $m->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDetailed(WorkflowTemplate $m): array
    {
        $data = $this->serialize($m);
        $data['steps'] = array_map(fn (WorkflowStep $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'step_order' => $s->step_order,
            'step_type' => $s->step_type,
            'job_template_id' => $s->job_template_id,
            'approval_rule_id' => $s->approval_rule_id,
            'on_success_step_id' => $s->on_success_step_id,
            'on_failure_step_id' => $s->on_failure_step_id,
            'on_always_step_id' => $s->on_always_step_id,
            'extra_vars_template' => $s->getParsedExtraVarsTemplate(),
        ], $m->steps);
        return $data;
    }

    private function findModel(int $id): WorkflowTemplate
    {
        /** @var WorkflowTemplate|null $model */
        $model = WorkflowTemplate::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Workflow template #{$id} not found.");
        }
        return $model;
    }

    private function firstError(WorkflowTemplate $model): string
    {
        foreach ($model->errors as $errors) {
            return $errors[0] ?? 'Validation failed.';
        }
        return 'Validation failed.';
    }
}
