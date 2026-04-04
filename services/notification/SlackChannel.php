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
     * @param string[] $headers
     */
    protected function post(string $url, string $payload, array $headers): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('SlackChannel: failed to init curl');
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
            throw new \RuntimeException("SlackChannel: HTTP {$code} — " . substr((string)$result, 0, 200));
        }

        \Yii::info("SlackChannel: sent to webhook — {$code}", __CLASS__);
        return (string)$result;
    }
}
