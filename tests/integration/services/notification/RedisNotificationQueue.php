<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\components\AllowlistQueueSerializer;
use app\jobs\DeliverNotificationJob;
use yii\queue\redis\Queue as RedisQueue;

/**
 * Test double of the notification queue (notificationQueue) on Redis, as
 * the stack runs it, on a channel of the test's own that no worker runs: its
 * messages stay in Redis, as while no notification-worker consumes them. It
 * records every delivery pushed, with its delay. reserveNext() takes the
 * next waiting message as a worker does, without running it, and clear()
 * (the driver's) empties the channel, as a Redis that started empty; call
 * clear() in tearDown(), so that no key of the test stays in Redis.
 */
final class RedisNotificationQueue extends RedisQueue
{
    /** @var list<array{deliveryId: int, delay: int}> the deliveries pushed, in order */
    public array $pushes = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config + [
            'redis' => \Yii::$app->get('redis'),
            'channel' => 'ansilume-test-notification-sweep-' . bin2hex(random_bytes(6)),
            'serializer' => AllowlistQueueSerializer::class,
            'ttr' => 120,
        ]);
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
     * How often each delivery was pushed: its ID => the pushes, by ID.
     *
     * @return array<int, int>
     */
    public function pushesPerDelivery(): array
    {
        $counts = array_count_values(array_column($this->pushes, 'deliveryId'));
        ksort($counts);

        return $counts;
    }

    /**
     * Takes the next waiting message as a worker does, without running it:
     * it is reserved until its TTR passes. Its ID; null when none waits.
     */
    public function reserveNext(): ?string
    {
        $payload = $this->reserve(0);

        return $payload === null ? null : (string)$payload[0];
    }

    /**
     * @param string $message
     * @param int $ttr
     * @param int $delay
     * @param mixed $priority
     */
    protected function pushMessage($message, $ttr, $delay, $priority): string
    {
        $job = $this->serializer->unserialize($message);
        $this->pushes[] = ['deliveryId' => $job instanceof DeliverNotificationJob ? $job->deliveryId : -1, 'delay' => (int)$delay];

        return (string)parent::pushMessage($message, $ttr, $delay, $priority);
    }
}
