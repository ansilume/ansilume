<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Job;
use app\models\Webhook;
use app\services\WebhookService;
use app\tests\integration\DbTestCase;
use app\tests\unit\services\notification\HttpLoopbackServer;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\log\Logger;

class WebhookServiceTest extends DbTestCase
{
    /** The warning of a delivery the webhook did not take (WebhookService::NOT_DELIVERED). */
    private const NOT_DELIVERED = 'Webhook #%d non-success for event=%s job=%d: %s';

    private WebhookService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WebhookService();
    }

    private function scaffoldJob(): Job
    {
        $user = $this->createUser('webhook');
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $template = $this->createJobTemplate(
            $project->id,
            $inventory->id,
            $group->id,
            $user->id
        );
        return $this->createJob($template->id, $user->id, Job::STATUS_SUCCEEDED);
    }

    public function testDispatchSkipsDisabledWebhook(): void
    {
        $user = $this->createUser('wh_disabled');
        $job = $this->scaffoldJob();
        $this->createWebhook($user->id, 'job.success', false);

        // Should complete without error — disabled webhooks are skipped
        $this->service->dispatch(Webhook::EVENT_JOB_SUCCESS, $job);
        $this->assertTrue(true);
    }

    public function testDispatchSkipsWebhookNotListeningToEvent(): void
    {
        $user = $this->createUser('wh_event');
        $job = $this->scaffoldJob();
        // Webhook only listens to job.failure
        $this->createWebhook($user->id, 'job.failure', true);

        // Dispatching job.success should not fire
        $this->service->dispatch(Webhook::EVENT_JOB_SUCCESS, $job);
        $this->assertTrue(true);
    }

    public function testDispatchDoesNotThrowOnDeliveryFailure(): void
    {
        $user = $this->createUser('wh_fail');
        $job = $this->scaffoldJob();
        // URL that will fail (connection refused)
        $wh = $this->createWebhook($user->id, 'job.success', true);
        $wh->url = 'http://127.0.0.1:19999/nonexistent';
        $wh->save(false);

        // Should not throw — delivery failures are caught
        $this->service->dispatch(Webhook::EVENT_JOB_SUCCESS, $job);
        $this->assertTrue(true);
    }

    public function testDispatchFiresMatchingEnabledWebhook(): void
    {
        $user = $this->createUser('wh_fire');
        $job = $this->scaffoldJob();
        $this->createWebhook($user->id, 'job.success', true);

        // Use a testable subclass to track deliveries
        $delivered = [];
        $service = new class ($delivered) extends WebhookService {
            /** @var array<int, array{webhook_id: int, event: string}> */
            private array $log;

            /**
             * @param array<int, array{webhook_id: int, event: string}> $log
             */
            public function __construct(array &$log)
            {
                $this->log = &$log;
                parent::__construct();
            }

            protected function deliver(
                Webhook $webhook,
                string $event,
                Job $job
            ): void {
                $this->log[] = [
                    'webhook_id' => $webhook->id,
                    'event' => $event,
                ];
            }
        };

        $service->dispatch(Webhook::EVENT_JOB_SUCCESS, $job);
        $this->assertCount(1, $delivered);
        $this->assertSame(Webhook::EVENT_JOB_SUCCESS, $delivered[0]['event']);
    }

    public function testBuildPayloadContainsJobData(): void
    {
        $job = $this->scaffoldJob();

        $service = new class extends WebhookService {
            /**
             * @return array<string, mixed>
             */
            public function testBuildPayload(string $event, Job $job): array
            {
                return $this->buildPayload($event, $job);
            }
        };

        $payload = $service->testBuildPayload('job.success', $job);
        $this->assertSame('job.success', $payload['event']);
        $this->assertSame($job->id, $payload['job']['id']);
        $this->assertSame(Job::STATUS_SUCCEEDED, $payload['job']['status']);
    }

    /**
     * Regression: a redirect was followed (the POST became a GET to the
     * redirect's target) and taken for a delivery, as its status line held a
     * "2". A redirect is a failure now, and its target is never requested.
     */
    public function testARedirectIsAFailure(): void
    {
        $server = new HttpLoopbackServer();
        $base = $server->startWith([
            '/hooks/ansilume' => ['status' => 302, 'headers' => ['Location' => '/elsewhere']],
            '*' => ['status' => 200, 'body' => 'taken'],
        ]);
        try {
            [$webhook, $job] = $this->webhookTo($base . '/hooks/ansilume');
            $warnings = $this->warningsWhile(fn () => (new WebhookService())->dispatch(Webhook::EVENT_JOB_SUCCESS, $job));
            $requests = $server->requests();
        } finally {
            $server->stop();
        }

        $this->assertSame([sprintf(self::NOT_DELIVERED, $webhook->id, Webhook::EVENT_JOB_SUCCESS, $job->id, 'HTTP 302.')], $warnings);
        $this->assertSame([['POST', '/hooks/ansilume']], array_map(static fn (array $r): array => [$r['method'], $r['path']], $requests));
    }

    /**
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function statuses(): array
    {
        return [
            'ok' => [200, true],
            'created' => [201, true],
            'no content' => [204, true],
            'regression: a 502 has a 2 in its status line' => [502, false],
            'found' => [302, false],
            'not found' => [404, false],
            'server error' => [500, false],
        ];
    }

    /**
     * Regression: a status line with a "2" anywhere counted as taken, so a
     * 502 was no failure. Only a 2xx status is a delivery; any other is
     * logged with its status, and nothing of the answer.
     */
    #[DataProvider('statuses')]
    public function testOnlyA2xxStatusIsSuccess(int $status, bool $taken): void
    {
        $server = new HttpLoopbackServer();
        $base = $server->start($status, 'answer-body-5531');
        try {
            [$webhook, $job] = $this->webhookTo($base . '/hooks/ansilume');
            $warnings = $this->warningsWhile(fn () => (new WebhookService())->dispatch(Webhook::EVENT_JOB_SUCCESS, $job));
        } finally {
            $server->stop();
        }

        $expected = $taken ? [] : [sprintf(self::NOT_DELIVERED, $webhook->id, Webhook::EVENT_JOB_SUCCESS, $job->id, sprintf('HTTP %d.', $status))];
        $this->assertSame($expected, $warnings);
    }

    /**
     * Regression: a delivery that got no answer was logged with PHP's
     * warning text, which holds the full URL of the webhook (and with it
     * any token in it). The log names curl's error only.
     */
    public function testRegressionTheLogOfAFailedDeliveryNamesNoUrl(): void
    {
        [$webhook, $job] = $this->webhookTo('http://url-user-5532:url-password-5533@127.0.0.1:1/hooks/url-token-5534?key=url-key-5535');

        $warnings = $this->warningsWhile(fn () => (new WebhookService())->dispatch(Webhook::EVENT_JOB_SUCCESS, $job));

        $this->assertSame(
            [sprintf(self::NOT_DELIVERED, $webhook->id, Webhook::EVENT_JOB_SUCCESS, $job->id, 'Could not connect (curl error 7).')],
            $warnings
        );
        foreach (['url-user', 'url-password', 'url-token', 'url-key', '127.0.0.1'] as $secret) {
            $this->assertStringNotContainsString($secret, implode("\n", $warnings));
        }
    }

    /**
     * Regression: a delivery that failed before its request was logged with
     * the error's message, which for a database error carries the SQL
     * statement with its values. The log names a database error by its
     * class, SQLSTATE and driver error code (ErrorText).
     */
    public function testRegressionAFailedDeliveryLogsNoDatabaseErrorMessage(): void
    {
        [$webhook, $job] = $this->webhookTo('https://hooks.example.com/ansilume');
        $service = new class () extends WebhookService {
            protected function deliver(Webhook $webhook, string $event, Job $job): void
            {
                throw new \yii\db\Exception(
                    "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away\n"
                        . "The SQL being executed was: SELECT * FROM `webhook` WHERE `secret`='sql-marker-5537'",
                    ['HY000', 2006, 'MySQL server has gone away']
                );
            }
        };
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $service->dispatch(Webhook::EVENT_JOB_SUCCESS, $job);
        } finally {
            \Yii::setLogger($previous);
        }

        $errors = array_values(array_map(
            static fn (array $message): string => (string)$message[0],
            array_filter($logger->messages, static fn (array $message): bool => $message[2] === WebhookService::class)
        ));
        $this->assertSame(
            [sprintf('Webhook #%d delivery failed for event=%s job=%d: %s', $webhook->id, Webhook::EVENT_JOB_SUCCESS, $job->id, 'yii\db\Exception (SQLSTATE HY000, driver error 2006)')],
            $errors
        );
    }

    /**
     * An enabled webhook of the event job.success to $url, and a job.
     *
     * @return array{0: Webhook, 1: Job}
     */
    private function webhookTo(string $url): array
    {
        $user = $this->createUser('wh_http');
        $job = $this->scaffoldJob();
        $webhook = $this->createWebhook($user->id, Webhook::EVENT_JOB_SUCCESS, true, 'whsec-5536');
        $webhook->url = $url;
        $webhook->save(false);

        return [$webhook, $job];
    }

    /**
     * The warnings WebhookService logs while $dispatch runs.
     *
     * @return list<string>
     */
    private function warningsWhile(callable $dispatch): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $dispatch();
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
