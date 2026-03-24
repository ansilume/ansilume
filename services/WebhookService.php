<?php

declare(strict_types=1);

namespace app\services;

use app\models\Job;
use app\models\Webhook;

/**
 * Fires outbound webhook deliveries for job lifecycle events.
 *
 * Each delivery is an HTTP POST with a JSON body signed with
 * HMAC-SHA256 in the X-Ansilume-Signature header when a secret
 * is configured on the webhook.
 */
class WebhookService extends \yii\base\Component
{
    /**
     * Fire all enabled webhooks that listen to the given event.
     * Failures are logged but never throw — webhook delivery must not
     * affect the caller's flow.
     */
    public function dispatch(string $event, Job $job): void
    {
        $webhooks = Webhook::find()
            ->where(['enabled' => true])
            ->all();

        foreach ($webhooks as $webhook) {
            /** @var Webhook $webhook */
            if (!$webhook->listensTo($event)) {
                continue;
            }

            try {
                $this->deliver($webhook, $event, $job);
            } catch (\Throwable $e) {
                \Yii::error(
                    "Webhook #{$webhook->id} delivery failed for event={$event} job={$job->id}: " . $e->getMessage(),
                    __CLASS__
                );
            }
        }
    }

    /**
     * Build the payload and POST it to the webhook URL.
     */
    protected function deliver(Webhook $webhook, string $event, Job $job): void
    {
        $payload = json_encode($this->buildPayload($event, $job), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = [
            'Content-Type: application/json',
            'X-Ansilume-Event: ' . $event,
            'X-Ansilume-Delivery: ' . bin2hex(random_bytes(8)),
        ];

        if (!empty($webhook->secret)) {
            $sig = 'sha256=' . hash_hmac('sha256', $payload, $webhook->secret);
            $headers[] = 'X-Ansilume-Signature: ' . $sig;
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $payload,
                'timeout'       => 10,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($webhook->url, false, $ctx);

        // Log non-2xx responses for diagnostics but do not throw.
        $statusLine = $http_response_header[0] ?? '';
        if ($response === false || !str_contains($statusLine, '2')) {
            \Yii::warning(
                "Webhook #{$webhook->id} non-success for event={$event} job={$job->id}: {$statusLine}",
                __CLASS__
            );
        }
    }

    protected function buildPayload(string $event, Job $job): array
    {
        return [
            'event'      => $event,
            'timestamp'  => time(),
            'job'        => [
                'id'              => $job->id,
                'status'          => $job->status,
                'job_template_id' => $job->job_template_id,
                'launched_by'     => $job->launched_by,
                'started_at'      => $job->started_at,
                'finished_at'     => $job->finished_at,
                'exit_code'       => $job->exit_code,
            ],
        ];
    }
}
