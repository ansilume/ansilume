<?php

declare(strict_types=1);

namespace app\services\runstate;

use app\models\ApprovalRequest;
use app\models\Job;
use app\models\JobTemplate;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowAccessChecker;
use yii\base\Component;
use yii\db\Query;

/**
 * What approval requests are for and where jobs come from (component
 * approvalRequestContexts): the workflow run and step a job was created for,
 * else its job template.
 *
 * The workflow, its run and the step are set only when the viewer may view
 * that workflow (team scoping, one batched query through
 * WorkflowAccessChecker::buildWorkflowTemplateFilter()). A user may see the
 * request of a gated job step through its job template's project while the
 * workflow stays hidden from them (WorkflowAccessChecker::
 * buildApprovalRequestFilter()); such a user learns only the job template.
 * The RBAC permissions behind links (approval.view, workflow.view,
 * workflow-template.view) are for the pages to check.
 *
 * Callers pass requests and jobs the viewer may see. The cost does not grow
 * with their number: forRequests() reads the jobs' templates (one query),
 * the step executions with their runs, workflows and steps (four queries,
 * one when no job belongs to a workflow) and, for a viewer restricted by
 * team scoping, which of those workflows they may view (one query, after
 * the access checker's own lookups).
 */
class ApprovalRequestContexts extends Component
{
    /**
     * What each request is for, by request ID.
     *
     * @param list<ApprovalRequest> $requests
     * @return array<int, RequestContext>
     */
    public function forRequests(array $requests, Viewer $viewer): array
    {
        if ($requests === []) {
            return [];
        }
        $jobIds = [];
        foreach ($requests as $request) {
            $jobIds[(int)$request->job_id] = (int)$request->job_id;
        }
        $jobIds = array_values($jobIds);
        $templates = $this->templatesOfJobs($jobIds);
        $executions = $this->executionsOfJobs($jobIds);
        $origins = $this->visibleOrigins($executions, $viewer);

        $contexts = [];
        foreach ($requests as $request) {
            $jobId = (int)$request->job_id;
            $contexts[(int)$request->id] = self::requestContext(
                (int)$request->id,
                $templates[$jobId] ?? null,
                $executions[$jobId] ?? null,
                $origins[$jobId] ?? null
            );
        }

        return $contexts;
    }

    /**
     * Where $job comes from and the approval it waits or waited for: its
     * workflow run and step (for a viewer who may view the workflow), its
     * approval request (one the viewer may see, ApprovalWaits::forJobs()),
     * and whether it is the placeholder of an approval step.
     */
    public function forJob(Job $job, Viewer $viewer): JobContext
    {
        $jobId = (int)$job->id;
        $executions = $this->executionsOfJobs([$jobId]);
        $origins = $this->visibleOrigins($executions, $viewer);
        $waits = $this->waits()->forJobs([$jobId], $viewer);
        $execution = $executions[$jobId] ?? null;

        return new JobContext(
            $origins[$jobId] ?? null,
            $waits[$jobId] ?? null,
            $execution !== null && $execution->isApprovalPlaceholder()
        );
    }

    /**
     * @param array{template_id: int|null, template_name: string|null, template_deleted: bool}|null $template
     *     the template of the request's job; null when the job no longer exists
     */
    private static function requestContext(
        int $requestId,
        ?array $template,
        ?WorkflowJobStep $execution,
        ?WorkflowOrigin $origin
    ): RequestContext {
        $templateId = $template['template_id'] ?? null;
        // A job without a template that is no approval step's placeholder lost its template to a purge.
        $purged = $template !== null && $templateId === null
            && ($execution === null || !$execution->isApprovalPlaceholder());

        return new RequestContext(
            $requestId,
            $origin,
            $templateId,
            $template['template_name'] ?? null,
            $purged || ($template['template_deleted'] ?? false),
            $origin !== null
        );
    }

    /**
     * The job template of each job, by job ID, in one query: its ID (null
     * when the job has none: the placeholder of an approval step, or a job
     * whose template was purged), its name, and whether it is deleted
     * (soft-deleted, or its row is gone). A job that no longer exists is
     * left out.
     *
     * @param list<int> $jobIds
     * @return array<int, array{template_id: int|null, template_name: string|null, template_deleted: bool}>
     */
    private function templatesOfJobs(array $jobIds): array
    {
        /**
         * @var list<array{
         *     job_id: int|string,
         *     template_id: int|string|null,
         *     template_name: string|null,
         *     deleted_at: int|string|null
         * }> $rows
         */
        $rows = (new Query())
            ->select([
                'job_id' => 'j.id',
                'template_id' => 'j.job_template_id',
                'template_name' => 'jt.name',
                'deleted_at' => 'jt.deleted_at',
            ])
            ->from(['j' => Job::tableName()])
            ->leftJoin(['jt' => JobTemplate::tableName()], 'jt.id = j.job_template_id')
            ->where(['j.id' => $jobIds])
            ->all();

        $templates = [];
        foreach ($rows as $row) {
            $templateId = $row['template_id'] === null ? null : (int)$row['template_id'];
            $templates[(int)$row['job_id']] = [
                'template_id' => $templateId,
                'template_name' => $row['template_name'],
                'template_deleted' => $templateId !== null
                    && ($row['template_name'] === null || $row['deleted_at'] !== null),
            ];
        }

        return $templates;
    }

    /**
     * The step execution each job was created for, by job ID, with its run,
     * the run's workflow (also a deleted one) and its live step, which
     * stepName() falls back to. A job outside workflows is left out.
     *
     * @param list<int> $jobIds
     * @return array<int, WorkflowJobStep>
     */
    private function executionsOfJobs(array $jobIds): array
    {
        /** @var list<WorkflowJobStep> $executions */
        $executions = WorkflowJobStep::find()
            ->where(['job_id' => $jobIds])
            ->with(['workflowJob.workflowTemplate', 'workflowStep'])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $byJob = [];
        foreach ($executions as $execution) {
            // Every execution creates a job of its own; were there more, the newest counts.
            $byJob[(int)$execution->job_id] = $execution;
        }

        return $byJob;
    }

    /**
     * The origin of each execution's job, by job ID, only for workflows the
     * viewer may view.
     *
     * @param array<int, WorkflowJobStep> $executions by job ID
     * @return array<int, WorkflowOrigin>
     */
    private function visibleOrigins(array $executions, Viewer $viewer): array
    {
        $origins = [];
        foreach ($executions as $jobId => $execution) {
            $origin = self::originOf($execution);
            if ($origin !== null) {
                $origins[$jobId] = $origin;
            }
        }
        $visible = $this->visibleWorkflowIds(
            array_map(static fn (WorkflowOrigin $origin): int => $origin->workflowTemplateId, $origins),
            $viewer
        );

        return array_filter(
            $origins,
            static fn (WorkflowOrigin $origin): bool => isset($visible[$origin->workflowTemplateId])
        );
    }

    /**
     * The run and step of $execution; null when its run or the run's
     * workflow no longer exists (the foreign keys delete the execution with
     * them, so only data written without them lacks one). Whether the
     * workflow was deleted comes with it: the run's workflow is loaded with
     * the executions, deleted ones included, so no query per request.
     */
    private static function originOf(WorkflowJobStep $execution): ?WorkflowOrigin
    {
        /** @var WorkflowJob|null $run */
        $run = $execution->workflowJob;
        /** @var WorkflowTemplate|null $workflow */
        $workflow = $run?->workflowTemplate;
        if ($run === null || $workflow === null) {
            return null;
        }

        return new WorkflowOrigin(
            (int)$run->id,
            (int)$execution->id,
            (int)$workflow->id,
            (string)$workflow->name,
            $execution->stepName(),
            $execution->isApprovalPlaceholder(),
            $workflow->isDeleted()
        );
    }

    /**
     * Which of $workflowIds the viewer may view, as keys: all of them when
     * team scoping does not restrict the viewer, else one query. Nobody (a
     * guest, user ID 0) may view none, also where no project is restricted.
     *
     * @param array<int, int> $workflowIds
     * @return array<int, true>
     */
    private function visibleWorkflowIds(array $workflowIds, Viewer $viewer): array
    {
        $ids = array_values(array_unique($workflowIds));
        if ($ids === []) {
            return [];
        }
        $userId = $viewer->userId > 0 ? $viewer->userId : null;
        $filter = $this->access()->buildWorkflowTemplateFilter($userId, 'wt.id');
        if ($filter !== null) {
            $ids = array_map('intval', (new Query())
                ->select('wt.id')
                ->from(['wt' => WorkflowTemplate::tableName()])
                ->where(['wt.id' => $ids])
                ->andWhere($filter)
                ->column());
        }

        return array_fill_keys($ids, true);
    }

    private function access(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $checker */
        $checker = \Yii::$app->get('workflowAccessChecker');

        return $checker;
    }

    private function waits(): ApprovalWaits
    {
        /** @var ApprovalWaits $waits */
        $waits = \Yii::$app->get('approvalWaits');

        return $waits;
    }
}
