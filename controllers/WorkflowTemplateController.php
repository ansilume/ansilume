<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\ErrorText;
use app\components\WorkflowStepChange;
use app\controllers\traits\TeamScopingTrait;
use app\models\AuditLog;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\runstate\Display;
use app\services\runstate\Viewer;
use app\services\runstate\WorkflowRunStates;
use app\services\TriggerTokenService;
use app\services\WorkflowAccessDeniedException;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepChangeRefusedException;
use app\services\WorkflowStepChangeSummary;
use app\services\WorkflowStepWriteService;
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
 * launching it, managing its trigger token and changing its steps need
 * operator access to every one. A job step may only get a job template the
 * user may operate.
 *
 * Steps: add-step, edit-step, move-step and remove-step need
 * workflow-template.update (the access rules) and operator access to every
 * job step's project (requireWorkflowOperate()), checked before anything is
 * written. Then WorkflowStepWriteService changes the step, with the workflow
 * locked: gated steps are for admins (approval-rule.update, a 403 otherwise;
 * ApprovalRuleAssignmentPolicy), approval steps and job steps whose job
 * template or its inventory has an approval rule; a change while a run of the
 * workflow is active, or the removal of a step that other steps route to, is
 * refused. The controller turns what the service did into a success flash and
 * warning flashes (WorkflowStepChangeSummary), a refusal into a 403, a 404 or
 * a danger flash; the edit page shows its refusal in the form instead. A user
 * who lost operator access meanwhile, which the service finds with the
 * workflow locked, gets the text of requireWorkflowOperate()
 * (MODIFY_FORBIDDEN), not the API's
 * (WorkflowStepChangeGuard::TEAM_SCOPE_REFUSED). The service audits every
 * change. Deleting the workflow cancels its active runs
 * (WorkflowTemplateDeletionService).
 *
 * What the pages show a user and the requests their step forms post are
 * WorkflowTemplatePage's.
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

    /** The prefix of every refusal flash of add-step (409, 422). */
    public const FLASH_STEP_NOT_ADDED = 'Step not added: ';

    /** The prefix of every refusal flash of move-step (409, 422). */
    public const FLASH_STEP_NOT_MOVED = 'Step not moved: ';

    /** The prefix of every refusal of edit-step (409, 422), which the edit page shows above its form. */
    public const FLASH_STEP_NOT_SAVED = 'Step not saved: ';

    /**
     * A danger flash. sprintf(): why the launch failed, as ErrorText words
     * it: a refusal's reason, a database error's class, SQLSTATE and driver
     * error code.
     */
    public const FLASH_LAUNCH_FAILED = 'Launch failed: %s';

    /**
     * Every step change needs workflow-template.update, as the API's does:
     * workflow-template.create alone creates a workflow, but changes no steps.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'], 'allow' => true, 'roles' => ['workflow-template.view']],
            ['actions' => ['create'], 'allow' => true, 'roles' => ['workflow-template.create']],
            [
                'actions' => ['update', 'add-step', 'edit-step', 'move-step', 'remove-step',
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
            'edit-step' => ['GET', 'POST'],
            'move-step' => ['POST'],
            'remove-step' => ['POST'],
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
            'stepCounts' => $this->stepCounts($dataProvider),
        ]);
    }

    /**
     * The template page. $after: the step after which the Add Step form
     * places a new step ("+ Insert below"); ignored when the workflow has no
     * such step.
     */
    public function actionView(int $id, ?int $after = null): string
    {
        $model = $this->findModel($id);
        $this->requireWorkflowView((int)$model->id);

        return $this->render('view', (new WorkflowTemplatePage((int)$this->currentUserId()))->viewParams($model, $after));
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
     * logs it without its message. The success flash names the step the
     * new run waits at, when it waits for an approval or is paused
     * (Display::launchFlash()).
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
        /** @var WorkflowRunStates $states */
        $states = \Yii::$app->get('workflowRunStates');
        $state = $states->forRuns([$wfJob], Viewer::current())[(int)$wfJob->id];
        $this->session()->setFlash('success', Display::launchFlash((string)$model->name, $state));
        return $this->redirect(['/workflow-job/view', 'id' => $wfJob->id]);
    }

    /**
     * Add the step the Add Step form posts (WorkflowStep[...] and position,
     * the end by default; a posted step_order is ignored), then show it on
     * the page (#step-<id>). A refusal (409, 422) is a danger flash, shown
     * at the form (#add-step).
     */
    public function actionAddStep(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireWorkflowOperate((int)$model->id);
        $request = \Yii::$app->request;
        $added = $this->changed(fn (WorkflowStepWriteService $writer): WorkflowStepChange => $writer->add(
            $model,
            WorkflowTemplatePage::formCommand($request->post('WorkflowStep'), $request->post('position'), false),
            (int)$this->currentUserId(),
            WorkflowStepWriteService::SOURCE_WEB
        ));
        if (!$added instanceof WorkflowStepChange) {
            $this->session()->setFlash('danger', self::FLASH_STEP_NOT_ADDED . $added);
            return $this->redirect(['view', 'id' => $id, '#' => 'add-step']);
        }
        return $this->redirect(['view', 'id' => $id, '#' => 'step-' . $added->step->id]);
    }

    /**
     * The page that edits the step $step of the workflow $id (GET), and its
     * save (POST: WorkflowStep[...] without the type, which cannot change,
     * and position, kept by default). The step keeps its ID, so the branches
     * that lead to it and its run history stay. 404 when the workflow has no
     * such step, 403 when the user may not change its steps; a saved step is
     * shown on the template page (#step-<id>), a refused one (409, 422) here
     * again, with what the user typed.
     */
    public function actionEditStep(int $id, int $step): Response|string
    {
        $model = $this->findModel($id);
        $this->requireWorkflowOperate((int)$model->id);
        /** @var WorkflowStep|null $stored */
        $stored = WorkflowStep::findOne(['id' => $step, 'workflow_template_id' => (int)$model->id]);
        if ($stored === null) {
            throw new NotFoundHttpException(sprintf(WorkflowStepWriteService::STEP_NOT_FOUND, (int)$model->id, $step));
        }
        $userId = (int)$this->currentUserId();
        /** @var \app\services\ApprovalRuleAssignmentPolicy $policy */
        $policy = \Yii::$app->get('approvalRuleAssignmentPolicy');
        $lock = $policy->workflowStepsChangeRefusal($userId, $model, false);
        if ($lock !== null) {
            throw new ForbiddenHttpException($lock);
        }
        $request = \Yii::$app->request;
        $refusal = null;
        if ($request->isPost) {
            $saved = $this->changed(fn (WorkflowStepWriteService $writer): WorkflowStepChange => $writer->update(
                $model,
                $step,
                WorkflowTemplatePage::formCommand($request->post('WorkflowStep'), $request->post('position'), true),
                $userId,
                WorkflowStepWriteService::SOURCE_WEB
            ));
            if ($saved instanceof WorkflowStepChange) {
                return $this->redirect(['view', 'id' => $id, '#' => 'step-' . $step]);
            }
            $refusal = self::FLASH_STEP_NOT_SAVED . $saved;
        }
        try {
            $params = (new WorkflowTemplatePage($userId))->editParams(
                $model,
                $stored,
                $refusal === null ? null : WorkflowTemplatePage::postedStep($stored, $request->post('WorkflowStep')),
                $refusal,
                $refusal === null ? null : $request->post('position')
            );
        } catch (WorkflowStepChangeRefusedException $e) {
            // Another request removed the step after it was looked up.
            throw new NotFoundHttpException($e->getMessage(), 0, $e);
        }
        return $this->render('edit-step', $params);
    }

    /**
     * Move the step step_id: one place with ▲ and ▼ (direction=up|down), or
     * anywhere with Move to… (position=start|end|after:<step ID>), exactly
     * one of them. A move to where the step stands writes nothing, and says
     * so (an info flash). A refusal (409, 422) is a danger flash. The page
     * shows the step (#step-<id>).
     */
    public function actionMoveStep(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireWorkflowOperate((int)$model->id);
        $request = \Yii::$app->request;
        $stepId = (int)$request->post('step_id');
        $moved = $this->changed(fn (WorkflowStepWriteService $writer): WorkflowStepChange => $writer->move(
            $model,
            $stepId,
            WorkflowTemplatePage::movePosition($request->post('direction'), $request->post('position')),
            (int)$this->currentUserId(),
            WorkflowStepWriteService::SOURCE_WEB
        ));
        if (!$moved instanceof WorkflowStepChange) {
            $this->session()->setFlash('danger', self::FLASH_STEP_NOT_MOVED . $moved);
        }
        return $this->redirect(['view', 'id' => $id, '#' => 'step-' . $stepId]);
    }

    /**
     * Remove the step step_id. A refusal (409: a run is active, or other
     * steps route to it) is a danger flash; runs keep the history of a
     * removed step; an unknown step is ignored. The page shows the steps
     * (#workflow-steps).
     */
    public function actionRemoveStep(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireWorkflowOperate((int)$model->id);
        $stepId = (int)\Yii::$app->request->post('step_id');
        $removed = $this->changed(fn (WorkflowStepWriteService $writer): ?WorkflowStepChange => $writer->remove(
            $model,
            $stepId,
            (int)$this->currentUserId(),
            WorkflowStepWriteService::SOURCE_WEB
        ));
        if (is_string($removed)) {
            $this->session()->setFlash('danger', $removed);
        }
        return $this->redirect(['view', 'id' => $id, '#' => 'workflow-steps']);
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
     * Run $change, one call of the step write service, and flash what it
     * did: its summary as a success flash (an info flash when it changed
     * nothing), then one warning flash per warning
     * (WorkflowStepChangeSummary). A refusal wrote nothing: a 403 or 404
     * answers as such, the team scope's 403 under the lock
     * (WorkflowStepChangeGuard::TEAM_SCOPE_REFUSED) with MODIFY_FORBIDDEN;
     * the reason of a 409 or 422 is returned, for the action to show. Null
     * when there was nothing to change (a remove of a step the workflow
     * does not have).
     *
     * @param callable(WorkflowStepWriteService): ?WorkflowStepChange $change
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     */
    private function changed(callable $change): WorkflowStepChange|string|null
    {
        /** @var WorkflowStepWriteService $writer */
        $writer = \Yii::$app->get('workflowStepWriteService');
        try {
            $done = $change($writer);
        } catch (WorkflowStepChangeRefusedException $e) {
            if ($e->status === WorkflowStepChangeRefusedException::FORBIDDEN) {
                // The team scope, checked again under the lock: in the words of requireWorkflowOperate().
                $teamScope = $e->getMessage() === WorkflowStepChangeGuard::TEAM_SCOPE_REFUSED;
                throw new ForbiddenHttpException($teamScope ? self::MODIFY_FORBIDDEN : $e->getMessage(), 0, $e);
            }
            if ($e->status === WorkflowStepChangeRefusedException::NOT_FOUND) {
                throw new NotFoundHttpException($e->getMessage(), 0, $e);
            }
            return $e->getMessage();
        }
        if ($done !== null) {
            $summary = WorkflowStepChangeSummary::of($done);
            $this->session()->setFlash($summary->isInfo() ? 'info' : 'success', $summary->message());
            foreach ($summary->warnings() as $warning) {
                $this->session()->addFlash('warning', $warning);
            }
        }
        return $done;
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
     * The number of steps of each listed workflow template, by its ID, in
     * one query, however many are listed; a template without steps is
     * missing.
     *
     * @return array<int, int>
     */
    private function stepCounts(ActiveDataProvider $dataProvider): array
    {
        $rows = WorkflowStep::find()
            ->select(['workflow_template_id', 'steps' => 'COUNT(*)'])
            ->where(['workflow_template_id' => $dataProvider->getKeys()])
            ->groupBy('workflow_template_id')
            ->asArray()
            ->all();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int)$row['workflow_template_id']] = (int)$row['steps'];
        }
        return $counts;
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
