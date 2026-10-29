<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\RunnerHttpClient;
use app\tests\unit\services\notification\HttpLoopbackServer;
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
     * The status is the one of the answer's status line, which the runner
     * acts on: a new token on 401, another registration attempt on 502 and
     * 503. The body is read whatever the status.
     *
     * @dataProvider answeredStatuses
     */
    public function testTheStatusIsTheOneTheServerAnsweredWith(int $status): void
    {
        $server = new HttpLoopbackServer();
        $client = new RunnerHttpClient($server->start($status, '{"ok":false,"error":"refused"}'), 'runner-token');
        try {
            $result = $client->post('/api/runner/v1/heartbeat', []);
        } finally {
            $server->stop();
        }

        $this->assertSame(['ok' => false, 'error' => 'refused'], $result);
        $this->assertSame($status, $client->getLastHttpStatus());
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function answeredStatuses(): array
    {
        return [
            'created' => [201],
            'unauthorized' => [401],
            'conflict' => [409],
            'bad gateway' => [502],
            'not ready' => [503],
        ];
    }

    /**
     * A request that got no answer has no status, whatever the request
     * before it got: a 503 left over would make the runner try again to
     * register where it cannot connect at all, a 401 would make it drop its
     * token. PHP 8.5 deprecates $http_response_header, the headers local to
     * the call; http_get_last_response_headers(), which replaces it, keeps
     * them for the whole process.
     */
    public function testARequestThatGotNoAnswerHasNoStatus(): void
    {
        $server = new HttpLoopbackServer();
        $client = new RunnerHttpClient($server->start(503, '{"ok":false}'), 'runner-token');
        try {
            $client->postUnauthenticated('/api/runner/v1/register', ['name' => 'runner-1']);
        } finally {
            $server->stop();
        }
        $this->assertSame(503, $client->getLastHttpStatus());

        $result = $client->postUnauthenticated('/api/runner/v1/register', ['name' => 'runner-1']);

        $this->assertNull($result, 'the server is gone');
        $this->assertSame(0, $client->getLastHttpStatus());
    }

    /**
     * An answer that did not come over HTTP has no status either, although
     * PHP keeps the headers of the last HTTP answer until the next HTTP
     * request: the client clears them before each request.
     */
    public function testAnAnswerThatDidNotComeOverHttpHasNoStatus(): void
    {
        $server = new HttpLoopbackServer();
        $answered = new RunnerHttpClient($server->start(401, '{"ok":false}'), 'runner-token');
        try {
            $answered->post('/api/runner/v1/heartbeat', []);
        } finally {
            $server->stop();
        }
        $directory = sys_get_temp_dir() . '/runner_http_client_' . uniqid('', true);
        mkdir($directory, 0o700, true);
        file_put_contents($directory . '/heartbeat', '{"ok":true}');
        try {
            $local = new RunnerHttpClient('file://' . $directory, 'runner-token');
            $result = $local->post('/heartbeat', []);
        } finally {
            unlink($directory . '/heartbeat');
            rmdir($directory);
        }

        $this->assertSame(401, $answered->getLastHttpStatus());
        $this->assertSame(['ok' => true], $result);
        $this->assertSame(0, $local->getLastHttpStatus());
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
