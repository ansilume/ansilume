<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\components\WorkflowRunOperationFailure;
use app\controllers\api\v1\traits\ApiTeamScopingTrait;
use app\models\WorkflowJob;
use app\models\WorkflowJobSearch;
use app\services\runstate\ApiFields;
use app\services\runstate\Viewer;
use app\services\runstate\WorkflowRunStates;
use app\services\WorkflowAccessChecker;
use app\services\WorkflowAccessDeniedException;
use app\services\WorkflowExecutionService;
use yii\data\ActiveDataProvider;
use yii\db\Exception as DbException;
use yii\web\NotFoundHttpException;

/**
 * API v1: Workflow Jobs — list, view, cancel, resume.
 *
 * Team scoping follows the run's workflow template, by its
 * workflow_template_id (deleted templates included): seeing a run needs view
 * access to the project of every job step, canceling or resuming it operator
 * access to every one.
 *
 * A run that waits stays stored as running: every answer adds what it waits
 * for (waiting_for: approval, resume or runner; null when it does not wait)
 * and its current step execution (current_step; null between two steps and
 * once the run finished), as WorkflowRunStates derives them (ApiFields). The
 * run detail adds the same per step execution, with its job's status and
 * the approval request it waits or waited for, null where the caller may
 * not see that request. The list takes ?waiting_for=, ?workflow_template_id=
 * and ?status= (WorkflowJobSearch); a value it does not know answers 422
 * with the filter's error, so a typo never passes for "no filter".
 */
class WorkflowJobsController extends BaseApiController
{
    use ApiTeamScopingTrait;

    protected function apiAccessRules(): array
    {
        return [
            'index' => 'workflow.view',
            'view' => 'workflow.view',
            'cancel' => 'workflow.cancel',
            'resume' => 'workflow.launch',
        ];
    }

    /**
     * Only the runs of workflow templates the caller may view, newest first,
     * filtered by ?waiting_for=, ?workflow_template_id= and ?status=. An
     * invalid filter answers 422 with its error (WorkflowJobSearch::errorOf()),
     * before anything is read. The cost does not grow with the number of
     * runs on the page (WorkflowRunStates::forRuns()).
     *
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}|array{error: array{message: string}}
     */
    public function actionIndex(): array
    {
        $search = new WorkflowJobSearch();
        $search->load((array)\Yii::$app->request->get(), '');
        if (!$search->validate()) {
            return $this->error((string)current($search->getFirstErrors()), 422);
        }
        // Unaliased: the filters name workflow_job.id and workflow_job.status.
        $query = WorkflowJob::find()->orderBy(['workflow_job.id' => SORT_DESC]);
        $filter = $this->workflows()->buildWorkflowTemplateFilter(
            $this->currentUserId(),
            'workflow_job.workflow_template_id'
        );
        if ($filter !== null) {
            $query->andWhere($filter);
        }
        $runs = new ActiveDataProvider([
            'query' => $search->apply($query),
            'pagination' => ['pageSize' => 25],
        ]);
        $page = $this->requestedPage();
        /** @var list<WorkflowJob> $models */
        $models = array_values($this->modelsOfPage($runs, $page));

        return $this->paginated(
            $this->serializeAll($models),
            (int)$runs->totalCount,
            $page,
            25
        );
    }

    /**
     * The run with its launch extra vars and every step execution, oldest
     * first (ApiFields::runDetail()); the pending approval request of the
     * current step carries how many approvers may still decide.
     *
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionView(int $id): array
    {
        $run = $this->findModel($id);
        if (!$this->may($run, false)) {
            return $this->error('Forbidden.', 403);
        }
        return $this->success(ApiFields::runDetail($run, $this->runStates()->forRun($run, Viewer::current())));
    }

    /**
     * @return array{data: mixed}|array{error: array<string, mixed>}
     */
    public function actionCancel(int $id): array
    {
        return $this->operate(
            $id,
            WorkflowRunOperationFailure::CANCEL,
            static function (WorkflowExecutionService $service, WorkflowJob $run, int $userId): void {
                $service->cancel($run, $userId);
            }
        );
    }

    /**
     * @return array{data: mixed}|array{error: array<string, mixed>}
     */
    public function actionResume(int $id): array
    {
        return $this->operate(
            $id,
            WorkflowRunOperationFailure::RESUME,
            static function (WorkflowExecutionService $service, WorkflowJob $run, int $userId): void {
                $service->resume($run, $userId);
            }
        );
    }

    /**
     * Cancel or resume a run: 403 without operator access to the project of
     * every job step, before the run's state is looked at; 422 when the run
     * is in no state for it. A refusal by the service (access lost since the
     * check) names the job templates only to a caller who may see the run.
     * A database error answers 500 with WorkflowRunOperationFailure's
     * message for $name (CANCEL or RESUME), logged without values. Done, the
     * answer is the run as it stands now: canceled, or at the step a resume
     * led to.
     *
     * @param callable(WorkflowExecutionService, WorkflowJob, int): void $operation
     * @return array{data: mixed}|array{error: array<string, mixed>}
     */
    private function operate(int $id, string $name, callable $operation): array
    {
        $run = $this->findModel($id);
        if (!$this->may($run, true)) {
            return $this->error('Forbidden.', 403);
        }

        /** @var WorkflowExecutionService $service */
        $service = \Yii::$app->get('workflowExecutionService');
        try {
            $operation($service, $run, (int)\Yii::$app->user->id);
        } catch (WorkflowAccessDeniedException $e) {
            return $this->errorWithDetails(
                $e->getMessage(),
                403,
                $e->jobTemplateIds === [] ? [] : ['job_template_ids' => $e->jobTemplateIds]
            );
        } catch (DbException | \PDOException $e) {
            return $this->error(WorkflowRunOperationFailure::report($name, $id, $e), 500);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $run->refresh();
        return $this->success($this->serializeAll([$run])[0]);
    }

    /**
     * Whether the caller may view the run or, with $operate, cancel and
     * resume it.
     */
    private function may(WorkflowJob $run, bool $operate): bool
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return false;
        }
        $workflowTemplateId = (int)$run->workflow_template_id;

        return $operate
            ? $this->workflows()->canOperateWorkflowTemplate($userId, $workflowTemplateId)
            : $this->workflows()->canViewWorkflowTemplate($userId, $workflowTemplateId);
    }

    /**
     * $runs as the list, cancel and resume answer them (ApiFields::run()):
     * their states are read for all of them at once, so the cost does not
     * grow with their number.
     *
     * @param list<WorkflowJob> $runs
     * @return list<array<string, mixed>>
     */
    private function serializeAll(array $runs): array
    {
        $states = $this->runStates()->forRuns($runs, Viewer::current());

        return array_map(static fn (WorkflowJob $run): array => ApiFields::run($run, $states[(int)$run->id]), $runs);
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

    private function workflows(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $checker */
        $checker = \Yii::$app->get('workflowAccessChecker');
        return $checker;
    }

    private function runStates(): WorkflowRunStates
    {
        /** @var WorkflowRunStates $states */
        $states = \Yii::$app->get('workflowRunStates');
        return $states;
    }
}
