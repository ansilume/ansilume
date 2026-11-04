<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\components\OutboundHttpException;
use app\components\OutboundHttpResponse;
use app\models\AuditLog;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\NotificationThread;
use app\models\User;
use app\services\NotificationDispatcher;
use app\services\notification\NotificationDeliveryService;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\TeamScopeFixtures;
use app\tests\unit\services\notification\HttpLoopbackServer;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\db\BaseActiveRecord;
use yii\log\Logger;

/**
 * How the notification worker delivers one row of the outbox
 * (NotificationDeliveryService::deliver()): it claims the row with a
 * conditional update, waits for the earlier rows of its run's thread,
 * drops a status that a later one replaces, checks again that the template
 * may deliver the event (NotificationVisibility), sends it through the
 * template's channel as the dispatcher renders it, and records the
 * outcome: delivered, retried with the backoff, failed, skipped or
 * superseded, with one audit entry per final outcome and none per attempt.
 * A channel's error never leaves deliver(), and is kept only as ErrorText
 * gives it.
 */
class NotificationDeliveryServiceTest extends DbTestCase
{
    use RestoresComponents;
    use TeamScopeFixtures;
    use DeliveryFixtures;

    private ?HttpLoopbackServer $server = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTestDoubles();
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        $this->server?->stop();
        parent::tearDown();
    }

    // -- The claim ----------------------------------------------------------------

    /**
     * A delivery is claimed with a conditional update: of two messages for
     * it (a duplicate push, or one the sweeper pushed again), only one
     * sends, also when the second comes while the first sends.
     */
    public function testADeliveryIsClaimedOnce(): void
    {
        $delivery = $this->delivery();
        $id = (int)$delivery->id;
        $this->channel->during = function () use ($id): void {
            $this->service()->deliver($id);
        };
        $before = time();

        $this->service()->deliver($id);
        $this->channel->during = null;
        $this->service()->deliver($id);

        $this->assertSame([[
            'template' => (int)$delivery->notification_template_id,
            'subject' => 'Job #7 failed',
            'body' => 'Template: deploy',
            'variables' => ['job.id' => '7', 'job.status' => 'failed', 'template.name' => 'deploy'],
        ]], $this->channel->calls);
        $row = $this->reload($delivery);
        $this->assertSame(NotificationDelivery::STATUS_DELIVERED, $row->status);
        $this->assertSame(1, (int)$row->attempts);
        $this->assertNull($row->last_error);
        $this->assertNull($row->remote_id);
        $this->assertGreaterThanOrEqual($before, (int)$row->claimed_at);
        $this->assertGreaterThanOrEqual((int)$row->claimed_at, (int)$row->finished_at);
        $this->assertSame([], $this->queue->pushes, 'nothing is pushed again');
        $this->assertSame([AuditLog::ACTION_NOTIFICATION_DISPATCHED], array_keys($this->auditOf($delivery)));
    }

    /**
     * A message that comes before the delivery is due (a stray push during
     * its backoff) claims nothing; one for a delivery that does not exist
     * (deleted with its template or its run) neither.
     */
    public function testNothingIsClaimedBeforeItIsDueOrThatIsGone(): void
    {
        $delivery = $this->delivery(['next_attempt_at' => time() + 600]);

        $this->service()->deliver((int)$delivery->id);
        $this->service()->deliver(987654321);

        $this->assertSame([], $this->channel->calls);
        $row = $this->reload($delivery);
        $this->assertSame(NotificationDelivery::STATUS_PENDING, $row->status);
        $this->assertNull($row->claimed_at);
        $this->assertSame([], $this->queue->pushes);
    }

    // -- The order of a thread ------------------------------------------------------

    /**
     * A delivery of a run's thread waits until every earlier delivery of
     * the same template and run is finished: it is put back, unclaimed and
     * without counting an attempt, and pushed again for when the earliest
     * of them is due, THREAD_WAIT_SECONDS later, at least
     * THREAD_WAIT_SECONDS from now. Deliveries of another run or another
     * template do not wait for it. The status post so always exists before
     * an edit or a reply.
     */
    public function testAThreadRowWaitsForEarlierRowsOfItsRun(): void
    {
        $template = $this->template();
        $run = $this->runId();
        $first = $this->delivery(['event' => NotificationTemplate::EVENT_WORKFLOW_LAUNCHED, 'workflow_job_id' => $run, 'next_attempt_at' => time() + 100, 'variables' => '{"job.id":"7","job.status":"launched"}'], $template);
        $second = $this->delivery(['event' => NotificationTemplate::EVENT_WORKFLOW_FAILED, 'workflow_job_id' => $run], $template);
        $otherRun = $this->delivery(['event' => NotificationTemplate::EVENT_WORKFLOW_FAILED, 'workflow_job_id' => $this->runId()], $template);
        $otherTemplate = $this->delivery(['event' => NotificationTemplate::EVENT_WORKFLOW_FAILED, 'workflow_job_id' => $run]);
        $before = time();

        $this->service()->deliver((int)$second->id);

        $after = time();
        $this->assertSame([], $this->channel->calls);
        $row = $this->reload($second);
        $this->assertSame(NotificationDelivery::STATUS_PENDING, $row->status);
        $this->assertNull($row->claimed_at);
        $this->assertSame(0, (int)$row->attempts, 'a wait is no attempt');
        $this->assertNull($row->last_error);
        $due = (int)$first->next_attempt_at + NotificationDeliveryService::THREAD_WAIT_SECONDS;
        $this->assertGreaterThanOrEqual($due, (int)$row->next_attempt_at);
        $this->assertLessThanOrEqual($due + ($after - $before), (int)$row->next_attempt_at);
        $this->assertSame([(int)$second->id], $this->queue->pushedIds());
        $this->assertGreaterThanOrEqual($due - $after, $this->queue->pushes[0]['delay']);
        $this->assertLessThanOrEqual($due - $before, $this->queue->pushes[0]['delay']);
        $this->assertSame([], $this->auditOf($second), 'a wait writes no audit entry');

        $this->service()->deliver((int)$otherRun->id);
        $this->service()->deliver((int)$otherTemplate->id);
        $this->assertCount(2, $this->channel->calls, 'another run and another template do not wait');

        NotificationDelivery::updateAll(['next_attempt_at' => time()], ['id' => [$first->id, $second->id]]);
        $this->service()->deliver((int)$first->id);
        $this->service()->deliver((int)$second->id);
        $this->assertSame(NotificationDelivery::STATUS_DELIVERED, $this->reload($second)->status, 'once the first is finished');
        $this->assertSame(['Job #7 launched', 'Job #7 failed'], array_slice(array_column($this->channel->calls, 'subject'), 2), 'in the order of the run');
    }

    /**
     * An earlier delivery that a worker sends right now (sending) makes the
     * next one wait THREAD_WAIT_SECONDS.
     */
    public function testAThreadRowWaitsAShortWhileForOneBeingSent(): void
    {
        $template = $this->template();
        $run = $this->runId();
        $this->delivery(['workflow_job_id' => $run, 'status' => NotificationDelivery::STATUS_SENDING, 'claimed_at' => time(), 'next_attempt_at' => time() - 50], $template);
        $second = $this->delivery(['event' => NotificationTemplate::EVENT_WORKFLOW_FAILED, 'workflow_job_id' => $run], $template);

        $this->service()->deliver((int)$second->id);

        $this->assertSame([], $this->channel->calls);
        $this->assertSame([['deliveryId' => (int)$second->id, 'delay' => NotificationDeliveryService::THREAD_WAIT_SECONDS]], $this->queue->pushes);
        $this->assertSame(5, NotificationDeliveryService::THREAD_WAIT_SECONDS);
    }

    // -- Errors that a later attempt may get past ---------------------------------------

    /**
     * @return array<string, array{0: \Throwable, 1: string}>
     */
    public static function transientErrors(): array
    {
        $cases = [
            'a timeout' => [self::httpError(OutboundHttpException::KIND_TIMEOUT, sprintf(OutboundHttpException::TIMED_OUT, 15)), 'WebhookChannel: The request timed out after 15 seconds.'],
            'no connection' => [self::httpError(OutboundHttpException::KIND_CONNECT, sprintf(OutboundHttpException::CONNECT_FAILED, 7)), 'WebhookChannel: Could not connect (curl error 7).'],
            'a TLS error' => [self::httpError(OutboundHttpException::KIND_TLS, sprintf(OutboundHttpException::TLS_FAILED, 60)), 'WebhookChannel: The TLS connection failed (curl error 60).'],
            'the mailer' => [new \RuntimeException('Mailer::send() returned false'), 'Mailer::send() returned false'],
            'a database error, by its summary only' => [
                new \yii\db\Exception("SQLSTATE[HY000]: General error: 2006\nThe SQL being executed was: SELECT 'marker-4711'", ['HY000', 2006, 'gone away']),
                'yii\db\Exception (SQLSTATE HY000, driver error 2006)',
            ],
        ];
        foreach ([408, 425, 429, 500, 502, 503, 504] as $status) {
            $cases["HTTP {$status}"] = [self::statusError($status), "WebhookChannel: HTTP {$status}."];
        }

        return $cases;
    }

    /**
     * An error a later attempt may get past is retried after the backoff's
     * first step: the delivery is pending again with the error, the attempt
     * counted, and pushed with the delay; no audit entry yet, a warning in
     * the log.
     */
    #[DataProvider('transientErrors')]
    public function testTransientErrorsRetryWithTheBackoff(\Throwable $error, string $text): void
    {
        $delivery = $this->delivery();
        $this->channel->script = [$error];
        $before = time();

        $logs = $this->logsDuring(fn () => $this->service()->deliver((int)$delivery->id));

        $after = time();
        $row = $this->reload($delivery);
        $this->assertSame(NotificationDelivery::STATUS_PENDING, $row->status);
        $this->assertSame(1, (int)$row->attempts);
        $this->assertSame($text, $row->last_error);
        $this->assertNull($row->claimed_at);
        $this->assertNull($row->finished_at);
        $this->assertGreaterThanOrEqual($before + 30, (int)$row->next_attempt_at);
        $this->assertLessThanOrEqual($after + 30, (int)$row->next_attempt_at);
        $this->assertSame([['deliveryId' => (int)$delivery->id, 'delay' => 30]], $this->queue->pushes);
        $this->assertSame([], $this->auditOf($delivery), 'no audit entry per attempt');
        $template = NotificationTemplate::findOne($delivery->notification_template_id);
        $this->assertInstanceOf(NotificationTemplate::class, $template);
        $this->assertSame(
            [sprintf(NotificationDeliveryService::RETRYING, (int)$delivery->id, (int)$template->id, 'email', 'webhook.invalid_token', 1, 8, 30, $text)],
            self::serviceLines($logs, Logger::LEVEL_WARNING)
        );
        foreach ($logs as [, , $line]) {
            $this->assertStringNotContainsString('marker-4711', $line);
        }
    }

    /**
     * Retries follow the backoff: 30 seconds, 2, 10 and 30 minutes, 1, 2
     * and 4 hours; the eighth attempt that fails fails the delivery.
     */
    public function testTheBackoffGrowsUntilTheLastAttempt(): void
    {
        $delivery = $this->delivery();
        $this->channel->script = array_fill(0, 8, self::statusError(503));
        $service = $this->service();
        $this->assertSame([30, 120, 600, 1800, 3600, 7200, 14400], $service->backoffSeconds);
        $this->assertSame(8, $service->maxAttempts);

        for ($attempt = 1; $attempt <= 8; $attempt++) {
            NotificationDelivery::updateAll(['next_attempt_at' => time()], ['id' => $delivery->id]);
            $service->deliver((int)$delivery->id);
        }

        $this->assertSame([30, 120, 600, 1800, 3600, 7200, 14400], array_column($this->queue->pushes, 'delay'));
        $row = $this->reload($delivery);
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $row->status);
        $this->assertSame(8, (int)$row->attempts);
        $this->assertSame('WebhookChannel: HTTP 503.', $row->last_error);
        $this->assertCount(8, $this->channel->calls);
        $audit = $this->auditOf($delivery);
        $this->assertSame([AuditLog::ACTION_NOTIFICATION_FAILED], array_keys($audit));
        $this->assertCount(1, $audit[AuditLog::ACTION_NOTIFICATION_FAILED], 'one entry for the final outcome');
        $this->assertSame(8, $audit[AuditLog::ACTION_NOTIFICATION_FAILED][0]['attempts']);
    }

    /**
     * maxAttempts and backoffSeconds are the component's: after the last
     * step, every retry waits as long as the last step.
     */
    public function testAfterMaxAttemptsTheDeliveryFails(): void
    {
        $this->swapComponent('notificationDeliveryService', ['class' => NotificationDeliveryService::class, 'maxAttempts' => 4, 'backoffSeconds' => [10, 20]]);
        $delivery = $this->delivery();
        $this->channel->script = array_fill(0, 4, new \RuntimeException('Mailer::send() returned false'));

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            NotificationDelivery::updateAll(['next_attempt_at' => time()], ['id' => $delivery->id]);
            $logs = $this->logsDuring(fn () => $this->service()->deliver((int)$delivery->id));
        }

        $this->assertSame([10, 20, 20], array_column($this->queue->pushes, 'delay'));
        $row = $this->reload($delivery);
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $row->status);
        $this->assertSame(4, (int)$row->attempts);
        $this->assertNotNull($row->finished_at);
        $template = NotificationTemplate::findOne($delivery->notification_template_id);
        $this->assertInstanceOf(NotificationTemplate::class, $template);
        $this->assertSame(
            [sprintf(NotificationDeliveryService::FAILED, (int)$delivery->id, (int)$template->id, 'email', 'webhook.invalid_token', 4, 'Mailer::send() returned false')],
            self::serviceLines($logs, Logger::LEVEL_ERROR)
        );
    }

    /**
     * The status, Retry-After (a number of seconds ahead for an HTTP date:
     * the date is made when the test runs), and the delay expected.
     *
     * @return array<string, array{0: int, 1: string|int|null, 2: int}>
     */
    public static function retryAfterAnswers(): array
    {
        return [
            'longer than the step' => [429, '90', 90],
            'shorter than the step: the step' => [429, '5', 30],
            'beyond the cap: the cap' => [503, '999999', 3600],
            'an HTTP date' => [429, 600, 600],
            'no Retry-After: the step' => [503, null, 30],
        ];
    }

    /**
     * A Retry-After of an answer that is retried (408, 425, 429, 5xx) is
     * honoured, at least the backoff's step, at most
     * RETRY_AFTER_MAX_SECONDS.
     */
    #[DataProvider('retryAfterAnswers')]
    public function testRetryAfterIsHonouredWithinTheBackoffAndTheCap(int $status, string|int|null $retryAfter, int $delay): void
    {
        $delivery = $this->delivery();
        if (is_int($retryAfter)) {
            $retryAfter = gmdate('D, d M Y H:i:s', time() + $retryAfter) . ' GMT';
        }
        $this->channel->script = [self::statusError($status, $retryAfter === null ? [] : ['retry-after' => $retryAfter])];

        $this->service()->deliver((int)$delivery->id);

        $this->assertCount(1, $this->queue->pushes);
        $this->assertEqualsWithDelta($delay, $this->queue->pushes[0]['delay'], 1.0);
        $this->assertSame(3600, NotificationDeliveryService::RETRY_AFTER_MAX_SECONDS);
    }

    // -- Errors that fail at once ---------------------------------------------------------

    /**
     * @return array<string, array{0: \Throwable, 1: string}>
     */
    public static function permanentErrors(): array
    {
        $cases = [
            'a scheme other than http or https' => [self::httpError(OutboundHttpException::KIND_SCHEME, OutboundHttpException::SCHEME_REFUSED), 'WebhookChannel: The URL must use http or https.'],
            'a header with a line break' => [self::httpError(OutboundHttpException::KIND_REQUEST, OutboundHttpException::HEADER_REFUSED), 'WebhookChannel: A request header holds a line break or a NUL byte.'],
        ];
        foreach ([302, 400, 401, 403, 404, 413, 422] as $status) {
            $cases["HTTP {$status}"] = [self::statusError($status), "WebhookChannel: HTTP {$status}."];
        }

        return $cases;
    }

    /**
     * An error that a later attempt would meet again fails the delivery at
     * once: one attempt, the error, finished, one notification.failed
     * entry, an error in the log, nothing pushed again.
     */
    #[DataProvider('permanentErrors')]
    public function testPermanentErrorsFailAtOnce(\Throwable $error, string $text): void
    {
        $delivery = $this->delivery();
        $this->channel->script = [$error];
        $before = time();

        $logs = $this->logsDuring(fn () => $this->service()->deliver((int)$delivery->id));

        $row = $this->reload($delivery);
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $row->status);
        $this->assertSame(1, (int)$row->attempts);
        $this->assertSame($text, $row->last_error);
        $this->assertGreaterThanOrEqual($before, (int)$row->finished_at);
        $this->assertSame([], $this->queue->pushes);
        $audit = $this->auditOf($delivery);
        $template = NotificationTemplate::findOne($delivery->notification_template_id);
        $this->assertInstanceOf(NotificationTemplate::class, $template);
        $this->assertSame([AuditLog::ACTION_NOTIFICATION_FAILED => [[
            'template' => $template->name,
            'channel' => 'email',
            'event' => 'webhook.invalid_token',
            'delivery_id' => (int)$delivery->id,
            'kind' => NotificationDelivery::KIND_MESSAGE,
            'attempts' => 1,
            'error' => $text,
        ]]], $audit);
        $this->assertSame(
            [sprintf(NotificationDeliveryService::FAILED, (int)$delivery->id, (int)$template->id, 'email', 'webhook.invalid_token', 1, $text)],
            self::serviceLines($logs, Logger::LEVEL_ERROR)
        );
    }

    /**
     * A template whose channel no handler sends fails at once, with the
     * dispatcher's error.
     */
    public function testAnUnknownChannelFailsAtOnce(): void
    {
        $delivery = $this->delivery([], $this->template('carrier-pigeon', '{}'));

        $this->service()->deliver((int)$delivery->id);

        $row = $this->reload($delivery);
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $row->status);
        $this->assertSame(sprintf(NotificationDispatcher::UNKNOWN_CHANNEL, 'carrier-pigeon'), $row->last_error);
        $this->assertSame([], $this->channel->calls);
    }

    /**
     * The status post of a run and the replies in its thread need a channel
     * that keeps a post: none of today's channels does, so such a delivery
     * fails at once (as after a template's channel changed while its
     * deliveries waited).
     */
    public function testAStatusOrAReplyFailsOnAChannelThatKeepsNoPost(): void
    {
        $run = $this->runId();
        $template = $this->template();
        foreach ([NotificationDelivery::KIND_STATUS, NotificationDelivery::KIND_REPLY] as $kind) {
            $delivery = $this->delivery(['event' => NotificationTemplate::EVENT_WORKFLOW_FAILED, 'kind' => $kind, 'workflow_job_id' => $run], $template);

            $this->service()->deliver((int)$delivery->id);

            $row = $this->reload($delivery);
            $this->assertSame(NotificationDelivery::STATUS_FAILED, $row->status, $kind);
            $this->assertSame(sprintf(NotificationDeliveryService::NOT_THREADED, 'email', $kind), $row->last_error);
        }
        $this->assertSame([], $this->channel->calls);
    }

    // -- What is not sent ------------------------------------------------------------------

    /**
     * Regression (the check of section 4 of the workflow notifications
     * design runs when the event happens and again when it is delivered):
     * a delivery whose identity may no longer see its event, because the
     * user left the team, or is inactive now, is skipped: not sent, its
     * reason kept, one notification.skipped entry.
     */
    public function testADeliveryTheIdentityMayNoLongerSeeIsSkipped(): void
    {
        $scope = $this->teamScope();
        $template = $this->createNotificationTemplate((int)$scope['member']->id);
        $foreignJob = $this->createJob((int)$scope['foreign']->id, (int)$scope['admin']->id);
        $hidden = $this->delivery(['event' => NotificationTemplate::EVENT_JOB_FAILED, 'job_id' => (int)$foreignJob->id], $template);

        $logs = $this->logsDuring(fn () => $this->service()->deliver((int)$hidden->id));

        $reason = sprintf(NotificationDeliveryService::NOT_VISIBLE, (int)$scope['member']->id);
        $row = $this->reload($hidden);
        $this->assertSame(NotificationDelivery::STATUS_SKIPPED, $row->status);
        $this->assertSame($reason, $row->last_error);
        $this->assertSame(0, (int)$row->attempts, 'nothing was sent');
        $this->assertNotNull($row->finished_at);
        $this->assertSame([], $this->channel->calls);
        $this->assertSame([], $this->queue->pushes);
        $this->assertSame([AuditLog::ACTION_NOTIFICATION_SKIPPED => [[
            'template' => $template->name,
            'channel' => 'email',
            'event' => NotificationTemplate::EVENT_JOB_FAILED,
            'delivery_id' => (int)$hidden->id,
            'kind' => NotificationDelivery::KIND_MESSAGE,
            'attempts' => 0,
            'reason' => $reason,
        ]]], $this->auditOf($hidden));
        $this->assertSame(
            [sprintf(NotificationDeliveryService::NOT_SENT, (int)$hidden->id, (int)$template->id, NotificationTemplate::EVENT_JOB_FAILED, $reason)],
            self::serviceLines($logs, Logger::LEVEL_INFO)
        );

        $ownJob = $this->createJob((int)$scope['own']->id, (int)$scope['admin']->id);
        $visible = $this->delivery(['event' => NotificationTemplate::EVENT_JOB_FAILED, 'job_id' => (int)$ownJob->id], $template);
        $scope['member']->status = User::STATUS_INACTIVE;
        $scope['member']->save(false);

        $this->service()->deliver((int)$visible->id);

        $this->assertSame(NotificationDelivery::STATUS_SKIPPED, $this->reload($visible)->status);
        $this->assertSame(sprintf(NotificationDeliveryService::INACTIVE_IDENTITY, (int)$scope['member']->id), $this->reload($visible)->last_error);
        $this->assertSame([], $this->channel->calls);
    }

    /**
     * A status of a run's thread whose post exists is superseded by a later
     * status of the same thread that is not finished: the later one renders
     * the run as it is then. Not sent, no attempt, no audit entry. A reply
     * is never superseded; without a post yet the first status is sent, to
     * create it (here it fails: no channel of today keeps a post).
     */
    public function testAStatusIsSupersededByALaterStatusOfItsThread(): void
    {
        $template = $this->template();
        $withPost = $this->runId();
        $this->thread($template, $withPost);
        $older = $this->delivery(['event' => NotificationTemplate::EVENT_WORKFLOW_LAUNCHED, 'kind' => NotificationDelivery::KIND_STATUS, 'workflow_job_id' => $withPost], $template);
        $reply = $this->delivery(['event' => NotificationTemplate::EVENT_WORKFLOW_FAILED, 'kind' => NotificationDelivery::KIND_REPLY, 'workflow_job_id' => $withPost], $template);
        $later = $this->delivery(['event' => NotificationTemplate::EVENT_WORKFLOW_FAILED, 'kind' => NotificationDelivery::KIND_STATUS, 'workflow_job_id' => $withPost, 'next_attempt_at' => time() + 3600], $template);

        $this->service()->deliver((int)$older->id);
        $this->service()->deliver((int)$reply->id);

        $row = $this->reload($older);
        $this->assertSame(NotificationDelivery::STATUS_SUPERSEDED, $row->status);
        $this->assertNull($row->last_error);
        $this->assertSame(0, (int)$row->attempts);
        $this->assertNotNull($row->finished_at);
        $this->assertSame([], $this->auditOf($older), 'a superseded status writes no audit entry');
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $this->reload($reply)->status, 'a reply is never superseded');
        $this->assertSame(NotificationDelivery::STATUS_PENDING, $this->reload($later)->status);

        $withoutPost = $this->runId();
        $first = $this->delivery(['event' => NotificationTemplate::EVENT_WORKFLOW_LAUNCHED, 'kind' => NotificationDelivery::KIND_STATUS, 'workflow_job_id' => $withoutPost], $template);
        $this->delivery(['event' => NotificationTemplate::EVENT_WORKFLOW_FAILED, 'kind' => NotificationDelivery::KIND_STATUS, 'workflow_job_id' => $withoutPost, 'next_attempt_at' => time() + 3600], $template);

        $this->service()->deliver((int)$first->id);

        $this->assertSame(NotificationDelivery::STATUS_FAILED, $this->reload($first)->status, 'sent to create the post');
        $this->assertSame(sprintf(NotificationDeliveryService::NOT_THREADED, 'email', NotificationDelivery::KIND_STATUS), $this->reload($first)->last_error);
    }

    // -- The audit -----------------------------------------------------------------------

    /**
     * One audit entry per final outcome, none per attempt or wait:
     * notification.dispatched with the subject sent, notification.failed
     * with the error, notification.skipped with the reason; none for a
     * superseded status. Entries of the worker carry no user.
     */
    public function testOneAuditEntryPerFinalOutcome(): void
    {
        $template = $this->template();
        $delivered = $this->delivery([], $template);
        $retried = $this->delivery([], $template);
        $this->channel->script = [null, self::statusError(503), self::statusError(503)];
        $this->swapComponent('notificationDeliveryService', ['class' => NotificationDeliveryService::class, 'maxAttempts' => 2]);

        $this->service()->deliver((int)$delivered->id);
        $this->service()->deliver((int)$retried->id);
        $this->assertSame([], $this->auditOf($retried), 'the first attempt failed: no entry yet');
        NotificationDelivery::updateAll(['next_attempt_at' => time()], ['id' => $retried->id]);
        $this->service()->deliver((int)$retried->id);
        $this->service()->deliver((int)$retried->id);
        $this->service()->deliver((int)$delivered->id);

        $this->assertSame([AuditLog::ACTION_NOTIFICATION_DISPATCHED => [[
            'template' => $template->name,
            'channel' => 'email',
            'event' => 'webhook.invalid_token',
            'delivery_id' => (int)$delivered->id,
            'kind' => NotificationDelivery::KIND_MESSAGE,
            'attempts' => 1,
            'subject' => 'Job #7 failed',
        ]]], $this->auditOf($delivered));
        $failed = $this->auditOf($retried);
        $this->assertSame([AuditLog::ACTION_NOTIFICATION_FAILED], array_keys($failed));
        $this->assertCount(1, $failed[AuditLog::ACTION_NOTIFICATION_FAILED]);
        $this->assertSame(['WebhookChannel: HTTP 503.', 2], [$failed[AuditLog::ACTION_NOTIFICATION_FAILED][0]['error'], $failed[AuditLog::ACTION_NOTIFICATION_FAILED][0]['attempts']]);
        $this->assertSame('notification.skipped', AuditLog::ACTION_NOTIFICATION_SKIPPED);
    }

    /**
     * The subject in notification.dispatched is cut to 200 characters, as
     * the dispatcher cuts it.
     */
    public function testTheSubjectOfADispatchedEntryIsCut(): void
    {
        $template = $this->template();
        $template->subject_template = str_repeat('s', 250);
        $template->save(false);
        $delivery = $this->delivery([], $template);

        $this->service()->deliver((int)$delivery->id);

        $this->assertSame(str_repeat('s', 200), $this->auditOf($delivery)[AuditLog::ACTION_NOTIFICATION_DISPATCHED][0]['subject']);
    }

    // -- A worker that comes back late ---------------------------------------------------------

    /**
     * A worker records the outcome only while it still holds its claim: a
     * delivery the sweeper gave back to the queue meanwhile (the worker
     * took longer than STALE_SENDING_SECONDS) keeps what the sweeper set,
     * and no audit entry is written for it.
     */
    public function testALateWorkerDoesNotOverwriteADeliveryTheSweeperGaveBack(): void
    {
        $delivery = $this->delivery();
        $id = (int)$delivery->id;
        $this->channel->during = static function () use ($id): void {
            NotificationDelivery::updateAll(['status' => NotificationDelivery::STATUS_PENDING, 'claimed_at' => null, 'attempts' => 1], ['id' => $id]);
        };

        $this->service()->deliver($id);

        $row = $this->reload($delivery);
        $this->assertSame(NotificationDelivery::STATUS_PENDING, $row->status);
        $this->assertSame(1, (int)$row->attempts);
        $this->assertNull($row->finished_at);
        $this->assertSame([], $this->auditOf($delivery));
        $this->assertSame([], $this->queue->pushes);
    }

    /**
     * A retry of a delivery that the sweep gave back meanwhile changes
     * nothing either: the sweep's state stays, and nothing is pushed or
     * logged as a retry.
     */
    public function testARetryOfADeliveryTheSweepGaveBackChangesNothing(): void
    {
        $delivery = $this->delivery();
        $id = (int)$delivery->id;
        $this->channel->during = static function () use ($id): void {
            NotificationDelivery::updateAll(
                ['status' => NotificationDelivery::STATUS_PENDING, 'claimed_at' => null, 'attempts' => 1, 'last_error' => NotificationDeliveryService::WORKER_STOPPED],
                ['id' => $id]
            );
        };
        $this->channel->script = [self::statusError(503)];

        $logs = $this->logsDuring(fn () => $this->service()->deliver($id));

        $row = $this->reload($delivery);
        $this->assertSame([NotificationDelivery::STATUS_PENDING, 1, NotificationDeliveryService::WORKER_STOPPED], [$row->status, (int)$row->attempts, $row->last_error]);
        $this->assertSame([], $this->queue->pushes);
        $this->assertSame([], self::serviceLines($logs, Logger::LEVEL_WARNING));
    }

    /**
     * A delivery whose template is deleted right after the claim goes with
     * it (the foreign key cascades): nothing is sent or recorded.
     */
    public function testADeliveryWhoseTemplateIsDeletedAfterTheClaimIsLeftAlone(): void
    {
        $delivery = $this->delivery();
        $template = NotificationTemplate::findOne($delivery->notification_template_id);
        $this->assertInstanceOf(NotificationTemplate::class, $template);
        $deleteTemplate = static function () use ($template): void {
            $template->delete();
        };

        Event::on(NotificationDelivery::class, BaseActiveRecord::EVENT_AFTER_FIND, $deleteTemplate);
        try {
            $this->service()->deliver((int)$delivery->id);
        } finally {
            Event::off(NotificationDelivery::class, BaseActiveRecord::EVENT_AFTER_FIND, $deleteTemplate);
        }

        $this->assertNull(NotificationDelivery::findOne($delivery->id));
        $this->assertSame([], $this->channel->calls);
        $this->assertSame([], $this->queue->pushes);
    }

    // -- The queue ---------------------------------------------------------------------------

    /**
     * A push that fails (Redis cannot be reached, its command in the
     * error's message) never throws: it is logged as the error's class,
     * SQLSTATE and driver error code only, and the maintenance run pushes
     * the delivery again.
     */
    public function testEnqueuePushNeverThrows(): void
    {
        $this->queue->failWith = new \yii\redis\SocketException('Failed to write to socket. RPUSH ansilume-notifications marker-4712');

        $logs = $this->logsDuring(fn () => $this->assertFalse($this->service()->enqueuePush(42, 30)));

        $this->assertSame(
            [sprintf(NotificationDeliveryService::PUSH_FAILED, 42, \yii\redis\SocketException::class, '-', '-')],
            self::serviceLines($logs, Logger::LEVEL_ERROR)
        );
        foreach ($logs as [, , $line]) {
            $this->assertStringNotContainsString('marker-4712', $line);
        }
        $this->queue->failWith = null;
        $this->assertTrue($this->service()->enqueuePush(42, 30));
        $this->assertTrue($this->service()->enqueuePush(43, -5));
        $this->assertSame([['deliveryId' => 42, 'delay' => 30], ['deliveryId' => 43, 'delay' => 0]], $this->queue->pushes);
    }

    /**
     * A retry whose push fails stays pending, due when its backoff passed:
     * the maintenance run pushes it again then.
     */
    public function testARetryWhosePushFailedIsLeftToTheSweeper(): void
    {
        $delivery = $this->delivery();
        $this->channel->script = [self::statusError(503)];
        $this->queue->failWith = new \yii\redis\SocketException('Connection refused');

        $this->logsDuring(fn () => $this->service()->deliver((int)$delivery->id));

        $row = $this->reload($delivery);
        $this->assertSame(NotificationDelivery::STATUS_PENDING, $row->status);
        $this->assertSame(1, (int)$row->attempts);
        $this->assertGreaterThan(time(), (int)$row->next_attempt_at);
    }

    // -- Through a real channel -------------------------------------------------------------

    /**
     * The webhook channel through the outbound HTTP client, against a
     * local server: a 503 with Retry-After is retried after it, a 404
     * fails, a 200 delivers what the template renders.
     */
    public function testAWebhookTemplateIsDeliveredThroughTheOutboundHttpClient(): void
    {
        $this->server = new HttpLoopbackServer();
        $base = $this->server->startWith([
            '/busy' => ['status' => 503, 'headers' => ['Retry-After' => '120'], 'body' => 'secret-busy-body'],
            '/gone' => ['status' => 404, 'body' => 'secret-gone-body'],
            '/ok' => ['status' => 200, 'body' => 'ok'],
        ]);
        $deliveries = [];
        foreach (['busy', 'gone', 'ok'] as $path) {
            $deliveries[$path] = $this->delivery([], $this->template(NotificationTemplate::CHANNEL_WEBHOOK, (string)json_encode(['url' => $base . '/' . $path])));
            $this->service()->deliver((int)$deliveries[$path]->id);
        }

        $busy = $this->reload($deliveries['busy']);
        $this->assertSame([NotificationDelivery::STATUS_PENDING, 'WebhookChannel: HTTP 503.'], [$busy->status, $busy->last_error]);
        $this->assertSame([['deliveryId' => (int)$busy->id, 'delay' => 120]], $this->queue->pushes);
        $gone = $this->reload($deliveries['gone']);
        $this->assertSame([NotificationDelivery::STATUS_FAILED, 'WebhookChannel: HTTP 404.'], [$gone->status, $gone->last_error]);
        $this->assertSame(NotificationDelivery::STATUS_DELIVERED, $this->reload($deliveries['ok'])->status);
        $requests = $this->server->requests();
        $this->assertSame(['/busy', '/gone', '/ok'], array_column($requests, 'path'));
        $body = json_decode(end($requests)['body'], true);
        $this->assertIsArray($body);
        $this->assertSame(['Job #7 failed', 'Template: deploy'], [$body['subject'] ?? null, $body['body'] ?? null]);
    }

    // -- The component -----------------------------------------------------------------------------

    /**
     * The queue job finds the service in the application, so the web and
     * console configuration (config/services.php) and the tests' must both
     * register it.
     */
    public function testIsRegisteredAsAnApplicationComponentEverywhere(): void
    {
        /** @var array<string, array<string, mixed>> $services */
        $services = require dirname(__DIR__, 4) . '/config/services.php';
        $test = require dirname(__DIR__, 4) . '/config/test.php';

        $this->assertSame(NotificationDeliveryService::class, $services['notificationDeliveryService']['class'] ?? null);
        $this->assertSame(NotificationDeliveryService::class, $test['components']['notificationDeliveryService']['class'] ?? null);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidConfigs(): array
    {
        return [
            'no backoff' => [['backoffSeconds' => []]],
            'a step of no seconds' => [['backoffSeconds' => [30, 0]]],
            'a step that is no number' => [['backoffSeconds' => [30, '60']]],
            'no attempt' => [['maxAttempts' => 0]],
        ];
    }

    /**
     * @dataProvider invalidConfigs
     *
     * @param array<string, mixed> $config
     */
    public function testAConfigurationWithoutAWayToRetryIsRefused(array $config): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(NotificationDeliveryService::INVALID_RETRIES);

        new NotificationDeliveryService($config);
    }

    // -- Helpers --------------------------------------------------------------------------------

    /** The status post of $template for run $runId, as a channel that keeps one records it. */
    private function thread(NotificationTemplate $template, int $runId): void
    {
        $thread = new NotificationThread();
        $thread->notification_template_id = (int)$template->id;
        $thread->workflow_job_id = $runId;
        $thread->root_post_id = 'post-1';
        $thread->channel_id = 'channel-1';
        $thread->server = 'https://mattermost.example.com';
        $this->assertTrue($thread->save(), (string)json_encode($thread->errors));
    }

    private static function httpError(string $kind, string $message): OutboundHttpException
    {
        return (new OutboundHttpException($kind, $message))->from('WebhookChannel');
    }

    /**
     * @param array<string, string> $headers
     */
    private static function statusError(int $status, array $headers = []): OutboundHttpException
    {
        return (new OutboundHttpException(
            OutboundHttpException::KIND_STATUS,
            sprintf(OutboundHttpException::HTTP_STATUS, $status),
            $status,
            new OutboundHttpResponse($status, $headers, 'secret-answer-body')
        ))->from('WebhookChannel');
    }
}
