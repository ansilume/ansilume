<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Job;
use app\models\JobTemplateNotification;
use app\models\NotificationTemplate;
use app\services\NotificationDispatcher;
use app\services\notification\ChannelInterface;
use app\tests\integration\DbTestCase;

class NotificationDispatcherIntegrationTest extends DbTestCase
{
    private function scaffold(): array
    {
        $user = $this->createUser('dispatch');
        $group = $this->createRunnerGroup($user->id);
        $proj = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $tpl = $this->createJobTemplate($proj->id, $inv->id, $group->id, $user->id);
        $job = $this->createJob($tpl->id, $user->id);

        return [$user, $tpl, $job];
    }

    private function linkNotification(int $jobTemplateId, int $notificationTemplateId): void
    {
        $link = new JobTemplateNotification();
        $link->job_template_id = $jobTemplateId;
        $link->notification_template_id = $notificationTemplateId;
        $link->save(false);
    }

    public function testDispatchSendsToLinkedTemplateOnMatchingEvent(): void
    {
        [$user, $tpl, $job] = $this->scaffold();
        $nt = $this->createNotificationTemplate($user->id, 'email', 'job.failed');
        $this->linkNotification($tpl->id, $nt->id);

        $job->status = Job::STATUS_FAILED;
        $job->save(false);

        $sent = false;
        $mockChannel = $this->createMock(ChannelInterface::class);
        $mockChannel->expects($this->once())->method('send')
            ->willReturnCallback(function () use (&$sent): void {
                $sent = true;
            });

        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');
        $dispatcher->setChannel('email', $mockChannel);

        $dispatcher->dispatch(NotificationTemplate::EVENT_JOB_FAILED, $job);
        $this->assertTrue($sent);
    }

    public function testDispatchSkipsUnlinkedTemplates(): void
    {
        [$user, $tpl, $job] = $this->scaffold();
        // Create but do NOT link
        $this->createNotificationTemplate($user->id, 'email', 'job.failed');

        $job->status = Job::STATUS_FAILED;
        $job->save(false);

        $mockChannel = $this->createMock(ChannelInterface::class);
        $mockChannel->expects($this->never())->method('send');

        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');
        $dispatcher->setChannel('email', $mockChannel);

        $dispatcher->dispatch(NotificationTemplate::EVENT_JOB_FAILED, $job);
    }

    public function testDispatchSkipsNonMatchingEvent(): void
    {
        [$user, $tpl, $job] = $this->scaffold();
        $nt = $this->createNotificationTemplate($user->id, 'email', 'job.succeeded');
        $this->linkNotification($tpl->id, $nt->id);

        $job->status = Job::STATUS_FAILED;
        $job->save(false);

        $mockChannel = $this->createMock(ChannelInterface::class);
        $mockChannel->expects($this->never())->method('send');

        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');
        $dispatcher->setChannel('email', $mockChannel);

        $dispatcher->dispatch(NotificationTemplate::EVENT_JOB_FAILED, $job);
    }

    public function testDispatchSendsToMultipleChannels(): void
    {
        [$user, $tpl, $job] = $this->scaffold();
        $nt1 = $this->createNotificationTemplate($user->id, 'email', 'job.failed');
        $nt2 = $this->createNotificationTemplate($user->id, 'slack', 'job.failed');
        $this->linkNotification($tpl->id, $nt1->id);
        $this->linkNotification($tpl->id, $nt2->id);

        $job->status = Job::STATUS_FAILED;
        $job->save(false);

        $emailChannel = $this->createMock(ChannelInterface::class);
        $emailChannel->expects($this->once())->method('send');

        $slackChannel = $this->createMock(ChannelInterface::class);
        $slackChannel->expects($this->once())->method('send');

        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');
        $dispatcher->setChannel('email', $emailChannel);
        $dispatcher->setChannel('slack', $slackChannel);

        $dispatcher->dispatch(NotificationTemplate::EVENT_JOB_FAILED, $job);
    }

    public function testPivotUniqueConstraint(): void
    {
        [$user, $tpl] = $this->scaffold();
        $nt = $this->createNotificationTemplate($user->id, 'email', 'job.failed');
        $this->linkNotification($tpl->id, $nt->id);

        // Second link with same pair should fail validation
        $link = new JobTemplateNotification();
        $link->job_template_id = $tpl->id;
        $link->notification_template_id = $nt->id;
        $this->assertFalse($link->validate());
        $this->assertTrue($link->hasErrors('notification_template_id'));
    }

    public function testNotificationTemplateCrudIntegration(): void
    {
        $user = $this->createUser('crud');
        $nt = $this->createNotificationTemplate(
            $user->id,
            'slack',
            'job.failed,job.succeeded',
            '{"webhook_url": "https://hooks.slack.com/test"}'
        );

        $this->assertNotNull($nt->id);
        $this->assertSame('slack', $nt->channel);
        $this->assertTrue($nt->listensTo('job.failed'));
        $this->assertTrue($nt->listensTo('job.succeeded'));
        $this->assertFalse($nt->listensTo('job.started'));

        $config = $nt->getParsedConfig();
        $this->assertSame('https://hooks.slack.com/test', $config['webhook_url']);
    }
}
