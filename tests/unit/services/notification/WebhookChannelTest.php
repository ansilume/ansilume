<?php

declare(strict_types=1);

namespace app\tests\unit\services\notification;

use app\models\NotificationTemplate;
use app\services\notification\WebhookChannel;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;
use yii\log\Logger;

class WebhookChannelTest extends TestCase
{
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
            'id' => 1,
            'name' => 'Webhook Test',
            'channel' => NotificationTemplate::CHANNEL_WEBHOOK,
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

    public function testEmptyUrlDoesNotSend(): void
    {
        $nt = $this->makeTemplate('{}');
        $channel = new WebhookChannel();

        $channel->send($nt, 'Subject', 'Body', []);
        $this->assertTrue(true);
    }

    public function testPayloadContainsSubjectBodyVariables(): void
    {
        $nt = $this->makeTemplate('{"url": "https://example.com/hook", "headers": {"X-Token": "abc"}}');

        $channel = new class extends WebhookChannel {
            public ?string $capturedPayload = null;
            /** @var string[] */
            public array $capturedHeaders = [];

            protected function post(string $url, string $payload, array $headers): string
            {
                $this->capturedPayload = $payload;
                $this->capturedHeaders = $headers;
                return 'ok';
            }
        };

        $vars = ['job.id' => '42', 'job.status' => 'failed'];
        $channel->send($nt, 'Job Failed', 'Details', $vars);

        $decoded = json_decode((string)$channel->capturedPayload, true);
        $this->assertSame('Job Failed', $decoded['subject']);
        $this->assertSame('Details', $decoded['body']);
        $this->assertSame('42', $decoded['variables']['job.id']);
        $this->assertContains('X-Token: abc', $channel->capturedHeaders);
        $this->assertContains('Content-Type: application/json', $channel->capturedHeaders);
    }

    /**
     * Without custom headers, only the channel's own are sent: the type,
     * the event (a test send without one is sent for 'test') and the
     * delivery, which a test send has none of: test-<16 hex digits>.
     * Without a signing secret, no signature.
     */
    public function testNoCustomHeadersSendsOnlyTheChannelsOwn(): void
    {
        $nt = $this->makeTemplate('{"url": "https://example.com/hook"}');

        $channel = new class extends WebhookChannel {
            /** @var string[] */
            public array $capturedHeaders = [];

            protected function post(string $url, string $payload, array $headers): string
            {
                $this->capturedHeaders = $headers;
                return 'ok';
            }
        };

        $channel->send($nt, 'Test', 'Body', []);

        $this->assertCount(3, $channel->capturedHeaders);
        $this->assertSame(['Content-Type: application/json', 'X-Ansilume-Event: test'], array_slice($channel->capturedHeaders, 0, 2));
        $this->assertMatchesRegularExpression('/^X-Ansilume-Delivery: test-[0-9a-f]{16}$/', $channel->capturedHeaders[2]);
    }

    public function testMultipleCustomHeaders(): void
    {
        $config = json_encode([
            'url' => 'https://example.com/hook',
            'headers' => ['X-Token' => 'abc', 'X-Source' => 'ansilume'],
        ]);
        $nt = $this->makeTemplate((string)$config);

        $channel = new class extends WebhookChannel {
            /** @var string[] */
            public array $capturedHeaders = [];

            protected function post(string $url, string $payload, array $headers): string
            {
                $this->capturedHeaders = $headers;
                return 'ok';
            }
        };

        $channel->send($nt, 'Test', 'Body', []);

        $this->assertContains('Content-Type: application/json', $channel->capturedHeaders);
        $this->assertContains('X-Token: abc', $channel->capturedHeaders);
        $this->assertContains('X-Source: ansilume', $channel->capturedHeaders);
        $this->assertCount(5, $channel->capturedHeaders, 'the channel’s own three and the two of the config');
    }

    /**
     * regression: a header value that is a list or an object (a config
     * stored through the API) was joined to its name as text, which PHP
     * warns about ("Array to string conversion"), and the error handler
     * turned the warning into an error of every attempt of the delivery. It
     * is left out; an empty or a number value is sent as before.
     */
    public function testRegressionAHeaderValueThatIsAListIsLeftOut(): void
    {
        $nt = $this->makeTemplate((string)json_encode([
            'url' => 'https://example.com/hook',
            'headers' => ['X-List' => ['a'], 'X-Object' => ['k' => 'v'], 'X-Empty' => null, 'X-Number' => 5],
        ]));

        $channel = new class extends WebhookChannel {
            /** @var string[] */
            public array $capturedHeaders = [];

            protected function post(string $url, string $payload, array $headers): string
            {
                $this->capturedHeaders = $headers;
                return 'ok';
            }
        };

        $channel->send($nt, 'Test', 'Body', []);

        $this->assertSame(['X-Empty: ', 'X-Number: 5'], array_slice($channel->capturedHeaders, 3));
    }

    public function testInvalidConfigDoesNotSend(): void
    {
        $nt = $this->makeTemplate('');
        $channel = new WebhookChannel();

        $channel->send($nt, 'Subject', 'Body', []);
        $this->assertTrue(true);
    }

    public function testVariablesPassedInPayload(): void
    {
        $nt = $this->makeTemplate('{"url": "https://example.com/hook"}');

        $channel = new class extends WebhookChannel {
            public ?string $capturedPayload = null;

            protected function post(string $url, string $payload, array $headers): string
            {
                $this->capturedPayload = $payload;
                return 'ok';
            }
        };

        $vars = ['job.id' => '1', 'job.status' => 'failed', 'template.name' => 'Deploy'];
        $channel->send($nt, 'Alert', 'Failed', $vars);

        $decoded = json_decode((string)$channel->capturedPayload, true);
        $this->assertSame($vars, $decoded['variables']);
    }

    /**
     * Regression: post() called curl_close(), which PHP 8.5 deprecates, after
     * the delivery; the error handler turned the deprecation into an
     * exception, so every delivered notification was recorded as failed.
     */
    public function testPostSucceedsAgainstLoopbackServer(): void
    {
        $server = new HttpLoopbackServer();
        $baseUrl = $server->start(204, '');
        try {
            $nt = $this->makeTemplate(json_encode(['url' => $baseUrl, 'headers' => ['X-Foo' => 'bar']]) ?: '{}');
            $channel = new WebhookChannel();
            $channel->send($nt, 'Hello', 'World', ['k' => 'v']);
            $this->assertTrue(true);
        } finally {
            $server->stop();
        }
    }

    /**
     * regression: a delivery the webhook took was logged at info with the
     * webhook's URL ("WebhookChannel: sent to <url> — 204"), which the queue
     * worker's log target writes. The URL is a channel secret and often
     * carries a token. The log names the template and the HTTP code only, as
     * SlackChannel and TeamsChannel do.
     */
    public function testRegressionTheLogNamedTheWebhookUrl(): void
    {
        $server = new HttpLoopbackServer();
        $baseUrl = $server->start(204, '');
        $url = $baseUrl . '/hooks/w5c-webhook-token-7713?key=w5c-webhook-key-7714';
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $nt = $this->makeTemplate((string)json_encode(['url' => $url, 'headers' => ['Authorization' => 'Bearer w5c-header-7715']]));
            (new WebhookChannel())->send($nt, 'Hello', 'World', []);
        } finally {
            \Yii::setLogger($previous);
            $server->stop();
        }

        $logged = array_values(array_filter(
            $logger->messages,
            static fn (array $message): bool => $message[2] === WebhookChannel::class
        ));
        $text = (string)json_encode($logged);
        $this->assertStringNotContainsString('w5c-webhook-token-7713', $text);
        $this->assertStringNotContainsString('w5c-webhook-key-7714', $text);
        $this->assertStringNotContainsString('w5c-header-7715', $text);
        $this->assertStringNotContainsString('127.0.0.1', $text);
        $this->assertSame([[Logger::LEVEL_INFO, sprintf(WebhookChannel::SENT, 1, 204)]], array_map(
            static fn (array $message): array => [$message[1], $message[0]],
            $logged
        ));
    }

    public function testPostRaisesOnNon2xxResponse(): void
    {
        $server = new HttpLoopbackServer();
        $baseUrl = $server->start(502, 'bad gateway');
        try {
            $nt = $this->makeTemplate(json_encode(['url' => $baseUrl]) ?: '{}');
            $channel = new WebhookChannel();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('WebhookChannel: HTTP 502.');
            $channel->send($nt, 'Hello', 'World', []);
        } finally {
            $server->stop();
        }
    }

    /**
     * curl does not take a URL longer than libcurl takes
     * (CURL_MAX_INPUT_LENGTH, 8000000 bytes): curl error 43.
     */
    public function testAUrlThatCurlDoesNotTakeIsAnError(): void
    {
        $nt = $this->makeTemplate((string)json_encode(['url' => 'http://127.0.0.1/' . str_repeat('a', 8000000)]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WebhookChannel: The request could not be made (curl error 43).');
        (new WebhookChannel())->send($nt, 'Hello', 'World', []);
    }
}
