<?php

declare(strict_types=1);

namespace app\services\notification;

use app\components\DbErrorSummary;
use app\jobs\DeliverNotificationJob;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\services\NotificationDispatcher;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\queue\Queue;

/**
 * Delivers the notification outbox (notification_delivery): the
 * notification worker runs deliver() for each message of the notification
 * queue (DeliverNotificationJob), and the maintenance run runs sweep()
 * every minute, which makes delivery durable without Redis (DeliverySweep).
 *
 * deliver() sends one delivery:
 * 1. It claims the row with a conditional update (pending and due becomes
 *    sending; DeliveryRows::claim()). A message for a row that another
 *    worker has, that is finished, not due yet or gone does nothing: a
 *    duplicate push, one the sweeper made, or a stray one.
 * 2. A delivery of a run's thread (workflow_job_id) waits while an earlier
 *    delivery of the same template and run is not finished: it is given
 *    back, without an attempt, and pushed for when the earliest of them is
 *    due, THREAD_WAIT_SECONDS later, at least THREAD_WAIT_SECONDS from now.
 *    So the status post of a run is there before an edit or a reply.
 * 3. A status whose post exists is superseded by a later status of its
 *    thread that is not finished, which renders the run as it is then.
 * 4. It checks again that the template may deliver the event
 *    (NotificationVisibility): the user it delivers as may still see it,
 *    and the workflow filter still takes its run; a status or a reply,
 *    which shows the run, also that the user may still see the run's
 *    workflow; otherwise it is skipped (INACTIVE_IDENTITY, NOT_VISIBLE).
 *    A message of a job or an approval event of a run whose workflow the
 *    user may no longer see goes out without the run's context
 *    (RunContext::without()), as the dispatcher leaves it out for such a
 *    user when the event happens.
 *    A message or a reply of an event that the template's channel sends
 *    nothing for (SelectiveChannelInterface), written while the template
 *    had another channel, is skipped too (CHANNEL_SENDS_NOTHING), never
 *    claimed as delivered; a status still goes out.
 * 5. It sends through the template's channel as the dispatcher renders it
 *    (NotificationDispatcher::channel(), render()): a message with the
 *    channel's send(), or with sendDelivery() on a channel whose message
 *    names its delivery (DeliveryChannelInterface: the webhook's
 *    X-Ansilume-Delivery); on a channel that keeps a status post per run
 *    (ThreadedChannelInterface: Mattermost), a status, a reply or a
 *    message by its kind (ThreadedSend). A status or a reply on another
 *    channel fails (NOT_THREADED), as after a template's channel changed
 *    while its deliveries waited; a channel no handler sends fails as the
 *    dispatcher's UNKNOWN_CHANNEL.
 * 6. It records the outcome (DeliveryOutcome::ofError() classifies a
 *    channel's error): delivered; a transient error retried after the
 *    backoff (backoffSeconds, at least, or a server's Retry-After up to
 *    RETRY_AFTER_MAX_SECONDS), pushed with that delay; failed, for a
 *    permanent error or once maxAttempts attempts failed. One audit entry
 *    and log line per final outcome (DeliveryAudit), none per attempt.
 *
 * A channel's error never leaves deliver(): the queue's log behaviour
 * would log its message. It is kept as ErrorText gives it, at most 500
 * characters. An error of deliver()'s own bookkeeping, such as a database
 * that went away, does: the queue logs it as ExceptionLogText gives it, and
 * the row stays pending or sending for the sweep.
 *
 * A worker owns a delivery from its claim to its outcome; one that took
 * longer than STALE_SENDING_SECONDS finds the delivery given back by the
 * sweep, and records nothing (DeliveryRows).
 */
class NotificationDeliveryService extends Component
{
    /**
     * Logged (warning) when an attempt failed and the delivery is tried
     * again; sprintf(): the delivery's ID, the template's ID, its channel,
     * the event, the attempt, maxAttempts, the seconds until the next
     * attempt, the error (ErrorText).
     */
    public const RETRYING = 'NotificationDeliveryService: delivery #%d of notification template #%d (%s) for %s failed on attempt %d of %d; the next attempt in %d seconds: %s';

    /**
     * Logged (error) when a delivery failed for good; its notification.failed
     * entry carries the same error. sprintf(): the delivery's ID, the
     * template's ID, its channel, the event, the attempts made, the error.
     */
    public const FAILED = 'NotificationDeliveryService: delivery #%d of notification template #%d (%s) for %s failed after %d attempt(s): %s';

    /** Logged (info) when a delivery was skipped; sprintf(): the delivery's ID, the template's ID, the event, the reason. */
    public const NOT_SENT = 'NotificationDeliveryService: delivery #%d of notification template #%d for %s was not sent: %s';

    /**
     * Logged (error) when a delivery could not be pushed to the notification
     * queue; sprintf(): the delivery's ID, then the error's class, SQLSTATE
     * and driver error code (DbErrorSummary): yii2-redis puts the command
     * with its values in the message.
     */
    public const PUSH_FAILED = 'NotificationDeliveryService: delivery #%d was not queued: %s (SQLSTATE %s, driver error %s); the maintenance run queues it again.';

    /**
     * Logged (warning) when the sweep gave back a delivery whose worker
     * stopped; sprintf(): the delivery's ID, the template's ID, the seconds
     * since the claim, the attempt that was lost, maxAttempts.
     */
    public const WORKER_STOPPED_LOGGED = 'NotificationDeliveryService: delivery #%d of notification template #%d was claimed %d seconds ago and not finished: the notification worker stopped while it sent it (attempt %d of %d).';

    /** The error of a delivery whose worker stopped while it sent it. */
    public const WORKER_STOPPED = 'The notification worker stopped while it sent the delivery.';

    /** Why a delivery was skipped: its identity is inactive. sprintf(): the user's ID. */
    public const INACTIVE_IDENTITY = 'Not sent: the user the template delivers as, #%d, is inactive.';

    /** Why a delivery was skipped: its identity may no longer see the event. sprintf(): the user's ID. */
    public const NOT_VISIBLE = 'Not sent: the user the template delivers as, #%d, may no longer see the event, or the workflow filter no longer takes its run.';

    /**
     * Why a message or a reply was skipped: the template's channel sends
     * nothing for its event (SelectiveChannelInterface), as after the
     * template's channel changed while the delivery waited. sprintf(): the
     * channel, the event.
     */
    public const CHANNEL_SENDS_NOTHING = 'Not sent: the %s channel sends nothing for %s.';

    /** The error of a status or reply for a channel that keeps no status post (ThreadedChannelInterface); sprintf(): the channel, the kind. */
    public const NOT_THREADED = 'The %s channel keeps no status post, so it sends no %s delivery.';

    /** init() refuses a configuration without a way to retry. */
    public const INVALID_RETRIES = 'NotificationDeliveryService needs backoffSeconds, a list of at least one step of whole seconds above 0, and maxAttempts of at least 1.';

    /** The shortest wait of a delivery for the earlier ones of its thread. */
    public const THREAD_WAIT_SECONDS = 5;

    /** The longest wait a server's Retry-After sets. */
    public const RETRY_AFTER_MAX_SECONDS = 3600;

    /** A pending delivery due this long ago lost its message: the sweep pushes it again. */
    public const REPUSH_AFTER_SECONDS = 120;

    /**
     * A delivery claimed this long ago and not finished lost its worker: the
     * notification queue's TTR (120 seconds), after which Redis hands the
     * message out again, and a margin.
     */
    public const STALE_SENDING_SECONDS = 180;

    /** How long a finished delivery is kept: 30 days. */
    public const RETENTION_SECONDS = 2592000;

    /** The rows a step of the sweep handles per batch. */
    public const SWEEP_BATCH_SIZE = 100;

    /** The status a final outcome leaves a delivery in. */
    private const STATUSES = [
        DeliveryOutcome::DELIVERED => NotificationDelivery::STATUS_DELIVERED,
        DeliveryOutcome::TRANSIENT => NotificationDelivery::STATUS_FAILED,
        DeliveryOutcome::PERMANENT => NotificationDelivery::STATUS_FAILED,
        DeliveryOutcome::SKIPPED => NotificationDelivery::STATUS_SKIPPED,
        DeliveryOutcome::SUPERSEDED => NotificationDelivery::STATUS_SUPERSEDED,
    ];

    /** The outcomes of an attempt at sending, which count as an attempt. */
    private const SENT = [DeliveryOutcome::DELIVERED, DeliveryOutcome::TRANSIENT, DeliveryOutcome::PERMANENT];

    /**
     * The seconds to wait before the next attempt, after the first failed
     * attempt, the second, ...; after the last step, its seconds again.
     *
     * @var list<int>
     */
    public array $backoffSeconds = [30, 120, 600, 1800, 3600, 7200, 14400];

    /** The attempts a delivery gets before it fails. */
    public int $maxAttempts = 8;

    public function init(): void
    {
        parent::init();
        if ($this->maxAttempts < 1 || !self::isBackoff($this->backoffSeconds)) {
            throw new InvalidConfigException(self::INVALID_RETRIES);
        }
    }

    /**
     * Delivers notification delivery $deliveryId (see the class).
     */
    public function deliver(int $deliveryId): void
    {
        $claimedAt = time();
        if (!DeliveryRows::claim($deliveryId, $claimedAt)) {
            return;
        }
        $delivery = NotificationDelivery::findOne($deliveryId);
        $template = $delivery?->template;
        if (!$delivery instanceof NotificationDelivery || !$template instanceof NotificationTemplate) {
            return;
        }
        $this->record($delivery, $template, $claimedAt, $this->attempt($delivery, $template, $claimedAt));
    }

    /**
     * Pushes back what was lost and deletes what finished long ago
     * (DeliverySweep), at $now, starting no further batch after $deadline
     * (microtime(true); null: no bound).
     *
     * @return array{repushed: int, reset: int, purged: int}
     */
    public function sweep(int $now, ?float $deadline = null): array
    {
        return (new DeliverySweep($this))->run($now, $deadline);
    }

    /**
     * Pushes delivery $deliveryId to the notification queue, to run in
     * $delaySeconds. It never throws: a push that fails (Redis cannot be
     * reached) is logged (PUSH_FAILED) and answered with false; the sweep
     * pushes the delivery again once it is overdue.
     */
    public function enqueuePush(int $deliveryId, int $delaySeconds = 0): bool
    {
        try {
            /** @var Queue $queue */
            $queue = \Yii::$app->get('notificationQueue');
            $queue->delay(max(0, $delaySeconds))->push(new DeliverNotificationJob(['deliveryId' => $deliveryId]));
        } catch (\Throwable $e) {
            \Yii::error(sprintf(self::PUSH_FAILED, $deliveryId, ...DbErrorSummary::of($e)), __CLASS__);

            return false;
        }

        return true;
    }

    /**
     * The check whether a template may deliver an event.
     */
    protected function visibility(): NotificationVisibility
    {
        return new NotificationVisibility();
    }

    /**
     * Steps 2 to 5 of deliver() for the claimed $delivery of $template.
     */
    private function attempt(NotificationDelivery $delivery, NotificationTemplate $template, int $now): DeliveryOutcome
    {
        $earliest = DeliveryRows::earliestBefore($delivery);
        if ($earliest !== null) {
            return DeliveryOutcome::waiting(max(self::THREAD_WAIT_SECONDS, $earliest - $now + self::THREAD_WAIT_SECONDS));
        }
        if (DeliveryRows::isSuperseded($delivery)) {
            return DeliveryOutcome::superseded();
        }
        if (!$this->mayDeliver($template, $delivery)) {
            $reason = $template->isDeliveringAsInactive() ? self::INACTIVE_IDENTITY : self::NOT_VISIBLE;

            return DeliveryOutcome::skipped(sprintf($reason, (int)$template->delivers_as));
        }
        if ($this->sendsNothingFor($delivery, $template)) {
            return DeliveryOutcome::skipped(sprintf(self::CHANNEL_SENDS_NOTHING, $template->channel, $delivery->event));
        }

        return $this->send($delivery, $template);
    }

    /**
     * Whether $delivery is a message or a reply of an event that the
     * channel of $template sends nothing for (SelectiveChannelInterface); a
     * status keeps the run's post current whatever its event.
     */
    private function sendsNothingFor(NotificationDelivery $delivery, NotificationTemplate $template): bool
    {
        return $delivery->kind !== NotificationDelivery::KIND_STATUS
            && !EventSubscription::sends(self::dispatcher()->channel((string)$template->channel), (string)$delivery->event);
    }

    /**
     * Whether $template may still deliver $delivery: its event as its refs
     * name it, and a status or a reply, which shows the run, only while the
     * user it delivers as may see the run's workflow (seesRun()): a job or
     * an approval event of the run is judged by its job or request alone,
     * and its message goes out without the run's context (variablesOf()).
     */
    private function mayDeliver(NotificationTemplate $template, NotificationDelivery $delivery): bool
    {
        if (!$this->visibility()->allows($template, (string)$delivery->event, $delivery->getRefs())) {
            return false;
        }

        return $delivery->kind === NotificationDelivery::KIND_MESSAGE || $this->seesRun($template, $delivery);
    }

    /**
     * Whether the user $template delivers as may see the workflow of the
     * run of $delivery now: for a workflow event and a status refresh,
     * which allows() judges by the run's workflow, it may.
     */
    private function seesRun(NotificationTemplate $template, NotificationDelivery $delivery): bool
    {
        $event = (string)$delivery->event;

        return NotificationTemplate::isWorkflowEvent($event)
            || $event === NotificationDelivery::EVENT_STATUS_REFRESH
            || $this->visibility()->allows($template, NotificationDelivery::EVENT_STATUS_REFRESH, ['workflow_job_id' => (int)$delivery->workflow_job_id]);
    }

    /**
     * The variables $delivery is sent with: as the dispatcher kept them
     * when the event happened, but without the run's context
     * (RunContext::without(): workflow.*, step.*) for a message of a job or
     * an approval event of a run whose workflow the user $template delivers
     * as may no longer see: the dispatcher kept it for the user the
     * template delivered as then, who could, and since then the workflow
     * may have got a step of another team's project, or the template
     * another user. A status and a reply are sent only while the user sees
     * the run (mayDeliver()).
     *
     * @return array<string, string>
     */
    private function variablesOf(NotificationDelivery $delivery, NotificationTemplate $template): array
    {
        $variables = $delivery->getVariableMap();
        if ($delivery->kind !== NotificationDelivery::KIND_MESSAGE || $delivery->workflow_job_id === null || $this->seesRun($template, $delivery)) {
            return $variables;
        }

        return RunContext::without($variables);
    }

    /**
     * Sends $delivery through the channel of $template, as the dispatcher
     * renders it: by its kind on a channel that keeps a status post per run
     * (ThreadedSend), else as a message.
     */
    private function send(NotificationDelivery $delivery, NotificationTemplate $template): DeliveryOutcome
    {
        $dispatcher = self::dispatcher();
        $channel = $dispatcher->channel((string)$template->channel);
        if ($channel === null) {
            return DeliveryOutcome::permanent(sprintf(NotificationDispatcher::UNKNOWN_CHANNEL, $template->channel));
        }
        if ($delivery->kind !== NotificationDelivery::KIND_MESSAGE && !$channel instanceof ThreadedChannelInterface) {
            return DeliveryOutcome::permanent(sprintf(self::NOT_THREADED, $template->channel, $delivery->kind));
        }
        $variables = $this->variablesOf($delivery, $template);
        $message = $dispatcher->render($template, $variables);
        if ($channel instanceof ThreadedChannelInterface) {
            return ThreadedSend::outcome($channel, $delivery, $template, $message, $variables);
        }
        try {
            if ($channel instanceof DeliveryChannelInterface) {
                $channel->sendDelivery($template, $message['subject'], $message['body'], $variables, $delivery);
            } else {
                $channel->send($template, $message['subject'], $message['body'], $variables);
            }
        } catch (\Throwable $e) {
            return DeliveryOutcome::ofError($e, time());
        }

        return DeliveryOutcome::delivered(null, $message['subject']);
    }

    /**
     * Records $outcome of $delivery, claimed at $claimedAt: gives it back for
     * a wait or a retry, finishes it otherwise.
     */
    private function record(NotificationDelivery $delivery, NotificationTemplate $template, int $claimedAt, DeliveryOutcome $outcome): void
    {
        if ($outcome->kind === DeliveryOutcome::WAITING) {
            $this->requeue($delivery, $claimedAt, $outcome->wait, []);

            return;
        }
        $attempts = (int)$delivery->attempts + (in_array($outcome->kind, self::SENT, true) ? 1 : 0);
        if ($outcome->kind === DeliveryOutcome::TRANSIENT && $attempts < $this->maxAttempts) {
            $this->retry($delivery, $template, $claimedAt, $attempts, $outcome);

            return;
        }
        $changes = [
            'attempts' => $attempts,
            'last_error' => $outcome->error === '' ? null : $outcome->error,
            'remote_id' => $outcome->remoteId,
        ];
        if (DeliveryRows::finish($delivery, $claimedAt, self::STATUSES[$outcome->kind], $changes, time())) {
            DeliveryAudit::record($delivery, $template, $attempts, $outcome);
        }
    }

    /**
     * Gives $delivery back after its attempt $attempts failed with a
     * transient error, for the next attempt after the backoff.
     */
    private function retry(NotificationDelivery $delivery, NotificationTemplate $template, int $claimedAt, int $attempts, DeliveryOutcome $outcome): void
    {
        $delay = $this->retryDelay($attempts, $outcome->retryAfter);
        if (!$this->requeue($delivery, $claimedAt, $delay, ['attempts' => $attempts, 'last_error' => $outcome->error])) {
            return;
        }
        \Yii::warning(
            sprintf(self::RETRYING, (int)$delivery->id, (int)$template->id, $template->channel, $delivery->event, $attempts, $this->maxAttempts, $delay, $outcome->error),
            __CLASS__
        );
    }

    /**
     * Gives $delivery, claimed at $claimedAt, back, due in $delay seconds,
     * with $changes, and pushes it for then. Whether it was still owned.
     *
     * @param array<string, int|string|null> $changes
     */
    private function requeue(NotificationDelivery $delivery, int $claimedAt, int $delay, array $changes): bool
    {
        $now = time();
        if (!DeliveryRows::release($delivery, $claimedAt, $now + $delay, $changes, $now)) {
            return false;
        }
        $this->enqueuePush((int)$delivery->id, $delay);

        return true;
    }

    /**
     * The seconds until the attempt after attempt $attempts: the backoff's
     * step for it (the last one for every attempt beyond), or the server's
     * Retry-After when it is longer, up to RETRY_AFTER_MAX_SECONDS.
     */
    private function retryDelay(int $attempts, ?int $retryAfter): int
    {
        $step = $this->backoffSeconds[min($attempts, count($this->backoffSeconds)) - 1];

        return max($step, min($retryAfter ?? 0, self::RETRY_AFTER_MAX_SECONDS));
    }

    private static function dispatcher(): NotificationDispatcher
    {
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');

        return $dispatcher;
    }

    /**
     * Whether $steps is a list of at least one step, each whole seconds
     * above 0.
     *
     * @param array<mixed> $steps
     */
    private static function isBackoff(array $steps): bool
    {
        if ($steps === [] || !array_is_list($steps)) {
            return false;
        }
        foreach ($steps as $step) {
            if (!is_int($step) || $step < 1) {
                return false;
            }
        }

        return true;
    }
}
