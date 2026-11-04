<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\models\NotificationTemplate;
use app\models\NotificationThread;
use app\models\WorkflowJob;
use app\services\notification\ThreadedChannelInterface;

/**
 * Test double of a channel that keeps a status post per workflow run
 * (ThreadedChannelInterface), for the tests of what the producers queue
 * for such a channel; they send nothing through it, so each of its
 * methods fails the test that calls it.
 */
final class ThreadedTestChannel implements ThreadedChannelInterface
{
    public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
    {
        throw new \LogicException('ThreadedTestChannel sends nothing.');
    }

    public function syncStatus(NotificationTemplate $template, ?NotificationThread $thread, WorkflowJob $run, int $deliveryId): NotificationThread
    {
        throw new \LogicException('ThreadedTestChannel keeps no status post.');
    }

    public function reply(
        NotificationTemplate $template,
        NotificationThread $thread,
        string $subject,
        string $body,
        array $variables,
        bool $attention,
        int $deliveryId
    ): string {
        throw new \LogicException('ThreadedTestChannel posts no reply.');
    }

    public function postMessage(NotificationTemplate $template, string $subject, string $body, array $variables, int $deliveryId): string
    {
        throw new \LogicException('ThreadedTestChannel posts no message.');
    }
}
