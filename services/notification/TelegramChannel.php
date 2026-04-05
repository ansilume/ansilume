<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationTemplate;

/**
 * Sends notifications to a Telegram chat via the Bot API.
 *
 * Config: {"bot_token": "123:ABC...", "chat_id": "-1001234567890"}
 *
 * Uses MarkdownV2 parse mode so both subject and body render with formatting.
 * MarkdownV2 is picky — a strict set of characters must be escaped. We escape
 * the rendered content (not the user's template string) so template authors
 * can still use plain *bold* / _italic_ where they like.
 */
class TelegramChannel implements ChannelInterface
{
    public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
    {
        $config = $template->getParsedConfig();
        $botToken = (string)($config['bot_token'] ?? '');
        $chatId = (string)($config['chat_id'] ?? '');
        if ($botToken === '' || $chatId === '') {
            return;
        }

        $text = '*' . $this->escape($subject) . "*\n" . $this->escape($body);
        $payload = (string)json_encode([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'MarkdownV2',
            'disable_web_page_preview' => true,
        ]);

        $url = 'https://api.telegram.org/bot' . $botToken . '/sendMessage';
        $this->post($url, $payload, ['Content-Type: application/json']);
    }

    /**
     * Escape every MarkdownV2 reserved character. Official list:
     * _ * [ ] ( ) ~ ` > # + - = | { } . !
     */
    private function escape(string $text): string
    {
        return (string)preg_replace('/([_*\[\]()~`>#+\-=|{}.!])/', '\\\\$1', $text);
    }

    /**
     * @param string[] $headers
     */
    protected function post(string $url, string $payload, array $headers): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('TelegramChannel: failed to init curl');
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
            throw new \RuntimeException("TelegramChannel: HTTP {$code} — " . substr((string)$result, 0, 200));
        }

        \Yii::info("TelegramChannel: sent — {$code}", __CLASS__);
        return (string)$result;
    }
}
