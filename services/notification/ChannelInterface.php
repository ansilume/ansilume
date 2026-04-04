<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationTemplate;

/**
 * Contract for notification delivery channels.
 */
interface ChannelInterface
{
    /**
     * Send a notification through this channel.
     *
     * @param array<string, string> $variables Rendered template variables
     */
    public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void;
}
