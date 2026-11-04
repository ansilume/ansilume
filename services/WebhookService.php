<?php

declare(strict_types=1);

namespace app\services;

use app\components\ErrorText;
use app\components\OutboundHttpClient;
use app\components\OutboundHttpException;
use app\models\Job;
use app\models\Webhook;

/**
 * Fires outbound webhook deliveries for job lifecycle events.
 *
 * Each delivery is an HTTP POST with a JSON body signed with
 * HMAC-SHA256 in the X-Ansilume-Signature header when a secret
 * is configured on the webhook. It goes through the outbound HTTP client
 * (OutboundHttpClient): http and https only, no redirect followed, and only
 * a 2xx answer is a delivery. A delivery the webhook did not take is logged
 * with its status or why no answer came (NOT_DELIVERED), never with the
 * URL, a header or the answer.
 */
class WebhookService extends \yii\base\Component
{
    /**
     * Logged (warning) for a delivery the webhook did not take. sprintf():
     * the webhook's ID, the event, the job's ID, then the outbound HTTP
     * client's error (ErrorText): the HTTP status of an answer outside 2xx,
     * a redirect included, or why no answer came.
     */
    public const NOT_DELIVERED = 'Webhook #%d non-success for event=%s job=%d: %s';

    /**
     * Logged (error) for a delivery that failed before its request.
     * sprintf(): the webhook's ID, the event, the job's ID, then what may be
     * said about the error (ErrorText): never the message of a database
     * error.
     */
    public const DELIVERY_FAILED = 'Webhook #%d delivery failed for event=%s job=%d: %s';

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
                \Yii::error(sprintf(self::DELIVERY_FAILED, $webhook->id, $event, $job->id, ErrorText::of($e)), __CLASS__);
            }
        }
    }

    /**
     * Build the payload and POST it to the webhook URL; a delivery the
     * webhook did not take is logged (NOT_DELIVERED), not thrown.
     */
    protected function deliver(Webhook $webhook, string $event, Job $job): void
    {
        $payload = json_encode($this->buildPayload($event, $job), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            $payload = '{}';
        }

        $headers = [
            'Content-Type: application/json',
            'X-Ansilume-Event: ' . $event,
            'X-Ansilume-Delivery: ' . bin2hex(random_bytes(8)),
        ];

        if (!empty($webhook->secret)) {
            $sig = 'sha256=' . hash_hmac('sha256', $payload, $webhook->secret);
            $headers[] = 'X-Ansilume-Signature: ' . $sig;
        }

        /** @var OutboundHttpClient $client */
        $client = \Yii::$app->get('outboundHttp');
        try {
            $client->request('POST', (string)$webhook->url, $headers, $payload);
        } catch (OutboundHttpException $e) {
            \Yii::warning(sprintf(self::NOT_DELIVERED, $webhook->id, $event, $job->id, ErrorText::of($e)), __CLASS__);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPayload(string $event, Job $job): array
    {
        return [
            'event' => $event,
            'timestamp' => time(),
            'job' => [
                'id' => $job->id,
                'status' => $job->status,
                'job_template_id' => $job->job_template_id,
                'launched_by' => $job->launched_by,
                'started_at' => $job->started_at,
                'finished_at' => $job->finished_at,
                'exit_code' => $job->exit_code,
            ],
        ];
    }
}
