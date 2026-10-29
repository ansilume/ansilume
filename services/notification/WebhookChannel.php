<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationTemplate;

/**
 * Sends notifications via a generic HTTP webhook.
 *
 * Config: {"url": "https://example.com/hook", "headers": {"X-Custom": "value"}}
 *
 * The URL and the header values are channel secrets
 * (NotificationTemplate::SECRET_CONFIG_KEYS, SECRET_CONFIG_MAPS): the URL
 * often carries a token, so no log line names it.
 */
class WebhookChannel implements ChannelInterface
{
    /**
     * Logged (info) once the webhook took a notification; sprintf(): the
     * notification template's ID, then the HTTP code. As SlackChannel and
     * TeamsChannel, never the URL.
     */
    public const SENT = 'WebhookChannel: sent the notification of template #%d to its webhook — %d';

    /** The template whose notification send() is sending, for the line post() logs. */
    private int $templateId = 0;

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

        $this->templateId = (int)$template->id;
        $this->post($url, $payload, $headers);
    }

    /**
     * @param string[] $headers
     */
    protected function post(
        #[\SensitiveParameter] string $url,
        string $payload,
        #[\SensitiveParameter] array $headers
    ): string {
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

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("WebhookChannel: HTTP {$code} — " . substr((string)$result, 0, 200));
        }

        \Yii::info(sprintf(self::SENT, $this->templateId, $code), __CLASS__);
        return (string)$result;
    }
}
