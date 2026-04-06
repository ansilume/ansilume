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
        $this->assertSame('telegram', NotificationTemplate::CHANNEL_TELEGRAM);
        $this->assertSame('pagerduty', NotificationTemplate::CHANNEL_PAGERDUTY);
    }

    public function testEventConstants(): void
    {
        $this->assertSame('job.launched', NotificationTemplate::EVENT_JOB_LAUNCHED);
        $this->assertSame('job.succeeded', NotificationTemplate::EVENT_JOB_SUCCEEDED);
        $this->assertSame('job.failed', NotificationTemplate::EVENT_JOB_FAILED);
        $this->assertSame('job.canceled', NotificationTemplate::EVENT_JOB_CANCELED);
        $this->assertSame('workflow.failed', NotificationTemplate::EVENT_WORKFLOW_FAILED);
        $this->assertSame('approval.requested', NotificationTemplate::EVENT_APPROVAL_REQUESTED);
        $this->assertSame('runner.offline', NotificationTemplate::EVENT_RUNNER_OFFLINE);
    }

    public function testChannelLabels(): void
    {
        $labels = NotificationTemplate::channelLabels();
        $this->assertSame('Email', $labels['email']);
        $this->assertSame('Slack', $labels['slack']);
        $this->assertSame('Microsoft Teams', $labels['teams']);
        $this->assertSame('Webhook', $labels['webhook']);
        $this->assertSame('Telegram', $labels['telegram']);
        $this->assertSame('PagerDuty', $labels['pagerduty']);
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
        $this->assertFalse($m->listensTo('job.canceled'));
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
        // 18 events across jobs (4), workflows (5), approvals (3), schedules (1),
        // runners (2), projects (2), webhooks (1).
        $this->assertCount(18, $labels);
        $this->assertArrayHasKey('job.failed', $labels);
        $this->assertArrayHasKey('workflow.succeeded', $labels);
        $this->assertArrayHasKey('approval.requested', $labels);
        $this->assertArrayHasKey('runner.offline', $labels);
        $this->assertArrayHasKey('project.sync_failed', $labels);
        $this->assertArrayHasKey('webhook.invalid_token', $labels);
    }

    public function testDefaultFailureEventsSubset(): void
    {
        $defaults = NotificationTemplate::defaultFailureEvents();
        $this->assertContains(NotificationTemplate::EVENT_JOB_FAILED, $defaults);
        $this->assertContains(NotificationTemplate::EVENT_WORKFLOW_FAILED, $defaults);
        $this->assertContains(NotificationTemplate::EVENT_RUNNER_OFFLINE, $defaults);
        $this->assertNotContains(NotificationTemplate::EVENT_JOB_SUCCEEDED, $defaults);
    }

    public function testEventSeverityMapping(): void
    {
        $this->assertSame('critical', NotificationTemplate::eventSeverity(NotificationTemplate::EVENT_JOB_FAILED));
        $this->assertSame('critical', NotificationTemplate::eventSeverity(NotificationTemplate::EVENT_RUNNER_OFFLINE));
        $this->assertSame('error', NotificationTemplate::eventSeverity(NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN));
        $this->assertSame('warning', NotificationTemplate::eventSeverity(NotificationTemplate::EVENT_APPROVAL_REQUESTED));
        $this->assertSame('info', NotificationTemplate::eventSeverity(NotificationTemplate::EVENT_JOB_SUCCEEDED));
    }

    // -- Channel config validation ------------------------------------------------

    public function testTelegramRequiresBotTokenAndChatId(): void
    {
        $m = $this->makeModel([
            'channel' => NotificationTemplate::CHANNEL_TELEGRAM,
            'config' => '{}',
        ]);
        $m->validateChannelConfig('config');
        $this->assertTrue($m->hasErrors('config'));
        $errors = $m->getErrors('config');
        $this->assertCount(2, $errors);
        $this->assertStringContainsString('bot_token', $errors[0]);
        $this->assertStringContainsString('chat_id', $errors[1]);
    }

    public function testTelegramValidWithBothFields(): void
    {
        $m = $this->makeModel([
            'channel' => NotificationTemplate::CHANNEL_TELEGRAM,
            'config' => '{"bot_token":"123:abc","chat_id":"-100"}',
        ]);
        $m->validateChannelConfig('config');
        $this->assertFalse($m->hasErrors('config'));
    }

    public function testSlackRequiresWebhookUrl(): void
    {
        $m = $this->makeModel([
            'channel' => NotificationTemplate::CHANNEL_SLACK,
            'config' => '{}',
        ]);
        $m->validateChannelConfig('config');
        $this->assertTrue($m->hasErrors('config'));
        $this->assertStringContainsString('webhook_url', $m->getFirstError('config') ?? '');
    }

    public function testTeamsRequiresWebhookUrl(): void
    {
        $m = $this->makeModel([
            'channel' => NotificationTemplate::CHANNEL_TEAMS,
            'config' => '{}',
        ]);
        $m->validateChannelConfig('config');
        $this->assertTrue($m->hasErrors('config'));
        $this->assertStringContainsString('webhook_url', $m->getFirstError('config') ?? '');
    }

    public function testWebhookRequiresUrl(): void
    {
        $m = $this->makeModel([
            'channel' => NotificationTemplate::CHANNEL_WEBHOOK,
            'config' => '{}',
        ]);
        $m->validateChannelConfig('config');
        $this->assertTrue($m->hasErrors('config'));
        $this->assertStringContainsString('url', $m->getFirstError('config') ?? '');
    }

    public function testPagerdutyRequiresRoutingKey(): void
    {
        $m = $this->makeModel([
            'channel' => NotificationTemplate::CHANNEL_PAGERDUTY,
            'config' => '{}',
        ]);
        $m->validateChannelConfig('config');
        $this->assertTrue($m->hasErrors('config'));
        $this->assertStringContainsString('routing_key', $m->getFirstError('config') ?? '');
    }

    public function testEmailRequiresEmails(): void
    {
        $m = $this->makeModel([
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'config' => '{}',
        ]);
        $m->validateChannelConfig('config');
        $this->assertTrue($m->hasErrors('config'));
        $this->assertStringContainsString('emails', $m->getFirstError('config') ?? '');
    }

    public function testEmailValidWithRecipients(): void
    {
        $m = $this->makeModel([
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'config' => '{"emails":["a@b.com"]}',
        ]);
        $m->validateChannelConfig('config');
        $this->assertFalse($m->hasErrors('config'));
    }

    public function testWebhookValidWithUrl(): void
    {
        $m = $this->makeModel([
            'channel' => NotificationTemplate::CHANNEL_WEBHOOK,
            'config' => '{"url":"https://example.com/hook"}',
        ]);
        $m->validateChannelConfig('config');
        $this->assertFalse($m->hasErrors('config'));
    }

    public function testPagerdutyValidWithRoutingKey(): void
    {
        $m = $this->makeModel([
            'channel' => NotificationTemplate::CHANNEL_PAGERDUTY,
            'config' => '{"routing_key":"abc123"}',
        ]);
        $m->validateChannelConfig('config');
        $this->assertFalse($m->hasErrors('config'));
    }
}
