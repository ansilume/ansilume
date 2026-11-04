<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\models\NotificationTemplate;
use app\services\notification\ChannelInterface;

/**
 * A channel class of one's own, for the tests of channels configured by an
 * installation (ChannelRegistryTest): it records every message it is given.
 */
final class ConfiguredTestChannel implements ChannelInterface
{
    /** @var list<array{template: int, subject: string, body: string, variables: array<string, string>}> */
    public array $calls = [];

    public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
    {
        $this->calls[] = ['template' => (int)$template->id, 'subject' => $subject, 'body' => $body, 'variables' => $variables];
    }
}
