<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationDelivery;
use app\models\NotificationTemplate;

/**
 * Writes the notification outbox (notification_delivery): one pending
 * delivery of an event to a template, due now, which the notification
 * worker delivers (NotificationDeliveryService). The dispatcher decides
 * which templates get one and pushes each to the notification queue.
 *
 * A delivery keeps the event's variables as they were when it happened,
 * flat strings that the producers chose (no extra vars, survey answers,
 * credentials, tokens or job output), cut to fit their column (snapshot()),
 * and its refs, the objects it is about (NotificationRefs), which the worker
 * checks again before it sends.
 *
 * Some events are throttled (THROTTLE_SECONDS): a template gets at most one
 * delivery of such an event within the seconds given; enqueueThrottled()
 * decides that under the lock of the template's row, so that two calls at
 * the same time cannot both find none and both write one. The lock is the
 * only one it takes, and it takes it first.
 */
final class NotificationOutbox
{
    /**
     * The events a template gets at most one delivery of within the seconds
     * given: webhook.invalid_token, which every call with an unknown trigger
     * token causes, so that a caller cannot flood the channels with it.
     */
    public const THROTTLE_SECONDS = [NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN => 300];

    /**
     * The note that the message of a throttled event carries (the variable
     * trigger.note of webhook.invalid_token): further calls are in the audit
     * log (AuditLog::ACTION_NOTIFICATION_THROTTLED). sprintf(): the minutes
     * of the throttle.
     */
    public const THROTTLE_NOTE = 'A template gets this notification at most once every %d minutes; every further call with an unknown trigger token is in the audit log as notification.throttled.';

    /** The most bytes the JSON of a delivery's variables may take: its TEXT column holds 65535. */
    public const VARIABLES_MAX_BYTES = 65535;

    /** The most bytes a variable keeps; snapshot() cuts every value shorter while they do not fit together. */
    public const VALUE_MAX_BYTES = 8192;

    /** The fewest bytes snapshot() cuts a value to. */
    public const VALUE_MIN_BYTES = 64;

    /** What ends a value that snapshot() cut. */
    public const CUT = '…';

    /** A delivery that the database did not take (an insert that a handler refused). */
    public const NOT_WRITTEN = 'NotificationOutbox: the delivery was not written.';

    /** The lock of the template's row that the throttle decides under. */
    private const LOCK_SQL = 'SELECT [[id]] FROM {{%notification_template}} WHERE [[id]] = :id FOR UPDATE';

    /** The newest delivery of the event to the template created after :since. */
    private const RECENT_SQL = 'SELECT [[id]] FROM {{%notification_delivery}} WHERE [[notification_template_id]] = :template'
        . ' AND [[event]] = :event AND [[created_at]] > :since ORDER BY [[id]] DESC LIMIT 1';

    /**
     * How the throttle reads RECENT_SQL: a locking read, so that it sees the
     * deliveries other transactions committed while this one waited for the
     * template's lock, whatever its snapshot is.
     */
    private const LOCKING_READ = ' LOCK IN SHARE MODE';

    /** How the JSON of the variables is measured: as NotificationDelivery::setVariableMap() writes it. */
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * Writes a pending delivery of $event to $template, due now, of $kind
     * (NotificationDelivery::KINDS), with $variables (snapshot()) and $refs.
     *
     * @param array<string, string> $variables
     * @param array{workflow_job_id?: int, job_id?: int, approval_request_id?: int, project_id?: int, job_template_id?: int} $refs
     * @throws \RuntimeException (NOT_WRITTEN) when the row was not written; a database error as it is
     */
    public static function enqueue(
        NotificationTemplate $template,
        string $event,
        array $variables,
        array $refs,
        string $kind = NotificationDelivery::KIND_MESSAGE
    ): NotificationDelivery {
        $delivery = new NotificationDelivery();
        $delivery->notification_template_id = (int)$template->id;
        $delivery->event = $event;
        $delivery->kind = $kind;
        $delivery->status = NotificationDelivery::STATUS_PENDING;
        $delivery->attempts = 0;
        $delivery->next_attempt_at = time();
        $delivery->setVariableMap(self::snapshot($variables));
        $delivery->setRefs($refs);
        if (!$delivery->save(false)) {
            throw new \RuntimeException(self::NOT_WRITTEN);
        }

        return $delivery;
    }

    /**
     * enqueue() a message, unless $template has a delivery of $event created
     * within the last $seconds: then nothing is written, and null is the
     * answer. Decided under the lock of the template's row (see the class);
     * a template deleted meanwhile gets nothing either.
     *
     * @param array<string, string> $variables
     * @param array{workflow_job_id?: int, job_id?: int, approval_request_id?: int, project_id?: int, job_template_id?: int} $refs
     */
    public static function enqueueThrottled(
        NotificationTemplate $template,
        string $event,
        array $variables,
        array $refs,
        int $seconds
    ): ?NotificationDelivery {
        $db = NotificationDelivery::getDb();

        /** @var NotificationDelivery|null $delivery */
        $delivery = $db->transaction(static function () use ($db, $template, $event, $variables, $refs, $seconds): ?NotificationDelivery {
            $id = (int)$template->id;
            if ($db->createCommand(self::LOCK_SQL, [':id' => $id])->queryScalar() === false) {
                return null;
            }
            $recent = self::recentDeliveryId($template, $event, $seconds, true);

            return $recent === null ? self::enqueue($template, $event, $variables, $refs) : null;
        });

        return $delivery;
    }

    /**
     * The ID of the newest delivery of $event to $template created within
     * the last $seconds; null when there is none. $lock reads it as the
     * throttle does (LOCKING_READ), inside the caller's transaction.
     */
    public static function recentDeliveryId(NotificationTemplate $template, string $event, int $seconds, bool $lock = false): ?int
    {
        $id = NotificationDelivery::getDb()->createCommand(
            self::RECENT_SQL . ($lock ? self::LOCKING_READ : ''),
            [':template' => (int)$template->id, ':event' => $event, ':since' => time() - $seconds]
        )->queryScalar();

        return $id === false || $id === null ? null : (int)$id;
    }

    /**
     * $variables as a delivery keeps them: each value valid UTF-8 (a byte
     * that is none becomes '?'), and cut (CUT ends it, between characters)
     * to VALUE_MAX_BYTES, or shorter, halving the limit down to
     * VALUE_MIN_BYTES, until their JSON fits VARIABLES_MAX_BYTES.
     *
     * @param array<string, string> $variables
     * @return array<string, string>
     */
    public static function snapshot(array $variables): array
    {
        $limit = self::VALUE_MAX_BYTES;
        $snapshot = self::cutTo($variables, $limit);
        while ($limit > self::VALUE_MIN_BYTES && strlen((string)json_encode((object)$snapshot, self::JSON_FLAGS)) > self::VARIABLES_MAX_BYTES) {
            $limit = max(self::VALUE_MIN_BYTES, intdiv($limit, 2));
            $snapshot = self::cutTo($variables, $limit);
        }

        return $snapshot;
    }

    /**
     * @param array<string, string> $variables
     * @return array<string, string> every value of $variables valid UTF-8 and at most $limit bytes
     */
    private static function cutTo(array $variables, int $limit): array
    {
        $cut = [];
        foreach ($variables as $name => $value) {
            $text = mb_scrub($value, 'UTF-8');
            $cut[$name] = strlen($text) <= $limit
                ? $text
                : mb_strcut($text, 0, $limit - strlen(self::CUT), 'UTF-8') . self::CUT;
        }

        return $cut;
    }
}
