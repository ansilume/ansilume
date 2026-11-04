<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\NotificationThread;
use app\models\WorkflowJob;

/**
 * How the notification worker sends a delivery through a channel that keeps
 * a status post per run (ThreadedChannelInterface), by its kind
 * (NotificationDeliveryService):
 * - a status (NotificationDelivery::KIND_STATUS) creates or edits the run's
 *   status post (syncStatus()), with the template's thread of the run
 *   (notification_thread), which the channel saves; its remote_id is the
 *   post;
 * - a reply (KIND_REPLY) is posted in that thread (reply()), with the
 *   template's mention first for RunThread::ATTENTION_EVENTS; it fails
 *   (NO_STATUS_POST) when the run has no status post, which the status
 *   delivery before it then failed to make;
 * - a message (KIND_MESSAGE) is a post of its own (postMessage()).
 * A channel's error is classified as every channel's (DeliveryOutcome::ofError()).
 */
final class ThreadedSend
{
    /** The error of a reply of a run without a status post. */
    public const NO_STATUS_POST = 'The run has no status post to reply to: its status delivery did not create one.';

    /** The error of a status of a run that no longer exists. */
    public const RUN_GONE = 'The run no longer exists.';

    /** The error of a status post whose thread could not be saved. */
    public const THREAD_NOT_SAVED = 'The status post was made, but its thread could not be saved.';

    /** The subject of a delivered status in its notification.dispatched entry; sprintf(): the run's ID. */
    public const STATUS_SUBJECT = 'The status post of Workflow Job #%d';

    /**
     * The outcome of sending $delivery of $template through $channel, with
     * $message, the template's subject and body as rendered for it.
     *
     * @param array{subject: string, body: string} $message
     * @param array<string, string> $variables
     */
    public static function outcome(
        ThreadedChannelInterface $channel,
        NotificationDelivery $delivery,
        NotificationTemplate $template,
        array $message,
        array $variables
    ): DeliveryOutcome {
        try {
            return match ($delivery->kind) {
                NotificationDelivery::KIND_STATUS => self::status($channel, $delivery, $template),
                NotificationDelivery::KIND_REPLY => self::reply($channel, $delivery, $template, $message, $variables),
                default => DeliveryOutcome::delivered(
                    $channel->postMessage($template, $message['subject'], $message['body'], $variables, (int)$delivery->id),
                    $message['subject']
                ),
            };
        } catch (\Throwable $e) {
            return DeliveryOutcome::ofError($e, time());
        }
    }

    private static function status(ThreadedChannelInterface $channel, NotificationDelivery $delivery, NotificationTemplate $template): DeliveryOutcome
    {
        $runId = (int)$delivery->workflow_job_id;
        $run = WorkflowJob::findOne($runId);
        if (!$run instanceof WorkflowJob) {
            return DeliveryOutcome::permanent(self::RUN_GONE);
        }
        $thread = $channel->syncStatus($template, self::threadOf($delivery), $run, (int)$delivery->id);

        return DeliveryOutcome::delivered((string)$thread->root_post_id, sprintf(self::STATUS_SUBJECT, $runId));
    }

    /**
     * @param array{subject: string, body: string} $message
     * @param array<string, string> $variables
     */
    private static function reply(
        ThreadedChannelInterface $channel,
        NotificationDelivery $delivery,
        NotificationTemplate $template,
        array $message,
        array $variables
    ): DeliveryOutcome {
        $thread = self::threadOf($delivery);
        if ($thread === null) {
            return DeliveryOutcome::permanent(self::NO_STATUS_POST);
        }
        $replyId = $channel->reply(
            $template,
            $thread,
            $message['subject'],
            $message['body'],
            $variables,
            RunThread::needsAttention((string)$delivery->event),
            (int)$delivery->id
        );

        return DeliveryOutcome::delivered($replyId, $message['subject']);
    }

    /** The thread of $delivery's template and run; null without one. */
    private static function threadOf(NotificationDelivery $delivery): ?NotificationThread
    {
        return NotificationThread::findOne([
            'notification_template_id' => (int)$delivery->notification_template_id,
            'workflow_job_id' => (int)$delivery->workflow_job_id,
        ]);
    }
}
