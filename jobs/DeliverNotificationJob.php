<?php

declare(strict_types=1);

namespace app\jobs;

use app\services\notification\NotificationDeliveryService;
use yii\base\BaseObject;
use yii\queue\JobInterface;

/**
 * The job of the notification queue (notificationQueue), which the
 * notification worker runs (worker/start --queue=notificationQueue): it
 * delivers one row of the notification outbox
 * (NotificationDeliveryService::deliver()).
 *
 * It carries the row's ID only: what is sent stays in the database, and a
 * message in Redis holds no part of it, also of a channel of one's own,
 * which so never enters the allowlist of the queue's serializer
 * (AllowlistQueueSerializer). deliver() keeps every error of a channel
 * from the job, so that the queue's log behaviour (QueueLogBehavior) never
 * logs one; an error of the delivery's own bookkeeping, such as a
 * database that went away, reaches the queue, which logs it as
 * ExceptionLogText gives it, and the maintenance run delivers the row
 * later (NotificationDeliveryService::sweep()).
 */
final class DeliverNotificationJob extends BaseObject implements JobInterface
{
    /** The ID of the notification delivery (notification_delivery.id). */
    public int $deliveryId = 0;

    public function execute($queue): void
    {
        /** @var NotificationDeliveryService $service */
        $service = \Yii::$app->get('notificationDeliveryService');
        $service->deliver($this->deliveryId);
    }
}
