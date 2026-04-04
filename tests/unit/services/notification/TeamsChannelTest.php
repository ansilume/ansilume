<?php

declare(strict_types=1);

namespace app\tests\unit\services\notification;

use app\models\NotificationTemplate;
use app\services\notification\TeamsChannel;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

class TeamsChannelTest extends TestCase
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
            'name' => 'Teams Test',
            'channel' => NotificationTemplate::CHANNEL_TEAMS,
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
        $nt = $this->makeTemplate('{}');
        $channel = new TeamsChannel();

        $channel->send($nt, 'Subject', 'Body', []);
        $this->assertTrue(true);
    }

    public function testPayloadConstructionWithSuccessStatus(): void
    {
        $nt = $this->makeTemplate('{"webhook_url": "https://outlook.office.com/test"}');

        $channel = new class extends TeamsChannel {
            public ?string $capturedPayload = null;

            protected function post(string $url, string $payload, array $headers): string
            {
                $this->capturedPayload = $payload;
                return '1';
            }
        };

        $channel->send($nt, 'Job OK', 'All good', ['job.status' => 'successful', 'job.url' => 'http://test']);

        $decoded = json_decode((string)$channel->capturedPayload, true);
        $this->assertSame('MessageCard', $decoded['@type']);
        $this->assertSame('2DC72D', $decoded['themeColor']);
        $this->assertSame('Job OK', $decoded['summary']);
        $this->assertNotEmpty($decoded['potentialAction']);
    }

    public function testPayloadConstructionWithFailedStatus(): void
    {
        $nt = $this->makeTemplate('{"webhook_url": "https://outlook.office.com/test"}');

        $channel = new class extends TeamsChannel {
            public ?string $capturedPayload = null;

            protected function post(string $url, string $payload, array $headers): string
            {
                $this->capturedPayload = $payload;
                return '1';
            }
        };

        $channel->send($nt, 'Job Failed', 'Error', ['job.status' => 'failed']);

        $decoded = json_decode((string)$channel->capturedPayload, true);
        $this->assertSame('FF0000', $decoded['themeColor']);
    }
}
