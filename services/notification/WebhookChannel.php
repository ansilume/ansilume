<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationTemplate;

/**
 * Sends notifications via a generic HTTP webhook.
 *
 * Config: {"url": "https://example.com/hook", "headers": {"X-Custom": "value"}}
 */
class WebhookChannel implements ChannelInterface
{
    public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
    {
        $config = $template->getParsedConfig();
        $url = (string)($config['url'] ?? '');
        if ($url === '') {
            return;
        }

        $payload = (string)json_encode([
            'subject' => $subject,
            'body' => $body,
            'variables' => $variables,
        ]);

        $headers = ['Content-Type: application/json'];
        $extra = $config['headers'] ?? [];
        if (is_array($extra)) {
            foreach ($extra as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
        }

        $this->post($url, $payload, $headers);
    }

    /**
     * @param string[] $headers
     */
    protected function post(string $url, string $payload, array $headers): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('WebhookChannel: failed to init curl');
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
            throw new \RuntimeException("WebhookChannel: HTTP {$code} — " . substr((string)$result, 0, 200));
        }

        \Yii::info("WebhookChannel: sent to {$url} — {$code}", __CLASS__);
        return (string)$result;
    }
}
