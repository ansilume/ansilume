<?php

declare(strict_types=1);

namespace app\services;

use app\components\DbErrorSummary;
use app\components\ErrorText;
use app\models\AuditLog;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\NotificationThread;
use app\services\notification\ChannelDefinition;
use app\services\notification\ChannelInterface;
use app\services\notification\ChannelRegistry;
use app\services\notification\CheckedChannelInterface;
use app\services\notification\DeliveryRows;
use app\services\notification\EventSubscription;
use app\services\notification\NotificationDeliveryService;
use app\services\notification\NotificationOutbox;
use app\services\notification\NotificationRefs;
use app\services\notification\NotificationVisibility;
use app\services\notification\RunThread;
use app\services\notification\TemplateRenderer;
use app\services\notification\ThreadedChannelInterface;
use yii\base\Component;

/**
 * Dispatches notifications for any lifecycle event across the platform.
 *
 * dispatch() sends nothing itself, and never throws into the process that
 * caused the event (a runner's report, a vote, a web request, the
 * maintenance run): it writes the outbox (NotificationOutbox), one delivery
 * for each template
 * - that listens to the event (its events),
 * - whose level lets it through (NotificationTemplate::isWithinLevel()),
 * - whose channel sends something for it (SelectiveChannelInterface:
 *   PagerDuty sends nothing for the info events of a step; such an event is
 *   as one the template does not listen to: EventSubscription),
 * - and whose identity, the user it delivers as, may see it, of a run only
 *   while its workflow filter takes the run (NotificationVisibility; the
 *   event's refs, which producers hand over under REFS, name its objects),
 * with the event's variables as they are now: the payload flattened into
 * dot keys (TemplateRenderer), with links absolute from appBaseUrl, and the
 * event's ID (event_id: newEventId(), the same for every template) and Unix
 * time (occurred_at). A job or
 * an approval event of a run reaches an identity that may not see the run's
 * workflow without the run's context (RUN_CONTEXT_PREFIXES). An event of a
 * run whose workflow the identity may see goes, on a channel that keeps a
 * status post per run (ThreadedChannelInterface), into the run's thread
 * (RunThread): a status delivery, and a reply for a template that
 * subscribes to the event; a template whose post of the run exists or is on
 * its way gets the status delivery whatever its events and level. Each delivery
 * is pushed to the notification queue after it was written; the notification
 * worker sends it, with retries, and writes its one audit entry
 * (NotificationDeliveryService). Every producer calls dispatch() after its
 * own transaction committed: a delivery written in a transaction that is
 * still open is pushed before others see it, and the worker's claim waits
 * for that transaction (or finds nothing once it was rolled back); the
 * sweep pushes a delivery whose message came too early again. An error is logged (NOT_QUEUED,
 * TEMPLATE_NOT_QUEUED: the class, SQLSTATE and driver error code, never the
 * message) and costs at most that template its delivery; a push that failed
 * is logged by the delivery service, and the maintenance run's sweep pushes
 * the delivery again.
 *
 * Throttled events (NotificationOutbox::THROTTLE_SECONDS: webhook.invalid_token)
 * reach a template at most once within their seconds; each further one is
 * audited as notification.throttled, with what it carried.
 *
 * refreshStatus() queues a status delivery of a workflow run whose state
 * changed without an event of its own, for each template whose channel
 * keeps a status post of the run (ThreadedChannelInterface) and whose post
 * exists or is on its way.
 *
 * The channels are the built-in ones and those of the installation's own
 * ($channelsFile, config/notification-channels.php; $channels in the
 * tests), each defined by a ChannelDefinition (ChannelRegistry): init()
 * refuses a definition it cannot take, and makes the handler of each
 * channel once. The web and the console application build the dispatcher
 * when they start (bootstrap), so a wrong definition stops them there.
 *
 * sendSingle() serves the test send of a template (Send Test, the API's
 * test): it refuses an event its channel sends nothing for (SENDS_NOTHING),
 * checks the destination of a channel that has a check
 * (CheckedChannelInterface: Mattermost's token and channel), sends at once,
 * in the caller's process, and writes one audit row, success or failure. What a failure's audit row and log line say
 * about its error is ErrorText's: the message, but never that of a database
 * error. sendSingle() also returns that error, so a test of one template
 * can report it.
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

    /**
     * The error of a test of a template whose channel sends nothing for its
     * event (SelectiveChannelInterface), which sendSingle() therefore does
     * not send: its notification.failed audit entry and sendSingle()'s
     * answer. sprintf(): the channel, the event.
     */
    public const SENDS_NOTHING = 'The %s channel sends nothing for %s.';

    /**
     * The key of a payload under which a producer names the objects of its
     * event, its refs (NotificationRefs); they are no variables.
     */
    public const REFS = 'refs';

    /**
     * The variables of a run's context: the run's and its step's. A job or
     * an approval event of a run carries them only for an identity that may
     * see the run's workflow; an identity that may see the job, through its
     * project, but not the workflow gets the event without them.
     */
    public const RUN_CONTEXT_PREFIXES = ['workflow.', 'step.'];

    /**
     * Logged when an event was queued for no template, because an error came
     * before any template was judged (such as reading the templates).
     * sprintf(): the event, then the error's class, SQLSTATE and driver
     * error code (DbErrorSummary).
     */
    public const NOT_QUEUED = 'NotificationDispatcher: the %s notification was queued for no template: %s (SQLSTATE %s, driver error %s).';

    /**
     * Logged when the delivery of an event to one template was not queued;
     * the other templates still get theirs. sprintf(): the event, the
     * template's ID, then the error's class, SQLSTATE and driver error code
     * (DbErrorSummary).
     */
    public const TEMPLATE_NOT_QUEUED = 'NotificationDispatcher: the %s notification of template #%d was not queued: %s (SQLSTATE %s, driver error %s).';

    /**
     * The channels of the installation's own, beside the built-in ones
     * (ChannelRegistry::BUILT_IN): name => its definition (ChannelDefinition).
     * Used while $channelsFile is null, as in the tests.
     *
     * @var array<int|string, mixed>
     */
    public array $channels = [];

    /**
     * The file that returns them in place of $channels, read by init()
     * (ChannelRegistry::configuredIn()): config/notification-channels.php
     * (config/services.php). Not read while the config loads, before the
     * error handler exists: only PHP's error log would get its errors.
     */
    public ?string $channelsFile = null;

    private TemplateRenderer $renderer;

    /** @var array<string, ChannelDefinition> */
    private array $definitions = [];

    /** @var array<string, ChannelInterface> */
    private array $handlers = [];

    /**
     * Defines the channels, the built-in ones and those of $channelsFile or
     * $channels (ChannelRegistry::build()), and makes the handler of each.
     *
     * @throws \yii\base\InvalidConfigException for a channel it cannot define, or a file it cannot take, with what is wrong
     */
    public function init(): void
    {
        parent::init();
        $this->renderer = new TemplateRenderer();
        $this->definitions = ChannelRegistry::build(
            $this->channelsFile === null ? $this->channels : ChannelRegistry::configuredIn($this->channelsFile)
        );
        foreach ($this->definitions as $name => $definition) {
            $this->handlers[$name] = $definition->handler();
        }
    }

    /**
     * The channels of the installation, the built-in ones first, by name:
     * what the template's rules, its secrets and its identity read.
     *
     * @return array<string, ChannelDefinition>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * Queues $event for every notification template that may deliver it
     * (see the class); never throws.
     *
     * @param string               $event   One of NotificationTemplate::EVENT_*
     * @param array<string, mixed> $payload Structured context (job, workflow, ...), and
     *     under REFS the objects of the event (NotificationRefs)
     */
    public function dispatch(string $event, array $payload = []): void
    {
        try {
            $refs = NotificationRefs::of($payload[self::REFS] ?? null);
            $templates = $this->recipients($event, $refs);
            if ($templates === []) {
                return;
            }
            unset($payload[self::REFS]);
            $payload['event'] = $event;
            $payload['severity'] = NotificationTemplate::eventSeverity($event);
            $payload['event_id'] = self::newEventId();
            $payload['occurred_at'] = (string)time();
            $variables = $this->renderer->buildVariables($payload);
            $visibility = $this->visibility();
        } catch (\Throwable $e) {
            \Yii::error(sprintf(self::NOT_QUEUED, $event, ...DbErrorSummary::of($e)), __CLASS__);

            return;
        }

        self::eachTemplate($event, $templates, fn (NotificationTemplate $template) => $this->queue($visibility, $template, $event, $variables, $refs));
    }

    /**
     * Queues a status delivery of run $workflowJobId (NotificationDelivery::KIND_STATUS,
     * event NotificationDelivery::EVENT_STATUS_REFRESH) for every template whose
     * status post of the run exists (notification_thread) or is on its way
     * (a status delivery of the run that is not finished), whose channel
     * keeps one (ThreadedChannelInterface), and whose identity may still see
     * the run, its workflow filter included (NotificationVisibility): the
     * post then shows the run as it is when the delivery is sent. The
     * template's events and level are not asked: they decide which replies
     * a run's thread gets, not whether its post stays current. Never throws:
     * an error is logged as dispatch() logs it (NOT_QUEUED,
     * TEMPLATE_NOT_QUEUED) and costs at most that template its refresh.
     */
    public function refreshStatus(int $workflowJobId): void
    {
        $event = NotificationDelivery::EVENT_STATUS_REFRESH;
        try {
            $templates = $this->postHolders($workflowJobId);
            $visibility = $this->visibility();
            $variables = $this->renderer->buildVariables(['event' => $event, 'workflow' => ['id' => (string)$workflowJobId]]);
        } catch (\Throwable $e) {
            \Yii::error(sprintf(self::NOT_QUEUED, $event, ...DbErrorSummary::of($e)), __CLASS__);

            return;
        }
        $refs = ['workflow_job_id' => $workflowJobId];

        self::eachTemplate($event, $templates, function (NotificationTemplate $template) use ($visibility, $event, $variables, $refs): void {
            if (!$this->channel((string)$template->channel) instanceof ThreadedChannelInterface || !$visibility->allows($template, $event, $refs)) {
                return;
            }
            $delivery = NotificationOutbox::enqueue($template, $event, $variables, $refs, NotificationDelivery::KIND_STATUS);
            self::deliveries()->enqueuePush((int)$delivery->id);
        });
    }

    /**
     * Send one notification template at once (the test actions of the web
     * UI and the API). Always writes an audit row — success or failure.
     *
     * @param array<string, string> $variables
     * @return string|null null when the channel accepted the message; else
     *     the error its notification.failed audit entry carries:
     *     UNKNOWN_CHANNEL, SENDS_NOTHING, or what may be said about the
     *     channel's error (ErrorText), never the message of a database error
     */
    public function sendSingle(NotificationTemplate $nt, array $variables, string $event = 'test'): ?string
    {
        ['subject' => $subject, 'body' => $body] = $this->render($nt, $variables);

        $channel = $this->channel((string)$nt->channel);
        if ($channel === null) {
            $error = sprintf(self::UNKNOWN_CHANNEL, $nt->channel);
            $this->audit(AuditLog::ACTION_NOTIFICATION_FAILED, $nt, $event, ['error' => $error]);
            \Yii::warning(sprintf(self::UNKNOWN_CHANNEL_LOGGED, $nt->channel), __CLASS__);
            return $error;
        }
        if (!EventSubscription::sends($channel, $event)) {
            $error = sprintf(self::SENDS_NOTHING, $nt->channel, $event);
            $this->audit(AuditLog::ACTION_NOTIFICATION_FAILED, $nt, $event, ['error' => $error]);
            return $error;
        }

        try {
            if ($channel instanceof CheckedChannelInterface) {
                $channel->checkDestination($nt);
            }
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
     * The handler of channel $name: what sendSingle() and the notification
     * worker (NotificationDeliveryService) send through; null for a channel
     * that no handler sends.
     */
    public function channel(string $name): ?ChannelInterface
    {
        return $this->handlers[$name] ?? null;
    }

    /**
     * $nt's subject and body, rendered with $variables as every send of
     * the template renders them (TemplateRenderer).
     *
     * @param array<string, string> $variables
     * @return array{subject: string, body: string}
     */
    public function render(NotificationTemplate $nt, array $variables): array
    {
        return [
            'subject' => $this->renderer->render((string)$nt->subject_template, $variables),
            'body' => $this->renderer->render((string)$nt->body_template, $variables),
        ];
    }

    /**
     * Replace the channel handler for a given channel name (used in testing).
     */
    public function setChannel(string $name, ChannelInterface $channel): void
    {
        $this->handlers[$name] = $channel;
    }

    /**
     * The templates that take $event (EventSubscription): they listen to it,
     * their level lets it through and their channel sends something for it.
     *
     * @return list<NotificationTemplate>
     */
    protected function listeners(string $event): array
    {
        /** @var list<NotificationTemplate> $templates */
        $templates = NotificationTemplate::find()->orderBy(['id' => SORT_ASC])->all();

        return array_values(array_filter(
            $templates,
            fn (NotificationTemplate $t): bool => EventSubscription::takes($t, $event, $this->channel((string)$t->channel))
        ));
    }

    /**
     * The templates an event of $event about $refs may reach: those that
     * listen to it at their level, and for an event of a run those whose
     * status post of the run exists or is on its way on a channel that keeps
     * one; by ID, each once.
     *
     * @param array{workflow_job_id?: int, job_id?: int, approval_request_id?: int, project_id?: int, job_template_id?: int} $refs
     * @return list<NotificationTemplate>
     */
    private function recipients(string $event, array $refs): array
    {
        $byId = [];
        foreach ($this->listeners($event) as $template) {
            $byId[(int)$template->id] = $template;
        }
        $runId = $refs['workflow_job_id'] ?? null;
        foreach ($runId === null ? [] : $this->postHolders($runId) as $holder) {
            if (!isset($byId[(int)$holder->id]) && $this->channel((string)$holder->channel) instanceof ThreadedChannelInterface) {
                $byId[(int)$holder->id] = $holder;
            }
        }
        ksort($byId);

        return array_values($byId);
    }

    /**
     * The templates with a status post of run $workflowJobId, or one on its
     * way: a notification_thread row of the run, or a status delivery of
     * the run that is not finished; by ID.
     *
     * @return list<NotificationTemplate>
     */
    protected function postHolders(int $workflowJobId): array
    {
        $posts = NotificationThread::find()->select('notification_template_id')->where(['workflow_job_id' => $workflowJobId]);
        $onTheirWay = NotificationDelivery::find()
            ->select('notification_template_id')
            ->where(['workflow_job_id' => $workflowJobId, 'kind' => NotificationDelivery::KIND_STATUS, 'status' => DeliveryRows::UNFINISHED]);

        /** @var list<NotificationTemplate> $templates */
        $templates = NotificationTemplate::find()
            ->where(['or', ['id' => $posts], ['id' => $onTheirWay]])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return $templates;
    }

    /**
     * The check whether a template may deliver an event.
     */
    protected function visibility(): NotificationVisibility
    {
        return new NotificationVisibility();
    }

    /**
     * Runs $queue for each of $templates, the templates an event of $event
     * may reach: an error is logged (TEMPLATE_NOT_QUEUED, without values)
     * and costs only that template its delivery.
     *
     * @param list<NotificationTemplate> $templates
     * @param \Closure(NotificationTemplate): void $queue
     */
    private static function eachTemplate(string $event, array $templates, \Closure $queue): void
    {
        foreach ($templates as $template) {
            try {
                $queue($template);
            } catch (\Throwable $e) {
                \Yii::error(sprintf(self::TEMPLATE_NOT_QUEUED, $event, (int)$template->id, ...DbErrorSummary::of($e)), __CLASS__);
            }
        }
    }

    /**
     * Writes and pushes the delivery of $event to $template, when its
     * identity may see the event, with the variables it may see: into the
     * run's thread (goesIntoThread(): the deliveries RunThread names), else
     * a message for a template that listens to it at its level; a
     * throttled event within its seconds is audited instead.
     *
     * @param array<string, string> $variables
     * @param array{workflow_job_id?: int, job_id?: int, approval_request_id?: int, project_id?: int, job_template_id?: int} $refs
     */
    private function queue(NotificationVisibility $visibility, NotificationTemplate $template, string $event, array $variables, array $refs): void
    {
        if (!$visibility->allows($template, $event, $refs)) {
            return;
        }
        $channel = $this->channel((string)$template->channel);
        if ($this->goesIntoThread($visibility, $template, $event, $refs)) {
            foreach (RunThread::kindsOf($template, $event, $channel) as $kind) {
                $delivery = NotificationOutbox::enqueue($template, $event, $variables, $refs, $kind);
                self::deliveries()->enqueuePush((int)$delivery->id);
            }

            return;
        }
        if (!EventSubscription::takes($template, $event, $channel)) {
            // A holder of a post of the run that may not see the run's workflow.
            return;
        }
        $variables = $this->variablesFor($visibility, $template, $event, $variables, $refs);
        $seconds = NotificationOutbox::THROTTLE_SECONDS[$event] ?? null;
        $delivery = $seconds === null
            ? NotificationOutbox::enqueue($template, $event, $variables, $refs)
            : NotificationOutbox::enqueueThrottled($template, $event, $variables, $refs, $seconds);
        if ($delivery instanceof NotificationDelivery) {
            self::deliveries()->enqueuePush((int)$delivery->id);

            return;
        }
        // Throttled: the delivery within the seconds, which the template got;
        // none when the template was deleted meanwhile.
        $recent = NotificationOutbox::recentDeliveryId($template, $event, (int)$seconds);
        if ($recent !== null) {
            $this->audit(AuditLog::ACTION_NOTIFICATION_THROTTLED, $template, $event, [
                'throttle_seconds' => (int)$seconds,
                'delivery_id' => $recent,
                'variables' => $variables,
            ]);
        }
    }

    /**
     * Whether $event goes into the thread of its run on $template (RunThread):
     * an event of a run, on a channel that keeps a status post per run, whose
     * workflow the template's identity may see.
     *
     * @param array{workflow_job_id?: int, job_id?: int, approval_request_id?: int, project_id?: int, job_template_id?: int} $refs
     */
    private function goesIntoThread(NotificationVisibility $visibility, NotificationTemplate $template, string $event, array $refs): bool
    {
        $runId = $refs['workflow_job_id'] ?? null;

        return $runId !== null
            && $this->channel((string)$template->channel) instanceof ThreadedChannelInterface
            && (NotificationTemplate::isWorkflowEvent($event) || $visibility->allows($template, NotificationDelivery::EVENT_STATUS_REFRESH, ['workflow_job_id' => $runId]));
    }

    /**
     * $variables as $template may deliver them: without the run's context
     * (RUN_CONTEXT_PREFIXES) for a job or an approval event of a run whose
     * workflow the template's identity may not see.
     *
     * @param array<string, string> $variables
     * @param array{workflow_job_id?: int, job_id?: int, approval_request_id?: int, project_id?: int, job_template_id?: int} $refs
     * @return array<string, string>
     */
    private function variablesFor(NotificationVisibility $visibility, NotificationTemplate $template, string $event, array $variables, array $refs): array
    {
        $runId = $refs['workflow_job_id'] ?? null;
        if (
            $runId === null
            || NotificationTemplate::isWorkflowEvent($event)
            || $visibility->allows($template, NotificationTemplate::EVENT_WORKFLOW_LAUNCHED, ['workflow_job_id' => $runId])
        ) {
            return $variables;
        }

        return array_filter(
            $variables,
            static fn (string $name): bool => !self::isRunContext($name),
            ARRAY_FILTER_USE_KEY
        );
    }

    private static function isRunContext(string $name): bool
    {
        foreach (self::RUN_CONTEXT_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The ID of an event (the variable event_id): 32 random hex digits, the
     * same in every template's delivery of the event and another for every
     * event, so a receiver can tell an event it got from two templates.
     */
    public static function newEventId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function deliveries(): NotificationDeliveryService
    {
        /** @var NotificationDeliveryService $deliveries */
        $deliveries = \Yii::$app->get('notificationDeliveryService');

        return $deliveries;
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
