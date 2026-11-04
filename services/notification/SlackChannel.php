<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationTemplate;

/**
 * Sends notifications to a Slack incoming webhook.
 *
 * Config: {"webhook_url": "https://hooks.slack.com/services/..."}
 */
class SlackChannel implements ChannelInterface
{
    public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
    {
        $config = $template->getParsedConfig();
        $url = (string)($config['webhook_url'] ?? '');
        if ($url === '') {
            return;
        }

        $payload = json_encode([
            'text' => $subject,
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => ['type' => 'mrkdwn', 'text' => "*{$subject}*\n{$body}"],
                ],
            ],
        ]);

        $this->post($url, (string)$payload, ['Content-Type: application/json']);
    }

    /**
     * POSTs $payload to the webhook through the outbound HTTP client
     * (ChannelHttp): http and https only, no redirect, and an error that
     * names the status, never the URL or the answer.
     *
     * @param string[] $headers
     * @throws \app\components\OutboundHttpException when the webhook did not take it
     */
    protected function post(#[\SensitiveParameter] string $url, string $payload, array $headers): string
    {
        $response = ChannelHttp::post('SlackChannel', $url, $payload, $headers);

        \Yii::info("SlackChannel: sent to webhook — {$response->status}", __CLASS__);
        return $response->body;
    }
}
