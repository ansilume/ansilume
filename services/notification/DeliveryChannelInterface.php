<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationDelivery;
use app\models\NotificationTemplate;

/**
 * A channel whose message names its delivery (notification_delivery), such
 * as the webhook channel: its X-Ansilume-Delivery header carries the
 * delivery's ID, the same on every retry, and its event payload the
 * delivery's event and refs (NotificationEventPayload). The notification
 * worker sends a message (NotificationDelivery::KIND_MESSAGE) of such a
 * channel with sendDelivery() instead of send()
 * (NotificationDeliveryService); send() serves Send Test, which has no
 * delivery.
 */
interface DeliveryChannelInterface extends ChannelInterface
{
    /**
     * Sends $delivery of $template, with its subject and body as rendered
     * and its variables.
     *
     * @param array<string, string> $variables
     */
    public function sendDelivery(NotificationTemplate $template, string $subject, string $body, array $variables, NotificationDelivery $delivery): void;
}
