<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\NotificationTemplate;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

class NotificationTemplateTest extends TestCase
{
    private function makeModel(array $attrs = []): NotificationTemplate
    {
        $m = $this->getMockBuilder(NotificationTemplate::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $m->method('attributes')->willReturn(
            ['id', 'name', 'description', 'channel', 'config', 'subject_template',
             'body_template', 'events', 'created_by', 'created_at', 'updated_at']
        );
        $m->method('save')->willReturn(true);
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($m, array_merge([
            'id' => 1,
            'name' => 'Test',
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'config' => '{"emails": ["a@b.com"]}',
            'subject_template' => 'Subject',
            'body_template' => 'Body',
            'events' => 'job.failed,job.succeeded',
            'created_by' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ], $attrs));
        return $m;
    }

    public function testChannelConstants(): void
    {
        $this->assertSame('email', NotificationTemplate::CHANNEL_EMAIL);
        $this->assertSame('slack', NotificationTemplate::CHANNEL_SLACK);
        $this->assertSame('teams', NotificationTemplate::CHANNEL_TEAMS);
        $this->assertSame('webhook', NotificationTemplate::CHANNEL_WEBHOOK);
    }

    public function testEventConstants(): void
    {
        $this->assertSame('job.started', NotificationTemplate::EVENT_JOB_STARTED);
        $this->assertSame('job.succeeded', NotificationTemplate::EVENT_JOB_SUCCEEDED);
        $this->assertSame('job.failed', NotificationTemplate::EVENT_JOB_FAILED);
        $this->assertSame('job.timed_out', NotificationTemplate::EVENT_JOB_TIMED_OUT);
    }

    public function testChannelLabels(): void
    {
        $labels = NotificationTemplate::channelLabels();
        $this->assertSame('Email', $labels['email']);
        $this->assertSame('Slack', $labels['slack']);
        $this->assertSame('Microsoft Teams', $labels['teams']);
        $this->assertSame('Webhook', $labels['webhook']);
    }

    public function testChannelLabelUnknown(): void
    {
        $this->assertSame('custom', NotificationTemplate::channelLabel('custom'));
    }

    public function testGetEventList(): void
    {
        $m = $this->makeModel();
        $this->assertSame(['job.failed', 'job.succeeded'], $m->getEventList());
    }

    public function testGetEventListEmpty(): void
    {
        $m = $this->makeModel(['events' => '']);
        $this->assertSame([], $m->getEventList());
    }

    public function testListensToMatchingEvent(): void
    {
        $m = $this->makeModel();
        $this->assertTrue($m->listensTo('job.failed'));
        $this->assertTrue($m->listensTo('job.succeeded'));
    }

    public function testListensToNonMatchingEvent(): void
    {
        $m = $this->makeModel();
        $this->assertFalse($m->listensTo('job.started'));
    }

    public function testGetParsedConfig(): void
    {
        $m = $this->makeModel();
        $config = $m->getParsedConfig();
        $this->assertSame(['a@b.com'], $config['emails']);
    }

    public function testGetParsedConfigEmpty(): void
    {
        $m = $this->makeModel(['config' => '']);
        $this->assertSame([], $m->getParsedConfig());
    }

    public function testGetParsedConfigInvalidJson(): void
    {
        $m = $this->makeModel(['config' => 'not-json']);
        $this->assertSame([], $m->getParsedConfig());
    }

    public function testEventLabels(): void
    {
        $labels = NotificationTemplate::eventLabels();
        $this->assertCount(4, $labels);
        $this->assertSame('Job Started', $labels['job.started']);
    }
}
