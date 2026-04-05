<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\NotificationTemplate;
use app\services\notification\ChannelInterface;

/**
 * Test double: in-memory notification channel that records every send() call
 * so assertions can inspect templates, subjects, and variables.
 */
class RecordingChannel implements ChannelInterface
{
    /** @var array<int, array{template: NotificationTemplate, subject: string, body: string, variables: array<string, string>}> */
    public array $calls = [];

    public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
    {
        $this->calls[] = [
            'template' => $template,
            'subject' => $subject,
            'body' => $body,
            'variables' => $variables,
        ];
    }
}
