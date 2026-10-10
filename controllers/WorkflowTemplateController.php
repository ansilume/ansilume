<?php

declare(strict_types=1);

namespace app\controllers;

use app\controllers\traits\TeamScopingTrait;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowAccessDeniedException;
use yii\data\ActiveDataProvider;
use yii\helpers\ArrayHelper;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Workflow templates.
 *
 * Team scoping: a workflow belongs to the projects of its job steps. Seeing
 * it needs view access to every job step's project; changing, deleting and
 * launching it, and managing its trigger token, need operator access to
 * every one. A new job step may only use a job template the user may operate.
 */
class WorkflowTemplateController extends BaseController
{
    use TeamScopingTrait;

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'], 'allow' => true, 'roles' => ['workflow-template.view']],
            ['actions' => ['create', 'add-step', 'remove-step'], 'allow' => true, 'roles' => ['workflow-template.create']],
            [
                'actions' => ['update', 'add-step', 'remove-step', 'move-step',
                              'generate-trigger-token', 'revoke-trigger-token'],
                'allow' => true,
                'roles' => ['workflow-template.update'],
            ],
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
            'move-step' => ['POST'],
            'generate-trigger-token' => ['POST'],
            'revoke-trigger-token' => ['POST'],
        ];
    }

    public function actionIndex(): string
    {
        $userId = $this->currentUserId();
        $query = WorkflowTemplate::find()->with('creator')->orderBy(['workflow_template.id' => SORT_DESC]);
        $filter = $this->workflowChecker()->buildWorkflowTemplateFilter($userId, 'workflow_template.id');
        if ($filter !== null) {
            $query->andWhere($filter);
        }
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 20],
        ]);
        return $this->render('index', [
            'dataProvider' => $dataProvider,
            'operableIds' => $this->operableIds($dataProvider, $userId),
        ]);
    }

    public function actionView(int $id): string
    {
        $model = $this->findModel($id);
        $this->requireWorkflowView((int)$model->id);
        $userId = (int)$this->currentUserId();
        $canOperate = $this->workflowChecker()->canOperateWorkflowTemplate($userId, (int)$model->id);

        return $this->render('view', [
            'model' => $model,
            'canOperate' => $canOperate,
            // Only job templates the user may operate: a step with any other
            // one would be refused.
            'jobTemplateOptions' => $canOperate ? $this->workflowChecker()->jobTemplateOptions($userId) : [],
            'approvalRuleOptions' => $canOperate ? $this->approvalRuleOptions() : [],
            'triggerUser' => $this->triggerUser($model),
        ]);
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
        $this->requireWorkflowOperate((int)$model->id);
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
        $this->requireWorkflowOperate((int)$model->id);
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

    /**
     * The service checks that the user may launch every job step and audits
     * a refusal, which answers 403.
     */
    public function actionLaunch(): Response
    {
        $id = (int)(\Yii::$app->request->get('id') ?? \Yii::$app->request->post('id', 0));
        $model = $this->findModel($id);

        /** @var \app\services\WorkflowExecutionService $service */
        $service = \Yii::$app->get('workflowExecutionService');
        try {
            $wfJob = $service->launch($model, (int)\Yii::$app->user->id, [], 'web');
        } catch (\RuntimeException $e) {
            // A refusal by team scoping is a RuntimeException too.
            if ($e instanceof WorkflowAccessDeniedException) {
                throw new ForbiddenHttpException($e->getMessage(), 0, $e);
            }
            $this->session()->setFlash('danger', 'Launch failed: ' . $e->getMessage());
            return $this->redirect(['/workflow-template/view', 'id' => $id]);
        }
        $this->session()->setFlash('success', "Workflow \"{$model->name}\" launched.");
        return $this->redirect(['/workflow-job/view', 'id' => $wfJob->id]);
    }

    public function actionAddStep(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireWorkflowOperate((int)$model->id);
        $step = new WorkflowStep();

        if ($step->load((array)\Yii::$app->request->post())) {
            // Not mass-assignable; set again from the URL after load() so no
            // form field can put the step into another workflow.
            $step->workflow_template_id = (int)$model->id;
            // Preserve the END_WORKFLOW sentinel (0): Yii's load() may set
            // empty-string prompt values to null, which is correct ("next step").
            // A submitted "0" means "end workflow" and must stay as integer 0.
            $this->applyBranchFields($step);
            $this->saveNewStep($model, $step);
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

    /**
     * Save a step from the add-step form; the outcome is reported as a flash.
     *
     * @throws ForbiddenHttpException when the step uses a job template the user may see but not operate
     */
    private function saveNewStep(WorkflowTemplate $model, WorkflowStep $step): void
    {
        $this->keepTargetOfType($step);
        $error = $this->stepTemplateExists($step) ? null : 'The selected job template does not exist.';
        if ($error === null && !$step->save()) {
            $errors = array_values($step->getFirstErrors());
            $error = $errors[0] ?? 'Validation failed.';
        }
        if ($error !== null) {
            $this->session()->setFlash('danger', 'Step not added: ' . $error);
            return;
        }
        // Renumber the whole template to a sparse 10/20/30 layout so a
        // user-typed step_order=15 keeps room for future inserts and
        // future ▲/▼ moves stay deterministic.
        /** @var \app\services\WorkflowStepReorderService $reorder */
        $reorder = \Yii::$app->get('workflowStepReorderService');
        $reorder->resequence($model);
        $this->auditStep(AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_ADDED, $model, $step);
        $this->session()->setFlash('success', "Step \"{$step->name}\" added.");
    }

    /**
     * A step keeps only the target of its type. The form posts the job and
     * the approval dropdown for every type, and a job template left on a
     * pause step would still decide who may see the workflow.
     */
    private function keepTargetOfType(WorkflowStep $step): void
    {
        if ($step->step_type !== WorkflowStep::TYPE_JOB) {
            $step->job_template_id = null;
        }
        if ($step->step_type !== WorkflowStep::TYPE_APPROVAL) {
            $step->approval_rule_id = null;
        }
    }

    /**
     * False when the posted job template does not exist for the user:
     * unknown, deleted, or in a project the user may not see. The ID is
     * checked as the int the step stores; values the step's integer rule
     * rejects pass here and fail validation.
     *
     * @throws ForbiddenHttpException when the user may see the job template but not operate it
     */
    private function stepTemplateExists(WorkflowStep $step): bool
    {
        $id = $this->normalizeSubmittedId($step, 'job_template_id');
        if ($id === null) {
            return true;
        }
        $found = $this->workflowChecker()->classifyStepTemplateIds((int)$this->currentUserId(), [$id]);
        if ($found['forbidden'] !== []) {
            throw new ForbiddenHttpException('You may not use this job template in a workflow: that needs operator access to its project.');
        }

        return $found['missing'] === [];
    }

    public function actionRemoveStep(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireWorkflowOperate((int)$model->id);
        $stepId = (int)\Yii::$app->request->post('step_id');
        /** @var WorkflowStep|null $step */
        $step = WorkflowStep::findOne(['id' => $stepId, 'workflow_template_id' => $model->id]);
        if ($step !== null) {
            $step->delete();
            $this->auditStep(AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_REMOVED, $model, $step);
            $this->session()->setFlash('success', 'Step removed.');
        }
        return $this->redirect(['view', 'id' => $id]);
    }

    /**
     * Move a step up or down by one slot inside its workflow template.
     * direction=up swaps with the immediate predecessor, direction=down
     * with the successor. Hitting the top/bottom is a soft no-op (no
     * flash, no redirect change) so the caller doesn't need to special-case
     * boundary rows.
     */
    public function actionMoveStep(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireWorkflowOperate((int)$model->id);
        $stepId = (int)\Yii::$app->request->post('step_id');
        $direction = (string)\Yii::$app->request->post('direction', '');

        if (!in_array($direction, ['up', 'down'], true)) {
            $this->session()->setFlash('danger', 'Invalid move direction.');
            return $this->redirect(['view', 'id' => $id]);
        }

        /** @var WorkflowStep|null $step */
        $step = WorkflowStep::findOne(['id' => $stepId, 'workflow_template_id' => $model->id]);
        if ($step === null) {
            throw new NotFoundHttpException("Step #{$stepId} not found in workflow #{$id}.");
        }

        /** @var \app\services\WorkflowStepReorderService $reorder */
        $reorder = \Yii::$app->get('workflowStepReorderService');
        $moved = $direction === 'up' ? $reorder->moveUp($step) : $reorder->moveDown($step);
        if ($moved) {
            $this->auditStep(AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_MOVED, $model, $step, ['direction' => $direction]);
            $this->session()->setFlash('success', "Step \"{$step->name}\" moved {$direction}.");
        }
        return $this->redirect(['view', 'id' => $id]);
    }

    /**
     * The trigger runs as the user who generates the token, so only a user
     * who may operate every job step may generate one.
     */
    public function actionGenerateTriggerToken(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireWorkflowOperate((int)$model->id);
        $rawToken = $model->generateTriggerToken((int)\Yii::$app->user->id);
        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_WORKFLOW_TEMPLATE_TRIGGER_TOKEN_GENERATED,
            'workflow_template',
            $id,
            \Yii::$app->user->id,
            ['name' => $model->name],
        );
        $this->session()->setFlash('success', 'Trigger token generated. Copy it now — it will not be shown again.');
        // Flash the raw token once so the view can display it. The DB stores only the hash.
        $this->session()->setFlash('trigger_token_raw', $rawToken);
        return $this->redirect(['view', 'id' => $id]);
    }

    public function actionRevokeTriggerToken(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireWorkflowOperate((int)$model->id);
        $model->revokeTriggerToken();
        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_WORKFLOW_TEMPLATE_TRIGGER_TOKEN_REVOKED,
            'workflow_template',
            $id,
            \Yii::$app->user->id,
            ['name' => $model->name],
        );
        $this->session()->setFlash('success', 'Trigger token revoked. The /trigger/fire-workflow endpoint is now disabled for this workflow.');
        return $this->redirect(['view', 'id' => $id]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function auditStep(string $action, WorkflowTemplate $model, WorkflowStep $step, array $context = []): void
    {
        \Yii::$app->get('auditService')->log(
            $action,
            'workflow_template',
            (int)$model->id,
            $this->currentUserId(),
            array_merge([
                'step_id' => (int)$step->id,
                'step_name' => (string)$step->name,
                'step_type' => (string)$step->step_type,
                'job_template_id' => $step->job_template_id === null ? null : (int)$step->job_template_id,
            ], $context)
        );
    }

    /**
     * IDs of the listed workflow templates the user may launch and change;
     * null when that is every one of them.
     *
     * @return list<int>|null
     */
    private function operableIds(ActiveDataProvider $dataProvider, ?int $userId): ?array
    {
        $filter = $this->workflowChecker()->buildWorkflowTemplateFilter($userId, 'workflow_template.id', true);
        if ($filter === null) {
            return null;
        }
        return array_map('intval', WorkflowTemplate::find()
            ->select('workflow_template.id')
            ->andWhere(['workflow_template.id' => $dataProvider->getKeys()])
            ->andWhere($filter)
            ->column());
    }

    /**
     * @return array<int, string>
     */
    private function approvalRuleOptions(): array
    {
        /** @var array<int, string> $options */
        $options = ArrayHelper::map(ApprovalRule::find()->orderBy('name')->all(), 'id', 'name');
        return $options;
    }

    /**
     * The user the inbound trigger runs as: whoever generated the token, or
     * the creator for older tokens. Null when no token is configured.
     */
    private function triggerUser(WorkflowTemplate $model): ?User
    {
        if (!$model->hasTriggerToken()) {
            return null;
        }
        /** @var User|null $user */
        $user = User::findOne($model->getTriggerUserId());
        return $user;
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
