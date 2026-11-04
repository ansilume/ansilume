<?php

declare(strict_types=1);

namespace app\services\notification;

use app\components\OutboundHttpClient;
use app\components\OutboundHttpException;
use app\components\OutboundHttpResponse;

/**
 * How the HTTP notification channels (SlackChannel, TeamsChannel,
 * WebhookChannel, TelegramChannel, PagerDutyChannel) send a message: a POST
 * through the outbound HTTP client (Yii::$app->get('outboundHttp')), which
 * sends only to http and https URLs and follows no redirect.
 *
 * An error is the client's, reported by the channel
 * (OutboundHttpException::from()), for example "SlackChannel: HTTP 404.":
 * never with the URL, a header or the answer's content, as the test flash,
 * the API's 502 and the notification.failed audit entry show it.
 */
final class ChannelHttp
{
    /**
     * POSTs $payload to $url and answers the 2xx answer.
     *
     * @param string $channel the channel's name in its errors, such as SlackChannel
     * @param string[] $headers request headers, each a "Name: value" line
     * @throws OutboundHttpException when no 2xx answer came, named after $channel
     */
    public static function post(
        string $channel,
        #[\SensitiveParameter] string $url,
        #[\SensitiveParameter] string $payload,
        #[\SensitiveParameter] array $headers
    ): OutboundHttpResponse {
        /** @var OutboundHttpClient $client */
        $client = \Yii::$app->get('outboundHttp');
        try {
            return $client->request('POST', $url, array_values($headers), $payload);
        } catch (OutboundHttpException $e) {
            throw $e->from($channel);
        }
    }
}
