<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationDelivery;
use app\models\NotificationThread;

/**
 * How the notification worker and the sweep change the rows of the
 * notification outbox (notification_delivery), and the queries that decide
 * it (NotificationDeliveryService, DeliverySweep).
 *
 * Each change is one conditional UPDATE, so that of two workers, or of a
 * worker and the sweep, only one changes a row. A worker owns a delivery
 * from its claim (pending and due becomes sending, claimed_at the time of
 * the claim) until it gives it back (release()) or finishes it (finish()),
 * each of which names the row as sending with that claimed_at: a worker
 * whose delivery the sweep gave back meanwhile, because it took longer than
 * NotificationDeliveryService::STALE_SENDING_SECONDS, changes nothing. The
 * sweep owns a stale row by the same two values.
 */
final class DeliveryRows
{
    /** The statuses of a delivery that is not finished. */
    public const UNFINISHED = [NotificationDelivery::STATUS_PENDING, NotificationDelivery::STATUS_SENDING];

    /**
     * Claims delivery $id at $now: a pending delivery that is due becomes
     * sending, claimed at $now. Whether this call claimed it: false for a
     * delivery another worker has, one finished, one not due yet, and one
     * that does not exist.
     */
    public static function claim(int $id, int $now): bool
    {
        return NotificationDelivery::updateAll(
            ['status' => NotificationDelivery::STATUS_SENDING, 'claimed_at' => $now, 'updated_at' => $now],
            ['and', ['id' => $id, 'status' => NotificationDelivery::STATUS_PENDING], ['<=', 'next_attempt_at', $now]]
        ) === 1;
    }

    /**
     * Gives $delivery, claimed at $claimedAt, back to the queue: pending
     * again, not claimed, due at $dueAt, with $changes (attempts,
     * last_error). Whether it was still owned.
     *
     * @param array<string, int|string|null> $changes
     */
    public static function release(NotificationDelivery $delivery, int $claimedAt, int $dueAt, array $changes, int $now): bool
    {
        return NotificationDelivery::updateAll(
            ['status' => NotificationDelivery::STATUS_PENDING, 'claimed_at' => null, 'next_attempt_at' => $dueAt, 'updated_at' => $now] + $changes,
            self::owned($delivery, $claimedAt)
        ) === 1;
    }

    /**
     * Finishes $delivery, claimed at $claimedAt, with $status (one of
     * NotificationDelivery::FINISHED_STATUSES) at $now, with $changes
     * (attempts, last_error, remote_id). Whether it was still owned.
     *
     * @param array<string, int|string|null> $changes
     */
    public static function finish(NotificationDelivery $delivery, int $claimedAt, string $status, array $changes, int $now): bool
    {
        return NotificationDelivery::updateAll(
            ['status' => $status, 'finished_at' => $now, 'updated_at' => $now] + $changes,
            self::owned($delivery, $claimedAt)
        ) === 1;
    }

    /**
     * The earliest time an unfinished delivery of $delivery's thread (the
     * same template and run) that came before it is due; null when there
     * is none, or $delivery belongs to no run.
     */
    public static function earliestBefore(NotificationDelivery $delivery): ?int
    {
        if ($delivery->workflow_job_id === null) {
            return null;
        }
        $due = NotificationDelivery::find()
            ->select('next_attempt_at')
            ->where(self::threadOf($delivery) + ['status' => self::UNFINISHED])
            ->andWhere(['<', 'id', $delivery->id])
            ->orderBy(['next_attempt_at' => SORT_ASC])
            ->limit(1)
            ->scalar();

        return $due === false || $due === null ? null : (int)$due;
    }

    /**
     * Whether $delivery is a status of a run whose post exists
     * (notification_thread) and a later status of the same thread is not
     * finished: that one renders the run as it is then, so this one need
     * not be sent.
     */
    public static function isSuperseded(NotificationDelivery $delivery): bool
    {
        if ($delivery->kind !== NotificationDelivery::KIND_STATUS || $delivery->workflow_job_id === null) {
            return false;
        }
        $thread = self::threadOf($delivery);

        return NotificationThread::find()->where($thread)->exists()
            && NotificationDelivery::find()
                ->where($thread + ['kind' => NotificationDelivery::KIND_STATUS, 'status' => self::UNFINISHED])
                ->andWhere(['>', 'id', $delivery->id])
                ->exists();
    }

    /**
     * Up to $limit deliveries after ID $afterId, by ID, that a worker claimed
     * before $claimedBefore and did not finish.
     *
     * @return list<NotificationDelivery>
     */
    public static function stale(int $claimedBefore, int $afterId, int $limit): array
    {
        /** @var list<NotificationDelivery> $rows */
        $rows = NotificationDelivery::find()
            ->where(['status' => NotificationDelivery::STATUS_SENDING])
            ->andWhere(['<', 'claimed_at', $claimedBefore])
            ->andWhere(['>', 'id', $afterId])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit)
            ->all();

        return $rows;
    }

    /**
     * Up to $limit pending deliveries after ID $afterId, by ID, that were
     * due at $dueBefore or before: their ID and when they were due.
     *
     * @return list<array{id: int, due: int}>
     */
    public static function overdue(int $dueBefore, int $afterId, int $limit): array
    {
        $rows = NotificationDelivery::find()
            ->select(['id', 'next_attempt_at'])
            ->where(['status' => NotificationDelivery::STATUS_PENDING])
            ->andWhere(['<=', 'next_attempt_at', $dueBefore])
            ->andWhere(['>', 'id', $afterId])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit)
            ->asArray()
            ->all();

        return array_values(array_map(
            static fn (array $row): array => ['id' => (int)$row['id'], 'due' => (int)$row['next_attempt_at']],
            $rows
        ));
    }

    /**
     * Marks pending delivery $id, which was due at $due, as pushed again at
     * $now: due now, so that it counts as overdue again only once the time
     * of the next push came. Whether it was still pending and due then.
     */
    public static function touch(int $id, int $due, int $now): bool
    {
        return NotificationDelivery::updateAll(
            ['next_attempt_at' => $now, 'updated_at' => $now],
            ['id' => $id, 'status' => NotificationDelivery::STATUS_PENDING, 'next_attempt_at' => $due]
        ) === 1;
    }

    /**
     * Deletes up to $limit deliveries that finished before $finishedBefore,
     * the oldest first; the number deleted.
     */
    public static function purge(int $finishedBefore, int $limit): int
    {
        $ids = NotificationDelivery::find()
            ->select('id')
            ->where(['status' => NotificationDelivery::FINISHED_STATUSES])
            ->andWhere(['<', 'finished_at', $finishedBefore])
            ->orderBy(['finished_at' => SORT_ASC, 'id' => SORT_ASC])
            ->limit($limit)
            ->column();

        return $ids === [] ? 0 : NotificationDelivery::deleteAll(['id' => $ids]);
    }

    /**
     * The condition of $delivery as long as the worker or sweep that
     * claimed it at $claimedAt owns it.
     *
     * @return array<string, int|string>
     */
    private static function owned(NotificationDelivery $delivery, int $claimedAt): array
    {
        return ['id' => (int)$delivery->id, 'status' => NotificationDelivery::STATUS_SENDING, 'claimed_at' => $claimedAt];
    }

    /**
     * The condition of the thread of $delivery: its template and run.
     *
     * @return array<string, int|null>
     */
    private static function threadOf(NotificationDelivery $delivery): array
    {
        return [
            'notification_template_id' => (int)$delivery->notification_template_id,
            'workflow_job_id' => $delivery->workflow_job_id === null ? null : (int)$delivery->workflow_job_id,
        ];
    }
}
