<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\components\AllowlistQueueSerializer;
use app\components\RedactingRedisConnection;
use app\jobs\DeliverNotificationJob;
use app\services\notification\NotificationQueueDepth;
use app\tests\integration\RestoresComponents;
use PHPUnit\Framework\TestCase;
use yii\queue\redis\Queue as RedisQueue;

/**
 * The depth of the notification queue (NotificationQueueDepth), read from a
 * real Redis queue on a channel of the test's own, as the yii2-queue
 * driver keeps it: the messages that wait, those a worker reserved, and
 * those delayed whose time has come; a message delayed to a later time
 * does not count. A queue of another driver, a Redis that cannot be
 * reached and a key the driver did not write give null: the sweep then
 * pushes as it did without the depth.
 */
class NotificationQueueDepthTest extends TestCase
{
    use RestoresComponents;

    private RedisNotificationQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = new RedisNotificationQueue();
        $this->swapComponent('notificationQueue', $this->queue);
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        $this->queue->clear();
        parent::tearDown();
    }

    /**
     * Each kind of message as the driver keeps it, pushed and taken through
     * the driver: waiting (a list), reserved by a worker and delayed (sorted
     * sets by the time they are due).
     */
    public function testTheDepthCountsWhatAWorkerIsToTakeOrHasTaken(): void
    {
        $now = time();
        $this->assertSame(0, NotificationQueueDepth::at($now), 'an empty queue');

        $this->push(1);
        $this->push(2);
        $this->assertSame(2, NotificationQueueDepth::at($now), 'two waiting');

        $this->push(3, 3600);
        $this->assertSame(2, NotificationQueueDepth::at($now), 'a message delayed to a later time does not count');
        $this->assertSame(3, NotificationQueueDepth::at($now + 3600), 'once its time has come, it does');

        $this->assertNotNull($this->queue->reserveNext());
        $this->assertSame(2, NotificationQueueDepth::at($now), 'one waiting, one reserved by a worker');
        $this->assertSame(2, NotificationQueueDepth::at($now + 60), 'reserved while its TTR runs, and after it until a worker moves it back');

        $this->assertNotNull($this->queue->reserveNext());
        $this->assertNull($this->queue->reserveNext());
        $this->assertSame(2, NotificationQueueDepth::at($now), 'two reserved');

        $this->queue->clear();
        $this->assertSame(0, NotificationQueueDepth::at($now), 'a Redis that started empty');
    }

    /**
     * A queue of another driver has no depth that the sweep could read.
     */
    public function testAQueueOfAnotherDriverHasNoDepth(): void
    {
        $this->swapComponent('notificationQueue', new RecordingNotificationQueue());

        $this->assertNull(NotificationQueueDepth::at(time()));
    }

    /**
     * A Redis that cannot be reached, a key the driver did not write and a
     * queue that cannot be made give no depth: no error leaves, and the
     * sweep pushes as it did without it.
     */
    public function testADepthThatCannotBeReadIsNone(): void
    {
        $this->swapComponent('notificationQueue', new RedisQueue([
            'redis' => ['class' => RedactingRedisConnection::class, 'hostname' => '127.0.0.1', 'port' => 1, 'connectionTimeout' => 1],
            'channel' => 'ansilume-test-unreachable',
            'serializer' => AllowlistQueueSerializer::class,
        ]));
        $this->assertNull(NotificationQueueDepth::at(time()), 'a Redis that cannot be reached');

        $this->swapComponent('notificationQueue', $this->queue);
        $this->queue->redis->executeCommand('SET', [$this->queue->channel . '.waiting', 'not a list']);
        $this->assertNull(NotificationQueueDepth::at(time()), 'a key of another type');

        $this->swapComponent('notificationQueue', ['class' => RedisQueue::class, 'redis' => 'noSuchComponent']);
        $this->assertNull(NotificationQueueDepth::at(time()), 'a queue that cannot be made');
    }

    private function push(int $deliveryId, int $delay = 0): void
    {
        $this->queue->delay($delay)->push(new DeliverNotificationJob(['deliveryId' => $deliveryId]));
    }
}
