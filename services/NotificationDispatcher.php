<?php

declare(strict_types=1);

namespace app\services;

use app\models\Job;
use app\models\NotificationTemplate;
use app\services\notification\ChannelInterface;
use app\services\notification\EmailChannel;
use app\services\notification\SlackChannel;
use app\services\notification\TeamsChannel;
use app\services\notification\TemplateRenderer;
use app\services\notification\WebhookChannel;
use yii\base\Component;

/**
 * Dispatches notifications for job lifecycle events.
 *
 * Loads notification templates linked to the job's template, filters by event,
 * renders subject/body, and delegates to the appropriate channel handler.
 * Each template is dispatched independently — one failure does not block others.
 */
class NotificationDispatcher extends Component
{
    private TemplateRenderer $renderer;

    /** @var array<string, ChannelInterface> */
    private array $channels = [];

    public function init(): void
    {
        parent::init();
        $this->renderer = new TemplateRenderer();
        $this->channels = [
            NotificationTemplate::CHANNEL_EMAIL => new EmailChannel(),
            NotificationTemplate::CHANNEL_SLACK => new SlackChannel(),
            NotificationTemplate::CHANNEL_TEAMS => new TeamsChannel(),
            NotificationTemplate::CHANNEL_WEBHOOK => new WebhookChannel(),
        ];
    }

    /**
     * Dispatch notifications for a job event.
     *
     * @param string $event One of NotificationTemplate::EVENT_* constants
     */
    public function dispatch(string $event, Job $job): void
    {
        $template = $job->jobTemplate;
        if ($template === null) {
            return;
        }

        /** @var NotificationTemplate[] $notifications */
        $notifications = $template->notificationTemplates;
        if (empty($notifications)) {
            return;
        }

        $variables = $this->renderer->buildJobVariables($job);

        foreach ($notifications as $nt) {
            if (!$nt->listensTo($event)) {
                continue;
            }
            $this->sendSingle($nt, $variables);
        }
    }

    /**
     * Send a single notification template (used by dispatch and test endpoint).
     *
     * @param array<string, string> $variables
     */
    public function sendSingle(NotificationTemplate $nt, array $variables): void
    {
        try {
            $subject = $this->renderer->render((string)$nt->subject_template, $variables);
            $body = $this->renderer->render((string)$nt->body_template, $variables);

            $channel = $this->channels[$nt->channel] ?? null;
            if ($channel === null) {
                \Yii::warning("NotificationDispatcher: unknown channel '{$nt->channel}'", __CLASS__);
                return;
            }

            $channel->send($nt, $subject, $body, $variables);
        } catch (\Throwable $e) {
            \Yii::error(
                sprintf(
                    'NotificationDispatcher: failed to send notification #%d (%s): %s',
                    $nt->id,
                    $nt->channel,
                    $e->getMessage()
                ),
                __CLASS__
            );
        }
    }

    /**
     * Replace the channel handler for a given channel name (used in testing).
     */
    public function setChannel(string $name, ChannelInterface $channel): void
    {
        $this->channels[$name] = $channel;
    }
}
