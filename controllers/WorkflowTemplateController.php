<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\AuditLog;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class WorkflowTemplateController extends BaseController
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'], 'allow' => true, 'roles' => ['workflow-template.view']],
            ['actions' => ['create', 'add-step', 'remove-step'], 'allow' => true, 'roles' => ['workflow-template.create']],
            ['actions' => ['update', 'add-step', 'remove-step'], 'allow' => true, 'roles' => ['workflow-template.update']],
            ['actions' => ['delete'], 'allow' => true, 'roles' => ['workflow-template.delete']],
            ['actions' => ['launch'], 'allow' => true, 'roles' => ['workflow.launch']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return [
            'delete' => ['POST'],
            'launch' => ['POST'],
            'add-step' => ['POST'],
            'remove-step' => ['POST'],
        ];
    }

    public function actionIndex(): string
    {
        $dataProvider = new ActiveDataProvider([
            'query' => WorkflowTemplate::find()->with('creator')->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 20],
        ]);
        return $this->render('index', ['dataProvider' => $dataProvider]);
    }

    public function actionView(int $id): string
    {
        $model = $this->findModel($id);
        return $this->render('view', ['model' => $model]);
    }

    public function actionCreate(): Response|string
    {
        $model = new WorkflowTemplate();

        if ($model->load((array)\Yii::$app->request->post())) {
            $model->created_by = (int)\Yii::$app->user->id;
            if ($model->save()) {
                \Yii::$app->get('auditService')->log(
                    AuditLog::ACTION_WORKFLOW_TEMPLATE_CREATED,
                    'workflow_template',
                    $model->id,
                    null,
                    ['name' => $model->name]
                );
                $this->session()->setFlash('success', "Workflow template \"{$model->name}\" created.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }
        return $this->render('form', ['model' => $model]);
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        if ($model->load((array)\Yii::$app->request->post()) && $model->save()) {
            \Yii::$app->get('auditService')->log(
                AuditLog::ACTION_WORKFLOW_TEMPLATE_UPDATED,
                'workflow_template',
                $model->id,
                null,
                ['name' => $model->name]
            );
            $this->session()->setFlash('success', "Workflow template \"{$model->name}\" updated.");
            return $this->redirect(['view', 'id' => $model->id]);
        }
        return $this->render('form', ['model' => $model]);
    }

    public function actionDelete(int $id): Response
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
        $this->session()->setFlash('success', "Workflow template \"{$name}\" deleted.");
        return $this->redirect(['index']);
    }

    public function actionLaunch(): Response
    {
        $id = (int)(\Yii::$app->request->get('id') ?? \Yii::$app->request->post('id', 0));
        $model = $this->findModel($id);

        try {
            /** @var \app\services\WorkflowExecutionService $service */
            $service = \Yii::$app->get('workflowExecutionService');
            $wfJob = $service->launch($model, (int)\Yii::$app->user->id);
            $this->session()->setFlash('success', "Workflow \"{$model->name}\" launched.");
            return $this->redirect(['/workflow-job/view', 'id' => $wfJob->id]);
        } catch (\RuntimeException $e) {
            $this->session()->setFlash('danger', 'Launch failed: ' . $e->getMessage());
            return $this->redirect(['/workflow-template/view', 'id' => $id]);
        }
    }

    public function actionAddStep(int $id): Response
    {
        $model = $this->findModel($id);
        $step = new WorkflowStep();
        $step->workflow_template_id = $model->id;

        if ($step->load((array)\Yii::$app->request->post())) {
            // Preserve the END_WORKFLOW sentinel (0): Yii's load() may set
            // empty-string prompt values to null, which is correct ("next step").
            // A submitted "0" means "end workflow" and must stay as integer 0.
            $this->applyBranchFields($step);
            if ($step->save()) {
                $this->session()->setFlash('success', "Step \"{$step->name}\" added.");
            }
        }
        return $this->redirect(['view', 'id' => $id]);
    }

    /**
     * Ensure on_success/on_failure/on_always correctly distinguish between
     * NULL (next step) and 0 (end workflow) from submitted form data.
     */
    private function applyBranchFields(WorkflowStep $step): void
    {
        $post = (array)\Yii::$app->request->post('WorkflowStep', []);
        foreach (['on_success_step_id', 'on_failure_step_id', 'on_always_step_id'] as $field) {
            if (!array_key_exists($field, $post) || $post[$field] === '') {
                $step->$field = null;
            } else {
                $step->$field = (int)$post[$field];
            }
        }
    }

    public function actionRemoveStep(int $id): Response
    {
        $stepId = (int)\Yii::$app->request->post('step_id');
        /** @var WorkflowStep|null $step */
        $step = WorkflowStep::findOne(['id' => $stepId, 'workflow_template_id' => $id]);
        if ($step !== null) {
            $step->delete();
            $this->session()->setFlash('success', 'Step removed.');
        }
        return $this->redirect(['view', 'id' => $id]);
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
}
