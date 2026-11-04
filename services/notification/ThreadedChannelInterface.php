<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationTemplate;
use app\models\NotificationThread;
use app\models\WorkflowJob;

/**
 * A channel that keeps one status post per notification template and
 * workflow run (notification_thread) and posts replies in its thread, such
 * as Mattermost. The producers queue status and reply deliveries
 * (NotificationDelivery::KIND_STATUS, KIND_REPLY) only for templates of
 * such a channel (NotificationDispatcher, RunThread); the notification
 * worker sends a status with syncStatus(), a reply with reply() and any
 * other message with postMessage() (ThreadedSend). send() serves Send Test.
 */
interface ThreadedChannelInterface extends ChannelInterface
{
    /**
     * Creates the status post of $run for $template, or edits $thread's
     * post, rendered from the run's state as it is now for the user the
     * template delivers as; the delivery's ID ($deliveryId) keeps a retry
     * from posting twice. Returns the thread as it is then, saved.
     */
    public function syncStatus(NotificationTemplate $template, ?NotificationThread $thread, WorkflowJob $run, int $deliveryId): NotificationThread;

    /**
     * Posts a reply in $thread, with the mention of the template's config
     * first when $attention holds; returns the ID of the reply.
     *
     * @param array<string, string> $variables
     */
    public function reply(
        NotificationTemplate $template,
        NotificationThread $thread,
        string $subject,
        string $body,
        array $variables,
        bool $attention,
        int $deliveryId
    ): string;

    /**
     * Posts a message of its own (NotificationDelivery::KIND_MESSAGE): an
     * event outside a run's thread; the delivery's ID ($deliveryId) keeps a
     * retry from posting twice. Returns the ID of the post.
     *
     * @param array<string, string> $variables
     */
    public function postMessage(NotificationTemplate $template, string $subject, string $body, array $variables, int $deliveryId): string;
}
