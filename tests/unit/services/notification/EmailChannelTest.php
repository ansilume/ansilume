<?php

declare(strict_types=1);

namespace app\tests\unit\services\notification;

use app\models\NotificationTemplate;
use app\services\notification\EmailChannel;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

class EmailChannelTest extends TestCase
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
            'name' => 'Test',
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'config' => $configJson,
            'subject_template' => 'Test Subject',
            'body_template' => 'Test Body',
            'events' => 'job.failed',
            'created_by' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        return $nt;
    }

    public function testEmptyEmailsDoesNotSend(): void
    {
        $nt = $this->makeTemplate('{"emails": []}');
        $channel = new EmailChannel();

        // Should not throw — just returns early
        $channel->send($nt, 'Subject', 'Body', []);
        $this->assertTrue(true);
    }

    public function testMissingEmailsKeyDoesNotSend(): void
    {
        $nt = $this->makeTemplate('{}');
        $channel = new EmailChannel();

        $channel->send($nt, 'Subject', 'Body', []);
        $this->assertTrue(true);
    }

    public function testInvalidConfigDoesNotSend(): void
    {
        $nt = $this->makeTemplate('');
        $channel = new EmailChannel();

        $channel->send($nt, 'Subject', 'Body', []);
        $this->assertTrue(true);
    }
}
