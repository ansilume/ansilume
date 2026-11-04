<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\ApprovalRequest;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\Project;
use app\models\User;
use app\models\WorkflowJob;
use app\services\ProjectAccessChecker;
use app\services\runstate\Viewer;
use app\services\VisibleWorkflowTemplates;
use app\services\WorkflowAccessChecker;

/**
 * Which events a notification template may deliver: only what the user it
 * delivers as (its identity, delivers_as: NotificationIdentity) may see in
 * Ansilume, while that user is active, and of a workflow run only the runs
 * its workflow filter takes. The dispatcher asks when the event happens,
 * the notification worker again when it delivers
 * (NotificationDeliveryService), so that a user who lost access by then,
 * or was deactivated, gets nothing more. Each asks with the user's roles as
 * they are then: the workers, whose authManager would keep the role
 * assignments it read for its whole life, read them anew for each job
 * (WorkerController::attachWorkerEvents()).
 *
 * An event is judged by its group of NotificationTemplate::eventGroups(),
 * the status refresh of a run (NotificationDelivery::EVENT_STATUS_REFRESH) as a
 * workflow event:
 *
 * | Group | Permission (PERMISSIONS) | Access check of the object |
 * |---|---|---|
 * | workflows | workflow.view | the run's workflow template (canViewWorkflowTemplate) |
 * | jobs | job.view | the job (canAccessJob) |
 * | approvals | approval.view | the approval request (canViewApprovalRequest) |
 * | projects | project.view | the project (ProjectAccessChecker::canView) |
 * | schedules | job.launch | the project of the job template, a soft-deleted one included |
 * | runners | runner-group.view | none: the events are about the installation |
 * | webhooks | none | none: webhook.invalid_token carries nothing of a team and goes to any active identity |
 *
 * The permission is asked first, through Viewer (a superadmin holds every
 * permission; an inactive or unknown user none), then the workflow filter,
 * then the object. The objects are named by the event's refs:
 * workflow_job_id, job_id, approval_request_id, project_id and
 * job_template_id (NotificationDelivery::getRefs()). It fails closed: an
 * event whose object is not named or no longer exists is not delivered, a
 * schedule event without a job template only to an identity that no team
 * restricts, and an event of no group, or of a group without a rule,
 * nowhere.
 *
 * The workflow filter: with workflow scope selected, an event that names a
 * run (workflow_job_id), a workflow event or a job or approval event of
 * the run, is delivered only when the run's workflow template is in the
 * filter (NotificationTemplate::isForWorkflowTemplate()); a filter whose
 * workflow templates were all deleted takes no run. An event of no run
 * does not ask.
 *
 * A job or approval event of a run is judged by its job or request, not by
 * the run's workflow: an identity that may see the job but not the
 * workflow still gets it, without the run's context, which the dispatcher
 * leaves out, and the notification worker again when the identity may no
 * longer see the workflow by then (allows() with a workflow event and the
 * run says whether the identity may see the run).
 *
 * Neither the template's event list nor its level is asked here: the
 * dispatcher decides which events a template listens to and posts.
 */
final class NotificationVisibility
{
    /**
     * The permission the identity needs for the events of each group of
     * NotificationTemplate::eventGroups(); null: none, any active identity.
     */
    public const PERMISSIONS = [
        'jobs' => 'job.view',
        'workflows' => 'workflow.view',
        'approvals' => 'approval.view',
        'schedules' => 'job.launch',
        'runners' => 'runner-group.view',
        'projects' => 'project.view',
        'webhooks' => null,
    ];

    /**
     * Whether $template may deliver $event about the objects $refs names
     * (see the class).
     *
     * @param array{workflow_job_id?: int, job_id?: int, approval_request_id?: int, project_id?: int, job_template_id?: int} $refs
     */
    public function allows(NotificationTemplate $template, string $event, array $refs): bool
    {
        $group = self::groupOf($event);
        $identity = (int)$template->delivers_as;
        if ($group === null || !self::holdsPermission($identity, self::PERMISSIONS[$group])) {
            return false;
        }
        $workflowTemplateId = null;
        if (isset($refs['workflow_job_id'])) {
            $workflowTemplateId = self::workflowTemplateOfRun($refs['workflow_job_id']);
            if ($workflowTemplateId === null || !$template->isForWorkflowTemplate($workflowTemplateId)) {
                return false;
            }
        }

        return $this->seesObject($identity, $group, $refs, $workflowTemplateId);
    }

    /**
     * The IDs of $ids that are live workflow templates $viewerId may see,
     * ascending, each once (VisibleWorkflowTemplates): what a template's
     * page, form and API offer and show of its workflow filter.
     *
     * @param array<int|string, int> $ids
     * @return list<int>
     */
    public function visibleWorkflowTemplateIds(int $viewerId, array $ids): array
    {
        return VisibleWorkflowTemplates::ids($viewerId, $ids);
    }

    /**
     * The group of NotificationTemplate::eventGroups() with a rule
     * (PERMISSIONS) that lists $event; null for an event of none, so that an
     * event of a group without a rule is delivered nowhere. The status
     * refresh of a run (NotificationDelivery::EVENT_STATUS_REFRESH), which no group
     * lists, is a workflow event of its run. The event payload of the
     * webhook channel carries the objects of this group
     * (NotificationEventPayload).
     *
     * @return key-of<self::PERMISSIONS>|null
     */
    public static function groupOf(string $event): ?string
    {
        if ($event === NotificationDelivery::EVENT_STATUS_REFRESH) {
            return 'workflows';
        }
        $groups = NotificationTemplate::eventGroups();
        foreach (array_keys(self::PERMISSIONS) as $group) {
            if (array_key_exists($event, $groups[$group]['events'] ?? [])) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Whether $identity may see the events of a group at all: is active and
     * holds the group's $permission, or, for a group without one (null), is
     * active.
     */
    private static function holdsPermission(int $identity, ?string $permission): bool
    {
        if ($permission === null) {
            return User::find()->where(['id' => $identity, 'status' => User::STATUS_ACTIVE])->exists();
        }

        return Viewer::of($identity)->may($permission);
    }

    /**
     * Whether $identity may see the object of an event of $group;
     * $workflowTemplateId is the workflow template of the run the event
     * names, if it names one.
     *
     * @param array{workflow_job_id?: int, job_id?: int, approval_request_id?: int, project_id?: int, job_template_id?: int} $refs
     */
    private function seesObject(int $identity, string $group, array $refs, ?int $workflowTemplateId): bool
    {
        return match ($group) {
            'workflows' => $workflowTemplateId !== null && self::workflows()->canViewWorkflowTemplate($identity, $workflowTemplateId),
            'jobs' => self::seesJob($identity, $refs['job_id'] ?? null),
            'approvals' => self::seesApprovalRequest($identity, $refs['approval_request_id'] ?? null),
            'projects' => self::seesProject($identity, $refs['project_id'] ?? null),
            'schedules' => self::seesJobTemplate($identity, $refs['job_template_id'] ?? null),
            'runners', 'webhooks' => true,
            default => false,
        };
    }

    /**
     * The workflow template of run $runId; null when there is no such run.
     */
    private static function workflowTemplateOfRun(int $runId): ?int
    {
        $id = WorkflowJob::find()->select('workflow_template_id')->where(['id' => $runId])->scalar();

        return $id === false || $id === null ? null : (int)$id;
    }

    private static function seesJob(int $identity, ?int $jobId): bool
    {
        $job = $jobId === null ? null : Job::findOne($jobId);

        return $job instanceof Job && self::workflows()->canAccessJob($identity, $job, false);
    }

    private static function seesApprovalRequest(int $identity, ?int $requestId): bool
    {
        $request = $requestId === null ? null : ApprovalRequest::findOne($requestId);

        return $request instanceof ApprovalRequest && self::workflows()->canViewApprovalRequest($identity, $request);
    }

    private static function seesProject(int $identity, ?int $projectId): bool
    {
        return $projectId !== null
            && Project::find()->where(['id' => $projectId])->exists()
            && self::projects()->canView($identity, $projectId);
    }

    /**
     * Whether $identity may see the project of job template $templateId, a
     * soft-deleted one included; without a job template, whether no team
     * restricts $identity (as for the jobs of a purged job template).
     */
    private static function seesJobTemplate(int $identity, ?int $templateId): bool
    {
        if ($templateId === null) {
            return self::projects()->templateIdSubquery($identity) === null;
        }
        $projectId = JobTemplate::findWithDeleted()->select('project_id')->where(['id' => $templateId])->scalar();

        return $projectId !== false && $projectId !== null && self::projects()->canView($identity, (int)$projectId);
    }

    private static function workflows(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $checker */
        $checker = \Yii::$app->get('workflowAccessChecker');

        return $checker;
    }

    private static function projects(): ProjectAccessChecker
    {
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');

        return $checker;
    }
}
