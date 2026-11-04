<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\Job;
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

    /**
     * The colour of the card for the job's status (Job::statuses()): green
     * for a success, red for a failure, blue while it runs, grey otherwise.
     * Up to 2.14.0 green went with "successful", a status no job has.
     */
    private function themeColor(string $status): string
    {
        return match ($status) {
            Job::STATUS_SUCCEEDED => '2DC72D',
            Job::STATUS_FAILED, Job::STATUS_TIMED_OUT => 'FF0000',
            Job::STATUS_RUNNING => '0078D7',
            default => '808080',
        };
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
        $response = ChannelHttp::post('TeamsChannel', $url, $payload, $headers);

        \Yii::info("TeamsChannel: sent to webhook — {$response->status}", __CLASS__);
        return $response->body;
    }
}
