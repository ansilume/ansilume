<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\services\NotificationDispatcher;
use app\services\notification\ChannelInterface;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

class NotificationDispatcherTest extends TestCase
{
    private function makeNotificationTemplate(string $channel, string $events): NotificationTemplate
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
            'name' => 'Test NT',
            'channel' => $channel,
            'config' => '{}',
            'subject_template' => 'Subject {{ job.id }}',
            'body_template' => 'Body {{ job.status }}',
            'events' => $events,
            'created_by' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        return $nt;
    }

    private function makeJobWithTemplate(string $status, array $notificationTemplates): Job
    {
        $jt = $this->getMockBuilder(JobTemplate::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $jt->method('attributes')->willReturn(
            ['id', 'name', 'description', 'project_id', 'inventory_id', 'credential_id',
             'playbook', 'extra_vars', 'verbosity', 'forks', 'become', 'become_method',
             'become_user', 'limit', 'tags', 'skip_tags', 'timeout_minutes',
             'runner_group_id', 'survey_fields',
             'trigger_token', 'lint_output', 'lint_at', 'lint_exit_code',
             'created_by', 'created_at', 'updated_at', 'deleted_at']
        );

        $job = $this->getMockBuilder(Job::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $job->method('attributes')->willReturn(
            ['id', 'job_template_id', 'status', 'exit_code', 'started_at', 'finished_at',
             'launched_by', 'created_at', 'updated_at']
        );

        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($job, [
            'id' => 42,
            'job_template_id' => 1,
            'status' => $status,
            'exit_code' => $status === 'successful' ? 0 : 1,
            'started_at' => time() - 60,
            'finished_at' => time(),
            'launched_by' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $ref->setValue($jt, [
            'id' => 1,
            'name' => 'Deploy',
            'project_id' => 1,
            'inventory_id' => 1,
            'playbook' => 'site.yml',
            'created_by' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        // Wire relations
        $relRef = new \ReflectionProperty(BaseActiveRecord::class, '_related');
        $relRef->setAccessible(true);
        $relRef->setValue($job, [
            'jobTemplate' => $jt,
            'launcher' => null,
        ]);
        $relRef->setValue($jt, [
            'notificationTemplates' => $notificationTemplates,
            'project' => null,
        ]);

        return $job;
    }

    public function testDispatchFiltersByEvent(): void
    {
        $nt = $this->makeNotificationTemplate('email', 'job.failed');
        $job = $this->makeJobWithTemplate('successful', [$nt]);

        $sent = false;
        $mockChannel = $this->createMock(ChannelInterface::class);
        $mockChannel->expects($this->never())->method('send');

        $dispatcher = new NotificationDispatcher();
        $dispatcher->setChannel('email', $mockChannel);

        $dispatcher->dispatch(NotificationTemplate::EVENT_JOB_SUCCEEDED, $job);
    }

    public function testDispatchSendsMatchingEvent(): void
    {
        $nt = $this->makeNotificationTemplate('email', 'job.failed');
        $job = $this->makeJobWithTemplate('failed', [$nt]);

        $mockChannel = $this->createMock(ChannelInterface::class);
        $mockChannel->expects($this->once())->method('send');

        $dispatcher = new NotificationDispatcher();
        $dispatcher->setChannel('email', $mockChannel);

        $dispatcher->dispatch(NotificationTemplate::EVENT_JOB_FAILED, $job);
    }

    public function testDispatchHandlesMultipleTemplates(): void
    {
        $nt1 = $this->makeNotificationTemplate('email', 'job.failed');
        $nt2 = $this->makeNotificationTemplate('slack', 'job.failed');
        $job = $this->makeJobWithTemplate('failed', [$nt1, $nt2]);

        $emailChannel = $this->createMock(ChannelInterface::class);
        $emailChannel->expects($this->once())->method('send');

        $slackChannel = $this->createMock(ChannelInterface::class);
        $slackChannel->expects($this->once())->method('send');

        $dispatcher = new NotificationDispatcher();
        $dispatcher->setChannel('email', $emailChannel);
        $dispatcher->setChannel('slack', $slackChannel);

        $dispatcher->dispatch(NotificationTemplate::EVENT_JOB_FAILED, $job);
    }

    public function testDispatchIsolatesExceptions(): void
    {
        $nt1 = $this->makeNotificationTemplate('email', 'job.failed');
        $nt2 = $this->makeNotificationTemplate('slack', 'job.failed');

        // Give nt2 a different id so both are processed
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $attrs = $ref->getValue($nt2);
        $attrs['id'] = 2;
        $attrs['channel'] = 'slack';
        $ref->setValue($nt2, $attrs);

        $job = $this->makeJobWithTemplate('failed', [$nt1, $nt2]);

        $emailChannel = $this->createMock(ChannelInterface::class);
        $emailChannel->method('send')->willThrowException(new \RuntimeException('Email down'));

        $slackChannel = $this->createMock(ChannelInterface::class);
        $slackChannel->expects($this->once())->method('send');

        $dispatcher = new NotificationDispatcher();
        $dispatcher->setChannel('email', $emailChannel);
        $dispatcher->setChannel('slack', $slackChannel);

        // Should not throw — email failure is isolated
        $dispatcher->dispatch(NotificationTemplate::EVENT_JOB_FAILED, $job);
    }

    public function testDispatchWithNoTemplatesDoesNothing(): void
    {
        $job = $this->makeJobWithTemplate('failed', []);

        $dispatcher = new NotificationDispatcher();
        // Should not throw
        $dispatcher->dispatch(NotificationTemplate::EVENT_JOB_FAILED, $job);
        $this->assertTrue(true);
    }

    public function testDispatchWithNullJobTemplateDoesNothing(): void
    {
        $job = $this->getMockBuilder(Job::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $job->method('attributes')->willReturn(
            ['id', 'job_template_id', 'status', 'exit_code', 'started_at', 'finished_at',
             'launched_by', 'created_at', 'updated_at']
        );
        $relRef = new \ReflectionProperty(BaseActiveRecord::class, '_related');
        $relRef->setAccessible(true);
        $relRef->setValue($job, ['jobTemplate' => null]);

        $dispatcher = new NotificationDispatcher();
        $dispatcher->dispatch(NotificationTemplate::EVENT_JOB_FAILED, $job);
        $this->assertTrue(true);
    }

    public function testMultipleEventsOnSingleTemplate(): void
    {
        $nt = $this->makeNotificationTemplate('email', 'job.failed,job.succeeded');
        $job = $this->makeJobWithTemplate('successful', [$nt]);

        $mockChannel = $this->createMock(ChannelInterface::class);
        $mockChannel->expects($this->once())->method('send');

        $dispatcher = new NotificationDispatcher();
        $dispatcher->setChannel('email', $mockChannel);

        $dispatcher->dispatch(NotificationTemplate::EVENT_JOB_SUCCEEDED, $job);
    }
}
