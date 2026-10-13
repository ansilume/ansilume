<?php

declare(strict_types=1);

namespace app\services;

use app\components\ErrorText;
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
 * others. What a failure's audit row and log line say about its error is
 * ErrorText's: the message, but never that of a database error.
 * sendSingle() also returns that error, so a test of one template can
 * report it.
 */
class NotificationDispatcher extends Component
{
    /**
     * Logged when a channel failed to send a notification; its
     * notification.failed audit entry carries the same error text.
     * sprintf(): the notification template's ID, its channel, the event,
     * then what may be said about the error (ErrorText): its message, but
     * for a database error only its class, SQLSTATE and driver error code,
     * never its message, which carries the SQL statement with its values.
     */
    public const SEND_FAILED = 'NotificationDispatcher: failed to send notification #%d (%s) for %s: %s';

    /**
     * The error of a notification template whose channel no handler sends:
     * its notification.failed audit entry and sendSingle()'s answer.
     * sprintf(): the channel.
     */
    public const UNKNOWN_CHANNEL = "Unknown channel '%s'";

    /** Logged for a notification template whose channel no handler sends; sprintf(): the channel. */
    public const UNKNOWN_CHANNEL_LOGGED = "NotificationDispatcher: unknown channel '%s'";

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
     * Send one notification template (used by dispatch() and the test
     * actions of the web UI and the API). Always writes an audit row —
     * success or failure.
     *
     * @param array<string, string> $variables
     * @return string|null null when the channel accepted the message; else
     *     the error its notification.failed audit entry carries:
     *     UNKNOWN_CHANNEL, or what may be said about the channel's error
     *     (ErrorText), never the message of a database error
     */
    public function sendSingle(NotificationTemplate $nt, array $variables, string $event = 'test'): ?string
    {
        $subject = $this->renderer->render((string)$nt->subject_template, $variables);
        $body = $this->renderer->render((string)$nt->body_template, $variables);

        $channel = $this->channels[$nt->channel] ?? null;
        if ($channel === null) {
            $error = sprintf(self::UNKNOWN_CHANNEL, $nt->channel);
            $this->audit(AuditLog::ACTION_NOTIFICATION_FAILED, $nt, $event, ['error' => $error]);
            \Yii::warning(sprintf(self::UNKNOWN_CHANNEL_LOGGED, $nt->channel), __CLASS__);
            return $error;
        }

        try {
            $channel->send($nt, $subject, $body, $variables);
        } catch (\Throwable $e) {
            $error = ErrorText::of($e);
            $this->audit(AuditLog::ACTION_NOTIFICATION_FAILED, $nt, $event, ['error' => $error]);
            \Yii::error(sprintf(self::SEND_FAILED, $nt->id, $nt->channel, $event, $error), __CLASS__);
            return $error;
        }
        $this->audit(AuditLog::ACTION_NOTIFICATION_DISPATCHED, $nt, $event, [
            'subject' => mb_substr($subject, 0, 200),
        ]);

        return null;
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
