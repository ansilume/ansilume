<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationDelivery;
use app\models\NotificationTemplate;

/**
 * The sweep of the notification outbox, which the maintenance run starts
 * every minute (NotificationDeliveryService::sweep()). It makes delivery
 * durable without Redis, whose messages are lost when it starts empty, and
 * without the notification worker, which can stop while it sends:
 *
 * 1. A delivery a worker claimed more than STALE_SENDING_SECONDS ago (the
 *    queue's TTR and a margin) and did not finish lost its worker: it is
 *    pending again, due now, the lost attempt counted (WORKER_STOPPED), and
 *    pushed; on its last attempt it fails, as a delivery that stops every
 *    worker it reaches would stop the next one too.
 * 2. A pending delivery that was due more than REPUSH_AFTER_SECONDS ago,
 *    while the notification queue holds no message for a worker (none
 *    waiting, reserved, or delayed and due: NotificationQueueDepth), lost
 *    its message (Redis started empty, the push failed, or an old worker of
 *    an update dropped it): it is pushed again, and marked as due now, so
 *    that it is pushed again only once REPUSH_AFTER_SECONDS more have
 *    passed. While the queue holds such a message, the worker is behind or
 *    does not run (stopped, restarting, missing from an older compose
 *    file): a message pushed then would wait behind the others, beside the
 *    one the delivery may still have, so none is pushed, and however long
 *    no worker takes them, a delivery gets at most one more message. The
 *    depth is read once, before this run pushes anything; a queue whose
 *    depth cannot be read (another driver, a Redis that cannot be read) is
 *    pushed to as an empty one. A message that is merely late is not
 *    pushed twice, and the claim makes a second message harmless.
 * 3. A delivery that finished more than RETENTION_SECONDS (30 days) ago is
 *    deleted.
 *
 * Each step works in batches of SWEEP_BATCH_SIZE and starts a further
 * batch only while the deadline has not passed (the time budget the
 * maintenance run shares among its tasks); its first batch always runs,
 * so that a tick whose budget the other tasks used up still makes
 * progress.
 */
final class DeliverySweep
{
    public function __construct(private readonly NotificationDeliveryService $service)
    {
    }

    /**
     * Runs the three steps at $now, until $deadline (microtime(true); null:
     * no bound).
     *
     * @return array{repushed: int, reset: int, purged: int} the deliveries
     *     pushed again, given back (or failed) after their worker stopped, and
     *     deleted
     */
    public function run(int $now, ?float $deadline): array
    {
        $messagesLost = self::messagesLost($now);
        $reset = $this->resetStale($now, $deadline);

        return [
            'repushed' => $messagesLost ? $this->repushOverdue($now, $deadline) : 0,
            'reset' => $reset,
            'purged' => $this->purgeFinished($now, $deadline),
        ];
    }

    /**
     * Whether an overdue pending delivery lost its message at $now: the
     * notification queue holds no message for a worker, or its depth cannot
     * be read (see the class).
     */
    private static function messagesLost(int $now): bool
    {
        return (NotificationQueueDepth::at($now) ?? 0) === 0;
    }

    private function resetStale(int $now, ?float $deadline): int
    {
        $count = 0;
        $afterId = 0;
        do {
            $rows = DeliveryRows::stale($now - NotificationDeliveryService::STALE_SENDING_SECONDS, $afterId, NotificationDeliveryService::SWEEP_BATCH_SIZE);
            foreach ($rows as $row) {
                $afterId = (int)$row->id;
                $count += $this->reset($row, $now) ? 1 : 0;
            }
        } while (self::goesOn(count($rows), $deadline));

        return $count;
    }

    /**
     * Gives back $row, whose worker stopped, or fails it on its last
     * attempt. Whether it changed it.
     */
    private function reset(NotificationDelivery $row, int $now): bool
    {
        $template = $row->template;
        if (!$template instanceof NotificationTemplate) {
            return false;
        }
        $attempts = (int)$row->attempts + 1;
        $claimedAt = (int)$row->claimed_at;
        $changes = ['attempts' => $attempts, 'last_error' => NotificationDeliveryService::WORKER_STOPPED];
        if ($attempts >= $this->service->maxAttempts) {
            $failed = DeliveryRows::finish($row, $claimedAt, NotificationDelivery::STATUS_FAILED, $changes, $now);
            if ($failed) {
                DeliveryAudit::failed($row, $template, $attempts, NotificationDeliveryService::WORKER_STOPPED);
            }

            return $failed;
        }
        if (!DeliveryRows::release($row, $claimedAt, $now, $changes, $now)) {
            return false;
        }
        \Yii::warning(
            sprintf(NotificationDeliveryService::WORKER_STOPPED_LOGGED, (int)$row->id, (int)$template->id, $now - $claimedAt, $attempts, $this->service->maxAttempts),
            NotificationDeliveryService::class
        );
        $this->service->enqueuePush((int)$row->id);

        return true;
    }

    private function repushOverdue(int $now, ?float $deadline): int
    {
        $count = 0;
        $afterId = 0;
        do {
            $rows = DeliveryRows::overdue($now - NotificationDeliveryService::REPUSH_AFTER_SECONDS, $afterId, NotificationDeliveryService::SWEEP_BATCH_SIZE);
            foreach ($rows as $row) {
                $afterId = $row['id'];
                if (DeliveryRows::touch($row['id'], $row['due'], $now) && $this->service->enqueuePush($row['id'])) {
                    $count++;
                }
            }
        } while (self::goesOn(count($rows), $deadline));

        return $count;
    }

    private function purgeFinished(int $now, ?float $deadline): int
    {
        $count = 0;
        do {
            $deleted = DeliveryRows::purge($now - NotificationDeliveryService::RETENTION_SECONDS, NotificationDeliveryService::SWEEP_BATCH_SIZE);
            $count += $deleted;
        } while (self::goesOn($deleted, $deadline));

        return $count;
    }

    /**
     * Whether a step goes on with a further batch after one that handled
     * $handled rows: only after a full batch, and while $deadline has not
     * passed.
     */
    private static function goesOn(int $handled, ?float $deadline): bool
    {
        return $handled === NotificationDeliveryService::SWEEP_BATCH_SIZE && ($deadline === null || microtime(true) < $deadline);
    }
}
