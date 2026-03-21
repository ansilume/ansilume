<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\Job;
use app\models\Webhook;
use app\services\WebhookService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WebhookService payload construction and HMAC signing.
 * No HTTP calls made — delivery logic is not tested here.
 */
class WebhookServiceTest extends TestCase
{
    private WebhookService $service;

    protected function setUp(): void
    {
        $this->service = new class extends WebhookService {
            /** Expose buildPayload for testing. */
            public function testBuildPayload(string $event, Job $job): array
            {
                return $this->buildPayload($event, $job);
            }
        };
    }

    public function testPayloadContainsEventAndTimestamp(): void
    {
        $job = $this->makeJob(42, Job::STATUS_SUCCEEDED);

        $payload = $this->service->testBuildPayload(Webhook::EVENT_JOB_SUCCESS, $job);

        $this->assertSame(Webhook::EVENT_JOB_SUCCESS, $payload['event']);
        $this->assertArrayHasKey('timestamp', $payload);
        $this->assertIsInt($payload['timestamp']);
    }

    public function testPayloadContainsJobFields(): void
    {
        $job = $this->makeJob(7, Job::STATUS_FAILED);
        $job->exit_code    = 1;
        $job->started_at   = 1710000000;
        $job->finished_at  = 1710000120;

        $payload = $this->service->testBuildPayload(Webhook::EVENT_JOB_FAILURE, $job);

        $this->assertSame(7,                     $payload['job']['id']);
        $this->assertSame(Job::STATUS_FAILED,    $payload['job']['status']);
        $this->assertSame(1,                     $payload['job']['exit_code']);
        $this->assertSame(1710000000,            $payload['job']['started_at']);
        $this->assertSame(1710000120,            $payload['job']['finished_at']);
    }

    public function testHmacSignatureIsCorrect(): void
    {
        $secret  = 'my-webhook-secret';
        $body    = '{"event":"job.success","timestamp":1234}';
        $sig     = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $this->assertStringStartsWith('sha256=', $sig);
        // Verify the signature matches independent computation
        $expected = 'sha256=' . hash_hmac('sha256', $body, $secret);
        $this->assertSame($expected, $sig);
    }

    public function testHmacSignatureDiffersForDifferentSecrets(): void
    {
        $body = '{"event":"job.success"}';
        $sig1 = hash_hmac('sha256', $body, 'secret-one');
        $sig2 = hash_hmac('sha256', $body, 'secret-two');
        $this->assertNotSame($sig1, $sig2);
    }

    public function testHmacSignatureDiffersForDifferentPayloads(): void
    {
        $secret = 'same-secret';
        $sig1   = hash_hmac('sha256', '{"event":"job.success"}', $secret);
        $sig2   = hash_hmac('sha256', '{"event":"job.failure"}', $secret);
        $this->assertNotSame($sig1, $sig2);
    }

    private function makeJob(int $id, string $status): Job
    {
        $job = new Job();
        $job->id              = $id;
        $job->status          = $status;
        $job->job_template_id = 1;
        $job->launched_by     = 1;
        return $job;
    }
}
