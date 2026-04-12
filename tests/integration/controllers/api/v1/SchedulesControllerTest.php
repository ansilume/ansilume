<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\SchedulesController;
use app\models\ApiToken;
use app\models\Schedule;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * Integration tests for the Schedules API controller.
 *
 * Exercises authentication, authorization, CRUD operations, toggle action,
 * and validation against a real database (rolled back after each test).
 */
class SchedulesControllerTest extends WebControllerTestCase
{
    private SchedulesController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new SchedulesController('api/v1/schedules', \Yii::$app);
    }

    // -- Index ----------------------------------------------------------------

    public function testIndexReturnsPaginatedList(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);
        $group = $this->createRunnerGroup($userId);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $userId);
        $this->createSchedule($template->id, $userId);

        $result = $this->ctrl->actionIndex();
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('meta', $result);
        /** @var array{total: int, page: int, per_page: int, pages: int} $meta */
        $meta = $result['meta'];
        $this->assertArrayHasKey('total', $meta);
        $this->assertArrayHasKey('page', $meta);
        $this->assertArrayHasKey('per_page', $meta);
        $this->assertArrayHasKey('pages', $meta);
        $this->assertGreaterThanOrEqual(1, $meta['total']);
    }

    // -- View -----------------------------------------------------------------

    public function testViewReturnsSchedule(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);
        $group = $this->createRunnerGroup($userId);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $userId);
        $schedule = $this->createSchedule($template->id, $userId);

        $data = $this->callSuccess($this->ctrl->actionView($schedule->id));
        /** @var array<string, mixed> $item */
        $item = $data;
        $this->assertSame($schedule->id, $item['id']);
        $this->assertSame($schedule->name, $item['name']);
        $this->assertSame($template->id, $item['job_template_id']);
        $this->assertArrayHasKey('cron_expression', $item);
        $this->assertArrayHasKey('timezone', $item);
        $this->assertArrayHasKey('enabled', $item);
    }

    public function testViewReturns404(): void
    {
        $this->authenticateWithAdmin();
        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionView(999999);
    }

    // -- Create ---------------------------------------------------------------

    public function testCreateWithValidData(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);
        $group = $this->createRunnerGroup($userId);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $userId);

        $this->setBody([
            'name' => 'api-test-schedule-' . uniqid('', true),
            'job_template_id' => $template->id,
            'cron_expression' => '*/15 * * * *',
            'timezone' => 'UTC',
            'enabled' => true,
        ]);

        $data = $this->callSuccess($this->ctrl->actionCreate());
        $this->assertSame(201, \Yii::$app->response->statusCode);

        /** @var array<string, mixed> $item */
        $item = $data;
        $this->assertArrayHasKey('id', $item);
        $this->assertSame($template->id, $item['job_template_id']);
        $this->assertSame('*/15 * * * *', $item['cron_expression']);
        $this->assertTrue($item['enabled']);
    }

    public function testCreateRejects403WithoutPermission(): void
    {
        $this->authenticateAs('no-launch-perm');
        $this->setBody([
            'name' => 'forbidden-schedule',
            'cron_expression' => '0 * * * *',
        ]);

        $this->ctrl->actionCreate();
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    // -- Update ---------------------------------------------------------------

    public function testUpdateWithValidData(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);
        $group = $this->createRunnerGroup($userId);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $userId);
        $schedule = $this->createSchedule($template->id, $userId);

        $newName = 'updated-schedule-' . uniqid('', true);
        $this->setBody(['name' => $newName, 'cron_expression' => '30 2 * * *']);
        $data = $this->callSuccess($this->ctrl->actionUpdate($schedule->id));

        /** @var array<string, mixed> $item */
        $item = $data;
        $this->assertSame($schedule->id, $item['id']);
        $this->assertSame($newName, $item['name']);
        $this->assertSame('30 2 * * *', $item['cron_expression']);
    }

    // -- Delete ---------------------------------------------------------------

    public function testDeleteReturnsSuccess(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);
        $group = $this->createRunnerGroup($userId);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $userId);
        $schedule = $this->createSchedule($template->id, $userId);

        $data = $this->callSuccess($this->ctrl->actionDelete($schedule->id));
        /** @var array<string, mixed> $payload */
        $payload = $data;
        $this->assertTrue($payload['deleted']);

        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionView($schedule->id);
    }

    // -- Toggle ---------------------------------------------------------------

    public function testToggleChangesEnabled(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);
        $group = $this->createRunnerGroup($userId);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $userId);
        $schedule = $this->createSchedule($template->id, $userId);
        $this->assertTrue((bool)$schedule->enabled);

        $data = $this->callSuccess($this->ctrl->actionToggle($schedule->id));
        /** @var array<string, mixed> $item */
        $item = $data;
        $this->assertFalse($item['enabled']);

        // Toggle back
        \Yii::$app->response->statusCode = 200;
        $data2 = $this->callSuccess($this->ctrl->actionToggle($schedule->id));
        /** @var array<string, mixed> $item2 */
        $item2 = $data2;
        $this->assertTrue($item2['enabled']);
    }

    public function testToggleDisableClearsNextRunAt(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);
        $group = $this->createRunnerGroup($userId);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $userId);
        $schedule = $this->createSchedule($template->id, $userId);

        // Set next_run_at to simulate an active schedule
        $schedule->next_run_at = time() + 3600;
        $schedule->save(false, ['next_run_at']);

        // Disable via toggle
        $data = $this->callSuccess($this->ctrl->actionToggle($schedule->id));
        /** @var array<string, mixed> $item */
        $item = $data;
        $this->assertFalse($item['enabled']);
        $this->assertNull($item['next_run_at'], 'next_run_at must be cleared when schedule is disabled');
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * Create a schedule record directly in the database.
     */
    private function createSchedule(int $templateId, int $createdBy): Schedule
    {
        $schedule = new Schedule();
        $schedule->name = 'test-schedule-' . uniqid('', true);
        $schedule->job_template_id = $templateId;
        $schedule->cron_expression = '0 * * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->created_by = $createdBy;
        $schedule->created_at = time();
        $schedule->updated_at = time();
        $schedule->save(false);
        return $schedule;
    }

    /**
     * Extract the data payload from a success response.
     *
     * @param array<string, mixed> $result
     */
    private function callSuccess(array $result): mixed
    {
        $this->assertArrayHasKey('data', $result);
        return $result['data'];
    }

    /**
     * Create a user with no RBAC role — will fail all permission checks.
     */
    private function authenticateAs(string $label): void
    {
        $user = $this->createUser($label);
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }

    /**
     * Create an admin user with full permissions and authenticate.
     */
    private function authenticateWithAdmin(): void
    {
        $user = $this->createUser('api-admin');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $adminRole = $auth->getRole('admin');
        $this->assertNotNull($adminRole);
        $auth->assign($adminRole, (string)$user->id);

        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'admin-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
    }
}
