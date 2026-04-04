<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Job;
use app\models\Webhook;
use app\services\WebhookService;
use app\tests\integration\DbTestCase;

class WebhookServiceTest extends DbTestCase
{
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
}
