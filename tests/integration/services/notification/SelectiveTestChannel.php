<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\models\NotificationTemplate;
use app\services\notification\SelectiveChannelInterface;

/**
 * A channel of an installation's own that sends nothing for the events it
 * is made with, and records what it sends.
 */
final class SelectiveTestChannel implements SelectiveChannelInterface
{
    /** @var list<array{event: string, subject: string}> */
    public array $sent = [];

    /**
     * @param list<string> $silent the events it sends nothing for
     */
    public function __construct(private readonly array $silent)
    {
    }

    public function sendsEvent(string $event): bool
    {
        return !in_array($event, $this->silent, true);
    }

    public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
    {
        $this->sent[] = ['event' => $variables['event'] ?? '', 'subject' => $subject];
    }
}
