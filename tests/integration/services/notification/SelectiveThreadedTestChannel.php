<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\models\NotificationTemplate;
use app\models\NotificationThread;
use app\models\WorkflowJob;
use app\services\notification\SelectiveChannelInterface;
use app\services\notification\ThreadedChannelInterface;

/**
 * A channel that keeps a status post per run and sends no reply for the
 * events it is made with; its status post is a thread row it saves.
 */
final class SelectiveThreadedTestChannel implements ThreadedChannelInterface, SelectiveChannelInterface
{
    /**
     * @param list<string> $silent the events it sends nothing for
     */
    public function __construct(private readonly array $silent)
    {
    }

    public function sendsEvent(string $event): bool
    {
        return !in_array($event, $this->silent, true);
    }

    public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
    {
        throw new \LogicException('SelectiveThreadedTestChannel sends no message.');
    }

    public function syncStatus(NotificationTemplate $template, ?NotificationThread $thread, WorkflowJob $run, int $deliveryId): NotificationThread
    {
        $thread ??= new NotificationThread();
        $thread->notification_template_id = (int)$template->id;
        $thread->workflow_job_id = (int)$run->id;
        $thread->root_post_id = 'selective-status-' . $deliveryId;
        $thread->channel_id = 'selective-channel';
        $thread->server = 'https://chat.example.com';
        if (!$thread->save(false)) {
            throw new \LogicException('The thread was not saved.');
        }

        return $thread;
    }

    public function reply(NotificationTemplate $template, NotificationThread $thread, string $subject, string $body, array $variables, bool $attention, int $deliveryId): string
    {
        throw new \LogicException('SelectiveThreadedTestChannel posts no reply.');
    }

    public function postMessage(NotificationTemplate $template, string $subject, string $body, array $variables, int $deliveryId): string
    {
        throw new \LogicException('SelectiveThreadedTestChannel posts no message.');
    }
}
