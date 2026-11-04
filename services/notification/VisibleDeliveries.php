<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\ApprovalRequest;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\Project;
use app\models\WorkflowJob;
use app\services\ProjectAccessChecker;
use app\services\runstate\Viewer;
use app\services\WorkflowAccessChecker;
use yii\db\Query;

/**
 * Which deliveries of a notification template a reader may see
 * (GET /api/v1/notification-templates/{id}/deliveries, spec answer 5): those
 * of an event the reader may see, every object it names included, as the
 * reader sees the objects in Ansilume:
 * - the event's group of NotificationTemplate::eventGroups() needs its
 *   permission (NotificationVisibility::PERMISSIONS; the status refresh of
 *   a run, NotificationDelivery::EVENT_STATUS_REFRESH, is a workflow event);
 *   an event of no group is shown to nobody;
 * - each object the delivery names (its refs) needs its permission
 *   (REF_PERMISSIONS) and, for a reader whom a team restricts, access to
 *   it: the run's workflow template (WorkflowAccessChecker), the job (that
 *   of a job template whose project the reader may view, or the
 *   placeholder of an approval step of a visible workflow), the approval
 *   request, the project, the job template's project;
 * - for a reader whom a team restricts, a delivery of a group that does not
 *   name the object of its group (GROUP_REFS) is not shown: it cannot be
 *   checked.
 * A reader no team restricts (an admin, a superadmin, or anyone while no
 * project is team-restricted) sees every delivery of the groups and objects
 * they hold the permissions of. The caller counts the rest as hidden; no
 * answer names them.
 */
final class VisibleDeliveries
{
    /** The permission each object a delivery names (NotificationDelivery::REF_ATTRIBUTES) needs. */
    public const REF_PERMISSIONS = [
        'workflow_job_id' => 'workflow.view',
        'job_id' => 'job.view',
        'approval_request_id' => 'approval.view',
        'project_id' => 'project.view',
        'job_template_id' => 'job.launch',
    ];

    /** The object a delivery of each group names; without it, a reader whom a team restricts does not see it. */
    public const GROUP_REFS = [
        'workflows' => 'workflow_job_id',
        'jobs' => 'job_id',
        'approvals' => 'approval_request_id',
        'projects' => 'project_id',
        'schedules' => 'job_template_id',
    ];

    /** The table the condition's columns belong to. */
    private const TABLE = 'notification_delivery';

    /**
     * The condition on notification_delivery that keeps the deliveries the
     * user $viewerId may see (see the class).
     *
     * @return array<int|string, mixed>
     */
    public static function condition(int $viewerId): array
    {
        $viewer = Viewer::of($viewerId);
        $restricted = self::projects()->templateIdSubquery($viewerId) !== null;
        $condition = ['and', [self::column('event') => self::eventsOf($viewer)]];
        foreach (self::REF_PERMISSIONS as $ref => $permission) {
            if (!$viewer->may($permission)) {
                $condition[] = [self::column($ref) => null];
            } elseif ($restricted) {
                $condition[] = ['or', [self::column($ref) => null], self::objectCondition($ref, $viewerId)];
            }
        }
        foreach ($restricted ? self::GROUP_REFS : [] as $group => $ref) {
            $condition[] = ['not', ['and', [self::column('event') => self::groupEvents($group)], [self::column($ref) => null]]];
        }

        return $condition;
    }

    /**
     * The events of the groups whose permission $viewer holds (the
     * webhooks group needs none).
     *
     * @return list<string>
     */
    private static function eventsOf(Viewer $viewer): array
    {
        $events = [];
        foreach (NotificationVisibility::PERMISSIONS as $group => $permission) {
            if ($permission === null || $viewer->may($permission)) {
                $events = array_merge($events, self::groupEvents($group));
            }
        }

        return $events;
    }

    /**
     * The events of $group, the status refresh of a run with the workflow
     * events.
     *
     * @return list<string>
     */
    private static function groupEvents(string $group): array
    {
        $events = array_keys(NotificationTemplate::eventGroups()[$group]['events'] ?? []);

        return $group === 'workflows' ? [...$events, NotificationDelivery::EVENT_STATUS_REFRESH] : $events;
    }

    /**
     * The condition that the object $ref of a delivery is one the user
     * $viewerId, whom a team restricts, may see.
     *
     * @return array<int|string, mixed>
     */
    private static function objectCondition(string $ref, int $viewerId): array
    {
        $column = self::column($ref);
        $workflows = self::workflows();

        return match ($ref) {
            'workflow_job_id' => ['in', $column, (new Query())
                ->select('wj.id')
                ->from(['wj' => WorkflowJob::tableName()])
                ->where($workflows->buildWorkflowTemplateFilter($viewerId, 'wj.workflow_template_id') ?? [])],
            'job_id' => $workflows->buildApprovalRequestFilter($viewerId, $column) ?? [],
            'approval_request_id' => ['in', $column, ApprovalRequest::find()
                ->select('approval_request.id')
                ->where($workflows->buildApprovalRequestFilter($viewerId) ?? [])],
            'project_id' => ['in', $column, Project::find()->select('project.id')->where(self::projects()->buildProjectFilter($viewerId) ?? [])],
            default => ['in', $column, self::projects()->templateIdSubquery($viewerId)],
        };
    }

    private static function column(string $name): string
    {
        return self::TABLE . '.' . $name;
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
