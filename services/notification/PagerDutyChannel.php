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
 */
class PagerDutyChannel implements ChannelInterface
{
    private const ENDPOINT = 'https://events.pagerduty.com/v2/enqueue';

    public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
    {
        $config = $template->getParsedConfig();
        $routingKey = (string)($config['routing_key'] ?? '');
        if ($routingKey === '') {
            return;
        }

        $event = $variables['event'] ?? '';
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
     * Stable key so retries + recoveries map to the same PD incident.
     *
     * @param array<string, string> $variables
     */
    private function dedupKey(NotificationTemplate $template, array $variables): string
    {
        $parts = [
            'ansilume',
            (string)$template->id,
        ];
        foreach (['runner.id', 'project.id', 'workflow.id', 'job.template_id'] as $key) {
            if (!empty($variables[$key])) {
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
     * @param string[] $headers
     */
    protected function post(string $url, string $payload, array $headers): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('PagerDutyChannel: failed to init curl');
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $result = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("PagerDutyChannel: HTTP {$code} — " . substr((string)$result, 0, 200));
        }

        \Yii::info("PagerDutyChannel: sent — {$code}", __CLASS__);
        return (string)$result;
    }
}
