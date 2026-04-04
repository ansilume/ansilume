<?php

declare(strict_types=1);

namespace app\tests\integration\api;

use app\models\NotificationTemplate;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for Notification Templates — model CRUD and validation.
 *
 * Tests the data layer used by both the web controller and API controller.
 */
class NotificationTemplatesApiTest extends DbTestCase
{
    public function testCreateNotificationTemplate(): void
    {
        $user = $this->createUser('api');
        $nt = $this->createNotificationTemplate($user->id);

        $this->assertNotNull($nt->id);
        $this->assertSame(NotificationTemplate::CHANNEL_EMAIL, $nt->channel);
        $this->assertSame('job.failed', $nt->events);
    }

    public function testUpdateNotificationTemplate(): void
    {
        $user = $this->createUser('api');
        $nt = $this->createNotificationTemplate($user->id);

        $nt->name = 'Updated Name';
        $nt->events = 'job.failed,job.succeeded';
        $this->assertTrue($nt->save());

        $nt->refresh();
        $this->assertSame('Updated Name', $nt->name);
        $this->assertSame('job.failed,job.succeeded', $nt->events);
    }

    public function testDeleteNotificationTemplate(): void
    {
        $user = $this->createUser('api');
        $nt = $this->createNotificationTemplate($user->id);
        $id = $nt->id;

        $nt->delete();
        $this->assertNull(NotificationTemplate::findOne($id));
    }

    public function testValidationRequiresName(): void
    {
        $nt = new NotificationTemplate();
        $nt->channel = NotificationTemplate::CHANNEL_EMAIL;
        $nt->events = 'job.failed';
        $nt->created_by = 1;

        $this->assertFalse($nt->validate());
        $this->assertTrue($nt->hasErrors('name'));
    }

    public function testValidationRequiresValidChannel(): void
    {
        $nt = new NotificationTemplate();
        $nt->name = 'Test';
        $nt->channel = 'invalid';
        $nt->events = 'job.failed';
        $nt->created_by = 1;

        $this->assertFalse($nt->validate());
        $this->assertTrue($nt->hasErrors('channel'));
    }

    public function testValidationRequiresEvents(): void
    {
        $nt = new NotificationTemplate();
        $nt->name = 'Test';
        $nt->channel = NotificationTemplate::CHANNEL_EMAIL;
        $nt->created_by = 1;

        $this->assertFalse($nt->validate());
        $this->assertTrue($nt->hasErrors('events'));
    }

    public function testValidationRejectsInvalidConfigJson(): void
    {
        $user = $this->createUser('api');
        $nt = new NotificationTemplate();
        $nt->name = 'Test';
        $nt->channel = NotificationTemplate::CHANNEL_EMAIL;
        $nt->events = 'job.failed';
        $nt->config = 'not-json';
        $nt->created_by = $user->id;

        $this->assertFalse($nt->validate());
        $this->assertTrue($nt->hasErrors('config'));
    }

    public function testValidChannelsAccepted(): void
    {
        $user = $this->createUser('api');

        foreach (['email', 'slack', 'teams', 'webhook'] as $channel) {
            $nt = new NotificationTemplate();
            $nt->name = "Test {$channel}";
            $nt->channel = $channel;
            $nt->events = 'job.failed';
            $nt->created_by = $user->id;
            $nt->created_at = time();
            $nt->updated_at = time();

            $this->assertTrue($nt->save(), "Channel '{$channel}' should be valid");
        }
    }

    public function testJobTemplateRelation(): void
    {
        $user = $this->createUser('api');
        $group = $this->createRunnerGroup($user->id);
        $proj = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $jt = $this->createJobTemplate($proj->id, $inv->id, $group->id, $user->id);
        $nt = $this->createNotificationTemplate($user->id);

        $link = new \app\models\JobTemplateNotification();
        $link->job_template_id = $jt->id;
        $link->notification_template_id = $nt->id;
        $link->save(false);

        $nt->refresh();
        $linked = $nt->jobTemplates;
        $this->assertCount(1, $linked);
        $this->assertSame($jt->id, $linked[0]->id);

        $jt->refresh();
        $notifications = $jt->notificationTemplates;
        $this->assertCount(1, $notifications);
        $this->assertSame($nt->id, $notifications[0]->id);
    }

    public function testCascadeDeleteRemovesPivot(): void
    {
        $user = $this->createUser('api');
        $group = $this->createRunnerGroup($user->id);
        $proj = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $jt = $this->createJobTemplate($proj->id, $inv->id, $group->id, $user->id);
        $nt = $this->createNotificationTemplate($user->id);

        $link = new \app\models\JobTemplateNotification();
        $link->job_template_id = $jt->id;
        $link->notification_template_id = $nt->id;
        $link->save(false);

        $ntId = $nt->id;
        $nt->delete();

        $count = \app\models\JobTemplateNotification::find()
            ->where(['notification_template_id' => $ntId])
            ->count();
        $this->assertSame(0, (int)$count);
    }
}
