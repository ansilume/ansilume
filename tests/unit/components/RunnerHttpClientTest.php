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
     * repository's vault settings and which take password survey answers
     * as a file. The claim request carries them too: the server decides
     * per claim how the answers travel.
     */
    public function testEveryAuthenticatedRequestReportsVersionAndCapabilities(): void
    {
        $client = $this->recordingClient();

        $client->post('/api/runner/v1/jobs/claim', []);
        $client->post('/api/runner/v1/jobs/7/logs', ['stream' => 'stdout', 'content' => 'x', 'sequence' => 0]);

        $this->assertSame(['vault_password_source', 'secret_extra_vars'], RunnerHttpClient::CAPABILITIES);
        $this->assertSame(
            ['software_version' => '2.8.0', 'capabilities' => ['vault_password_source', 'secret_extra_vars']],
            $client->sent[0]['body']
        );
        $this->assertSame(
            ['software_version' => '2.8.0', 'capabilities' => ['vault_password_source', 'secret_extra_vars'], 'stream' => 'stdout', 'content' => 'x', 'sequence' => 0],
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
        $this->assertSame(['vault_password_source', 'secret_extra_vars'], $client->sent[0]['body']['capabilities']);
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
     * Regression (review RUN-2): a body with bytes that are not UTF-8 (job
     * output can hold any) failed json_encode(), and the request was dropped
     * without a word. Now the request goes out with U+FFFD in their place.
     */
    public function testBytesThatAreNotUtf8ArriveAsReplacementCharacters(): void
    {
        $recorder = new RunnerApiRecorder();
        $client = new RunnerHttpClient($recorder->start(), 'runner-token');
        try {
            $logged = $client->post('/api/runner/v1/jobs/7/logs', ['stream' => 'stdout', 'content' => "cut \xC3", 'sequence' => 0]);
            $registered = $client->postUnauthenticated('/api/runner/v1/register', ['name' => "runner-\xFF"]);
            $requests = $recorder->requests();
        } finally {
            $recorder->stop();
        }

        $this->assertSame(['ok' => true], $logged);
        $this->assertSame(['ok' => true], $registered);
        $this->assertSame(200, $client->getLastHttpStatus());
        $this->assertSame(['/api/runner/v1/jobs/7/logs', '/api/runner/v1/register'], array_column($requests, 'path'));
        $log = json_decode($requests[0]['body'], true);
        $this->assertIsArray($log);
        $this->assertSame("cut \u{FFFD}", $log['content']);
        $this->assertSame(['name' => "runner-\u{FFFD}"], json_decode($requests[1]['body'], true));
    }

    /**
     * A body JSON cannot express at all is not sent.
     */
    public function testABodyJsonCannotExpressIsNotSent(): void
    {
        $recorder = new RunnerApiRecorder();
        $client = new RunnerHttpClient($recorder->start(), 'runner-token');
        try {
            $result = $client->post('/api/runner/v1/jobs/7/logs', ['sequence' => NAN]);
            $requests = $recorder->requests();
        } finally {
            $recorder->stop();
        }

        $this->assertNull($result);
        $this->assertSame([], $requests);
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
