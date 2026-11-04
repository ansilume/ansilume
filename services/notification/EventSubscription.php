<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationTemplate;

/**
 * Whether a notification template takes an event as one it subscribes to:
 * it listens to the event (its events), its level lets the event through
 * (NotificationTemplate::isWithinLevel()), and its channel sends something
 * for it (SelectiveChannelInterface). The dispatcher writes a template's
 * delivery of an event, and a run's thread its reply, only for an event it
 * takes; one it does not take is as an event it does not listen to.
 */
final class EventSubscription
{
    /**
     * Whether $template takes $event, sent through $channel, its channel's
     * handler (null: none).
     */
    public static function takes(NotificationTemplate $template, string $event, ?ChannelInterface $channel): bool
    {
        return $template->listensTo($event) && $template->isWithinLevel($event) && self::sends($channel, $event);
    }

    /**
     * Whether $channel sends anything for $event: every channel does, but
     * one that says it does not (SelectiveChannelInterface).
     */
    public static function sends(?ChannelInterface $channel, string $event): bool
    {
        return !$channel instanceof SelectiveChannelInterface || $channel->sendsEvent($event);
    }
}
