<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eNotificationDeliveriesSeeder;
use app\commands\E2eTeardownHelper;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowTemplate;
use app\services\NotificationDispatcher;
use app\services\notification\MattermostApiException;
use app\services\notification\MattermostConfig;
use app\services\notification\NotificationDeliveryService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The fixture of the E2E specs of a notification template's page
 * (E2eNotificationDeliveriesSeeder, tests/e2e/tests/notification-templates/
 * deliveries.spec.ts and rbac.spec.ts): a Mattermost template that delivers
 * as an inactive user, so it delivers nothing, with a kept check, a
 * workflow filter and finished deliveries. The test database holds no
 * "e2e-" rows outside a test; the rollback removes them.
 */
class E2eNotificationDeliveriesSeederTest extends DbTestCase
{
    use TeamScopeFixtures;

    private int $adminId;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->createUserWithRole('admin', 'admin');
        $admin->username = 'e2e-admin';
        $admin->save(false);
        $this->adminId = (int)$admin->id;
        $this->log = [];
    }

    public function testTheTemplateDeliversAsAnInactiveUserAndKeepsACheck(): void
    {
        $this->seed();

        $template = $this->template();
        $identity = User::findOne(['username' => E2eNotificationDeliveriesSeeder::IDENTITY]);
        $this->assertInstanceOf(User::class, $identity);
        $this->assertSame(User::STATUS_INACTIVE, (int)$identity->status);
        $this->assertFalse((bool)$identity->is_superadmin);
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $this->assertSame([], $auth->getAssignments((string)$identity->id), 'no role');
        $this->assertSame(NotificationTemplate::CHANNEL_MATTERMOST, $template->channel);
        $this->assertTrue($template->validate(), (string)json_encode($template->errors));
        $this->assertSame([
            'server_url' => E2eNotificationDeliveriesSeeder::SERVER_URL,
            'bot_token' => E2eNotificationDeliveriesSeeder::BOT_TOKEN,
            'team' => E2eNotificationDeliveriesSeeder::TEAM,
            'channel' => E2eNotificationDeliveriesSeeder::CHANNEL,
            'mention' => E2eNotificationDeliveriesSeeder::MENTION,
        ], $template->getParsedConfig());
        $this->assertSame($this->adminId, (int)$template->created_by);
        $this->assertSame((int)$identity->id, (int)$template->delivers_as);
        $this->assertTrue($template->isDeliveringAsInactive());
        $this->assertSame(NotificationTemplate::LEVEL_ESSENTIAL, $template->level);
        $this->assertSame(NotificationTemplate::WORKFLOW_SCOPE_SELECTED, $template->workflow_scope);
        $state = json_decode((string)$template->channel_state, true);
        $this->assertIsArray($state);
        $this->assertSame(MattermostConfig::of($template)->fingerprint(), $state['fingerprint'] ?? null, 'the check is of its config');
        $this->assertSame(E2eNotificationDeliveriesSeeder::CHANNEL_ID, $state['channel_id'] ?? null);
        $this->assertSame(E2eNotificationDeliveriesSeeder::BOT_USERNAME, $state['bot_username'] ?? null);
        $this->assertSame([sprintf(E2eNotificationDeliveriesSeeder::RESTORED, E2eNotificationDeliveriesSeeder::NAME, $template->id, E2eNotificationDeliveriesSeeder::IDENTITY, 2)], $this->log);
    }

    /**
     * With the team scoping fixtures, its filter holds their workflows, and
     * it has a delivery of a run of e2e-alpha-wf and one of a schedule of
     * e2e-alpha-tmpl; every delivery is finished.
     */
    public function testItsFilterAndItsDeliveries(): void
    {
        [$alpha, $beta, $run, $jobTemplate] = $this->scopingFixtures();

        $this->seed();

        $template = $this->template();
        $this->assertSame([(int)$alpha->id, (int)$beta->id], $template->getWorkflowTemplateIds());
        $rows = NotificationDelivery::find()->where(['notification_template_id' => $template->id])->orderBy(['id' => SORT_ASC])->all();
        $this->assertSame([
            ['runner.offline', 'message', 'delivered', 1, null, []],
            ['runner.recovered', 'message', 'failed', 8, sprintf(MattermostApiException::TOKEN_REFUSED, E2eNotificationDeliveriesSeeder::TOKEN_DETAIL), []],
            ['workflow.waiting', 'status', 'delivered', 1, null, ['workflow_job_id' => $run]],
            ['schedule.failed_to_launch', 'message', 'skipped', 0, sprintf(NotificationDeliveryService::INACTIVE_IDENTITY, (int)$template->delivers_as), ['job_template_id' => $jobTemplate]],
        ], array_map(static fn (NotificationDelivery $d): array => [$d->event, $d->kind, $d->status, (int)$d->attempts, $d->last_error, $d->getRefs()], $rows));
        foreach ($rows as $row) {
            $this->assertContains($row->status, NotificationDelivery::FINISHED_STATUSES);
            $this->assertNotNull($row->finished_at);
            $this->assertLessThan((int)$row->finished_at, (int)$row->created_at);
        }
    }

    /**
     * An event the template listens to gives it no delivery: the user it
     * delivers as is inactive, so nothing ever reaches its server.
     */
    public function testItsEventsGiveItNoDelivery(): void
    {
        $this->seed();
        $template = $this->template();
        $before = (int)NotificationDelivery::find()->where(['notification_template_id' => $template->id])->count();
        $dispatcher = \Yii::$app->get('notificationDispatcher');
        $this->assertInstanceOf(NotificationDispatcher::class, $dispatcher);

        $dispatcher->dispatch(NotificationTemplate::EVENT_RUNNER_OFFLINE, ['runner' => ['id' => 1, 'name' => 'e2e-runner']]);

        $this->assertSame($before, (int)NotificationDelivery::find()->where(['notification_template_id' => $template->id])->count());

        // The control: delivering as e2e-admin, the same event reaches it.
        $template->delivers_as = $this->adminId;
        $this->assertTrue($template->save(false));
        $dispatcher->dispatch(NotificationTemplate::EVENT_RUNNER_OFFLINE, ['runner' => ['id' => 1, 'name' => 'e2e-runner']]);
        $this->assertSame($before + 1, (int)NotificationDelivery::find()->where(['notification_template_id' => $template->id])->count());
    }

    public function testSeedingAgainRestoresEverythingInPlace(): void
    {
        $this->scopingFixtures();
        $this->seed();
        $template = $this->template();
        $identity = User::findOne(['username' => E2eNotificationDeliveriesSeeder::IDENTITY]);
        $this->assertInstanceOf(User::class, $identity);
        NotificationTemplate::updateAll(['channel' => NotificationTemplate::CHANNEL_EMAIL, 'config' => '{"emails":["ops@example.com"]}', 'delivers_as' => $this->adminId], ['id' => $template->id]);
        NotificationDelivery::updateAll(['status' => NotificationDelivery::STATUS_PENDING], ['notification_template_id' => $template->id]);
        $identity->status = User::STATUS_ACTIVE;
        $identity->save(false);

        $this->seed();

        $restored = $this->template();
        $this->assertSame((int)$template->id, (int)$restored->id);
        $this->assertSame(NotificationTemplate::CHANNEL_MATTERMOST, $restored->channel);
        $this->assertSame((int)$identity->id, (int)$restored->delivers_as);
        $this->assertTrue($restored->isDeliveringAsInactive());
        $this->assertSame(4, (int)NotificationDelivery::find()->where(['notification_template_id' => $template->id])->count());
        $this->assertFalse(NotificationDelivery::find()->where(['notification_template_id' => $template->id, 'status' => NotificationDelivery::STATUS_PENDING])->exists());
        $this->assertSame(1, (int)User::find()->where(['username' => E2eNotificationDeliveriesSeeder::IDENTITY])->count());
    }

    public function testTheTeardownRemovesTheTemplateItsDeliveriesAndItsUser(): void
    {
        $this->scopingFixtures();
        $this->seed();
        $id = (int)$this->template()->id;

        $problems = (new E2eTeardownHelper('e2e-', static function (string $msg): void {
        }))->teardownDatabase();

        $this->assertSame([], $problems);
        $this->assertNull(NotificationTemplate::findOne(['name' => E2eNotificationDeliveriesSeeder::NAME]));
        $this->assertFalse(NotificationDelivery::find()->where(['notification_template_id' => $id])->exists());
        $this->assertNull(User::findOne(['username' => E2eNotificationDeliveriesSeeder::IDENTITY]));
    }

    private function seed(): void
    {
        (new E2eNotificationDeliveriesSeeder(function (string $msg): void {
            $this->log[] = $msg;
        }))->seed($this->adminId);
    }

    private function template(): NotificationTemplate
    {
        $template = NotificationTemplate::findOne(['name' => E2eNotificationDeliveriesSeeder::NAME]);
        $this->assertInstanceOf(NotificationTemplate::class, $template);

        return $template;
    }

    /**
     * e2e-alpha-wf with a run, e2e-beta-wf and e2e-alpha-tmpl, as the team
     * scoping seeders leave them.
     *
     * @return array{0: WorkflowTemplate, 1: WorkflowTemplate, 2: int, 3: int}
     */
    private function scopingFixtures(): array
    {
        $jobTemplate = $this->createJobTemplate(
            (int)$this->createProject($this->adminId)->id,
            (int)$this->createInventory($this->adminId)->id,
            (int)$this->createRunnerGroup($this->adminId)->id,
            $this->adminId
        );
        $jobTemplate->name = E2eNotificationDeliveriesSeeder::SCHEDULE_TEMPLATE;
        $jobTemplate->save(false);
        $alpha = $this->createWorkflowWithJobSteps($this->adminId, (int)$jobTemplate->id);
        $alpha->name = E2eNotificationDeliveriesSeeder::WORKFLOWS[0];
        $alpha->save(false);
        $beta = $this->createWorkflowTemplate($this->adminId);
        $beta->name = E2eNotificationDeliveriesSeeder::WORKFLOWS[1];
        $beta->save(false);
        $this->createWorkflowJob((int)$alpha->id, $this->adminId);
        $run = (int)$this->createWorkflowJob((int)$alpha->id, $this->adminId)->id;

        return [$alpha, $beta, $run, (int)$jobTemplate->id];
    }
}
