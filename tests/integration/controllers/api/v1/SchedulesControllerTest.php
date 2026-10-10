<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\SchedulesController;
use app\models\ApiToken;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\services\ScheduleService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\db\BaseActiveRecord;

/**
 * Integration tests for the Schedules API controller.
 *
 * Exercises authentication, authorization, CRUD operations, toggle action,
 * and validation against a real database (rolled back after each test).
 */
class SchedulesControllerTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

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

    public function testUpdateRejects403WithoutPermission(): void
    {
        $scope = $this->teamScope();
        $schedule = $this->createSchedule((int)$scope['open']->id, (int)$scope['admin']->id);
        $this->authenticateAs('no-launch-perm');
        $this->setBody(['name' => 'renamed-without-permission']);

        $result = $this->ctrl->actionUpdate($schedule->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $result);
        $schedule->refresh();
        $this->assertNotSame('renamed-without-permission', $schedule->name);
    }

    public function testDeleteRejects403WithoutPermission(): void
    {
        $scope = $this->teamScope();
        $schedule = $this->createSchedule((int)$scope['open']->id, (int)$scope['admin']->id);
        $this->authenticateAs('no-launch-perm');

        $this->ctrl->actionDelete($schedule->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertNotNull(Schedule::findOne($schedule->id));
    }

    public function testUpdateCanClearTheExtraVars(): void
    {
        $scope = $this->apiScope();
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);
        $schedule->extra_vars = '{"env": "prod"}';
        $schedule->save(false);
        $this->setBody(['extra_vars' => null]);

        $this->callSuccess($this->ctrl->actionUpdate($schedule->id));

        $schedule->refresh();
        $this->assertNull($schedule->extra_vars);
    }

    public function testAScheduleThatCannotBeStoredIs422(): void
    {
        $scope = $this->apiScope();
        $this->setBody($this->scheduleBody('not-stored', (int)$scope['own']->id));
        $veto = static function (\yii\base\ModelEvent $event): void {
            $event->isValid = false;
        };
        Event::on(Schedule::class, BaseActiveRecord::EVENT_BEFORE_INSERT, $veto);
        try {
            $result = $this->ctrl->actionCreate();
        } finally {
            Event::off(Schedule::class, BaseActiveRecord::EVENT_BEFORE_INSERT, $veto);
        }

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Failed to save schedule.']], $result);
        $this->assertNull(Schedule::findOne(['name' => 'api-scope-not-stored']));
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

    // -- Team scoping of the job template a schedule points at --------------

    /**
     * Regression: the job template of a request body was checked before it
     * was read on update, and another team's template answered 403 on
     * create, which told the caller that it exists. Templates the caller
     * cannot see are reported like unknown ones now.
     */
    public function testCreateReportsATemplateTheCallerCannotSeeLikeAnUnknownOne(): void
    {
        $scope = $this->apiScope();

        $this->setBody($this->scheduleBody('foreign', (int)$scope['foreign']->id));
        $foreign = $this->ctrl->actionCreate();
        $foreignStatus = \Yii::$app->response->statusCode;
        $this->setBody($this->scheduleBody('unknown', 999999999));
        $unknown = $this->ctrl->actionCreate();

        $expected = ['error' => ['message' => ScheduleService::TEMPLATE_MISSING_MESSAGE]];
        $this->assertSame(422, $foreignStatus);
        $this->assertSame($expected, $foreign);
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame($expected, $unknown);
        $this->assertNull(Schedule::findOne(['name' => 'api-scope-foreign']));
        $this->assertNull(Schedule::findOne(['name' => 'api-scope-unknown']));
    }

    public function testCreateWithADeletedTemplateIs422(): void
    {
        $scope = $this->apiScope();
        $scope['own']->softDelete();
        $this->setBody($this->scheduleBody('deleted', (int)$scope['own']->id));

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => ScheduleService::TEMPLATE_MISSING_MESSAGE]], $result);
    }

    public function testCreateWithATemplateTheTeamOnlyViewsIsForbidden(): void
    {
        $scope = $this->apiScope();
        $this->setBody($this->scheduleBody('viewed', (int)$scope['viewed']->id));

        $result = $this->ctrl->actionCreate();

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $result);
        $this->assertNull(Schedule::findOne(['name' => 'api-scope-viewed']));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function templateTheCallerMayOperateProvider(): array
    {
        return ['the team\'s project' => ['own'], 'an open project' => ['open']];
    }

    /**
     * @dataProvider templateTheCallerMayOperateProvider
     */
    public function testCreateSchedulesATemplateTheCallerMayOperate(string $key): void
    {
        $scope = $this->apiScope();
        $template = $this->scopedTemplate($scope, $key);
        $body = $this->scheduleBody($key, (int)$template->id);
        $body['created_by'] = (int)$scope['admin']->id;
        $this->setBody($body);

        $data = $this->callSuccess($this->ctrl->actionCreate());

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertIsArray($data);
        $stored = Schedule::findOne($data['id']);
        $this->assertNotNull($stored);
        $this->assertSame((int)$template->id, (int)$stored->job_template_id);
        $this->assertSame((int)$scope['member']->id, (int)$stored->created_by, 'the body cannot choose whom the schedule runs as');
    }

    /**
     * Regression: a body without job_template_id reached a method that takes
     * an int and failed with a server error.
     */
    public function testCreateWithoutATemplateIs422(): void
    {
        $this->apiScope();
        $this->setBody(['name' => 'api-scope-no-template', 'cron_expression' => '0 * * * *']);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $label = (new Schedule())->getAttributeLabel('job_template_id');
        $this->assertSame(['error' => ['message' => $label . ' cannot be blank.']], $result);
    }

    /**
     * Regression: the next run was computed from the missing cron expression
     * before validation, and CronExpression takes a string, so a body
     * without cron_expression failed with a TypeError (500) instead of 422.
     */
    public function testCreateWithoutACronExpressionIs422(): void
    {
        $scope = $this->apiScope();
        $body = $this->scheduleBody('no-cron', (int)$scope['own']->id);
        unset($body['cron_expression']);
        $this->setBody($body);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $label = (new Schedule())->getAttributeLabel('cron_expression');
        $this->assertSame(['error' => ['message' => $label . ' cannot be blank.']], $result);
        $this->assertNull(Schedule::findOne(['name' => 'api-scope-no-cron']));
    }

    /**
     * Regression: as above, for a body with neither a job template nor a
     * cron expression.
     */
    public function testCreateWithoutATemplateAndACronExpressionIs422(): void
    {
        $this->apiScope();
        $this->setBody(['name' => 'api-scope-nothing', 'timezone' => 'UTC']);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $label = (new Schedule())->getAttributeLabel('job_template_id');
        $this->assertSame(['error' => ['message' => $label . ' cannot be blank.']], $result);
        $this->assertNull(Schedule::findOne(['name' => 'api-scope-nothing']));
    }

    /**
     * Regression: next_run_at was computed after the save, so it was in the
     * response but never stored.
     */
    public function testCreateStoresTheNextRun(): void
    {
        $scope = $this->apiScope();
        $this->setBody($this->scheduleBody('next-run', (int)$scope['own']->id));

        $data = $this->callSuccess($this->ctrl->actionCreate());

        $this->assertIsArray($data);
        $stored = Schedule::findOne($data['id']);
        $this->assertNotNull($stored);
        $this->assertNotNull($stored->next_run_at);
        $this->assertSame($data['next_run_at'], $stored->next_run_at);
    }

    /**
     * Regression: update checked the stored template, then applied the body,
     * so a schedule could be moved to a template of a project the caller's
     * team only views.
     */
    public function testUpdateCannotSwitchToATemplateTheTeamOnlyViews(): void
    {
        $scope = $this->apiScope();
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);
        $this->setBody(['job_template_id' => (int)$scope['viewed']->id]);

        $result = $this->ctrl->actionUpdate($schedule->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $result);
        $this->assertSame((int)$scope['own']->id, $this->storedTemplateId($schedule));
    }

    /**
     * Regression: as above, with another team's template, which also told
     * the caller that the template exists.
     */
    public function testUpdateCannotSwitchToATemplateTheCallerCannotSee(): void
    {
        $scope = $this->apiScope();
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);
        $this->setBody(['job_template_id' => (int)$scope['foreign']->id]);

        $result = $this->ctrl->actionUpdate($schedule->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => ScheduleService::TEMPLATE_MISSING_MESSAGE]], $result);
        $this->assertSame((int)$scope['own']->id, $this->storedTemplateId($schedule));
    }

    public function testUpdateSwitchesToAnOpenTemplateAndKeepsTheCreator(): void
    {
        $scope = $this->apiScope();
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['outsider']->id);
        $this->setBody(['job_template_id' => (int)$scope['open']->id, 'created_by' => (int)$scope['member']->id]);

        $data = $this->callSuccess($this->ctrl->actionUpdate($schedule->id));

        $this->assertIsArray($data);
        $this->assertSame((int)$scope['open']->id, $data['job_template_id']);
        $schedule->refresh();
        $this->assertSame((int)$scope['open']->id, (int)$schedule->job_template_id);
        $this->assertSame((int)$scope['outsider']->id, (int)$schedule->created_by);
    }

    /**
     * Regression: next_run_at was computed after the save, so a changed cron
     * expression kept the old next run.
     */
    public function testUpdateStoresTheNextRunOfTheNewCronExpression(): void
    {
        $scope = $this->apiScope();
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);
        $schedule->next_run_at = 1;
        $schedule->save(false);
        $this->setBody(['cron_expression' => '30 2 * * *']);

        $data = $this->callSuccess($this->ctrl->actionUpdate($schedule->id));

        $this->assertIsArray($data);
        $schedule->refresh();
        $this->assertGreaterThan(time(), (int)$schedule->next_run_at);
        $this->assertSame($data['next_run_at'], $schedule->next_run_at);
    }

    public function testAnAdminMaySwitchAScheduleToAnyTemplate(): void
    {
        $scope = $this->teamScope();
        $this->authenticateUser($scope['admin']);
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);
        $this->setBody(['job_template_id' => (int)$scope['foreign']->id]);

        $this->callSuccess($this->ctrl->actionUpdate($schedule->id));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame((int)$scope['foreign']->id, $this->storedTemplateId($schedule));
    }

    /**
     * Regression: the access checks read the project through the template
     * relation, which skips soft-deleted templates, so the schedule of
     * another team's deleted template counted as global.
     *
     * @return array<string, array{0: string}>
     */
    public static function pathActionProvider(): array
    {
        return ['view' => ['view'], 'update' => ['update'], 'toggle' => ['toggle'], 'delete' => ['delete']];
    }

    /**
     * @dataProvider pathActionProvider
     */
    public function testAScheduleOfAnotherTeamsDeletedTemplateStaysForbidden(string $action): void
    {
        $scope = $this->apiScope();
        $schedule = $this->createSchedule((int)$scope['foreign']->id, (int)$scope['outsider']->id);
        $scope['foreign']->softDelete();
        $this->setBody(['job_template_id' => (int)$scope['own']->id]);

        $result = match ($action) {
            'view' => $this->ctrl->actionView($schedule->id),
            'update' => $this->ctrl->actionUpdate($schedule->id),
            'toggle' => $this->ctrl->actionToggle($schedule->id),
            default => $this->ctrl->actionDelete($schedule->id),
        };

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $result);
        $stored = Schedule::findOne($schedule->id);
        $this->assertNotNull($stored);
        $this->assertSame((int)$scope['foreign']->id, (int)$stored->job_template_id);
        $this->assertTrue((bool)$stored->enabled);
    }

    // -- Whom a schedule runs as ----------------------------------------------

    /**
     * Regression: schedules launch as their creator, but no response said
     * who that is, so API-only operators could not tell why a schedule was
     * refused.
     */
    public function testEveryResponseSaysWhomTheScheduleRunsAs(): void
    {
        $scope = $this->apiScope();
        $memberId = (int)$scope['member']->id;

        $this->setBody($this->scheduleBody('runs-as', (int)$scope['own']->id));
        $created = $this->callSuccess($this->ctrl->actionCreate());
        $this->assertIsArray($created);
        $this->assertSame($memberId, $created['created_by']);
        $id = (int)$created['id'];

        $viewed = $this->callSuccess($this->ctrl->actionView($id));
        $this->assertIsArray($viewed);
        $this->assertSame($memberId, $viewed['created_by']);

        $toggled = $this->callSuccess($this->ctrl->actionToggle($id));
        $this->assertIsArray($toggled);
        $this->assertSame($memberId, $toggled['created_by']);

        $listed = array_column($this->ctrl->actionIndex()['data'], 'created_by', 'id');
        $this->assertSame($memberId, $listed[$id] ?? null);
    }

    public function testAnUpdateBySomeoneElseStillReportsTheCreator(): void
    {
        $scope = $this->teamScope();
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);
        $this->authenticateUser($scope['admin']);
        $this->setBody(['name' => 'renamed-by-admin']);

        $updated = $this->callSuccess($this->ctrl->actionUpdate($schedule->id));

        $this->assertIsArray($updated);
        $this->assertSame('renamed-by-admin', $updated['name']);
        $this->assertSame((int)$scope['member']->id, $updated['created_by']);
    }

    public function testIndexCountsOnlyVisibleSchedules(): void
    {
        $scope = $this->apiScope();
        $created = [];
        foreach (['own', 'viewed', 'foreign', 'open'] as $key) {
            $template = $this->scopedTemplate($scope, $key);
            $created[$key] = (int)$this->createSchedule((int)$template->id, (int)$scope['admin']->id)->id;
        }

        $result = $this->ctrl->actionIndex();

        $ids = array_map('intval', array_column($result['data'], 'id'));
        $this->assertNotContains($created['foreign'], $ids);
        unset($created['foreign']);
        $this->assertSame([], array_values(array_diff($created, $ids)));
        $this->assertSame(
            (int)Schedule::find()->where(['in', 'job_template_id', $this->visibleTemplateIds($scope)])->count(),
            $result['meta']['total']
        );
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

    /**
     * teamScope() with the member (RBAC operator) authenticated.
     *
     * @return array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate}
     */
    private function apiScope(): array
    {
        $scope = $this->teamScope();
        $this->authenticateUser($scope['member']);
        return $scope;
    }

    private function authenticateUser(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'scope-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function scopedTemplate(array $scope, string $key): JobTemplate
    {
        $template = $scope[$key] ?? null;
        $this->assertInstanceOf(JobTemplate::class, $template);
        return $template;
    }

    /**
     * IDs of the job templates the member may see: all but another team's.
     *
     * @param array<string, mixed> $scope
     * @return list<int>
     */
    private function visibleTemplateIds(array $scope): array
    {
        $query = JobTemplate::findWithDeleted()->select('id');
        $filter = \Yii::$app->get('projectAccessChecker')->buildChildResourceFilter((int)$scope['member']->id, 'project_id');
        if ($filter !== null) {
            $query->andWhere($filter);
        }
        return array_map('intval', $query->column());
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleBody(string $suffix, int $templateId): array
    {
        return [
            'name' => 'api-scope-' . $suffix,
            'job_template_id' => $templateId,
            'cron_expression' => '0 3 * * *',
            'timezone' => 'UTC',
            'enabled' => true,
        ];
    }

    private function storedTemplateId(Schedule $schedule): int
    {
        $stored = Schedule::findOne($schedule->id);
        $this->assertNotNull($stored);
        return (int)$stored->job_template_id;
    }
}
