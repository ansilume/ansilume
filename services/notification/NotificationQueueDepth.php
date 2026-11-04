<?php

declare(strict_types=1);

namespace app\services\notification;

use yii\queue\redis\Queue as RedisQueue;
use yii\redis\Connection;

/**
 * How many messages the notification queue (the component
 * notificationQueue) holds for a worker: those that wait, those a worker
 * reserved (it sends them now, or it stopped before it finished them), and
 * those delayed whose time has come. The sweep pushes a lost delivery again
 * only while there are none (DeliverySweep): while there are, the worker is
 * behind or does not run. A message delayed to a later time, the retry of a
 * delivery after its backoff or a delivery that waits for an earlier one of
 * its run, says nothing of the worker, and does not count.
 *
 * It reads a yii\queue\redis\Queue from its Redis connection with the
 * driver's own keys, which its InfoAction counts the same way: the list
 * <channel>.waiting, and the sorted sets <channel>.reserved and
 * <channel>.delayed, whose scores are the times their messages are due. A
 * queue of another driver, a queue that cannot be made and a Redis that
 * cannot be read have no depth (null): the sweep then pushes as it does
 * for an empty queue.
 */
final class NotificationQueueDepth
{
    /**
     * The messages of the notification queue for a worker at $now (see the
     * class); null when they cannot be read.
     */
    public static function at(int $now): ?int
    {
        try {
            $queue = \Yii::$app->get('notificationQueue');

            return $queue instanceof RedisQueue && $queue->redis instanceof Connection
                ? self::count($queue->redis, $queue->channel, $now)
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The messages on $channel of $redis that wait, are reserved, or are
     * delayed to $now or before.
     */
    private static function count(Connection $redis, string $channel, int $now): int
    {
        return (int)$redis->executeCommand('LLEN', [$channel . '.waiting'])
            + (int)$redis->executeCommand('ZCARD', [$channel . '.reserved'])
            + (int)$redis->executeCommand('ZCOUNT', [$channel . '.delayed', '-inf', (string)$now]);
    }
}
