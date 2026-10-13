<?php

declare(strict_types=1);

namespace app\tests\integration\services;

/**
 * Test double: dispatching one of $failingEvents throws $error (as a
 * database error while reading the notification templates would); every
 * other event is recorded.
 */
final class FailingNotificationDispatcher extends RecordingNotificationDispatcher
{
    /** @var list<string> the events whose dispatch threw, in order */
    public array $failed = [];

    /**
     * @param list<string> $failingEvents
     */
    public function __construct(private readonly array $failingEvents, private readonly \Throwable $error)
    {
        parent::__construct();
    }

    public function dispatch(string $event, array $payload = []): void
    {
        if (in_array($event, $this->failingEvents, true)) {
            $this->failed[] = $event;
            throw $this->error;
        }
        parent::dispatch($event, $payload);
    }
}
