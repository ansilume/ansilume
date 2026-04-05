<?php

declare(strict_types=1);

namespace app\tests\unit\services\notification;

use app\models\NotificationTemplate;
use app\services\notification\SlackChannel;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

class SlackChannelTest extends TestCase
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
        $ref->setAccessible(true);
        $ref->setValue($nt, [
            'id' => 1,
            'name' => 'Slack Test',
            'channel' => NotificationTemplate::CHANNEL_SLACK,
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

    public function testEmptyWebhookUrlDoesNotSend(): void
    {
        $nt = $this->makeTemplate('{"webhook_url": ""}');
        $channel = new SlackChannel();

        $channel->send($nt, 'Subject', 'Body', []);
        $this->assertTrue(true);
    }

    public function testMissingWebhookUrlDoesNotSend(): void
    {
        $nt = $this->makeTemplate('{}');
        $channel = new SlackChannel();

        $channel->send($nt, 'Subject', 'Body', []);
        $this->assertTrue(true);
    }

    public function testPayloadConstruction(): void
    {
        $nt = $this->makeTemplate('{"webhook_url": "https://hooks.slack.com/test"}');
        $posted = null;

        $channel = new class extends SlackChannel {
            /** @var string|null */
            public ?string $capturedPayload = null;
            /** @var string|null */
            public ?string $capturedUrl = null;

            protected function post(string $url, string $payload, array $headers): string
            {
                $this->capturedUrl = $url;
                $this->capturedPayload = $payload;
                return 'ok';
            }
        };

        $channel->send($nt, 'Job Failed', 'Details here', []);

        $this->assertSame('https://hooks.slack.com/test', $channel->capturedUrl);
        $decoded = json_decode((string)$channel->capturedPayload, true);
        $this->assertSame('Job Failed', $decoded['text']);
        $this->assertCount(1, $decoded['blocks']);
    }

    public function testBlockContainsMrkdwnWithSubjectAndBody(): void
    {
        $nt = $this->makeTemplate('{"webhook_url": "https://hooks.slack.com/test"}');

        $channel = new class extends SlackChannel {
            public ?string $capturedPayload = null;

            protected function post(string $url, string $payload, array $headers): string
            {
                $this->capturedPayload = $payload;
                return 'ok';
            }
        };

        $channel->send($nt, 'Alert', 'Something broke', []);

        $decoded = json_decode((string)$channel->capturedPayload, true);
        $block = $decoded['blocks'][0];
        $this->assertSame('section', $block['type']);
        $this->assertSame('mrkdwn', $block['text']['type']);
        $this->assertStringContainsString('*Alert*', $block['text']['text']);
        $this->assertStringContainsString('Something broke', $block['text']['text']);
    }

    public function testPostHeadersContainJsonContentType(): void
    {
        $nt = $this->makeTemplate('{"webhook_url": "https://hooks.slack.com/test"}');

        $channel = new class extends SlackChannel {
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
    }

    public function testInvalidConfigDoesNotSend(): void
    {
        $nt = $this->makeTemplate('');
        $channel = new SlackChannel();

        $channel->send($nt, 'Subject', 'Body', []);
        $this->assertTrue(true);
    }

    public function testPostSucceedsAgainstLoopbackServer(): void
    {
        $server = new HttpLoopbackServer();
        $baseUrl = $server->start(200, 'slack-ok');
        try {
            $nt = $this->makeTemplate(json_encode(['webhook_url' => $baseUrl]) ?: '{}');
            $channel = new SlackChannel();
            // No exception = success path covered (post() returns normally, info log written).
            $channel->send($nt, 'Hello', 'World', []);
            $this->assertTrue(true);
        } finally {
            $server->stop();
        }
    }

    public function testPostRaisesOnNon2xxResponse(): void
    {
        $server = new HttpLoopbackServer();
        $baseUrl = $server->start(500, 'boom');
        try {
            $nt = $this->makeTemplate(json_encode(['webhook_url' => $baseUrl]) ?: '{}');
            $channel = new SlackChannel();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('SlackChannel: HTTP 500');
            $channel->send($nt, 'Hello', 'World', []);
        } finally {
            $server->stop();
        }
    }
}
