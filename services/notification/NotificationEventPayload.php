<?php

declare(strict_types=1);

namespace app\services\notification;

use app\components\AppLinks;
use app\models\NotificationTemplate;

/**
 * The event payload of the webhook channel (a webhook template whose config
 * says {"payload": "event"}; docs/notifications.md, and the schema
 * NotificationEventPayload of web/openapi.yaml): one JSON object per event,
 * built from what its delivery keeps of the event, and only from that:
 * - event, event_id (the same in every template's delivery of one event,
 *   another for every event: NotificationDispatcher), occurred_at (a Unix
 *   time) and severity (NotificationTemplate::eventSeverity());
 * - the objects of the event's group (OBJECTS, by
 *   NotificationVisibility::groupOf()), each shaped like the REST API's
 *   resource of it, with its page (url): workflow (the run), step (the
 *   step's execution), job, approval (the request), project, schedule,
 *   runner and trigger.
 *
 * An object is left out when the variables do not name it: a job's event
 * outside a run names no run; and the run's context of a job or an approval
 * event whose template's identity may not see the run's workflow is not in
 * its variables (NotificationDispatcher::RUN_CONTEXT_PREFIXES), so its
 * payload names no run, although its refs do. The refs only add what the
 * variables do not hold of an object they name: the approval request of a
 * step, the job template of a schedule.
 *
 * The variables are strings, as the dispatcher took them when the event
 * happened (NotificationDelivery::getVariableMap()): an ID or a count is a
 * whole number, else null; an empty text is null.
 */
final class NotificationEventPayload
{
    /** The objects of an event of each group of NotificationTemplate::eventGroups(), in the payload's order. */
    public const OBJECTS = [
        'jobs' => ['job', 'project', 'workflow', 'step'],
        'workflows' => ['workflow', 'step', 'approval'],
        'approvals' => ['approval', 'job', 'workflow', 'step'],
        'schedules' => ['schedule'],
        'runners' => ['runner'],
        'projects' => ['project'],
        'webhooks' => ['trigger'],
    ];

    /** How the channel writes the payload: slashes and characters as they are. */
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * The payload of $event with $variables and $refs (see the class).
     *
     * @param array<string, string> $variables
     * @param array{workflow_job_id?: int, job_id?: int, approval_request_id?: int, project_id?: int, job_template_id?: int} $refs
     * @return array<string, mixed>
     */
    public static function build(string $event, array $variables, array $refs): array
    {
        $payload = [
            'event' => $event,
            'event_id' => self::text($variables, 'event_id'),
            'occurred_at' => self::integer($variables, 'occurred_at'),
            'severity' => self::text($variables, 'severity') ?? NotificationTemplate::eventSeverity($event),
        ];
        $group = NotificationVisibility::groupOf($event);
        foreach ($group === null ? [] : self::OBJECTS[$group] as $name) {
            $object = self::object($name, $variables, $refs);
            if ($object !== null) {
                $payload[$name] = $object;
            }
        }

        return $payload;
    }

    /**
     * $payload as the channel sends it (JSON_FLAGS).
     *
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload): string
    {
        return (string)json_encode($payload, self::JSON_FLAGS);
    }

    /**
     * @param array<string, string> $variables
     * @param array{workflow_job_id?: int, job_id?: int, approval_request_id?: int, project_id?: int, job_template_id?: int} $refs
     * @return array<string, mixed>|null
     */
    private static function object(string $name, array $variables, array $refs): ?array
    {
        return match ($name) {
            'workflow' => self::workflow($variables),
            'step' => self::step($variables, $refs['approval_request_id'] ?? null),
            'job' => self::job($variables),
            'approval' => self::approval($variables, $refs['approval_request_id'] ?? null),
            'project' => self::project($variables),
            'schedule' => self::schedule($variables, $refs['job_template_id'] ?? null),
            'runner' => self::runner($variables),
            default => self::trigger($variables),
        };
    }

    /**
     * The run, as GET /api/v1/workflow-jobs/{id} names its fields.
     *
     * @param array<string, string> $variables
     * @return array<string, mixed>|null
     */
    private static function workflow(array $variables): ?array
    {
        $id = self::integer($variables, 'workflow.id');
        if ($id === null) {
            return null;
        }

        return [
            'id' => $id,
            'workflow_template_id' => self::integer($variables, 'workflow.template_id'),
            'template_name' => self::text($variables, 'workflow.template_name'),
            'status' => self::text($variables, 'workflow.status'),
            'steps_done' => self::integer($variables, 'workflow.steps_done'),
            'steps_total' => self::integer($variables, 'workflow.steps_total'),
            'reason' => self::text($variables, 'workflow.reason'),
            'url' => self::text($variables, 'workflow.url') ?? self::link($variables, AppLinks::WORKFLOW_RUN, $id),
        ];
    }

    /**
     * The step's execution, as the steps of a run name their fields; its
     * approval request ($requestId) is the one the event's refs name.
     *
     * @param array<string, string> $variables
     * @return array<string, mixed>|null
     */
    private static function step(array $variables, ?int $requestId): ?array
    {
        $id = self::integer($variables, 'step.execution_id') ?? self::integer($variables, 'step.id');
        if ($id === null) {
            return null;
        }

        return [
            'id' => $id,
            'workflow_step_id' => self::integer($variables, 'step.workflow_step_id'),
            'position' => self::integer($variables, 'step.position'),
            'step_name' => self::text($variables, 'step.name'),
            'step_type' => self::text($variables, 'step.type'),
            'job_id' => self::integer($variables, 'step.job_id'),
            'approval_request_id' => $requestId,
            'waiting_for' => self::text($variables, 'step.waiting_for'),
        ];
    }

    /**
     * @param array<string, string> $variables
     * @return array<string, mixed>|null
     */
    private static function job(array $variables): ?array
    {
        $id = self::integer($variables, 'job.id');
        if ($id === null) {
            return null;
        }

        return [
            'id' => $id,
            'status' => self::text($variables, 'job.status'),
            'job_template_id' => self::integer($variables, 'job.template_id'),
            'template_name' => self::text($variables, 'template.name'),
            'inventory_id' => self::integer($variables, 'job.inventory_id'),
            'inventory_name' => self::text($variables, 'job.inventory_name'),
            'launched_by' => self::text($variables, 'launched_by'),
            'exit_code' => self::integer($variables, 'job.exit_code'),
            'url' => self::text($variables, 'job.url') ?? self::link($variables, AppLinks::JOB, $id),
        ];
    }

    /**
     * The approval request of an approval event, or the one a step of a
     * workflow event waits for: $requestId, the one its refs name, where
     * the variables link it (approval.url).
     *
     * @param array<string, string> $variables
     * @return array<string, mixed>|null
     */
    private static function approval(array $variables, ?int $requestId): ?array
    {
        $id = self::integer($variables, 'approval.id') ?? (self::text($variables, 'approval.url') === null ? null : $requestId);
        if ($id === null) {
            return null;
        }

        return [
            'id' => $id,
            'status' => self::text($variables, 'approval.status'),
            'approval_rule_id' => self::integer($variables, 'approval.rule_id'),
            'approval_rule_name' => self::text($variables, 'approval.rule_name'),
            'required_by' => self::text($variables, 'approval.required_by'),
            'job_id' => self::integer($variables, 'job.id') ?? self::integer($variables, 'step.job_id'),
            'url' => self::text($variables, 'approval.url') ?? self::link($variables, AppLinks::APPROVAL, $id),
        ];
    }

    /**
     * @param array<string, string> $variables
     * @return array<string, mixed>|null
     */
    private static function project(array $variables): ?array
    {
        $id = self::integer($variables, 'project.id');
        if ($id === null) {
            return null;
        }

        return [
            'id' => $id,
            'name' => self::text($variables, 'project.name'),
            'status' => self::text($variables, 'project.status'),
            'last_sync_error' => self::text($variables, 'project.error'),
            'url' => self::link($variables, AppLinks::PROJECT, $id),
        ];
    }

    /**
     * A schedule, with its job template ($jobTemplateId, the one the
     * event's refs name).
     *
     * @param array<string, string> $variables
     * @return array<string, mixed>|null
     */
    private static function schedule(array $variables, ?int $jobTemplateId): ?array
    {
        $id = self::integer($variables, 'schedule.id');
        if ($id === null) {
            return null;
        }

        return [
            'id' => $id,
            'name' => self::text($variables, 'schedule.name'),
            'cron_expression' => self::text($variables, 'schedule.cron'),
            'job_template_id' => $jobTemplateId,
            'error' => self::text($variables, 'schedule.error'),
            'url' => self::link($variables, AppLinks::SCHEDULE, $id),
        ];
    }

    /**
     * @param array<string, string> $variables
     * @return array<string, mixed>|null
     */
    private static function runner(array $variables): ?array
    {
        $id = self::integer($variables, 'runner.id');
        if ($id === null) {
            return null;
        }

        return [
            'id' => $id,
            'name' => self::text($variables, 'runner.name'),
            'last_seen_at' => self::integer($variables, 'runner.last_seen_at'),
        ];
    }

    /**
     * The call with an unknown trigger token: the token's first characters,
     * the kind of trigger (workflow, null for a job template's), and where
     * the call came from.
     *
     * @param array<string, string> $variables
     * @return array<string, mixed>|null
     */
    private static function trigger(array $variables): ?array
    {
        $prefix = self::text($variables, 'trigger.token_prefix');
        if ($prefix === null) {
            return null;
        }

        return [
            'kind' => self::text($variables, 'trigger.kind'),
            'token_prefix' => $prefix,
            'ip' => self::text($variables, 'trigger.ip'),
            'user_agent' => self::text($variables, 'trigger.user_agent'),
            'note' => self::text($variables, 'trigger.note'),
        ];
    }

    /**
     * The whole number that the variable $key holds; null for none, for ''
     * and for any other text.
     *
     * @param array<string, string> $variables
     */
    private static function integer(array $variables, string $key): ?int
    {
        $value = $variables[$key] ?? '';

        return preg_match('/^-?\d{1,18}$/', $value) === 1 ? (int)$value : null;
    }

    /**
     * The text of the variable $key; null for none and for ''.
     *
     * @param array<string, string> $variables
     */
    private static function text(array $variables, string $key): ?string
    {
        $value = $variables[$key] ?? '';

        return $value === '' ? null : $value;
    }

    /**
     * The page $path of $id under the base of the event's links (app.url).
     *
     * @param array<string, string> $variables
     */
    private static function link(array $variables, string $path, int $id): string
    {
        return rtrim($variables['app.url'] ?? '', '/') . sprintf($path, $id);
    }
}
