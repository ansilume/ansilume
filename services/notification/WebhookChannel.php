<?php

declare(strict_types=1);

namespace app\services\notification;

use app\components\NotificationTestVariables;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;

/**
 * Sends notifications via a generic HTTP webhook (docs/notifications.md).
 *
 * Config: {"url": "https://example.com/hook", "headers": {"X-Custom":
 * "value"}, "signing_secret": "...", "payload": "event"}; signing_secret
 * and payload are optional (WebhookConfig).
 *
 * Each request is a POST of JSON: the template's subject, body and
 * variables (payload template, the default), or the event payload
 * (payload event: NotificationEventPayload). It names its event
 * (EVENT_HEADER) and its delivery (DELIVERY_HEADER): the delivery's ID,
 * the same on every retry of it (sendDelivery()); a test send, which has
 * no delivery, names one of its own (TEST_DELIVERY). With a signing secret
 * it is signed (SIGNATURE_HEADER, SIGNATURE): t is the Unix time of the
 * request, v1 the hex HMAC-SHA256 of "<t>.<body>" with the secret as key.
 * The headers of the config follow, but none named as these
 * (WebhookConfig::customHeaders()).
 *
 * The URL, the header values and the signing secret are channel secrets
 * (the secretKeys and secretMaps of its definition, ChannelRegistry::BUILT_IN): the URL
 * often carries a token, so no log line names it.
 */
class WebhookChannel implements DeliveryChannelInterface
{
    /**
     * Logged (info) once the webhook took a notification; sprintf(): the
     * notification template's ID, then the HTTP code. As SlackChannel and
     * TeamsChannel, never the URL.
     */
    public const SENT = 'WebhookChannel: sent the notification of template #%d to its webhook — %d';

    /** The signature of a request whose template has a signing secret: SIGNATURE. */
    public const SIGNATURE_HEADER = 'X-Ansilume-Signature';

    /** The delivery a request belongs to: its ID, or TEST_DELIVERY for a test send. */
    public const DELIVERY_HEADER = 'X-Ansilume-Delivery';

    /** The event a request is about. */
    public const EVENT_HEADER = 'X-Ansilume-Event';

    /** The headers the channel sets itself, which the config may not name. */
    public const RESERVED_HEADERS = [self::SIGNATURE_HEADER, self::DELIVERY_HEADER, self::EVENT_HEADER];

    /** The value of SIGNATURE_HEADER; sprintf(): the Unix time of the request, the hex HMAC-SHA256 of "<that time>.<body>". */
    public const SIGNATURE = 't=%d,v1=%s';

    /** The DELIVERY_HEADER of a test send, which has no delivery; sprintf(): 16 random hex digits. */
    public const TEST_DELIVERY = 'test-%s';

    /** The template whose notification send() is sending, for the line post() logs. */
    private int $templateId = 0;

    /**
     * A test send (Send Test): for the event of $variables, as a delivery
     * of its own (TEST_DELIVERY).
     */
    public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
    {
        $event = (string)($variables['event'] ?? NotificationTestVariables::DEFAULT_EVENT);
        $this->deliver($template, ['subject' => $subject, 'body' => $body], $variables, [
            'event' => $event,
            'delivery' => sprintf(self::TEST_DELIVERY, bin2hex(random_bytes(8))),
            'refs' => [],
        ]);
    }

    public function sendDelivery(NotificationTemplate $template, string $subject, string $body, array $variables, NotificationDelivery $delivery): void
    {
        $this->deliver($template, ['subject' => $subject, 'body' => $body], $variables, [
            'event' => (string)$delivery->event,
            'delivery' => (string)$delivery->id,
            'refs' => $delivery->getRefs(),
        ]);
    }

    /**
     * POSTs $payload to the webhook through the outbound HTTP client
     * (ChannelHttp): http and https only, no redirect, and an error that
     * names the status, never the URL, a header or the answer.
     *
     * @param string[] $headers
     * @throws \app\components\OutboundHttpException when the webhook did not take it
     */
    protected function post(
        #[\SensitiveParameter] string $url,
        string $payload,
        #[\SensitiveParameter] array $headers
    ): string {
        $response = ChannelHttp::post('WebhookChannel', $url, $payload, $headers);

        \Yii::info(sprintf(self::SENT, $this->templateId, $response->status), __CLASS__);
        return $response->body;
    }

    /**
     * Sends $message of $template for the event and the delivery of
     * $context: as the config says, with the channel's headers.
     *
     * @param array{subject: string, body: string} $message
     * @param array<string, string> $variables
     * @param array{event: string, delivery: string, refs: array{workflow_job_id?: int, job_id?: int, approval_request_id?: int, project_id?: int, job_template_id?: int}} $context
     */
    private function deliver(NotificationTemplate $template, array $message, array $variables, array $context): void
    {
        $config = $template->getParsedConfig();
        $url = (string)($config['url'] ?? '');
        if ($url === '') {
            return;
        }
        $payload = WebhookConfig::payloadOf($config) === WebhookConfig::PAYLOAD_EVENT
            ? NotificationEventPayload::json(NotificationEventPayload::build($context['event'], $variables, $context['refs']))
            : (string)json_encode(['subject' => $message['subject'], 'body' => $message['body'], 'variables' => $variables]);

        $headers = [
            'Content-Type: application/json',
            self::EVENT_HEADER . ': ' . $context['event'],
            self::DELIVERY_HEADER . ': ' . $context['delivery'],
        ];
        $secret = WebhookConfig::signingSecretOf($config);
        if ($secret !== null) {
            $headers[] = self::SIGNATURE_HEADER . ': ' . self::signature($secret, time(), $payload);
        }

        $this->templateId = (int)$template->id;
        $this->post($url, $payload, array_merge($headers, WebhookConfig::customHeaders($config)));
    }

    /**
     * The value of SIGNATURE_HEADER for $body sent at $time, signed with
     * $secret.
     */
    private static function signature(#[\SensitiveParameter] string $secret, int $time, string $body): string
    {
        return sprintf(self::SIGNATURE, $time, hash_hmac('sha256', $time . '.' . $body, $secret));
    }
}
