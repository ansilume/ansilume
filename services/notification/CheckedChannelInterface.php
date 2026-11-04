<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationTemplate;

/**
 * A channel whose destination Send Test checks before it sends its test
 * message (NotificationDispatcher::sendSingle()), such as Mattermost: that
 * the token is a bot's, and the bot a member of the channel. The error of a
 * check is Send Test's answer, as an error of send() is.
 */
interface CheckedChannelInterface extends ChannelInterface
{
    /**
     * Checks the destination of $template's config now, whatever was
     * checked before; throws an error that says what to fix.
     */
    public function checkDestination(NotificationTemplate $template): void;
}
