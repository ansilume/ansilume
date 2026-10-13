<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\ErrorText;
use app\controllers\traits\TeamScopingTrait;
use app\models\AuditLog;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\TriggerTokenService;
use app\services\WorkflowAccessDeniedException;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepChangeRefusedException;
use app\services\WorkflowTemplateDeletionService;
use app\services\WorkflowTemplatePage;
use yii\data\ActiveDataProvider;
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
 *
 * Steps change through WorkflowStepChangeGuard (add, remove, move): with the
 * workflow locked, gated steps are for admins (approval-rule.update, a 403
 * otherwise; ApprovalRuleAssignmentPolicy): approval steps, and job steps
 * whose job template has an approval rule. A change while a run of the
 * workflow is active, or the removal of a step that other steps route to, is
 * refused with a danger flash. Deleting the workflow cancels its active runs
 * (WorkflowTemplateDeletionService).
 *
 * What the template page shows a user, its texts, whether its add-step form
 * may add a step and the step that form posts are WorkflowTemplatePage's.
 */
class WorkflowTemplateController extends BaseController
{
    use TeamScopingTrait;

    /** sprintf(): the workflow template's name. */
    public const FLASH_DELETED = 'Workflow template "%s" deleted.';

    /** sprintf(): the workflow template's name, the number of its runs the delete canceled. */
    public const FLASH_DELETED_RUNS_CANCELED = 'Workflow template "%s" deleted. %d running run(s) were canceled.';

    /**
     * A warning flash. sprintf(): the workflow template's name, the number of
     * its runs the delete canceled, the number whose cancel failed.
     */
    public const FLASH_DELETED_RUNS_FAILED = 'Workflow template "%s" deleted. %d running run(s) were canceled; '
        . '%d could not be canceled, the maintenance run retries them (see the application log).';

    /** The prefix of every refusal flash of add-step. */
    public const FLASH_STEP_NOT_ADDED = 'Step not added: ';

    /**
     * A danger flash. sprintf(): why the launch failed, as ErrorText words
     * it: a refusal's reason, a database error's class, SQLSTATE and driver
     * error code.
     */
    public const FLASH_LAUNCH_FAILED = 'Launch failed: %s';

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

        return $this->render('view', (new WorkflowTemplatePage((int)$this->currentUserId()))->viewParams($model));
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

    /**
     * Soft-deletes the template and cancels its active runs; the flash says
     * how many, and, as a warning, how many could not be canceled.
     */
    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireWorkflowOperate((int)$model->id);
        /** @var WorkflowTemplateDeletionService $deletion */
        $deletion = \Yii::$app->get('workflowTemplateDeletionService');
        $result = $deletion->delete($model, $this->currentUserId(), 'web');
        if (!$result['deleted']) {
            // Another request deleted it after it was loaded.
            throw new NotFoundHttpException("Workflow template #{$id} not found.");
        }
        if ($result['failed_runs'] > 0) {
            $this->session()->setFlash('warning', sprintf(
                self::FLASH_DELETED_RUNS_FAILED,
                $model->name,
                $result['canceled_runs'],
                $result['failed_runs']
            ));
        } else {
            $this->session()->setFlash('success', $result['canceled_runs'] > 0
                ? sprintf(self::FLASH_DELETED_RUNS_CANCELED, $model->name, $result['canceled_runs'])
                : sprintf(self::FLASH_DELETED, $model->name));
        }
        return $this->redirect(['index']);
    }

    /**
     * The service checks that the user may launch every job step and audits
     * a refusal, which answers 403. Any other refusal, and a PDOException
     * that escaped Yii (a failed BEGIN or COMMIT), is a danger flash
     * (FLASH_LAUNCH_FAILED); a database error shows there only as its class,
     * SQLSTATE and driver error code (ErrorText). A yii\db\Exception is no
     * RuntimeException: it reaches the error page (500), and WebErrorHandler
     * logs it without its message.
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
            $this->session()->setFlash('danger', sprintf(self::FLASH_LAUNCH_FAILED, ErrorText::of($e)));
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
            // The routes ("0" ends the workflow) and the target of the step's
            // type, as the form means them.
            WorkflowTemplatePage::completeFromForm($step, (array)\Yii::$app->request->post('WorkflowStep', []));
            $this->addStep($model, $step);
        }
        return $this->redirect(['view', 'id' => $id]);
    }

    /**
     * Add $step to the workflow, answering in the order the API does: 403
     * when the user may not (only admins add approval steps and gated job
     * steps, or any step to a workflow that has one); a danger flash for an
     * invalid step; then, with the workflow locked, the rule again and a
     * danger flash while a run of the workflow is active.
     *
     * @throws ForbiddenHttpException
     */
    private function addStep(WorkflowTemplate $model, WorkflowStep $step): void
    {
        $addsApprovalStep = $step->step_type === WorkflowStep::TYPE_APPROVAL;
        // The job template as the int the step stores, before the policy
        // asks whether it has an approval rule: "05" is template 5 too.
        $this->normalizeSubmittedId($step, 'job_template_id');
        $refusal = (new WorkflowTemplatePage((int)$this->currentUserId()))->addRefusal($model, $step);
        if ($refusal !== null) {
            throw new ForbiddenHttpException($refusal);
        }
        $error = $this->stepTemplateExists($step) ? null : 'The selected job template does not exist.';
        if ($error === null && !$step->validate()) {
            $error = array_values($step->getFirstErrors())[0] ?? 'Validation failed.';
        }
        if ($error !== null) {
            $this->session()->setFlash('danger', self::FLASH_STEP_NOT_ADDED . $error);
            return;
        }
        $added = $this->guarded(self::FLASH_STEP_NOT_ADDED, fn (): bool => $this->stepGuard()->change(
            $model,
            (int)$this->currentUserId(),
            $addsApprovalStep,
            fn (): bool => $this->saveNewStep($model, $step)
        ));
        if ($added === true) {
            $this->auditStep(AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_ADDED, $model, $step);
            $this->session()->setFlash('success', "Step \"{$step->name}\" added.");
        }
    }

    /**
     * Save a step from the add-step form, inside the guard's transaction. It
     * is validated again with the workflow locked: a step it routes to may
     * have been removed meanwhile.
     *
     * @throws WorkflowStepChangeRefusedException (422) for a step that cannot be saved
     */
    private function saveNewStep(WorkflowTemplate $model, WorkflowStep $step): bool
    {
        if (!$step->save()) {
            throw new WorkflowStepChangeRefusedException(
                array_values($step->getFirstErrors())[0] ?? 'Validation failed.',
                WorkflowStepChangeRefusedException::INVALID
            );
        }
        // Renumber the whole template to a sparse 10/20/30 layout so a
        // user-typed step_order=15 keeps room for future inserts and
        // future ▲/▼ moves stay deterministic.
        /** @var \app\services\WorkflowStepReorderService $reorder */
        $reorder = \Yii::$app->get('workflowStepReorderService');
        $reorder->resequence($model);

        return true;
    }

    /**
     * Run a step change through WorkflowStepChangeGuard ($change calls it)
     * and answer its refusal, after which nothing was written: 403 when the
     * user may not change these steps, 404 when the workflow was deleted
     * meanwhile, a danger flash ($flashPrefix and the reason) otherwise.
     * Returns what $change returned, or null after a flash.
     *
     * @template T
     * @param callable(): T $change
     * @return T|null
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     */
    private function guarded(string $flashPrefix, callable $change): mixed
    {
        try {
            return $change();
        } catch (WorkflowStepChangeRefusedException $e) {
            if ($e->status === WorkflowStepChangeRefusedException::FORBIDDEN) {
                throw new ForbiddenHttpException($e->getMessage(), 0, $e);
            }
            if ($e->status === WorkflowStepChangeRefusedException::NOT_FOUND) {
                throw new NotFoundHttpException($e->getMessage(), 0, $e);
            }
            $this->session()->setFlash('danger', $flashPrefix . $e->getMessage());
            return null;
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

    /**
     * Only admins change the steps of a workflow that has an approval step
     * or a gated job step (403). Refused with a danger flash while a run of
     * the workflow is active, or while other steps route to the step. Runs
     * keep the history of a removed step. An unknown step is ignored.
     */
    public function actionRemoveStep(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireWorkflowOperate((int)$model->id);
        $stepId = (int)\Yii::$app->request->post('step_id');
        $removed = $this->guarded('', fn (): ?WorkflowStep => $this->stepGuard()->remove(
            $model,
            (int)$this->currentUserId(),
            $stepId
        ));
        if ($removed instanceof WorkflowStep) {
            $this->auditStep(AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_REMOVED, $model, $removed);
            $this->session()->setFlash('success', 'Step removed.');
        }
        return $this->redirect(['view', 'id' => $id]);
    }

    /**
     * Move a step up or down by one slot inside its workflow template.
     * direction=up swaps with the immediate predecessor, direction=down
     * with the successor. Hitting the top/bottom is a soft no-op (no
     * flash, no redirect change) so the caller doesn't need to special-case
     * boundary rows. Guarded as remove-step is: 403 for a workflow with an
     * approval step or a gated job step unless the user is an admin, a
     * danger flash while a run is active.
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

        $moved = $this->guarded('', fn (): ?WorkflowStep => $this->stepGuard()->change(
            $model,
            (int)$this->currentUserId(),
            false,
            static function () use ($model, $stepId, $direction): ?WorkflowStep {
                /** @var WorkflowStep|null $step */
                $step = WorkflowStep::findOne(['id' => $stepId, 'workflow_template_id' => $model->id]);
                if ($step === null) {
                    throw new NotFoundHttpException("Step #{$stepId} not found in workflow #{$model->id}.");
                }
                /** @var \app\services\WorkflowStepReorderService $reorder */
                $reorder = \Yii::$app->get('workflowStepReorderService');
                $moved = $direction === 'up' ? $reorder->moveUp($step) : $reorder->moveDown($step);

                return $moved ? $step : null;
            }
        ));
        if ($moved instanceof WorkflowStep) {
            $this->auditStep(AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_MOVED, $model, $moved, ['direction' => $direction]);
            $this->session()->setFlash('success', "Step \"{$moved->name}\" moved {$direction}.");
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
        $rawToken = (new TriggerTokenService())->generate($model, (int)\Yii::$app->user->id);
        $this->session()->setFlash('success', 'Trigger token generated. Copy it now — it will not be shown again.');
        // Flash the raw token once so the view can display it. The DB stores only the hash.
        $this->session()->setFlash('trigger_token_raw', $rawToken);
        return $this->redirect(['view', 'id' => $id]);
    }

    public function actionRevokeTriggerToken(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireWorkflowOperate((int)$model->id);
        (new TriggerTokenService())->revoke($model, (int)\Yii::$app->user->id);
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
                'approval_rule_id' => $step->approval_rule_id === null ? null : (int)$step->approval_rule_id,
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

    private function stepGuard(): WorkflowStepChangeGuard
    {
        /** @var WorkflowStepChangeGuard $guard */
        $guard = \Yii::$app->get('workflowStepChangeGuard');
        return $guard;
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
