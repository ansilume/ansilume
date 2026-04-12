<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\ProjectsController;
use app\models\ApiToken;
use app\models\Project;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * Integration tests for the Projects API controller.
 *
 * Exercises authentication, authorization, CRUD operations, sync action,
 * and delete guards against a real database (rolled back after each test).
 */
class ProjectsControllerTest extends WebControllerTestCase
{
    private ProjectsController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new ProjectsController('api/v1/projects', \Yii::$app);
    }

    // -- Index ----------------------------------------------------------------

    public function testIndexReturnsPaginatedList(): void
    {
        $this->authenticateWithAdmin();
        $user = \Yii::$app->user;
        $this->createProject((int)$user->id);

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

    public function testViewReturnsProject(): void
    {
        $this->authenticateWithAdmin();
        $project = $this->createProject((int)\Yii::$app->user->id);

        $data = $this->callSuccess($this->ctrl->actionView($project->id));
        /** @var array<string, mixed> $item */
        $item = $data;
        $this->assertSame($project->id, $item['id']);
        $this->assertSame($project->name, $item['name']);
        $this->assertArrayHasKey('scm_type', $item);
        $this->assertArrayHasKey('status', $item);
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
        $this->setBody([
            'name' => 'api-test-project-' . uniqid('', true),
            'scm_type' => Project::SCM_TYPE_MANUAL,
        ]);

        $data = $this->callSuccess($this->ctrl->actionCreate());
        $this->assertSame(201, \Yii::$app->response->statusCode);

        /** @var array<string, mixed> $item */
        $item = $data;
        $this->assertArrayHasKey('id', $item);
        $this->assertSame(Project::SCM_TYPE_MANUAL, $item['scm_type']);
        $this->assertSame('new', $item['status']);
    }

    public function testCreateRejects422OnMissingName(): void
    {
        $this->authenticateWithAdmin();
        $this->setBody([
            'scm_type' => Project::SCM_TYPE_MANUAL,
        ]);

        $this->ctrl->actionCreate();
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    public function testCreateRejects403WithoutPermission(): void
    {
        $this->authenticateAs('no-create-perm');
        $this->setBody([
            'name' => 'forbidden-project',
            'scm_type' => Project::SCM_TYPE_MANUAL,
        ]);

        $this->ctrl->actionCreate();
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    // -- Update ---------------------------------------------------------------

    public function testUpdateWithValidData(): void
    {
        $this->authenticateWithAdmin();
        $project = $this->createProject((int)\Yii::$app->user->id);

        $this->setBody(['name' => 'updated-name-' . uniqid('', true)]);
        $data = $this->callSuccess($this->ctrl->actionUpdate($project->id));

        /** @var array<string, mixed> $item */
        $item = $data;
        $this->assertSame($project->id, $item['id']);
        $this->assertStringStartsWith('updated-name-', (string)$item['name']);
    }

    public function testUpdateReturns404(): void
    {
        $this->authenticateWithAdmin();
        $this->setBody(['name' => 'ghost']);
        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionUpdate(999999);
    }

    // -- Delete ---------------------------------------------------------------

    public function testDeleteReturnsSuccess(): void
    {
        $this->authenticateWithAdmin();
        $project = $this->createProject((int)\Yii::$app->user->id);

        $data = $this->callSuccess($this->ctrl->actionDelete($project->id));
        /** @var array<string, mixed> $payload */
        $payload = $data;
        $this->assertTrue($payload['deleted']);

        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionView($project->id);
    }

    public function testDeleteRefusesWhenTemplatesExist(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);
        $group = $this->createRunnerGroup($userId);
        $this->createJobTemplate($project->id, $inventory->id, $group->id, $userId);

        $this->ctrl->actionDelete($project->id);
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    // -- Sync -----------------------------------------------------------------

    public function testSyncRejects422ForManualProject(): void
    {
        $this->authenticateWithAdmin();
        $project = $this->createProject((int)\Yii::$app->user->id);
        // createProject uses SCM_TYPE_MANUAL by default

        $this->ctrl->actionSync($project->id);
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    // -- Helpers --------------------------------------------------------------

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
