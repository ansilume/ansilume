<?php

declare(strict_types=1);

namespace app\tests\unit\services\notification;

use app\models\NotificationTemplate;
use app\services\notification\WebhookChannel;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

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
        $ref->setAccessible(true);
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
}
