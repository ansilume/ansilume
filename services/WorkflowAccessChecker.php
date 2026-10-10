<?php

declare(strict_types=1);

namespace app\services;

use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use yii\base\Component;
use yii\db\Query;

/**
 * Team scoping for workflows, approval requests and jobs without a template.
 *
 * A workflow template has no project of its own: it belongs to the projects
 * of its job steps. A user may see a workflow and its runs only when they may
 * view every job step's project, and change, launch, resume or cancel it only
 * when they may operate every one. Approval and pause steps do not restrict,
 * so a workflow without job steps is visible to everyone with the RBAC
 * permission; a job template ID that older versions left on such a step is
 * ignored, as dispatch ignores it. A job step whose job template no longer
 * exists fails closed.
 *
 * Admins, superadmins and installations without team-restricted projects are
 * not restricted, mirroring {@see ProjectAccessChecker}.
 */
class WorkflowAccessChecker extends Component
{
    /**
     * Query condition that keeps only the workflow templates the user may
     * view (or operate). $column holds a workflow_template id, for example
     * 'workflow_template.id' or 'workflow_job.workflow_template_id'.
     *
     * Returns null when no restriction applies.
     *
     * @return array<int|string, mixed>|null
     */
    public function buildWorkflowTemplateFilter(?int $userId, string $column, bool $operate = false): ?array
    {
        if ($userId === null) {
            return ProjectAccessChecker::DENY_ALL;
        }
        $usable = $this->projects()->templateIdSubquery($userId, $operate);
        if ($usable === null) {
            return null;
        }

        return ['not in', $column, $this->blockedWorkflowIds($usable)];
    }

    public function canViewWorkflowTemplate(int $userId, int $workflowTemplateId): bool
    {
        return $this->deniedJobTemplateIds($userId, $workflowTemplateId, false) === [];
    }

    public function canOperateWorkflowTemplate(int $userId, int $workflowTemplateId): bool
    {
        return $this->deniedJobTemplateIds($userId, $workflowTemplateId, true) === [];
    }

    /**
     * The job template IDs used by the workflow's job steps that the user may
     * not operate (or view, when $operate is false). IDs of templates that no
     * longer exist are included: they fail closed.
     *
     * @return list<int>
     */
    public function deniedJobTemplateIds(int $userId, int $workflowTemplateId, bool $operate = true): array
    {
        $ids = array_map('intval', WorkflowStep::find()
            ->select('job_template_id')
            ->where(['workflow_template_id' => $workflowTemplateId, 'step_type' => WorkflowStep::TYPE_JOB])
            ->andWhere(['not', ['job_template_id' => null]])
            ->distinct()
            ->column());
        if ($ids === []) {
            return [];
        }
        $usable = $this->projects()->templateIdSubquery($userId, $operate);
        if ($usable === null) {
            return [];
        }
        $allowed = array_map('intval', $usable->andWhere(['jt.id' => $ids])->column());

        return array_values(array_diff($ids, $allowed));
    }

    /**
     * Throws unless $userId may launch the workflow: an active user with
     * workflow.launch and operator access to every job step's project. The
     * refusal is audited with the job templates the user may not operate,
     * because a trigger caller never sees the reason; the message names them
     * only to a user who may see the workflow ({@see refusal()}).
     *
     * @param string $source web, api or trigger
     */
    public function assertMayLaunch(int $userId, WorkflowTemplate $template, string $source): void
    {
        $denied = $this->deniedJobTemplateIds($userId, (int)$template->id);
        if ($denied === [] && $this->isActiveWithPermission($userId, 'workflow.launch')) {
            return;
        }
        /** @var AuditService $audit */
        $audit = \Yii::$app->get('auditService');
        $audit->log(
            AuditLog::ACTION_WORKFLOW_LAUNCH_DENIED,
            'workflow_template',
            (int)$template->id,
            $userId,
            ['source' => $source, 'job_template_ids' => $denied]
        );
        if ($denied === []) {
            throw new WorkflowAccessDeniedException('You may not launch workflows.');
        }
        throw $this->refusal($userId, (int)$template->id, 'launch', $denied);
    }

    /**
     * Throws unless $userId may operate every job step's project, as needed
     * to change, resume or cancel the workflow.
     */
    public function assertMayOperate(int $userId, int $workflowTemplateId): void
    {
        $denied = $this->deniedJobTemplateIds($userId, $workflowTemplateId);
        if ($denied !== []) {
            throw $this->refusal($userId, $workflowTemplateId, 'operate', $denied);
        }
    }

    /**
     * Sorts job template IDs from a request (workflow steps): unknown,
     * soft-deleted and invisible ones are 'missing' and reported as not
     * existing; visible ones the user may not operate are 'forbidden'.
     *
     * @param list<int> $ids
     * @return array{missing: list<int>, forbidden: list<int>}
     */
    public function classifyStepTemplateIds(int $userId, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return ['missing' => [], 'forbidden' => []];
        }
        $query = JobTemplate::find()->select('job_template.id')->andWhere(['job_template.id' => $ids]);
        $viewFilter = $this->projects()->buildChildResourceFilter($userId, 'job_template.project_id');
        if ($viewFilter !== null) {
            $query->andWhere($viewFilter);
        }
        $visible = array_map('intval', $query->column());
        $missing = array_values(array_diff($ids, $visible));
        $usable = $this->projects()->templateIdSubquery($userId, true);
        if ($visible === [] || $usable === null) {
            return ['missing' => $missing, 'forbidden' => []];
        }
        $operable = array_map('intval', $usable->andWhere(['jt.id' => $visible])->column());

        return ['missing' => $missing, 'forbidden' => array_values(array_diff($visible, $operable))];
    }

    /**
     * Choices for a job step: the non-deleted job templates the user may
     * operate, by name.
     *
     * @return array<int, string>
     */
    public function jobTemplateOptions(int $userId): array
    {
        $query = JobTemplate::find()->orderBy(['job_template.name' => SORT_ASC]);
        $filter = $this->projects()->buildChildOperateFilter($userId, 'job_template.project_id');
        if ($filter !== null) {
            $query->andWhere($filter);
        }
        $options = [];
        /** @var JobTemplate $template */
        foreach ($query->all() as $template) {
            $options[(int)$template->id] = (string)$template->name;
        }

        return $options;
    }

    /**
     * Whether a workflow may launch this job template as $userId. Steps run
     * without a session, so the identity must still be an active user with
     * workflow.launch and operator access to the template's project.
     */
    public function canDispatch(int $userId, JobTemplate $template): bool
    {
        return $this->isActiveWithPermission($userId, 'workflow.launch')
            && $this->projects()->canOperateChildResource($userId, (int)$template->project_id);
    }

    /**
     * Whether $userId is an active account that holds $permission.
     * Superadmins hold every permission. Used for identities that act without
     * a session: workflow launchers, trigger token creators, schedule owners.
     */
    public function isActiveWithPermission(int $userId, string $permission): bool
    {
        /** @var User|null $user */
        $user = User::findOne(['id' => $userId, 'status' => User::STATUS_ACTIVE]);
        if ($user === null) {
            return false;
        }
        if ($user->is_superadmin) {
            return true;
        }
        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;

        return $auth->checkAccess($userId, $permission);
    }

    /**
     * View (or operate) access to a job. A job with a template follows the
     * template's project. A job without one is either the placeholder of a
     * workflow approval step, which follows its workflow, or the history of a
     * purged template, which only unrestricted users may see.
     */
    public function canAccessJob(int $userId, Job $job, bool $operate): bool
    {
        $projects = $this->projects();
        if ($job->job_template_id !== null) {
            $projectId = $job->jobTemplate?->project_id;
            $projectId = $projectId === null ? null : (int)$projectId;

            return $operate
                ? $projects->canOperateChildResource($userId, $projectId)
                : $projects->canViewChildResource($userId, $projectId);
        }
        if ($projects->templateIdSubquery($userId) === null) {
            return true;
        }
        $workflowTemplateId = (new Query())
            ->select('wj.workflow_template_id')
            ->from(['wjs' => WorkflowJobStep::tableName()])
            ->innerJoin(['wj' => WorkflowJob::tableName()], 'wj.id = wjs.workflow_job_id')
            ->where(['wjs.job_id' => $job->id])
            ->scalar();
        if ($workflowTemplateId === false || $workflowTemplateId === null) {
            return false;
        }

        return $this->deniedJobTemplateIds($userId, (int)$workflowTemplateId, $operate) === [];
    }

    /**
     * Query condition that keeps only the approval requests the user may see:
     * those of jobs whose template project is visible, and those of workflow
     * approval steps (placeholder jobs without a template) whose workflow is
     * visible. Returns null when no restriction applies.
     *
     * @return array<int|string, mixed>|null
     */
    public function buildApprovalRequestFilter(?int $userId, string $jobIdColumn = 'approval_request.job_id'): ?array
    {
        if ($userId === null) {
            return ProjectAccessChecker::DENY_ALL;
        }
        $usable = $this->projects()->templateIdSubquery($userId);
        if ($usable === null) {
            return null;
        }
        $templateJobs = (new Query())
            ->select('j.id')
            ->from(['j' => Job::tableName()])
            ->where(['in', 'j.job_template_id', $usable]);
        $placeholderJobs = (new Query())
            ->select('wjs.job_id')
            ->from(['wjs' => WorkflowJobStep::tableName()])
            ->innerJoin(['wj' => WorkflowJob::tableName()], 'wj.id = wjs.workflow_job_id')
            ->innerJoin(['pj' => Job::tableName()], 'pj.id = wjs.job_id')
            ->where(['pj.job_template_id' => null])
            ->andWhere(['not in', 'wj.workflow_template_id', $this->blockedWorkflowIds($usable)]);

        return ['or', ['in', $jobIdColumn, $templateJobs], ['in', $jobIdColumn, $placeholderJobs]];
    }

    public function canViewApprovalRequest(int $userId, ApprovalRequest $request): bool
    {
        $filter = $this->buildApprovalRequestFilter($userId);
        if ($filter === null) {
            return true;
        }

        return ApprovalRequest::find()
            ->where(['approval_request.id' => $request->id])
            ->andWhere($filter)
            ->exists();
    }

    /**
     * IDs of the workflow templates with a job step whose job template is not
     * in $usable (or no longer exists).
     */
    private function blockedWorkflowIds(Query $usable): Query
    {
        return (new Query())
            ->select('ws.workflow_template_id')
            ->from(['ws' => WorkflowStep::tableName()])
            ->where(['ws.step_type' => WorkflowStep::TYPE_JOB])
            ->andWhere(['not', ['ws.job_template_id' => null]])
            ->andWhere(['not in', 'ws.job_template_id', $usable]);
    }

    /**
     * The refusal to $action (launch, operate) a workflow because of the job
     * templates in $denied. It names them only to a user who may see the
     * workflow: anyone else would learn job template IDs of other teams.
     *
     * @param list<int> $denied
     */
    private function refusal(
        int $userId,
        int $workflowTemplateId,
        string $action,
        array $denied
    ): WorkflowAccessDeniedException {
        if (!$this->canViewWorkflowTemplate($userId, $workflowTemplateId)) {
            return new WorkflowAccessDeniedException("You may not {$action} this workflow.");
        }

        return new WorkflowAccessDeniedException(
            "You may not {$action} job template(s) " . self::idList($denied) . ' of this workflow.',
            $denied
        );
    }

    /**
     * @param list<int> $ids
     */
    private static function idList(array $ids): string
    {
        return implode(', ', array_map(static fn (int $id): string => '#' . $id, $ids));
    }

    private function projects(): ProjectAccessChecker
    {
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');

        return $checker;
    }
}
