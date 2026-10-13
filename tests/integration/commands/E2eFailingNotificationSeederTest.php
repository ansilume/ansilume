<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eFailingNotificationSeeder;
use app\commands\E2eTeardownHelper;
use app\models\AuditLog;
use app\models\NotificationTemplate;
use app\services\NotificationDispatcher;
use app\tests\integration\DbTestCase;

/**
 * The fixture of the E2E spec for a test notification whose channel fails
 * (E2eFailingNotificationSeeder,
 * tests/e2e/tests/notification-templates/send-test.spec.ts). The spec finds
 * it by its name; the test database holds no "e2e-" rows outside a test,
 * and the rollback removes them.
 */
class E2eFailingNotificationSeederTest extends DbTestCase
{
    private int $adminId;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->createUser('admin');
        $admin->username = 'e2e-admin';
        $admin->save(false);
        $this->adminId = (int)$admin->id;
        $this->log = [];
    }

    public function testTheTemplateIsAWebhookToAClosedPortThatListensToARunnerEventOnly(): void
    {
        $this->seed();

        $template = $this->template();
        $this->assertSame(NotificationTemplate::CHANNEL_WEBHOOK, $template->channel);
        $this->assertSame(['url' => 'http://127.0.0.1:1/e2e-failing-notification'], $template->getParsedConfig());
        $this->assertSame([NotificationTemplate::EVENT_RUNNER_RECOVERED], $template->getEventList());
        foreach (['job.failed', 'job.canceled', 'approval.requested', 'workflow.failed', 'schedule.failed_to_launch'] as $event) {
            $this->assertFalse($template->listensTo($event), "no notification of {$event} goes to it");
        }
        $this->assertTrue($template->validate(), json_encode($template->errors) ?: '');
        $this->assertSame($this->adminId, (int)$template->created_by);
        $this->assertSame([
            sprintf(
                "  Restored e2e-failing-notification (ID %d): a webhook to http://127.0.0.1:1/e2e-failing-notification, which refuses every delivery.\n",
                $template->id
            ),
        ], $this->log);
    }

    /**
     * What the spec's Send Test does: the delivery fails at once, and
     * sendSingle() reports the channel's error, which the
     * notification.failed audit entry carries too.
     */
    public function testEveryDeliveryFailsWithTheChannelsError(): void
    {
        $this->seed();
        $template = $this->template();
        $dispatcher = \Yii::$app->get('notificationDispatcher');
        $this->assertInstanceOf(NotificationDispatcher::class, $dispatcher);
        $started = microtime(true);

        $error = $dispatcher->sendSingle($template, ['event' => 'test'], NotificationTemplate::EVENT_RUNNER_RECOVERED);

        $this->assertSame('WebhookChannel: HTTP 0 — ', $error);
        $this->assertLessThan(5.0, microtime(true) - $started, 'refused at once, no timeout');
        $audit = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_NOTIFICATION_FAILED, 'object_type' => 'notification_template', 'object_id' => $template->id])
            ->one();
        $this->assertNotNull($audit);
        $metadata = json_decode((string)$audit->metadata, true);
        $this->assertIsArray($metadata);
        $this->assertSame($error, $metadata['error']);
    }

    public function testSeedingAgainRestoresTheTemplateInPlace(): void
    {
        $this->seed();
        $id = (int)$this->template()->id;
        NotificationTemplate::updateAll([
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'config' => '{"emails": ["ops@example.com"]}',
            'events' => 'job.failed',
            'description' => 'changed',
        ], ['id' => $id]);

        $this->seed();

        $template = $this->template();
        $this->assertSame($id, (int)$template->id, 'restored in place');
        $this->assertSame(NotificationTemplate::CHANNEL_WEBHOOK, $template->channel);
        $this->assertSame(['url' => E2eFailingNotificationSeeder::URL], $template->getParsedConfig());
        $this->assertSame(E2eFailingNotificationSeeder::EVENT, $template->events);
        $this->assertSame(1, (int)NotificationTemplate::find()->where(['name' => E2eFailingNotificationSeeder::NAME])->count());
        $this->assertCount(2, $this->log);
    }

    public function testTheTeardownRemovesTheTemplate(): void
    {
        $this->seed();

        $problems = (new E2eTeardownHelper('e2e-', static function (string $msg): void {
        }))->teardownDatabase();

        $this->assertSame([], $problems);
        $this->assertNull(NotificationTemplate::findOne(['name' => E2eFailingNotificationSeeder::NAME]));
    }

    private function seed(): void
    {
        (new E2eFailingNotificationSeeder(function (string $msg): void {
            $this->log[] = $msg;
        }))->seed($this->adminId);
    }

    private function template(): NotificationTemplate
    {
        $template = NotificationTemplate::findOne(['name' => E2eFailingNotificationSeeder::NAME]);
        $this->assertInstanceOf(NotificationTemplate::class, $template);

        return $template;
    }
}
