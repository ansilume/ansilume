<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\services\NotificationDispatcher;
use app\services\notification\NotificationRefs;

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

    /**
     * The refs of each event $event dispatched, in order, as the dispatcher
     * takes them from the payload (NotificationRefs::of()).
     *
     * @return list<array{workflow_job_id?: int, job_id?: int, approval_request_id?: int, project_id?: int, job_template_id?: int}>
     */
    public function refsOf(string $event): array
    {
        return array_map(
            static fn (array $payload): array => NotificationRefs::of($payload[NotificationDispatcher::REFS] ?? null),
            $this->payloadsOf($event)
        );
    }
}
