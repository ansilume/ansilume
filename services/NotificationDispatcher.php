<?php

declare(strict_types=1);

namespace app\services;

use app\models\AuditLog;
use app\models\NotificationTemplate;
use app\services\notification\ChannelInterface;
use app\services\notification\EmailChannel;
use app\services\notification\PagerDutyChannel;
use app\services\notification\SlackChannel;
use app\services\notification\TeamsChannel;
use app\services\notification\TelegramChannel;
use app\services\notification\TemplateRenderer;
use app\services\notification\WebhookChannel;
use yii\base\Component;

/**
 * Dispatches notifications for any lifecycle event across the platform.
 *
 * Subscriptions are global: every NotificationTemplate row that lists the
 * event in its `events` column fires. Producers hand us a structured payload
 * (job, workflow, runner, project, approval, ...); the renderer flattens it
 * into dot-keyed template variables.
 *
 * Every send attempt writes one audit row — `notification.dispatched` on
 * success, `notification.failed` on exception — so every channel delivery
 * is attributable and reviewable. Failures in one channel never block
 * others.
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
            NotificationTemplate::CHANNEL_TELEGRAM => new TelegramChannel(),
            NotificationTemplate::CHANNEL_PAGERDUTY => new PagerDutyChannel(),
        ];
    }

    /**
     * Dispatch every notification template that listens to this event.
     *
     * @param string               $event   One of NotificationTemplate::EVENT_*
     * @param array<string, mixed> $payload Structured context (job, workflow, ...)
     */
    public function dispatch(string $event, array $payload = []): void
    {
        /** @var NotificationTemplate[] $templates */
        $templates = NotificationTemplate::find()->all();
        if ($templates === []) {
            return;
        }

        $payload['event'] = $event;
        $payload['severity'] = NotificationTemplate::eventSeverity($event);
        $variables = $this->renderer->buildVariables($payload);

        foreach ($templates as $template) {
            if (!$template->listensTo($event)) {
                continue;
            }
            $this->sendSingle($template, $variables, $event);
        }
    }

    /**
     * Send one notification template (used by dispatch and the /test API).
     * Always writes an audit row — success or failure.
     *
     * @param array<string, string> $variables
     */
    public function sendSingle(NotificationTemplate $nt, array $variables, string $event = 'test'): void
    {
        $subject = $this->renderer->render((string)$nt->subject_template, $variables);
        $body = $this->renderer->render((string)$nt->body_template, $variables);

        $channel = $this->channels[$nt->channel] ?? null;
        if ($channel === null) {
            $this->audit(AuditLog::ACTION_NOTIFICATION_FAILED, $nt, $event, [
                'error' => "Unknown channel '{$nt->channel}'",
            ]);
            \Yii::warning("NotificationDispatcher: unknown channel '{$nt->channel}'", __CLASS__);
            return;
        }

        try {
            $channel->send($nt, $subject, $body, $variables);
            $this->audit(AuditLog::ACTION_NOTIFICATION_DISPATCHED, $nt, $event, [
                'subject' => mb_substr($subject, 0, 200),
            ]);
        } catch (\Throwable $e) {
            $this->audit(AuditLog::ACTION_NOTIFICATION_FAILED, $nt, $event, [
                'error' => $e->getMessage(),
            ]);
            \Yii::error(
                sprintf(
                    'NotificationDispatcher: failed to send notification #%d (%s) for %s: %s',
                    $nt->id,
                    $nt->channel,
                    $event,
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

    /**
     * @param array<string, mixed> $metadata
     */
    private function audit(string $action, NotificationTemplate $nt, string $event, array $metadata): void
    {
        /** @var AuditService $service */
        $service = \Yii::$app->get('auditService');
        $service->log(
            $action,
            'notification_template',
            $nt->id,
            null,
            array_merge([
                'template' => $nt->name,
                'channel' => $nt->channel,
                'event' => $event,
            ], $metadata)
        );
    }
}
