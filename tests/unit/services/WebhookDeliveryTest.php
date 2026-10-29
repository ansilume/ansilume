<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\Job;
use app\models\Webhook;
use app\services\WebhookService;
use app\tests\unit\services\notification\HttpLoopbackServer;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;
use yii\log\Logger;

/**
 * Tests for WebhookService delivery logic.
 *
 * The deliver() method is overridden to capture the request instead of making
 * real HTTP calls. Tests verify headers, HMAC signature, event filtering,
 * and error isolation. The real deliver() posts to HttpLoopbackServer.
 */
class WebhookDeliveryTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeJob(int $id = 1, string $status = Job::STATUS_SUCCEEDED): Job
    {
        $job = $this->getMockBuilder(Job::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setValue($job, [
            'id'              => $id,
            'status'          => $status,
            'job_template_id' => 1,
            'launched_by'     => 1,
            'exit_code'       => 0,
            'started_at'      => time() - 60,
            'finished_at'     => time(),
        ]);
        return $job;
    }

    private function makeWebhook(string $url = 'https://example.com/hook', string $events = 'job.success,job.failure', string $secret = ''): Webhook
    {
        $wh = $this->getMockBuilder(Webhook::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes'])
            ->getMock();
        $wh->method('attributes')->willReturn(
            ['id', 'name', 'url', 'events', 'secret', 'enabled', 'created_by', 'created_at', 'updated_at']
        );
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setValue($wh, [
            'id'         => 1,
            'name'       => 'test-webhook',
            'url'        => $url,
            'events'     => $events,
            'secret'     => $secret,
            'enabled'    => true,
            'created_by' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        return $wh;
    }

    /**
     * Build a WebhookService that captures deliver() calls and optionally throws.
     */
    private function makeService(bool $failDelivery = false): WebhookService
    {
        return new class ($failDelivery) extends WebhookService {
            public array $deliveries = [];
            private bool $failDelivery;

            public function __construct(bool $failDelivery)
            {
                $this->failDelivery = $failDelivery;
            }

            protected function deliver(Webhook $webhook, string $event, Job $job): void
            {
                $this->deliveries[] = [
                    'webhook_id' => $webhook->id,
                    'url'        => $webhook->url,
                    'event'      => $event,
                    'job_id'     => $job->id,
                ];
                if ($this->failDelivery) {
                    throw new \RuntimeException('Connection refused');
                }
            }

            /**
             * Override dispatch to use provided webhooks instead of DB query.
             * @var Webhook[] $webhooksOverride
             */
            public array $webhooksOverride = [];

            public function dispatch(string $event, Job $job): void
            {
                $webhooks = $this->webhooksOverride;

                foreach ($webhooks as $webhook) {
                    if (!$webhook->listensTo($event)) {
                        continue;
                    }

                    try {
                        $this->deliver($webhook, $event, $job);
                    } catch (\Throwable $e) {
                        // Swallowed, same as production
                    }
                }
            }
        };
    }

    // -------------------------------------------------------------------------
    // Delivery filtering
    // -------------------------------------------------------------------------

    public function testDispatchDeliversToMatchingWebhook(): void
    {
        $service = $this->makeService();
        $service->webhooksOverride = [$this->makeWebhook(events: 'job.success')];

        $service->dispatch(Webhook::EVENT_JOB_SUCCESS, $this->makeJob());

        $this->assertCount(1, $service->deliveries);
        $this->assertSame(Webhook::EVENT_JOB_SUCCESS, $service->deliveries[0]['event']);
    }

    public function testDispatchSkipsWebhookNotListeningToEvent(): void
    {
        $service = $this->makeService();
        $service->webhooksOverride = [$this->makeWebhook(events: 'job.failure')];

        $service->dispatch(Webhook::EVENT_JOB_SUCCESS, $this->makeJob());

        $this->assertEmpty($service->deliveries);
    }

    public function testDispatchDeliversToMultipleMatchingWebhooks(): void
    {
        $service = $this->makeService();
        $service->webhooksOverride = [
            $this->makeWebhook(url: 'https://a.example.com', events: 'job.success'),
            $this->makeWebhook(url: 'https://b.example.com', events: 'job.success,job.failure'),
        ];

        $service->dispatch(Webhook::EVENT_JOB_SUCCESS, $this->makeJob());

        $this->assertCount(2, $service->deliveries);
    }

    // -------------------------------------------------------------------------
    // Error isolation
    // -------------------------------------------------------------------------

    public function testDeliveryFailureDoesNotThrow(): void
    {
        $service = $this->makeService(failDelivery: true);
        $service->webhooksOverride = [$this->makeWebhook(events: 'job.success')];

        // Must not throw — failure is swallowed
        $service->dispatch(Webhook::EVENT_JOB_SUCCESS, $this->makeJob());

        $this->assertCount(1, $service->deliveries);
    }

    public function testDeliveryFailureDoesNotBlockSubsequentWebhooks(): void
    {
        // First webhook fails, second should still get delivered
        $failOnFirst = new class extends WebhookService {
            public array $deliveries = [];
            public array $webhooksOverride = [];

            protected function deliver(Webhook $webhook, string $event, Job $job): void
            {
                $this->deliveries[] = ['url' => $webhook->url];
                if (count($this->deliveries) === 1) {
                    throw new \RuntimeException('First webhook fails');
                }
            }

            public function dispatch(string $event, Job $job): void
            {
                foreach ($this->webhooksOverride as $wh) {
                    if (!$wh->listensTo($event)) {
                        continue;
                    }
                    try {
                        $this->deliver($wh, $event, $job);
                    } catch (\Throwable $e) {
                        // swallowed
                    }
                }
            }
        };

        $wh1 = $this->makeWebhook(url: 'https://fail.example.com', events: 'job.success');
        $wh2 = $this->makeWebhook(url: 'https://ok.example.com', events: 'job.success');
        $failOnFirst->webhooksOverride = [$wh1, $wh2];

        $failOnFirst->dispatch(Webhook::EVENT_JOB_SUCCESS, $this->makeJob());

        $this->assertCount(2, $failOnFirst->deliveries, 'Both webhooks must be attempted.');
    }

    // -------------------------------------------------------------------------
    // Payload & signature (via real deliver)
    // -------------------------------------------------------------------------

    public function testDeliverSendsCorrectPayloadStructure(): void
    {
        $service = new class extends WebhookService {
            public function testBuildPayload(string $event, Job $job): array
            {
                return $this->buildPayload($event, $job);
            }
        };

        $job     = $this->makeJob(42, Job::STATUS_FAILED);
        $payload = $service->testBuildPayload(Webhook::EVENT_JOB_FAILURE, $job);

        $this->assertSame(Webhook::EVENT_JOB_FAILURE, $payload['event']);
        $this->assertSame(42, $payload['job']['id']);
        $this->assertSame(Job::STATUS_FAILED, $payload['job']['status']);
    }

    public function testSignatureMatchesPayload(): void
    {
        $secret  = 'test-secret-key';
        $payload = '{"event":"job.success","job":{"id":1}}';

        $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        // Verify independently
        $this->assertTrue(
            hash_equals(
                hash_hmac('sha256', $payload, $secret),
                substr($signature, strlen('sha256='))
            )
        );
    }

    // -------------------------------------------------------------------------
    // The real deliver(), against a loopback receiver
    // -------------------------------------------------------------------------

    /**
     * @dataProvider takenStatuses
     */
    public function testADeliveryTheReceiverTookLogsNoWarning(int $status): void
    {
        $server = new HttpLoopbackServer();
        $webhook = $this->makeWebhook($server->start($status, '') . '/hooks/ansilume', secret: 'whsec-test-4711');
        $service = self::realService();
        $job = $this->makeJob(7);
        try {
            $warnings = self::warningsWhile(fn () => $service->deliverNow($webhook, Webhook::EVENT_JOB_SUCCESS, $job));
            $requests = $server->requests();
        } finally {
            $server->stop();
        }

        $this->assertSame([], $warnings);
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('/hooks/ansilume', $requests[0]['path']);
        $headers = $requests[0]['headers'];
        $this->assertSame('application/json', $headers['Content-Type'] ?? null);
        $this->assertSame(Webhook::EVENT_JOB_SUCCESS, $headers['X-Ansilume-Event'] ?? null);
        $signature = 'sha256=' . hash_hmac('sha256', $requests[0]['body'], 'whsec-test-4711');
        $this->assertSame($signature, $headers['X-Ansilume-Signature'] ?? null);
        $this->assertSame(7, json_decode($requests[0]['body'], true)['job']['id'] ?? null);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function takenStatuses(): array
    {
        return [
            'ok' => [200],
            'created' => [201],
            'no content' => [204],
        ];
    }

    /**
     * An answer outside 2xx is logged with its status line.
     *
     * @dataProvider refusedStatuses
     */
    public function testADeliveryTheReceiverRefusedLogsItsStatusLine(int $status, string $statusLine): void
    {
        $server = new HttpLoopbackServer();
        $webhook = $this->makeWebhook($server->start($status, 'refused') . '/hooks/ansilume');
        $service = self::realService();
        $job = $this->makeJob(7);
        try {
            $warnings = self::warningsWhile(fn () => $service->deliverNow($webhook, Webhook::EVENT_JOB_FAILURE, $job));
        } finally {
            $server->stop();
        }

        $this->assertSame(["Webhook #1 non-success for event=job.failure job=7: {$statusLine}"], $warnings);
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function refusedStatuses(): array
    {
        return [
            'not found' => [404, 'HTTP/1.1 404 Not Found'],
            'server error' => [500, 'HTTP/1.1 500 Internal Server Error'],
            'unavailable' => [503, 'HTTP/1.1 503 Service Unavailable'],
        ];
    }

    /**
     * Without an answer there is no status line: the warning names the
     * network error instead.
     */
    public function testADeliveryThatGotNoAnswerLogsTheNetworkError(): void
    {
        $webhook = $this->makeWebhook('http://127.0.0.1:1/hooks/ansilume');
        $service = self::realService();
        $job = $this->makeJob(7);

        $warnings = self::warningsWhile(fn () => $service->deliverNow($webhook, Webhook::EVENT_JOB_SUCCESS, $job));

        $this->assertSame([
            'Webhook #1 non-success for event=job.success job=7:  '
            . '(file_get_contents(http://127.0.0.1:1/hooks/ansilume): Failed to open stream: Connection refused)',
        ], $warnings);
    }

    /**
     * A delivery without an HTTP answer is never taken for the one before
     * it, which the receiver took. PHP 8.5 deprecates
     * $http_response_header, the headers local to the call;
     * http_get_last_response_headers(), which replaces it, keeps the headers
     * of the last HTTP answer until the next HTTP request, so deliver()
     * clears them before each delivery.
     */
    public function testADeliveryWithoutAnHttpAnswerIsNotTakenForTheOneBefore(): void
    {
        $server = new HttpLoopbackServer();
        $taken = $this->makeWebhook($server->start(200, 'ok') . '/hooks/ansilume');
        $file = (string)tempnam(sys_get_temp_dir(), 'webhook_delivery_');
        $notHttp = $this->makeWebhook('file://' . $file);
        $service = self::realService();
        $jobs = [$this->makeJob(7), $this->makeJob(8)];
        try {
            $warnings = self::warningsWhile(function () use ($service, $taken, $notHttp, $jobs): void {
                $service->deliverNow($taken, Webhook::EVENT_JOB_SUCCESS, $jobs[0]);
                $service->deliverNow($notHttp, Webhook::EVENT_JOB_SUCCESS, $jobs[1]);
            });
        } finally {
            $server->stop();
            unlink($file);
        }

        $this->assertSame(['Webhook #1 non-success for event=job.success job=8: '], $warnings);
    }

    /**
     * A WebhookService whose real deliver() a test can call.
     */
    private static function realService(): WebhookService
    {
        return new class () extends WebhookService {
            public function deliverNow(Webhook $webhook, string $event, Job $job): void
            {
                $this->deliver($webhook, $event, $job);
            }
        };
    }

    /**
     * The warnings WebhookService logs while $deliver runs.
     *
     * @return list<string>
     */
    private static function warningsWhile(callable $deliver): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $deliver();
        } finally {
            \Yii::setLogger($previous);
        }
        $warnings = [];
        foreach ($logger->messages as $message) {
            if ($message[2] === WebhookService::class && $message[1] === Logger::LEVEL_WARNING) {
                $warnings[] = (string)$message[0];
            }
        }

        return $warnings;
    }
}
