<?php

declare(strict_types=1);

namespace app\components;

use app\models\Job;
use app\models\NotificationTemplate;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\services\NotificationDispatcher;
use app\services\notification\EventSubscription;
use app\services\notification\NotificationOutbox;
use app\services\runstate\Banner;
use app\services\runstate\Display;

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

    /** The step of the sample run: a job step whose job waits for its approval. */
    private const STEP_NAME = 'Deploy';

    /**
     * The event a test of $template is sent for: the template's first event
     * that its channel sends something for (EventSubscription: PagerDuty
     * sends nothing for the info events of a step), else its first event,
     * which the test then cannot send (NotificationDispatcher::SENDS_NOTHING),
     * else DEFAULT_EVENT.
     */
    public static function eventOf(NotificationTemplate $template): string
    {
        $events = $template->getEventList();
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');
        $channel = $dispatcher->channel((string)$template->channel);
        foreach ($events as $event) {
            if (EventSubscription::sends($channel, $event)) {
                return $event;
            }
        }

        return $events[0] ?? self::DEFAULT_EVENT;
    }

    /**
     * Sample values, as strings, of the variables that notifications carry,
     * for a test sent for $event by the user named $launchedBy. The
     * approval ones are those of a job that its inventory's approval rule
     * gates (approval.required_by, job.inventory_id and job.inventory_name,
     * as ApprovalEffects sends them); the run's ones (workflow.*, step.*:
     * notification\RunContext) those of the run whose step that job is.
     *
     * @return array<string, string>
     */
    public static function of(string $event, string $launchedBy): array
    {
        $appUrl = \Yii::$app->params['appBaseUrl'] ?? 'http://localhost';

        return [
            'event' => $event,
            'severity' => NotificationTemplate::eventSeverity($event),
            // Every test is an event of its own, as every event the dispatcher queues.
            'event_id' => NotificationDispatcher::newEventId(),
            'occurred_at' => (string)time(),
            'timestamp' => date('Y-m-d H:i:s T'),
            'app.url' => $appUrl,
            'job.id' => '0',
            'job.status' => Job::STATUS_SUCCEEDED,
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
            // A Unix time, as the runner events carry it (HealthController).
            'runner.last_seen_at' => (string)(time() - 300),
            'workflow.id' => '0',
            'workflow.status' => WorkflowJob::STATUS_SUCCEEDED,
            'workflow.template_id' => '0',
            'workflow.template_name' => 'Example Workflow',
            'workflow.url' => $appUrl . sprintf(AppLinks::WORKFLOW_RUN, 0),
            'workflow.steps_done' => '1',
            'workflow.steps_total' => '3',
            'workflow.reason' => (string)Banner::title(Display::WAITING_FOR_APPROVAL, WorkflowStep::TYPE_JOB, self::STEP_NAME, 0),
            'step.id' => '0',
            'step.job_id' => '0',
            'step.execution_id' => '0',
            'step.workflow_step_id' => '0',
            'step.position' => '2',
            'step.name' => self::STEP_NAME,
            'step.type' => WorkflowStep::TYPE_JOB,
            'step.waiting_for' => Display::WAITING_FOR_APPROVAL,
            'schedule.id' => '0',
            'schedule.name' => 'Nightly Deploy',
            'schedule.cron' => '0 2 * * *',
            'schedule.error' => 'Template not found',
            'approval.id' => '0',
            'approval.status' => 'approved',
            'approval.rule_id' => '0',
            'approval.rule_name' => 'Production Gate',
            'approval.required_by' => 'inventory',
            'approval.url' => $appUrl . sprintf(AppLinks::APPROVAL, 0),
            'trigger.token_prefix' => 'abc123',
            'trigger.ip' => '192.168.1.100',
            'trigger.user_agent' => 'curl/8.0',
            'trigger.note' => sprintf(
                NotificationOutbox::THROTTLE_NOTE,
                intdiv(NotificationOutbox::THROTTLE_SECONDS[NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN], 60)
            ),
        ];
    }
}
