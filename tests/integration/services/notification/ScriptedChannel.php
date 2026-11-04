<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\models\NotificationTemplate;
use app\services\notification\ChannelInterface;

/**
 * Test double of a notification channel: it records every message it is
 * given, runs $during inside each send, and then does what the next entry
 * of $script says: throw that error, or (null, or a script used up) take
 * the message.
 */
final class ScriptedChannel implements ChannelInterface
{
    /** @var list<array{template: int, subject: string, body: string, variables: array<string, string>}> */
    public array $calls = [];

    /** @var list<\Throwable|null> */
    public array $script = [];

    /** @var (\Closure(): void)|null */
    public ?\Closure $during = null;

    public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
    {
        $this->calls[] = ['template' => (int)$template->id, 'subject' => $subject, 'body' => $body, 'variables' => $variables];
        if ($this->during !== null) {
            ($this->during)();
        }
        $next = array_shift($this->script);
        if ($next instanceof \Throwable) {
            throw $next;
        }
    }
}
