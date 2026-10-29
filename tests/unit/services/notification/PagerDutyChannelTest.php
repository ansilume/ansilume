<?php

declare(strict_types=1);

namespace app\tests\unit\services\notification;

use app\models\NotificationTemplate;
use app\services\notification\PagerDutyChannel;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;
use yii\log\Logger;

/**
 * PagerDutyChannel sends one Events API v2 event per notification: it
 * triggers an incident, or resolves it for a recovery event, under a
 * dedup_key of the template and the object the event is about.
 *
 * The loopback tests run the real post() against HttpLoopbackServer, which
 * takes the place of PagerDuty's endpoint.
 */
class PagerDutyChannelTest extends TestCase
{
    private const ROUTING_KEY = 'pd-test-routing-key-4711';

    private const ENDPOINT = 'https://events.pagerduty.com/v2/enqueue';

    private function makeTemplate(string $configJson): NotificationTemplate
    {
        $nt = $this->getMockBuilder(NotificationTemplate::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $nt->method('attributes')->willReturn(
            ['id', 'name', 'description', 'channel', 'config', 'subject_template',
             'body_template', 'events', 'created_by', 'created_at', 'updated_at']
        );
        $nt->method('save')->willReturn(true);
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setValue($nt, [
            'id' => 3,
            'name' => 'PagerDuty Test',
            'channel' => NotificationTemplate::CHANNEL_PAGERDUTY,
            'config' => $configJson,
            'subject_template' => 'Test',
            'body_template' => 'Body',
            'events' => 'job.failed',
            'created_by' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        return $nt;
    }

    /**
     * A PagerDutyChannel that records each post() of send(); given a
     * receiver, it makes the post with the real post() to the receiver in
     * place of PagerDuty's endpoint, and records the answer.
     */
    private function channel(string $receiver = ''): PagerDutyChannel
    {
        return new class ($receiver) extends PagerDutyChannel {
            /** @var list<array{url: string, payload: string, headers: array<int, string>, answer?: string}> */
            public array $posts = [];

            public function __construct(private readonly string $receiver)
            {
            }

            protected function post(string $url, string $payload, array $headers): string
            {
                $this->posts[] = ['url' => $url, 'payload' => $payload, 'headers' => $headers];
                if ($this->receiver === '') {
                    return '';
                }
                $answer = parent::post($this->receiver, $payload, $headers);
                $this->posts[count($this->posts) - 1]['answer'] = $answer;

                return $answer;
            }
        };
    }

    /**
     * The event send() posted, decoded.
     *
     * @return array<string, mixed>
     */
    private static function event(PagerDutyChannel $channel): array
    {
        self::assertCount(1, $channel->posts);
        $event = json_decode($channel->posts[0]['payload'], true);
        self::assertIsArray($event);

        return $event;
    }

    private static function config(): string
    {
        return (string)json_encode(['routing_key' => self::ROUTING_KEY]);
    }

    /**
     * @dataProvider configsWithoutRoutingKey
     */
    public function testWithoutARoutingKeyNothingIsSent(string $config): void
    {
        $channel = $this->channel();

        $channel->send($this->makeTemplate($config), 'Job failed', 'Body', [
            'event' => NotificationTemplate::EVENT_JOB_FAILED,
        ]);

        $this->assertSame([], $channel->posts);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function configsWithoutRoutingKey(): array
    {
        return [
            'no config' => [''],
            'no routing key' => ['{}'],
            'empty routing key' => ['{"routing_key": ""}'],
        ];
    }

    public function testAnEventTriggersAnIncidentOfItsSeverity(): void
    {
        $channel = $this->channel();
        $variables = [
            'event' => NotificationTemplate::EVENT_JOB_FAILED,
            'severity' => 'critical',
            'app.url' => 'https://ansilume.example.com',
            'job.id' => '42',
            'job.template_id' => '7',
        ];

        $channel->send($this->makeTemplate(self::config()), 'Job #42 failed', 'Deploy failed on web-1', $variables);

        $this->assertSame(self::ENDPOINT, $channel->posts[0]['url']);
        $this->assertSame(['Content-Type: application/json'], $channel->posts[0]['headers']);
        $this->assertSame([
            'routing_key' => self::ROUTING_KEY,
            'event_action' => 'trigger',
            'dedup_key' => 'ansilume:3:job.template_id=7',
            'payload' => [
                'summary' => 'Job #42 failed',
                'source' => 'https://ansilume.example.com',
                'severity' => 'critical',
                'component' => NotificationTemplate::EVENT_JOB_FAILED,
                'custom_details' => [
                    'body' => 'Deploy failed on web-1',
                    'variables' => $variables,
                ],
            ],
        ], self::event($channel));
    }

    /**
     * @dataProvider recoveryEvents
     */
    public function testARecoveryEventResolvesItsIncident(string $event): void
    {
        $channel = $this->channel();

        $channel->send($this->makeTemplate(self::config()), 'Recovered', 'Body', [
            'event' => $event,
            'runner.id' => '5',
        ]);

        $this->assertSame('resolve', self::event($channel)['event_action']);
        $this->assertSame('ansilume:3:runner.id=5', self::event($channel)['dedup_key']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function recoveryEvents(): array
    {
        return [
            'runner recovered' => [NotificationTemplate::EVENT_RUNNER_RECOVERED],
            'project synced' => [NotificationTemplate::EVENT_PROJECT_SYNC_SUCCEEDED],
            'job succeeded' => [NotificationTemplate::EVENT_JOB_SUCCEEDED],
            'workflow succeeded' => [NotificationTemplate::EVENT_WORKFLOW_SUCCEEDED],
        ];
    }

    /**
     * PagerDuty knows critical, error, warning and info; anything else, and
     * no severity at all, is sent as error.
     *
     * @dataProvider severities
     */
    public function testTheSeverityIsOneThatPagerDutyKnows(?string $severity, string $sent): void
    {
        $channel = $this->channel();
        $variables = ['event' => NotificationTemplate::EVENT_RUNNER_OFFLINE];
        if ($severity !== null) {
            $variables['severity'] = $severity;
        }

        $channel->send($this->makeTemplate(self::config()), 'Runner offline', 'Body', $variables);

        $this->assertSame($sent, self::event($channel)['payload']['severity']);
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function severities(): array
    {
        return [
            'critical' => ['critical', 'critical'],
            'error' => ['error', 'error'],
            'warning' => ['warning', 'warning'],
            'info' => ['info', 'info'],
            'unknown' => ['fatal', 'error'],
            'empty' => ['', 'error'],
            'none' => [null, 'error'],
        ];
    }

    /**
     * The dedup_key names the first object of runner, project, workflow and
     * job template that the event has, else the job, so that repeated
     * events and their recovery meet in one incident.
     *
     * @param array<string, string> $objects
     * @dataProvider dedupKeys
     */
    public function testTheDedupKeyNamesTheObjectOfTheEvent(array $objects, string $dedupKey): void
    {
        $channel = $this->channel();

        $channel->send($this->makeTemplate(self::config()), 'Alert', 'Body', ['event' => 'test'] + $objects);

        $this->assertSame($dedupKey, self::event($channel)['dedup_key']);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function dedupKeys(): array
    {
        return [
            'runner' => [['runner.id' => '5'], 'ansilume:3:runner.id=5'],
            'project' => [['project.id' => '9'], 'ansilume:3:project.id=9'],
            'workflow' => [['workflow.id' => '4'], 'ansilume:3:workflow.id=4'],
            'template before job' => [['job.id' => '42', 'job.template_id' => '7'], 'ansilume:3:job.template_id=7'],
            'runner before job template' => [['job.template_id' => '7', 'runner.id' => '5'], 'ansilume:3:runner.id=5'],
            'an empty ID is no object' => [['runner.id' => '', 'project.id' => '9'], 'ansilume:3:project.id=9'],
            'job only' => [['job.id' => '42'], 'ansilume:3:job=42'],
            'no object' => [[], 'ansilume:3'],
        ];
    }

    public function testTheSummaryIsTheSubjectCutTo1024Characters(): void
    {
        $channel = $this->channel();

        $channel->send($this->makeTemplate(self::config()), str_repeat('ü', 1500), 'Body', ['event' => 'test']);

        $this->assertSame(str_repeat('ü', 1024), self::event($channel)['payload']['summary']);
    }

    public function testWithoutASubjectTheEventIsTheSummaryAndAnsilumeTheSource(): void
    {
        $channel = $this->channel();

        $channel->send($this->makeTemplate(self::config()), '', 'Body', [
            'event' => NotificationTemplate::EVENT_RUNNER_OFFLINE,
        ]);

        $this->assertSame(NotificationTemplate::EVENT_RUNNER_OFFLINE, self::event($channel)['payload']['summary']);
        $this->assertSame('ansilume', self::event($channel)['payload']['source']);
    }

    /**
     * Regression: post() called curl_close(), which PHP 8.5 deprecates,
     * after curl_exec() had delivered the event. The error handler of the
     * application turned the deprecation into a yii\base\ErrorException, so
     * every PagerDuty notification was recorded as failed
     * (notification.failed: "Function curl_close() is deprecated since 8.5,
     * as it has no effect since PHP 8.0") and its Test action reported a
     * failure. No test reached post() before.
     */
    public function testRegressionAnEventPagerDutyTookWasRecordedAsFailedOnPhp85(): void
    {
        $answer = '{"status":"success","message":"Event processed","dedup_key":"ansilume:3:runner.id=5"}';
        $server = new HttpLoopbackServer();
        $channel = $this->channel($server->start(202, $answer) . '/v2/enqueue');
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $channel->send($this->makeTemplate(self::config()), 'Runner offline', 'runner-1 missed its heartbeat', [
                'event' => NotificationTemplate::EVENT_RUNNER_OFFLINE,
                'runner.id' => '5',
            ]);
            $requests = $server->requests();
        } finally {
            \Yii::setLogger($previous);
            $server->stop();
        }

        $this->assertSame($answer, $channel->posts[0]['answer'] ?? null);
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('/v2/enqueue', $requests[0]['path']);
        $this->assertSame('application/json', $requests[0]['headers']['Content-Type'] ?? null);
        $this->assertSame($channel->posts[0]['payload'], $requests[0]['body']);
        $this->assertSame([[Logger::LEVEL_INFO, 'PagerDutyChannel: sent — 202']], self::logged($logger));
    }

    /**
     * An answer outside 2xx is an error with its code and the first 200
     * bytes of its body, which notification.failed records.
     */
    public function testAnAnswerOutside2xxIsAnErrorWithItsCodeAndTheStartOfItsBody(): void
    {
        $answer = '{"status":"invalid event","message":"Event object is invalid","errors":["'
            . str_repeat('x', 300) . '"]}';
        $server = new HttpLoopbackServer();
        $channel = $this->channel($server->start(400, $answer));
        $template = $this->makeTemplate(self::config());
        try {
            $error = self::errorOf(fn () => $channel->send($template, 'Alert', 'Body', ['event' => 'test']));
            $requests = $server->requests();
        } finally {
            $server->stop();
        }

        $this->assertSame('PagerDutyChannel: HTTP 400 — ' . substr($answer, 0, 200), $error);
        $this->assertCount(1, $requests);
    }

    public function testAReceiverThatCannotBeReachedIsAnErrorWithoutCode(): void
    {
        $channel = $this->channel('http://127.0.0.1:1/v2/enqueue');
        $template = $this->makeTemplate(self::config());

        $error = self::errorOf(fn () => $channel->send($template, 'Alert', 'Body', ['event' => 'test']));

        $this->assertSame('PagerDutyChannel: HTTP 0 — ', $error);
    }

    /**
     * curl_init() gives false for a URL longer than libcurl takes
     * (CURL_MAX_INPUT_LENGTH, 8000000 bytes).
     */
    public function testAUrlThatCurlDoesNotTakeIsAnError(): void
    {
        $channel = $this->channel('http://127.0.0.1/' . str_repeat('a', 8000000));
        $template = $this->makeTemplate(self::config());

        $error = self::errorOf(fn () => $channel->send($template, 'Alert', 'Body', ['event' => 'test']));

        $this->assertSame('PagerDutyChannel: failed to init curl', $error);
    }

    /**
     * The message of the RuntimeException that $send raises.
     */
    private static function errorOf(callable $send): string
    {
        try {
            $send();
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        self::fail('post() raised no RuntimeException');
    }

    /**
     * What the channel logged: level, then message.
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function logged(Logger $logger): array
    {
        $logged = [];
        foreach ($logger->messages as $message) {
            if ($message[2] === PagerDutyChannel::class) {
                $logged[] = [(int)$message[1], (string)$message[0]];
            }
        }

        return $logged;
    }
}
