<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\AppLinks;
use app\components\OutboundHttpException;
use app\models\AuditLog;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\User;
use app\services\NotificationDispatcher;
use app\services\notification\ChannelInterface;
use app\services\notification\JobPayloadBuilder;
use app\services\notification\NotificationDeliveryService;
use app\services\notification\NotificationOutbox;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\services\notification\DeliveryFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\db\ActiveRecord;
use yii\log\Logger;

/**
 * Integration tests for NotificationDispatcher. dispatch() sends nothing
 * itself: it writes a delivery to the outbox for each template that may
 * deliver the event and pushes it to the notification queue, and it never
 * throws into the process that caused the event; the notification worker
 * sends each delivery and writes its audit entry (NotificationDeliveryService).
 * sendSingle(), the test send of a template, still sends at once.
 */
class NotificationDispatcherTest extends DbTestCase
{
    use RestoresComponents;
    use TeamScopeFixtures;
    use DeliveryFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTestDoubles();
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    // -- dispatch(): the outbox ---------------------------------------------------------

    /**
     * Regression of the synchronous sending (workflow notifications plan,
     * Task 7): dispatch() sent every template in the caller's process, a
     * runner's report, a vote, a web request. It now writes one pending
     * delivery, due now, for each template that listens, and pushes it; the
     * worker sends it.
     */
    public function testDispatchQueuesADeliveryForEachTemplateThatListens(): void
    {
        $matching = $this->runnerTemplate(NotificationTemplate::EVENT_RUNNER_OFFLINE);
        $other = $this->runnerTemplate(NotificationTemplate::EVENT_RUNNER_RECOVERED);

        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_RUNNER_OFFLINE, $this->runnerPayload());

        $this->assertSame([], $this->channel->calls, 'nothing is sent in the caller\'s process');
        $this->assertSame([], $this->deliveriesOf($other));
        $delivery = $this->onlyDeliveryOf($matching);
        $this->assertSame(NotificationDelivery::STATUS_PENDING, $delivery->status);
        $this->assertSame(NotificationTemplate::EVENT_RUNNER_OFFLINE, $delivery->event);
        $this->assertSame([['deliveryId' => (int)$delivery->id, 'delay' => 0]], $this->queue->pushes);

        $this->queue->run();

        $this->assertCount(1, $this->channel->calls);
        $this->assertSame((int)$matching->id, $this->channel->calls[0]['template']);
        $this->assertSame(NotificationDelivery::STATUS_DELIVERED, $this->reload($delivery)->status);
    }

    /**
     * A delivery keeps the event's variables as they were when it happened:
     * the payload flattened, with event, severity, timestamp and app.url,
     * its links absolute from appBaseUrl; the refs are no variables, they
     * are the delivery's refs. A job's event keeps these variables only:
     * never its extra vars, survey answers, credentials or output.
     */
    public function testADeliveryKeepsTheEventsVariablesAndItsRefs(): void
    {
        $admin = $this->createUserWithRole('disp-vars', 'admin');
        $job = $this->createJob((int)$this->jobTemplateOf($admin)->id, (int)$admin->id, \app\models\Job::STATUS_FAILED);
        $job->extra_vars = '{"db_password": "extra-vars-marker-4716"}';
        $job->runner_payload = '{"extra_vars": {"db_password": "extra-vars-marker-4716"}}';
        $job->save(false);
        $template = $this->createNotificationTemplate((int)$admin->id);

        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_JOB_FAILED, JobPayloadBuilder::build($job));

        $delivery = $this->onlyDeliveryOf($template);
        $variables = $delivery->getVariableMap();
        $this->assertSame(
            [
                'timestamp', 'app.url', 'job.id', 'job.status', 'job.exit_code', 'job.duration', 'job.url', 'job.template_id',
                'template.id', 'template.name', 'project.id', 'project.name', 'launched_by', 'event', 'severity', 'event_id', 'occurred_at',
            ],
            array_keys($variables)
        );
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string)($variables['event_id'] ?? ''), 'the event has an ID of its own');
        $this->assertEqualsWithDelta(time(), (int)($variables['occurred_at'] ?? 0), 5, 'occurred_at is the time of the event');
        $this->assertStringNotContainsString('extra-vars-marker-4716', (string)$delivery->variables);
        $this->assertSame(NotificationTemplate::EVENT_JOB_FAILED, $variables['event'] ?? null);
        $this->assertSame('critical', $variables['severity'] ?? null);
        $this->assertSame(AppLinks::base(), $variables['app.url'] ?? null);
        $this->assertSame((string)$job->id, $variables['job.id'] ?? null);
        $this->assertSame(AppLinks::job((int)$job->id), $variables['job.url'] ?? null);
        $this->assertSame((string)$admin->username, $variables['launched_by'] ?? null);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', (string)($variables['timestamp'] ?? ''));
        $this->assertSame([], preg_grep('/^refs(\.|$)/', array_keys($variables)), 'the refs are no variables');
        $this->assertSame(['job_id' => (int)$job->id], $delivery->getRefs());
        $this->assertSame(NotificationDelivery::KIND_MESSAGE, $delivery->kind);
    }

    public function testDispatchIsNoOpWhenNoTemplateListens(): void
    {
        $template = $this->runnerTemplate(NotificationTemplate::EVENT_RUNNER_RECOVERED);

        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_RUNNER_OFFLINE, $this->runnerPayload());

        $this->assertSame([], $this->deliveriesOf($template));
        $this->assertSame([], $this->queue->pushes);
    }

    /**
     * What dispatch() queued the worker sends as the template renders it,
     * and records with one notification.dispatched entry, which carries no
     * user: the worker writes it.
     */
    public function testTheWorkerSendsWhatDispatchQueued(): void
    {
        $template = $this->runnerTemplate(NotificationTemplate::EVENT_RUNNER_OFFLINE);
        $template->subject_template = 'Runner {{ runner.name }} {{ event }}';
        $template->save(false);

        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_RUNNER_OFFLINE, $this->runnerPayload());
        $this->queue->run();

        $this->assertSame('Runner runner-7 runner.offline', $this->channel->calls[0]['subject'] ?? null);
        $entries = $this->auditOf($this->onlyDeliveryOf($template));
        $this->assertSame([AuditLog::ACTION_NOTIFICATION_DISPATCHED], array_keys($entries));
        $this->assertSame('Runner runner-7 runner.offline', $entries[AuditLog::ACTION_NOTIFICATION_DISPATCHED][0]['subject'] ?? null);
    }

    /**
     * Failures in one template's delivery never keep another from its
     * message: each is sent and recorded on its own.
     */
    public function testOneTemplatesFailureLeavesTheOthersTheirMessage(): void
    {
        $first = $this->runnerTemplate(NotificationTemplate::EVENT_RUNNER_OFFLINE);
        $second = $this->runnerTemplate(NotificationTemplate::EVENT_RUNNER_OFFLINE);
        $this->channel->script = [new OutboundHttpException(OutboundHttpException::KIND_STATUS, sprintf(OutboundHttpException::HTTP_STATUS, 404), 404)];

        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_RUNNER_OFFLINE, $this->runnerPayload());
        $this->queue->run();

        $this->assertSame(NotificationDelivery::STATUS_FAILED, $this->onlyDeliveryOf($first)->status);
        $this->assertSame(NotificationDelivery::STATUS_DELIVERED, $this->onlyDeliveryOf($second)->status);
        $this->assertSame([AuditLog::ACTION_NOTIFICATION_FAILED], array_keys($this->auditOf($this->onlyDeliveryOf($first))));
        $this->assertSame([AuditLog::ACTION_NOTIFICATION_DISPATCHED], array_keys($this->auditOf($this->onlyDeliveryOf($second))));
    }

    /**
     * Regression: a channel's database error reached the notification.failed
     * audit entry and the application log with its message, which carries the
     * SQL statement with its values. Both say its class, SQLSTATE and driver
     * error code only, now that the worker records it.
     */
    public function testRegressionADatabaseErrorOfAChannelWasStoredAndLoggedWithTheStatementAndItsValues(): void
    {
        $nt = $this->runnerTemplate(NotificationTemplate::EVENT_RUNNER_OFFLINE);
        $this->service()->maxAttempts = 1;
        $this->channel->script = [new \yii\db\Exception(
            "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away\n"
            . "The SQL being executed was: SELECT * FROM `user` WHERE `email`='marker-4711'",
            ['HY000', 2006, 'MySQL server has gone away']
        )];

        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_RUNNER_OFFLINE, $this->runnerPayload());
        $logs = $this->logsDuring(fn () => $this->queue->run());

        $delivery = $this->onlyDeliveryOf($nt);
        $row = $this->failureAuditOf($nt);
        $this->assertStringNotContainsString('marker-4711', (string)$row->metadata, 'no value reaches the audit entry');
        $this->assertSame('yii\db\Exception (SQLSTATE HY000, driver error 2006)', $this->errorOf($row));
        $this->assertSame('yii\db\Exception (SQLSTATE HY000, driver error 2006)', $this->reload($delivery)->last_error);
        $this->assertSame(
            [sprintf(NotificationDeliveryService::FAILED, (int)$delivery->id, $nt->id, 'email', 'runner.offline', 1, 'yii\db\Exception (SQLSTATE HY000, driver error 2006)')],
            self::serviceLines($logs, Logger::LEVEL_ERROR)
        );
        $this->assertStringNotContainsString('marker-4711', (string)json_encode($logs), 'no value reaches the log');
    }

    /**
     * Any other error of a channel keeps its message in the audit entry and
     * the log: an operator needs it to fix the channel.
     */
    public function testAChannelErrorOfAnotherKindKeepsItsMessage(): void
    {
        $nt = $this->runnerTemplate(NotificationTemplate::EVENT_RUNNER_OFFLINE);
        $this->service()->maxAttempts = 1;
        $this->channel->script = [new \RuntimeException('SlackChannel: HTTP 404 — no_service')];

        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_RUNNER_OFFLINE, $this->runnerPayload());
        $logs = $this->logsDuring(fn () => $this->queue->run());

        $delivery = $this->onlyDeliveryOf($nt);
        $this->assertSame('SlackChannel: HTTP 404 — no_service', $this->errorOf($this->failureAuditOf($nt)));
        $this->assertSame(
            [sprintf(NotificationDeliveryService::FAILED, (int)$delivery->id, $nt->id, 'email', 'runner.offline', 1, 'SlackChannel: HTTP 404 — no_service')],
            self::serviceLines($logs, Logger::LEVEL_ERROR)
        );
    }

    public function testUnknownChannelWritesFailureAudit(): void
    {
        $nt = $this->runnerTemplate(NotificationTemplate::EVENT_RUNNER_OFFLINE);
        $nt->channel = 'nonexistent';
        $nt->save(false);

        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_RUNNER_OFFLINE, $this->runnerPayload());
        $this->queue->run();

        $this->assertSame("Unknown channel 'nonexistent'", $this->errorOf($this->failureAuditOf($nt)));
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $this->onlyDeliveryOf($nt)->status);
    }

    // -- dispatch() never throws -----------------------------------------------------

    /**
     * A notification queue that cannot be reached (Redis down) loses
     * nothing and never reaches the caller: the delivery stays pending, the
     * push is logged (PUSH_FAILED, without the Redis command), and the
     * maintenance run's sweep pushes it again once it is overdue.
     */
    public function testDispatchNeverThrowsWhenTheQueueIsDown(): void
    {
        $nt = $this->runnerTemplate(NotificationTemplate::EVENT_RUNNER_OFFLINE);
        $this->queue->failWith = new \yii\redis\SocketException('Failed to write to socket. RPUSH ansilume-notifications marker-4713');

        $logs = $this->logsDuring(fn () => $this->dispatcher()->dispatch(NotificationTemplate::EVENT_RUNNER_OFFLINE, $this->runnerPayload()));

        $delivery = $this->onlyDeliveryOf($nt);
        $this->assertSame(NotificationDelivery::STATUS_PENDING, $delivery->status);
        $this->assertSame(
            [sprintf(NotificationDeliveryService::PUSH_FAILED, (int)$delivery->id, 'yii\redis\SocketException', '-', '-')],
            self::serviceLines($logs, Logger::LEVEL_ERROR)
        );
        $this->assertStringNotContainsString('marker-4713', (string)json_encode($logs));

        $this->queue->failWith = null;
        $this->service()->sweep(time() + NotificationDeliveryService::REPUSH_AFTER_SECONDS + 1);
        $this->assertSame([(int)$delivery->id], $this->queue->pushedIds(), 'the sweep pushes it again');
    }

    /**
     * An error before any template is judged, such as a database that went
     * away while the templates were read, is logged (NOT_QUEUED: the class,
     * SQLSTATE and driver error code, never the message, which carries the
     * statement) and never reaches the caller.
     */
    public function testDispatchNeverThrowsWhenTheTemplatesCannotBeRead(): void
    {
        $dispatcher = new class () extends NotificationDispatcher {
            protected function listeners(string $event): array
            {
                throw new \yii\db\Exception(
                    "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away\nThe SQL being executed was: SELECT 'marker-4714'",
                    ['HY000', 2006, 'MySQL server has gone away']
                );
            }
        };

        $logs = $this->logsDuring(fn () => $dispatcher->dispatch(NotificationTemplate::EVENT_RUNNER_OFFLINE, $this->runnerPayload()));

        $this->assertSame(
            [sprintf(NotificationDispatcher::NOT_QUEUED, 'runner.offline', 'yii\db\Exception', 'HY000', '2006')],
            $this->dispatcherLines($logs)
        );
        $this->assertStringNotContainsString('marker-4714', (string)json_encode($logs));
        $this->assertSame([], $this->queue->pushes);
    }

    /**
     * A template whose delivery cannot be written is logged
     * (TEMPLATE_NOT_QUEUED, without the statement) and the next template
     * still gets its delivery.
     */
    public function testATemplateWhoseDeliveryCannotBeWrittenLeavesTheOthersTheirs(): void
    {
        $first = $this->runnerTemplate(NotificationTemplate::EVENT_RUNNER_OFFLINE);
        $second = $this->runnerTemplate(NotificationTemplate::EVENT_RUNNER_OFFLINE);
        $failOnce = static function (): void {
            static $failed = false;
            if (!$failed) {
                $failed = true;
                throw new \yii\db\Exception(
                    "SQLSTATE[HY000]: General error: 1030 Got error 28\nThe SQL being executed was: INSERT 'marker-4715'",
                    ['HY000', 1030, 'Got error 28']
                );
            }
        };
        Event::on(NotificationDelivery::class, ActiveRecord::EVENT_BEFORE_INSERT, $failOnce);
        try {
            $logs = $this->logsDuring(fn () => $this->dispatcher()->dispatch(NotificationTemplate::EVENT_RUNNER_OFFLINE, $this->runnerPayload()));
        } finally {
            Event::off(NotificationDelivery::class, ActiveRecord::EVENT_BEFORE_INSERT, $failOnce);
        }

        $this->assertSame([], $this->deliveriesOf($first));
        $this->assertCount(1, $this->deliveriesOf($second));
        $this->assertSame(
            [sprintf(NotificationDispatcher::TEMPLATE_NOT_QUEUED, 'runner.offline', (int)$first->id, 'yii\db\Exception', 'HY000', '1030')],
            $this->dispatcherLines($logs)
        );
        $this->assertStringNotContainsString('marker-4715', (string)json_encode($logs));
    }

    // -- webhook.invalid_token: the throttle ------------------------------------------

    /**
     * Regression (spec answer 6): every call with an unknown trigger token
     * sent webhook.invalid_token to every template that listens, so a caller
     * that sprayed tokens flooded the channels. A template now gets at most
     * one delivery of it per 300 seconds; each further call is in the audit
     * log as notification.throttled, as the message says (trigger.note), with
     * what the call sent.
     */
    public function testInvalidTokenEventsAreThrottledPerTemplate(): void
    {
        $event = NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN;
        $first = $this->createNotificationTemplate((int)$this->createUser('disp-token')->id, NotificationTemplate::CHANNEL_EMAIL, $event);
        $second = $this->createNotificationTemplate((int)$this->createUser('disp-token')->id, NotificationTemplate::CHANNEL_EMAIL, $event);
        $payload = static fn (string $prefix): array => ['trigger' => [
            'token_prefix' => $prefix,
            'note' => sprintf(NotificationOutbox::THROTTLE_NOTE, 5),
        ]];

        $this->dispatcher()->dispatch($event, $payload('abc123'));
        $this->dispatcher()->dispatch($event, $payload('def456'));

        foreach ([$first, $second] as $template) {
            $delivery = $this->onlyDeliveryOf($template);
            $this->assertSame('abc123', $delivery->getVariableMap()['trigger.token_prefix'] ?? null);
            $this->assertSame(sprintf(NotificationOutbox::THROTTLE_NOTE, 5), $delivery->getVariableMap()['trigger.note'] ?? null);
            $throttled = AuditLog::find()
                ->where(['action' => AuditLog::ACTION_NOTIFICATION_THROTTLED, 'object_type' => 'notification_template', 'object_id' => $template->id])
                ->all();
            $this->assertCount(1, $throttled);
            $this->assertInstanceOf(AuditLog::class, $throttled[0]);
            $context = json_decode((string)$throttled[0]->metadata, true);
            $this->assertIsArray($context);
            $this->assertSame((string)$template->name, $context['template'] ?? null);
            $this->assertSame($event, $context['event'] ?? null);
            $this->assertSame(300, $context['throttle_seconds'] ?? null);
            $this->assertSame((int)$delivery->id, $context['delivery_id'] ?? null, 'the delivery that already told the template');
            $this->assertSame('def456', $context['variables']['trigger.token_prefix'] ?? null, 'what the throttled call sent');
        }
        $this->assertCount(2, $this->queue->pushes, 'one push per template');
        $this->assertSame(
            'A template gets this notification at most once every 5 minutes; every further call with an unknown trigger token is in the audit log as notification.throttled.',
            sprintf(NotificationOutbox::THROTTLE_NOTE, 5)
        );
    }

    /**
     * A template deleted while an event of it was dispatched gets no
     * delivery, and no notification.throttled entry either: nothing is left
     * to throttle.
     */
    public function testATemplateDeletedMeanwhileIsNotThrottled(): void
    {
        $template = $this->createNotificationTemplate((int)$this->createUser('disp-gone')->id, NotificationTemplate::CHANNEL_EMAIL, NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN);
        $dispatcher = new class ($template) extends NotificationDispatcher {
            public function __construct(private readonly NotificationTemplate $template)
            {
                parent::__construct();
            }

            protected function listeners(string $event): array
            {
                return [$this->template];
            }
        };
        NotificationTemplate::deleteAll(['id' => $template->id]);

        $dispatcher->dispatch(NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN, ['trigger' => ['token_prefix' => 'abc123']]);

        $this->assertSame([], $this->deliveriesOf($template));
        $this->assertFalse(AuditLog::find()->where(['action' => AuditLog::ACTION_NOTIFICATION_THROTTLED, 'object_id' => $template->id])->exists());
        $this->assertSame([], $this->queue->pushes);
    }

    // -- sendSingle(): what it answers --------------------------------------------------

    /**
     * A message the channel accepted: null, and a notification.dispatched
     * entry.
     */
    public function testSendSingleAnswersNullWhenTheChannelAcceptedTheMessage(): void
    {
        $nt = $this->listeningTemplate();
        $channel = new RecordingChannel();
        $dispatcher = new NotificationDispatcher();
        $dispatcher->setChannel(NotificationTemplate::CHANNEL_EMAIL, $channel);

        $this->assertNull($dispatcher->sendSingle($nt, ['event' => 'test']));

        $this->assertCount(1, $channel->calls);
        $this->assertSame(1, (int)AuditLog::find()
            ->where(['action' => AuditLog::ACTION_NOTIFICATION_DISPATCHED, 'object_id' => $nt->id])
            ->count());
        $this->assertSame(0, (int)AuditLog::find()
            ->where(['action' => AuditLog::ACTION_NOTIFICATION_FAILED, 'object_id' => $nt->id])
            ->count());
    }

    /**
     * Regression (the test actions reported success for a failed channel):
     * a channel's error is sendSingle()'s answer, the same text its
     * notification.failed entry carries; a database error only as its
     * class, SQLSTATE and driver error code.
     *
     * @dataProvider channelErrorProvider
     */
    public function testSendSingleAnswersTheErrorItsAuditEntryCarries(\Throwable $error, string $expected): void
    {
        $nt = $this->listeningTemplate();
        $dispatcher = new NotificationDispatcher();
        $dispatcher->setChannel(NotificationTemplate::CHANNEL_EMAIL, $this->failingChannel($error));

        $answer = $dispatcher->sendSingle($nt, ['event' => 'test'], NotificationTemplate::EVENT_JOB_FAILED);

        $this->assertSame($expected, $answer);
        $this->assertSame($expected, $this->errorOf($this->failureAuditOf($nt)));
        $this->assertSame(0, (int)AuditLog::find()
            ->where(['action' => AuditLog::ACTION_NOTIFICATION_DISPATCHED, 'object_id' => $nt->id])
            ->count());
    }

    /**
     * @return array<string, array{0: \Throwable, 1: string}>
     */
    public static function channelErrorProvider(): array
    {
        return [
            'a channel error keeps its message' => [
                new \RuntimeException('SlackChannel: HTTP 404 — no_service'),
                'SlackChannel: HTTP 404 — no_service',
            ],
            'a database error only its class, SQLSTATE and code' => [
                new \yii\db\Exception(
                    "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away\n"
                        . "The SQL being executed was: SELECT * FROM `user` WHERE `email`='marker-4711'",
                    ['HY000', 2006, 'MySQL server has gone away']
                ),
                'yii\db\Exception (SQLSTATE HY000, driver error 2006)',
            ],
        ];
    }

    /**
     * A template whose channel no handler sends: UNKNOWN_CHANNEL, also in
     * its notification.failed entry, and the UNKNOWN_CHANNEL_LOGGED warning.
     */
    public function testSendSingleAnswersTheUnknownChannel(): void
    {
        $nt = $this->listeningTemplate();
        $nt->channel = 'carrier-pigeon';
        $nt->save(false);
        $dispatcher = new NotificationDispatcher();

        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $answer = $dispatcher->sendSingle($nt, ['event' => 'test']);
        } finally {
            \Yii::setLogger($previous);
        }

        $this->assertSame("Unknown channel 'carrier-pigeon'", $answer);
        $this->assertSame(sprintf(NotificationDispatcher::UNKNOWN_CHANNEL, 'carrier-pigeon'), $answer);
        $this->assertSame($answer, $this->errorOf($this->failureAuditOf($nt)));
        $warnings = [];
        foreach ($logger->messages as $message) {
            if ($message[1] === Logger::LEVEL_WARNING && $message[2] === NotificationDispatcher::class) {
                $warnings[] = $message[0];
            }
        }
        $this->assertSame(["NotificationDispatcher: unknown channel 'carrier-pigeon'"], $warnings);
        $this->assertSame([sprintf(NotificationDispatcher::UNKNOWN_CHANNEL_LOGGED, 'carrier-pigeon')], $warnings);
    }

    // -- channel() and render(): what the notification worker sends through -------------

    /**
     * The notification worker (NotificationDeliveryService) sends through
     * the dispatcher's channels: every channel of a template has its
     * handler, one set for a test replaces it, and a channel without one
     * has none.
     */
    public function testEveryChannelOfATemplateHasItsHandler(): void
    {
        $dispatcher = new NotificationDispatcher();
        $handlers = [
            NotificationTemplate::CHANNEL_EMAIL => \app\services\notification\EmailChannel::class,
            NotificationTemplate::CHANNEL_SLACK => \app\services\notification\SlackChannel::class,
            NotificationTemplate::CHANNEL_TEAMS => \app\services\notification\TeamsChannel::class,
            NotificationTemplate::CHANNEL_WEBHOOK => \app\services\notification\WebhookChannel::class,
            NotificationTemplate::CHANNEL_TELEGRAM => \app\services\notification\TelegramChannel::class,
            NotificationTemplate::CHANNEL_PAGERDUTY => \app\services\notification\PagerDutyChannel::class,
            NotificationTemplate::CHANNEL_MATTERMOST => \app\services\notification\MattermostChannel::class,
        ];

        $this->assertSame(array_keys(NotificationTemplate::channelLabels()), array_keys($handlers));
        foreach ($handlers as $channel => $class) {
            $this->assertInstanceOf($class, $dispatcher->channel($channel), $channel);
        }
        $this->assertNull($dispatcher->channel('carrier-pigeon'));
        $recording = new RecordingChannel();
        $dispatcher->setChannel(NotificationTemplate::CHANNEL_EMAIL, $recording);
        $this->assertSame($recording, $dispatcher->channel(NotificationTemplate::CHANNEL_EMAIL));
    }

    /**
     * The subject and the body as every send renders them: the template's
     * placeholders filled from the variables, a missing one empty.
     */
    public function testRenderFillsTheSubjectAndTheBody(): void
    {
        $nt = $this->listeningTemplate();
        $nt->subject_template = 'Job #{{ job.id }} {{ job.status }}{{ missing }}';
        $nt->body_template = 'Template: {{ template.name }}';

        $this->assertSame(
            ['subject' => 'Job #7 failed', 'body' => 'Template: deploy'],
            (new NotificationDispatcher())->render($nt, ['job.id' => '7', 'job.status' => 'failed', 'template.name' => 'deploy'])
        );
        $nt->subject_template = null;
        $nt->body_template = null;
        $this->assertSame(['subject' => '', 'body' => ''], (new NotificationDispatcher())->render($nt, []));
    }

    // -- Helpers ----------------------------------------------------------------------

    private function dispatcher(): NotificationDispatcher
    {
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');

        return $dispatcher;
    }

    /**
     * A template (channel email) that listens to $event and delivers as an
     * admin, who may see runner events.
     */
    private function runnerTemplate(string $event): NotificationTemplate
    {
        $this->identity ??= $this->createUserWithRole('disp-identity', 'admin');

        return $this->createNotificationTemplate((int)$this->identity->id, NotificationTemplate::CHANNEL_EMAIL, $event);
    }

    /**
     * @return array<string, mixed> the payload of a runner event, which names no object
     */
    private function runnerPayload(): array
    {
        return ['runner' => ['id' => '7', 'name' => 'runner-7', 'last_seen_at' => '1760000000']];
    }

    private function jobTemplateOf(User $user): \app\models\JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject((int)$user->id)->id,
            (int)$this->createInventory((int)$user->id)->id,
            (int)$this->createRunnerGroup((int)$user->id)->id,
            (int)$user->id
        );
    }

    /**
     * @return list<NotificationDelivery> the deliveries of $template, in order
     */
    private function deliveriesOf(NotificationTemplate $template): array
    {
        /** @var list<NotificationDelivery> $rows */
        $rows = NotificationDelivery::find()->where(['notification_template_id' => $template->id])->orderBy(['id' => SORT_ASC])->all();

        return $rows;
    }

    private function onlyDeliveryOf(NotificationTemplate $template): NotificationDelivery
    {
        $rows = $this->deliveriesOf($template);
        $this->assertCount(1, $rows, 'one delivery of template #' . $template->id);

        return $rows[0];
    }

    /**
     * The texts NotificationDispatcher logged (error) among $logs.
     *
     * @param list<array{0: int, 1: string, 2: string}> $logs
     * @return list<string>
     */
    private function dispatcherLines(array $logs): array
    {
        $lines = [];
        foreach ($logs as [$level, $category, $text]) {
            if ($level === Logger::LEVEL_ERROR && $category === NotificationDispatcher::class) {
                $lines[] = $text;
            }
        }

        return $lines;
    }

    /** A notification template (channel email) that listens to job.failed. */
    private function listeningTemplate(): NotificationTemplate
    {
        $nt = $this->createNotificationTemplate($this->createUser('disp')->id);
        $nt->events = NotificationTemplate::EVENT_JOB_FAILED;
        $nt->save(false);
        $this->assertSame('email', $nt->channel);

        return $nt;
    }

    private function failingChannel(\Throwable $error): ChannelInterface
    {
        return new class ($error) implements ChannelInterface {
            public function __construct(private readonly \Throwable $error)
            {
            }

            public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
            {
                throw $this->error;
            }
        };
    }

    /** The one notification.failed audit entry of $nt. */
    private function failureAuditOf(NotificationTemplate $nt): AuditLog
    {
        $rows = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_NOTIFICATION_FAILED, 'object_id' => $nt->id])
            ->all();
        $this->assertCount(1, $rows);
        $this->assertInstanceOf(AuditLog::class, $rows[0]);

        return $rows[0];
    }

    /** The error that $row (a notification.failed entry) carries. */
    private function errorOf(AuditLog $row): mixed
    {
        $context = json_decode((string)$row->metadata, true);
        $this->assertIsArray($context);

        return $context['error'] ?? null;
    }
}
