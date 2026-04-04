<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationTemplate;

/**
 * Sends notifications to a Microsoft Teams incoming webhook (MessageCard format).
 *
 * Config: {"webhook_url": "https://outlook.office.com/webhook/..."}
 */
class TeamsChannel implements ChannelInterface
{
    public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
    {
        $config = $template->getParsedConfig();
        $url = (string)($config['webhook_url'] ?? '');
        if ($url === '') {
            return;
        }

        $jobUrl = $variables['job.url'] ?? '';

        $card = [
            '@type' => 'MessageCard',
            '@context' => 'http://schema.org/extensions',
            'themeColor' => $this->themeColor($variables['job.status'] ?? ''),
            'summary' => $subject,
            'sections' => [
                [
                    'activityTitle' => $subject,
                    'text' => str_replace("\n", "<br>", htmlspecialchars($body, ENT_QUOTES, 'UTF-8')),
                ],
            ],
        ];

        if ($jobUrl !== '') {
            $card['potentialAction'] = [
                [
                    '@type' => 'OpenUri',
                    'name' => 'View Job',
                    'targets' => [['os' => 'default', 'uri' => $jobUrl]],
                ],
            ];
        }

        $payload = (string)json_encode($card);
        $this->post($url, $payload, ['Content-Type: application/json']);
    }

    private function themeColor(string $status): string
    {
        return match ($status) {
            'successful' => '2DC72D',
            'failed', 'error', 'timed_out' => 'FF0000',
            'running' => '0078D7',
            default => '808080',
        };
    }

    /**
     * @param string[] $headers
     */
    protected function post(string $url, string $payload, array $headers): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('TeamsChannel: failed to init curl');
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
            throw new \RuntimeException("TeamsChannel: HTTP {$code} — " . substr((string)$result, 0, 200));
        }

        \Yii::info("TeamsChannel: sent to webhook — {$code}", __CLASS__);
        return (string)$result;
    }
}
