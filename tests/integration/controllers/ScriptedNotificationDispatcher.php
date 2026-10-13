<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\models\NotificationTemplate;
use app\services\NotificationDispatcher;

/**
 * Test double for the notification test actions: sendSingle() records its
 * call instead of sending, then answers $result (null: the channel accepted
 * the message) or throws $throwOnSend.
 */
final class ScriptedNotificationDispatcher extends NotificationDispatcher
{
    public int $sendCalls = 0;

    /** @var array<string, string> */
    public array $lastVariables = [];

    public string $lastEvent = '';

    /** What sendSingle() answers: null for a message the channel accepted. */
    public ?string $result = null;

    public ?\Throwable $throwOnSend = null;

    public function sendSingle(NotificationTemplate $nt, array $variables, string $event = 'test'): ?string
    {
        $this->sendCalls++;
        $this->lastVariables = $variables;
        $this->lastEvent = $event;
        if ($this->throwOnSend !== null) {
            throw $this->throwOnSend;
        }

        return $this->result;
    }
}
