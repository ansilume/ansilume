<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\WorkflowRunOperationFailure;
use app\controllers\traits\TeamScopingTrait;
use app\models\WorkflowJob;
use app\models\WorkflowJobSearch;
use app\services\runstate\Display;
use app\services\runstate\PollPayload;
use app\services\runstate\Viewer;
use app\services\runstate\WorkflowRunStates;
use app\services\WorkflowAccessDeniedException;
use yii\data\ActiveDataProvider;
use yii\db\Exception as DbException;
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
 *
 * What a run and its steps are doing is derived, never stored
 * (runstate\WorkflowRunStates): a run that waits for an approval, at a pause
 * step or for a runner is stored as running and shows as Awaiting Approval,
 * Paused or Queued. The list filters by it (?waiting_for=, WorkflowJobSearch;
 * an invalid filter is ignored). The run page and its poll
 * (/workflow-job/status) show the same data (runstate\PollPayload), with the
 * banner that says what the run waits for; the page's script
 * (web/js/workflow-run-live.js) redraws it from the poll.
 *
 * A run keeps the history of its steps: an execution shows the name and type
 * its step had when it ran (WorkflowJobStep::stepName(), stepType()), also
 * once the step was removed from the workflow (workflow_step_id is then
 * null). Rows are keyed by the execution's own ID, as a step can run more
 * than once in a run.
 *
 * Cancel and resume answer a refusal of the service (the run finished, no
 * pause step waits) with a danger flash, and a database error with one too
 * (WorkflowRunOperationFailure, logged without values) instead of an error
 * page.
 */
class WorkflowJobController extends BaseController
{
    use TeamScopingTrait;

    /** The success flash of a cancel. */
    public const CANCELED = 'Workflow canceled.';

    /** The success flash of a resume. */
    public const RESUMED = 'Paused step resumed.';

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

    /**
     * The runs the user may see, newest first, with what each waits for at
     * its current step.
     */
    public function actionIndex(): string
    {
        $search = new WorkflowJobSearch();
        $search->load((array)\Yii::$app->request->get(), '');
        // The run table stays unaliased: the filters name workflow_job.
        $query = $search->apply(WorkflowJob::find())
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
        /** @var list<WorkflowJob> $runs */
        $runs = array_values($dataProvider->getModels());

        return $this->render('index', [
            'dataProvider' => $dataProvider,
            'search' => $search,
            'runStates' => $this->runStates()->forRuns($runs, Viewer::current()),
        ]);
    }

    public function actionView(int $id): string
    {
        $model = $this->findModel($id);
        $this->requireWorkflowView((int)$model->workflow_template_id);
        // One viewer for the request: each permission is checked once.
        $viewer = Viewer::current();
        $state = $this->runStates()->forRun($model, $viewer, true);
        // The page renders the Resume form, hidden, also while the run is
        // not paused: its script shows it once the run pauses.
        [$mayResume, $mayCancel] = $this->mayResumeAndCancel($model, $viewer, true);

        return $this->render('view', [
            'model' => $model,
            'mayResume' => $mayResume,
            'mayCancel' => $mayCancel,
            'payload' => (new PollPayload($mayResume, $mayCancel))->of($model, $state),
        ]);
    }

    /**
     * GET /workflow-job/status?id=N
     *
     * The run page's data as JSON, for its polling loop
     * (web/js/workflow-run-live.js): the run's stored status and display
     * state, its banner, which actions it offers now, and every step, so the
     * page updates in place without a reload (runstate\PollPayload). The
     * viewer's own relation to a request the run waits for is the banner's
     * personal line.
     *
     * A poll checks only what its answer needs: one viewer for the request,
     * the eligibility of the approvers once (ApprovalWaits), workflow.launch
     * only while the run is paused (Resume is offered only then), and
     * neither action's permissions once the run has finished.
     *
     * @return array<string, mixed>
     */
    public function actionStatus(int $id): array
    {
        $model = $this->findModel($id);
        $this->requireWorkflowView((int)$model->workflow_template_id);
        \Yii::$app->response->format = Response::FORMAT_JSON;
        $viewer = Viewer::current();
        $state = $this->runStates()->forRun($model, $viewer, true);
        [$mayResume, $mayCancel] = $this->mayResumeAndCancel($model, $viewer, $state->state === Display::STATE_PAUSED);

        return (new PollPayload($mayResume, $mayCancel))->of($model, $state);
    }

    /**
     * Whether the viewer may resume and cancel the run: operator access to
     * every job step's project, and workflow.launch or workflow.cancel.
     * Neither for a finished run, which offers neither (nothing is checked
     * then); Resume only when $withResume, else workflow.launch is not
     * checked.
     *
     * @return array{0: bool, 1: bool}
     */
    private function mayResumeAndCancel(WorkflowJob $model, Viewer $viewer, bool $withResume): array
    {
        if ($model->isFinished()) {
            return [false, false];
        }
        $canOperate = $this->workflowChecker()->canOperateWorkflowTemplate(
            $viewer->userId,
            (int)$model->workflow_template_id
        );

        return [
            $canOperate && $withResume && $viewer->may('workflow.launch'),
            $canOperate && $viewer->may('workflow.cancel'),
        ];
    }

    public function actionCancel(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireWorkflowOperate((int)$model->workflow_template_id);

        /** @var \app\services\WorkflowExecutionService $service */
        $service = \Yii::$app->get('workflowExecutionService');
        try {
            $service->cancel($model, (int)\Yii::$app->user->id);
        } catch (DbException | \PDOException $e) {
            $failure = WorkflowRunOperationFailure::report(WorkflowRunOperationFailure::CANCEL, $id, $e);
            $this->session()->setFlash('danger', $failure);
            return $this->redirect(['view', 'id' => $id]);
        } catch (\RuntimeException $e) {
            $this->rethrowDenied($e);
            // e.g. the run finished meanwhile: a message, not a server error.
            $this->session()->setFlash('danger', $e->getMessage());
            return $this->redirect(['view', 'id' => $id]);
        }

        $this->session()->setFlash('success', self::CANCELED);
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
            $this->session()->setFlash('success', self::RESUMED);
        } catch (DbException | \PDOException $e) {
            $failure = WorkflowRunOperationFailure::report(WorkflowRunOperationFailure::RESUME, $id, $e);
            $this->session()->setFlash('danger', $failure);
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

    private function runStates(): WorkflowRunStates
    {
        /** @var WorkflowRunStates $states */
        $states = \Yii::$app->get('workflowRunStates');

        return $states;
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
