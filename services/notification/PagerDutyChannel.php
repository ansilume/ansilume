<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationTemplate;

/**
 * Sends notifications to PagerDuty via the Events API v2.
 *
 * Config: {"routing_key": "R0UT1NGKEY..."}
 *
 * Severity is driven by NotificationTemplate::eventSeverity() and exposed via
 * the {{ severity }} template variable; we reuse it here to set the PD alert
 * severity so critical events page and info events don't.
 *
 * A stable `dedup_key` scoped to (template, object type, object id) means
 * repeated dispatches for the same incident collapse into one alert and get
 * auto-resolved when the recovery event fires (e.g. runner.recovered after
 * runner.offline).
 *
 * The info events of a workflow step's start and success (NEVER_TRIGGERED)
 * never trigger an incident: PagerDuty gets nothing for them, and a
 * template gets no delivery of them (sendsEvent(), SelectiveChannelInterface),
 * so none claims a send that never happened. Every other event triggers
 * one, or resolves it, as it did before they existed.
 */
class PagerDutyChannel implements SelectiveChannelInterface
{
    /** The events that never trigger an incident: a step of a run started, or succeeded. */
    public const NEVER_TRIGGERED = [
        NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED,
        NotificationTemplate::EVENT_WORKFLOW_STEP_SUCCEEDED,
    ];

    private const ENDPOINT = 'https://events.pagerduty.com/v2/enqueue';

    /**
     * Every event but those that never trigger an incident (NEVER_TRIGGERED).
     */
    public function sendsEvent(string $event): bool
    {
        return !in_array($event, self::NEVER_TRIGGERED, true);
    }

    public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
    {
        $config = $template->getParsedConfig();
        $routingKey = (string)($config['routing_key'] ?? '');
        $event = $variables['event'] ?? '';
        if ($routingKey === '' || !$this->sendsEvent($event)) {
            return;
        }

        $severity = $this->mapSeverity((string)($variables['severity'] ?? 'error'));
        $action = $this->isRecoveryEvent($event) ? 'resolve' : 'trigger';

        $payload = (string)json_encode([
            'routing_key' => $routingKey,
            'event_action' => $action,
            'dedup_key' => $this->dedupKey($template, $variables),
            'payload' => [
                'summary' => mb_substr($subject !== '' ? $subject : $event, 0, 1024),
                'source' => $variables['app.url'] ?? 'ansilume',
                'severity' => $severity,
                'component' => $event,
                'custom_details' => [
                    'body' => $body,
                    'variables' => $variables,
                ],
            ],
        ]);

        $this->post(self::ENDPOINT, $payload, ['Content-Type: application/json']);
    }

    /**
     * PagerDuty severities: critical, error, warning, info.
     */
    private function mapSeverity(string $severity): string
    {
        return match ($severity) {
            'critical', 'error', 'warning', 'info' => $severity,
            default => 'error',
        };
    }

    /**
     * Recovery events resolve their matching incident instead of creating a new one.
     */
    private function isRecoveryEvent(string $event): bool
    {
        return in_array($event, [
            NotificationTemplate::EVENT_RUNNER_RECOVERED,
            NotificationTemplate::EVENT_PROJECT_SYNC_SUCCEEDED,
            NotificationTemplate::EVENT_JOB_SUCCEEDED,
            NotificationTemplate::EVENT_WORKFLOW_SUCCEEDED,
        ], true);
    }

    /**
     * Stable key so retries + recoveries map to the same PD incident. An
     * event about a job (job.id: a job or an approval event) keeps the
     * incident of its job's project, job template or the job itself, also
     * when the job is a workflow step's and the event carries the run's
     * context (workflow.id, RunContext).
     *
     * @param array<string, string> $variables
     */
    private function dedupKey(NotificationTemplate $template, array $variables): string
    {
        $parts = [
            'ansilume',
            (string)$template->id,
        ];
        $aboutAJob = array_key_exists('job.id', $variables);
        foreach (['runner.id', 'project.id', 'workflow.id', 'job.template_id'] as $key) {
            if (!empty($variables[$key]) && ($key !== 'workflow.id' || !$aboutAJob)) {
                $parts[] = $key . '=' . $variables[$key];
                return implode(':', $parts);
            }
        }
        if (!empty($variables['job.id'])) {
            $parts[] = 'job=' . $variables['job.id'];
        }
        return implode(':', $parts);
    }

    /**
     * POSTs $payload, which holds the routing key, to the Events API through
     * the outbound HTTP client (ChannelHttp): an error names the status,
     * never the URL or the answer.
     *
     * @param string[] $headers
     * @throws \app\components\OutboundHttpException when PagerDuty did not take it
     */
    protected function post(#[\SensitiveParameter] string $url, #[\SensitiveParameter] string $payload, array $headers): string
    {
        $response = ChannelHttp::post('PagerDutyChannel', $url, $payload, $headers);

        \Yii::info("PagerDutyChannel: sent — {$response->status}", __CLASS__);
        return $response->body;
    }
}
