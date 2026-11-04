<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eSilentNotificationSeeder;
use app\commands\E2eTeardownHelper;
use app\models\AuditLog;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\WorkflowJob;
use app\services\NotificationDispatcher;
use app\tests\integration\DbTestCase;

/**
 * The fixture of the E2E spec for a test notification of events its
 * channel sends nothing for (E2eSilentNotificationSeeder,
 * tests/e2e/tests/notification-templates/send-test.spec.ts): a PagerDuty
 * template that listens only to the info events of a workflow step, which
 * never trigger an incident. The spec finds it by its name; the test
 * database holds no "e2e-" rows outside a test, and the rollback removes
 * them.
 */
class E2eSilentNotificationSeederTest extends DbTestCase
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
        // An admin, as e2e-admin is on the stack: it may see every event.
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->getRole('admin');
        $this->assertNotNull($role);
        $auth->assign($role, (string)$this->adminId);
        $this->log = [];
    }

    public function testTheTemplateIsAPagerDutyTemplateOfTheInfoEventsOfAStep(): void
    {
        $this->seed();

        $template = $this->template();
        $this->assertSame(NotificationTemplate::CHANNEL_PAGERDUTY, $template->channel);
        $this->assertSame(['routing_key' => E2eSilentNotificationSeeder::ROUTING_KEY], $template->getParsedConfig());
        $this->assertSame(E2eSilentNotificationSeeder::EVENTS, $template->getEventList());
        $this->assertTrue($template->validate(), json_encode($template->errors) ?: '');
        $this->assertSame($this->adminId, (int)$template->created_by);
        $this->assertSame($this->adminId, (int)$template->delivers_as, 'it delivers as e2e-admin, who created it');
        $this->assertSame([
            sprintf("  Restored e2e-silent-notification (ID %d): a PagerDuty template of events PagerDuty sends nothing for.\n", $template->id),
        ], $this->log);
    }

    /**
     * What the spec's Send Test does: nothing is sent, and sendSingle()
     * says why, which the notification.failed audit entry carries too; the
     * events of the E2E workflows give it no delivery.
     */
    public function testItsTestFailsAndItsEventsGiveItNoDelivery(): void
    {
        $this->seed();
        $template = $this->template();
        $dispatcher = \Yii::$app->get('notificationDispatcher');
        $this->assertInstanceOf(NotificationDispatcher::class, $dispatcher);

        $error = $dispatcher->sendSingle($template, ['event' => 'workflow.step_started'], NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED);

        $this->assertSame('The pagerduty channel sends nothing for workflow.step_started.', $error);
        $audit = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_NOTIFICATION_FAILED, 'object_type' => 'notification_template', 'object_id' => $template->id])
            ->one();
        $this->assertNotNull($audit);
        $metadata = json_decode((string)$audit->metadata, true);
        $this->assertIsArray($metadata);
        $this->assertSame($error, $metadata['error']);

        $run = $this->createWorkflowJob((int)$this->createWorkflowTemplate($this->adminId)->id);
        $dispatcher->dispatch(NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED, [NotificationDispatcher::REFS => ['workflow_job_id' => $run]]);
        $this->assertFalse(NotificationDelivery::find()->where(['notification_template_id' => $template->id])->exists());
        $template->events = 'workflow.step_started,workflow.failed';
        $this->assertTrue($template->save(false));
        $dispatcher->dispatch(NotificationTemplate::EVENT_WORKFLOW_FAILED, [NotificationDispatcher::REFS => ['workflow_job_id' => $run]]);
        $this->assertTrue(NotificationDelivery::find()->where(['notification_template_id' => $template->id])->exists(), 'the control: an event PagerDuty sends reaches it');
    }

    public function testSeedingAgainRestoresTheTemplateInPlace(): void
    {
        $this->seed();
        $id = (int)$this->template()->id;
        NotificationTemplate::updateAll([
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'config' => '{"emails": ["ops@example.com"]}',
            'events' => 'job.failed',
        ], ['id' => $id]);

        $this->seed();

        $template = $this->template();
        $this->assertSame($id, (int)$template->id, 'restored in place');
        $this->assertSame(NotificationTemplate::CHANNEL_PAGERDUTY, $template->channel);
        $this->assertSame(E2eSilentNotificationSeeder::EVENTS, $template->getEventList());
        $this->assertSame(1, (int)NotificationTemplate::find()->where(['name' => E2eSilentNotificationSeeder::NAME])->count());
        $this->assertCount(2, $this->log);
    }

    public function testTheTeardownRemovesTheTemplate(): void
    {
        $this->seed();

        $problems = (new E2eTeardownHelper('e2e-', static function (string $msg): void {
        }))->teardownDatabase();

        $this->assertSame([], $problems);
        $this->assertNull(NotificationTemplate::findOne(['name' => E2eSilentNotificationSeeder::NAME]));
    }

    private function seed(): void
    {
        (new E2eSilentNotificationSeeder(function (string $msg): void {
            $this->log[] = $msg;
        }))->seed($this->adminId);
    }

    private function template(): NotificationTemplate
    {
        $template = NotificationTemplate::findOne(['name' => E2eSilentNotificationSeeder::NAME]);
        $this->assertInstanceOf(NotificationTemplate::class, $template);

        return $template;
    }

    /** A running run of $workflowTemplateId, launched by e2e-admin. */
    private function createWorkflowJob(int $workflowTemplateId): int
    {
        $run = new WorkflowJob();
        $run->workflow_template_id = $workflowTemplateId;
        $run->launched_by = $this->adminId;
        $run->status = WorkflowJob::STATUS_RUNNING;
        $run->started_at = time();
        $run->created_at = time();
        $run->updated_at = time();
        $this->assertTrue($run->save(false));

        return (int)$run->id;
    }
}
