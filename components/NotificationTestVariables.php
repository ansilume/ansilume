<?php

declare(strict_types=1);

namespace app\components;

use app\models\NotificationTemplate;

/**
 * What a test notification sends, the same for the Send Test button of the
 * web UI (NotificationTemplateController::actionTest()) and for POST
 * /api/v1/notification-templates/{id}/test: the event it is sent for, and
 * sample values of the variables that notifications carry.
 */
final class NotificationTestVariables
{
    /** The event of a test of a template that listens to no event. */
    public const DEFAULT_EVENT = 'test';

    /** The name the callers give launched_by for a test without a signed-in user. */
    public const NO_USER = 'system';

    /**
     * The event a test of $template is sent for: the template's first
     * event, else DEFAULT_EVENT.
     */
    public static function eventOf(NotificationTemplate $template): string
    {
        $events = $template->getEventList();

        return !empty($events) ? $events[0] : self::DEFAULT_EVENT;
    }

    /**
     * Sample values, as strings, of the variables that notifications carry,
     * for a test sent for $event by the user named $launchedBy. The
     * approval ones are those of a job that its inventory's approval rule
     * gates (approval.required_by, job.inventory_id and job.inventory_name,
     * as ApprovalEffects sends them).
     *
     * @return array<string, string>
     */
    public static function of(string $event, string $launchedBy): array
    {
        $appUrl = \Yii::$app->params['appBaseUrl'] ?? 'http://localhost';

        return [
            'event' => $event,
            'severity' => NotificationTemplate::eventSeverity($event),
            'timestamp' => date('Y-m-d H:i:s T'),
            'app.url' => $appUrl,
            'job.id' => '0',
            'job.status' => 'successful',
            'job.exit_code' => '0',
            'job.duration' => '1m 23s',
            'job.url' => $appUrl . '/job/view?id=0',
            'job.template_id' => '0',
            'job.inventory_id' => '0',
            'job.inventory_name' => 'Production hosts',
            'template.id' => '0',
            'template.name' => 'Example Playbook',
            'project.id' => '0',
            'project.name' => 'Example Project',
            'project.status' => 'synced',
            'project.error' => '',
            'launched_by' => $launchedBy,
            'runner.id' => '1',
            'runner.name' => 'runner-1',
            'runner.last_seen_at' => date('Y-m-d H:i:s T', time() - 300),
            'workflow.id' => '0',
            'workflow.status' => 'successful',
            'workflow.template_id' => '0',
            'workflow.template_name' => 'Example Workflow',
            'schedule.id' => '0',
            'schedule.name' => 'Nightly Deploy',
            'schedule.cron' => '0 2 * * *',
            'schedule.error' => 'Template not found',
            'approval.id' => '0',
            'approval.status' => 'approved',
            'approval.rule_id' => '0',
            'approval.rule_name' => 'Production Gate',
            'approval.required_by' => 'inventory',
            'trigger.token_prefix' => 'abc123',
            'trigger.ip' => '192.168.1.100',
            'trigger.user_agent' => 'curl/8.0',
        ];
    }
}
