<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\controllers\api\v1\traits\ApiTeamScopingTrait;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\services\WorkflowAccessChecker;
use app\services\WorkflowAccessDeniedException;
use app\services\WorkflowExecutionService;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

/**
 * API v1: Workflow Jobs — list, view, cancel, resume.
 *
 * Team scoping follows the run's workflow template, by its
 * workflow_template_id (deleted templates included): seeing a run needs view
 * access to the project of every job step, canceling or resuming it operator
 * access to every one.
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
     * Only the runs of workflow templates the caller may view.
     *
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function actionIndex(): array
    {
        $query = WorkflowJob::find()->orderBy(['workflow_job.id' => SORT_DESC]);
        $filter = $this->workflows()->buildWorkflowTemplateFilter(
            $this->currentUserId(),
            'workflow_job.workflow_template_id'
        );
        if ($filter !== null) {
            $query->andWhere($filter);
        }
        $runs = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 25],
        ]);

        return $this->paginated(
            array_map(fn ($run) => $this->serialize($run), $runs->getModels()),
            (int)$runs->totalCount,
            $this->requestedPage(),
            25
        );
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionView(int $id): array
    {
        $run = $this->findModel($id);
        if (!$this->may($run, false)) {
            return $this->error('Forbidden.', 403);
        }
        return $this->success($this->serializeDetailed($run));
    }

    /**
     * @return array{data: mixed}|array{error: array<string, mixed>}
     */
    public function actionCancel(int $id): array
    {
        return $this->operate($id, static function (WorkflowExecutionService $service, WorkflowJob $run, int $userId): void {
            $service->cancel($run, $userId);
        });
    }

    /**
     * @return array{data: mixed}|array{error: array<string, mixed>}
     */
    public function actionResume(int $id): array
    {
        return $this->operate($id, static function (WorkflowExecutionService $service, WorkflowJob $run, int $userId): void {
            $service->resume($run, $userId);
        });
    }

    /**
     * Cancel or resume a run: 403 without operator access to the project of
     * every job step, before the run's state is looked at; 422 when the run
     * is in no state for it. A refusal by the service (access lost since the
     * check) names the job templates only to a caller who may see the run.
     *
     * @param callable(WorkflowExecutionService, WorkflowJob, int): void $operation
     * @return array{data: mixed}|array{error: array<string, mixed>}
     */
    private function operate(int $id, callable $operation): array
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
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $run->refresh();
        return $this->success($this->serialize($run));
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
            // Why the step failed without a job, e.g. no access to its job template.
            'error' => $s->error_message,
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

    private function workflows(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $checker */
        $checker = \Yii::$app->get('workflowAccessChecker');
        return $checker;
    }
}
