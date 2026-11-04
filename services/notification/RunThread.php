<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationDelivery;
use app\models\NotificationTemplate;

/**
 * What the thread of a workflow run gets on a channel that keeps a status
 * post per run (ThreadedChannelInterface), for an event of the run whose
 * workflow the template's identity may see (spec answers 1 and 2):
 * - a template that subscribes to the event, at a level that lets it
 *   through, on a channel that sends something for it (EventSubscription),
 *   gets a status delivery (its first one creates the post) and, for an
 *   event of REPLY_EVENTS or a job or approval event of the run, a reply in
 *   the post's thread;
 * - a template that does not, but whose post of the run exists or is on
 *   its way, gets a status delivery only, so that the post never stays
 *   behind the run: its events and its level decide which replies it gets,
 *   never whether its post stays current.
 * The status delivery comes first: a delivery waits for the earlier ones of
 * its thread, so the post is there before its reply.
 *
 * A reply of ATTENTION_EVENTS starts with the template's mention: edits
 * never notify, and replies reach only the thread's followers.
 */
final class RunThread
{
    /**
     * The workflow events that get a reply: a step that started, succeeded
     * or failed, a wait for a person, the run's end. The launch only makes
     * the post.
     */
    public const REPLY_EVENTS = [
        NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED,
        NotificationTemplate::EVENT_WORKFLOW_STEP_SUCCEEDED,
        NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED,
        NotificationTemplate::EVENT_WORKFLOW_WAITING,
        NotificationTemplate::EVENT_WORKFLOW_SUCCEEDED,
        NotificationTemplate::EVENT_WORKFLOW_FAILED,
        NotificationTemplate::EVENT_WORKFLOW_CANCELED,
    ];

    /** The replies that start with the template's mention: a wait, a failed step, the run's failure or cancel. */
    public const ATTENTION_EVENTS = [
        NotificationTemplate::EVENT_WORKFLOW_WAITING,
        NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED,
        NotificationTemplate::EVENT_WORKFLOW_FAILED,
        NotificationTemplate::EVENT_WORKFLOW_CANCELED,
    ];

    /**
     * The kinds of the deliveries of $event, an event of a run, to
     * $template (see the class), sent through $channel, its channel's
     * handler, in the order they are written.
     *
     * @return list<string> NotificationDelivery::KIND_STATUS, then KIND_REPLY when it gets one
     */
    public static function kindsOf(NotificationTemplate $template, string $event, ?ChannelInterface $channel = null): array
    {
        $subscribed = EventSubscription::takes($template, $event, $channel);
        $replies = !NotificationTemplate::isWorkflowEvent($event) || in_array($event, self::REPLY_EVENTS, true);

        return $subscribed && $replies
            ? [NotificationDelivery::KIND_STATUS, NotificationDelivery::KIND_REPLY]
            : [NotificationDelivery::KIND_STATUS];
    }

    /**
     * Whether the reply to $event starts with the template's mention.
     */
    public static function needsAttention(string $event): bool
    {
        return in_array($event, self::ATTENTION_EVENTS, true);
    }
}
