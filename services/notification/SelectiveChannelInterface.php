<?php

declare(strict_types=1);

namespace app\services\notification;

/**
 * A channel that sends nothing for some events, such as PagerDuty for the
 * info events of a workflow step, which never trigger an incident
 * (PagerDutyChannel::NEVER_TRIGGERED). A channel of an installation's own
 * may implement it too (docs/notifications.md, "Channels of your own").
 *
 * For an event its channel does not send, a template is treated as one
 * that does not listen to it: the dispatcher writes no delivery and no
 * audit entry (NotificationDispatcher), a workflow run's thread gets no
 * reply of it while the run's status post stays current (RunThread), the
 * notification worker skips a delivery of it written before, such as while
 * the template had another channel (NotificationDeliveryService), and Send
 * Test sends the template's first event that its channel sends
 * (NotificationTestVariables::eventOf()); a template whose channel sends
 * none of its events fails its test with why
 * (NotificationDispatcher::SENDS_NOTHING).
 */
interface SelectiveChannelInterface extends ChannelInterface
{
    /**
     * Whether the channel sends anything for $event.
     */
    public function sendsEvent(string $event): bool;
}
