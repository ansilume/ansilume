<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\services\NotificationDispatcher;

/**
 * Test double: records every dispatched event with its payload instead of
 * rendering and sending notifications.
 */
class RecordingNotificationDispatcher extends NotificationDispatcher
{
    /** @var list<array{event: string, payload: array<string, mixed>}> */
    public array $events = [];

    public function dispatch(string $event, array $payload = []): void
    {
        $this->events[] = ['event' => $event, 'payload' => $payload];
    }

    /**
     * The payloads dispatched for $event, in order.
     *
     * @return list<array<string, mixed>>
     */
    public function payloadsOf(string $event): array
    {
        $payloads = [];
        foreach ($this->events as $entry) {
            if ($entry['event'] === $event) {
                $payloads[] = $entry['payload'];
            }
        }

        return $payloads;
    }
}
