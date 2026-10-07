<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\RunnerHttpClient;
use PHPUnit\Framework\TestCase;

class RunnerHttpClientTest extends TestCase
{
    private mixed $versionBackup = null;

    protected function setUp(): void
    {
        $this->versionBackup = \Yii::$app->params['version'] ?? null;
        \Yii::$app->params['version'] = '2.8.0';
    }

    protected function tearDown(): void
    {
        \Yii::$app->params['version'] = $this->versionBackup;
    }

    public function testGetLastHttpStatusDefaultsToZero(): void
    {
        $client = new RunnerHttpClient('http://test', '');
        $this->assertSame(0, $client->getLastHttpStatus());
    }

    public function testSetTokenDoesNotThrow(): void
    {
        $client = new RunnerHttpClient('http://test', 'initial');
        $client->setToken('updated');

        // Token is private — verify via getLastHttpStatus that object is in valid state.
        $this->assertSame(0, $client->getLastHttpStatus());
    }

    public function testPostReturnsNullForInvalidUrl(): void
    {
        // Use an unresolvable hostname to get a fast failure.
        $client = new RunnerHttpClient('http://invalid.test.invalid', '');
        $result = $client->post('/api/test', ['foo' => 'bar']);

        $this->assertNull($result);
    }

    public function testPostUnauthenticatedReturnsNullForInvalidUrl(): void
    {
        $client = new RunnerHttpClient('http://invalid.test.invalid', '');
        $result = $client->postUnauthenticated('/api/test', ['foo' => 'bar']);

        $this->assertNull($result);
    }

    /**
     * Every authenticated request reports the runner's version and the
     * features it honours, so the server knows which runners neutralise a
     * repository's vault settings.
     */
    public function testEveryAuthenticatedRequestReportsVersionAndCapabilities(): void
    {
        $client = $this->recordingClient();

        $client->post('/api/runner/v1/jobs/claim', []);
        $client->post('/api/runner/v1/jobs/7/logs', ['stream' => 'stdout', 'content' => 'x', 'sequence' => 0]);

        $this->assertSame(['vault_password_source'], RunnerHttpClient::CAPABILITIES);
        $this->assertSame(
            ['software_version' => '2.8.0', 'capabilities' => ['vault_password_source']],
            $client->sent[0]['body']
        );
        $this->assertSame(
            ['software_version' => '2.8.0', 'capabilities' => ['vault_password_source'], 'stream' => 'stdout', 'content' => 'x', 'sequence' => 0],
            $client->sent[1]['body']
        );
        $this->assertSame(['Authorization: Bearer runner-token'], $client->sent[1]['headers']);
    }

    public function testAMissingVersionIsReportedAsDev(): void
    {
        \Yii::$app->params['version'] = '';
        $client = $this->recordingClient();

        $client->post('/api/runner/v1/heartbeat', []);

        $this->assertSame('dev', $client->sent[0]['body']['software_version']);
        $this->assertSame(['vault_password_source'], $client->sent[0]['body']['capabilities']);
    }

    public function testCallersCanOverrideTheReportedFields(): void
    {
        $client = $this->recordingClient();

        $client->post('/api/runner/v1/heartbeat', ['capabilities' => [], 'software_version' => '1.0.0']);

        $this->assertSame(['software_version' => '1.0.0', 'capabilities' => []], $client->sent[0]['body']);
    }

    public function testRegistrationCarriesOnlyWhatTheCallerSends(): void
    {
        $client = $this->recordingClient();

        $client->postUnauthenticated('/api/runner/v1/register', ['name' => 'runner-1']);

        $this->assertSame(['name' => 'runner-1'], $client->sent[0]['body']);
        $this->assertSame([], $client->sent[0]['headers']);
    }

    /**
     * @return RunnerHttpClient&object{sent: list<array{path: string, body: array<string, mixed>, headers: array<int, string>}>}
     */
    private function recordingClient(): RunnerHttpClient
    {
        return new class ('http://test-server', 'runner-token') extends RunnerHttpClient {
            /** @var list<array{path: string, body: array<string, mixed>, headers: array<int, string>}> */
            public array $sent = [];

            protected function httpPost(string $path, array $body, array $extraHeaders = []): ?array
            {
                $this->sent[] = ['path' => $path, 'body' => $body, 'headers' => $extraHeaders];

                return ['ok' => true];
            }
        };
    }
}
