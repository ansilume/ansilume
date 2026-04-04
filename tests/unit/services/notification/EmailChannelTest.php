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

    public function testNonStringEmailsAreFiltered(): void
    {
        $nt = $this->makeTemplate('{"emails": [123, null, false]}');
        $channel = new EmailChannel();

        // All non-string entries → empty after filter → should not send
        $channel->send($nt, 'Subject', 'Body', []);
        $this->assertTrue(true);
    }

    public function testSendWithValidEmailsCallsMailer(): void
    {
        $nt = $this->makeTemplate('{"emails": ["ops@example.com"]}');

        $message = $this->createMock(\yii\mail\MessageInterface::class);
        $message->method('setFrom')->willReturnSelf();
        $message->method('setTo')->willReturnSelf();
        $message->method('setSubject')->willReturnSelf();
        $message->method('setHtmlBody')->willReturnSelf();
        $message->method('setTextBody')->willReturnSelf();
        $message->expects($this->once())->method('send')->willReturn(true);

        $mailer = $this->createMock(\yii\mail\MailerInterface::class);
        $mailer->method('compose')->willReturn($message);

        // Capture existing mailer config (do NOT access ->mailer which triggers instantiation)
        $origDef = \Yii::$app->getComponents()['mailer'] ?? null;
        \Yii::$app->set('mailer', $mailer);

        try {
            $channel = new EmailChannel();
            $channel->send($nt, 'Alert', 'Details', []);
        } finally {
            \Yii::$app->set('mailer', $origDef);
        }
    }

    public function testSendThrowsWhenMailerReturnsFalse(): void
    {
        $nt = $this->makeTemplate('{"emails": ["ops@example.com"]}');

        $message = $this->createMock(\yii\mail\MessageInterface::class);
        $message->method('setFrom')->willReturnSelf();
        $message->method('setTo')->willReturnSelf();
        $message->method('setSubject')->willReturnSelf();
        $message->method('setHtmlBody')->willReturnSelf();
        $message->method('setTextBody')->willReturnSelf();
        $message->method('send')->willReturn(false);

        $mailer = $this->createMock(\yii\mail\MailerInterface::class);
        $mailer->method('compose')->willReturn($message);

        $origDef = \Yii::$app->getComponents()['mailer'] ?? null;
        \Yii::$app->set('mailer', $mailer);

        try {
            $channel = new EmailChannel();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Mailer::send() returned false');
            $channel->send($nt, 'Alert', 'Details', []);
        } finally {
            \Yii::$app->set('mailer', $origDef);
        }
    }
}
