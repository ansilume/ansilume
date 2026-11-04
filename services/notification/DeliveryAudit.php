<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\AuditLog;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\services\AuditService;

/**
 * The audit entry and the log line of a notification delivery's final
 * outcome, one each, never one per attempt (NotificationDeliveryService,
 * DeliverySweep):
 * - delivered: notification.dispatched, with the subject sent (cut to
 *   SUBJECT_LENGTH characters, as the dispatcher cuts it);
 * - failed: notification.failed, with the error as ErrorText gave it, and
 *   the FAILED line in the log;
 * - skipped: notification.skipped, with the reason, and the NOT_SENT line;
 * - superseded: none: a later status of the same thread replaced it.
 *
 * An entry is about the notification template, carries no user (the
 * worker acts for nobody), and says the template, channel and event, the
 * delivery and its kind, and the attempts made.
 */
final class DeliveryAudit
{
    /** The most characters of the subject a notification.dispatched entry keeps. */
    public const SUBJECT_LENGTH = 200;

    /**
     * The entry and the log line of $outcome, the final outcome of
     * $delivery of $template after $attempts attempts.
     */
    public static function record(NotificationDelivery $delivery, NotificationTemplate $template, int $attempts, DeliveryOutcome $outcome): void
    {
        switch ($outcome->kind) {
            case DeliveryOutcome::DELIVERED:
                $sent = $outcome->subject === '' ? [] : ['subject' => mb_substr($outcome->subject, 0, self::SUBJECT_LENGTH)];
                self::entry(AuditLog::ACTION_NOTIFICATION_DISPATCHED, $delivery, $template, $attempts, $sent);

                return;
            case DeliveryOutcome::SKIPPED:
                self::entry(AuditLog::ACTION_NOTIFICATION_SKIPPED, $delivery, $template, $attempts, ['reason' => $outcome->error]);
                \Yii::info(
                    sprintf(NotificationDeliveryService::NOT_SENT, (int)$delivery->id, (int)$template->id, $delivery->event, $outcome->error),
                    NotificationDeliveryService::class
                );

                return;
            case DeliveryOutcome::SUPERSEDED:
                return;
            default:
                self::failed($delivery, $template, $attempts, $outcome->error);
        }
    }

    /**
     * The notification.failed entry of $delivery of $template, failed after
     * $attempts attempts with $error, and the FAILED line in the log.
     */
    public static function failed(NotificationDelivery $delivery, NotificationTemplate $template, int $attempts, string $error): void
    {
        self::entry(AuditLog::ACTION_NOTIFICATION_FAILED, $delivery, $template, $attempts, ['error' => $error]);
        \Yii::error(
            sprintf(NotificationDeliveryService::FAILED, (int)$delivery->id, (int)$template->id, $template->channel, $delivery->event, $attempts, $error),
            NotificationDeliveryService::class
        );
    }

    /**
     * @param array<string, string> $context what the outcome adds
     */
    private static function entry(string $action, NotificationDelivery $delivery, NotificationTemplate $template, int $attempts, array $context): void
    {
        /** @var AuditService $audit */
        $audit = \Yii::$app->get('auditService');
        $audit->log($action, 'notification_template', (int)$template->id, null, [
            'template' => $template->name,
            'channel' => $template->channel,
            'event' => $delivery->event,
            'delivery_id' => (int)$delivery->id,
            'kind' => $delivery->kind,
            'attempts' => $attempts,
        ] + $context);
    }
}
