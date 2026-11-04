<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\components\AllowlistQueueSerializer;
use app\jobs\DeliverNotificationJob;
use yii\queue\sync\Queue as SyncQueue;

/**
 * Test double of the notification queue (notificationQueue): it records
 * every delivery pushed, with its delay, and runs the jobs pushed without
 * a delay when the test calls run(), as the notification worker would; a
 * job pushed with a delay is recorded only, as Redis holds it back until
 * its delay passed. failWith makes every push throw, as a Redis that
 * cannot be reached does.
 */
final class RecordingNotificationQueue extends SyncQueue
{
    /** @var list<array{deliveryId: int, delay: int}> the deliveries pushed, in order */
    public array $pushes = [];

    /** An error each push throws; null: every push is taken. */
    public ?\Throwable $failWith = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config + ['serializer' => AllowlistQueueSerializer::class, 'ttr' => 120]);
    }

    /**
     * The IDs of the deliveries pushed, in order.
     *
     * @return list<int>
     */
    public function pushedIds(): array
    {
        return array_column($this->pushes, 'deliveryId');
    }

    /**
     * @param string $message
     * @param int $ttr
     * @param int $delay
     * @param mixed $priority
     */
    protected function pushMessage($message, $ttr, $delay, $priority): string
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $job = $this->serializer->unserialize($message);
        $this->pushes[] = ['deliveryId' => $job instanceof DeliverNotificationJob ? $job->deliveryId : -1, 'delay' => (int)$delay];
        if ((int)$delay > 0) {
            return 'delayed-' . count($this->pushes);
        }

        return (string)parent::pushMessage($message, $ttr, $delay, $priority);
    }
}
