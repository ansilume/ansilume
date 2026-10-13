<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\RunnerGroupsController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\Runner;
use app\models\RunnerGroup;
use app\services\RunnerGroupDeletionService;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * Integration tests for the Runner Groups API controller.
 *
 * Exercises authentication, authorization, CRUD operations, and delete
 * guards (runners, templates) against a real database (rolled back after
 * each test).
 */
class RunnerGroupsControllerTest extends WebControllerTestCase
{
    private RunnerGroupsController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new RunnerGroupsController('api/v1/runner-groups', \Yii::$app);
    }

    // -- Index ----------------------------------------------------------------

    public function testIndexReturnsPaginatedList(): void
    {
        $this->authenticateWithAdmin();
        $this->createRunnerGroup((int)\Yii::$app->user->id);

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

    public function testViewReturnsGroup(): void
    {
        $this->authenticateWithAdmin();
        $group = $this->createRunnerGroup((int)\Yii::$app->user->id);

        $data = $this->callSuccess($this->ctrl->actionView($group->id));
        /** @var array<string, mixed> $item */
        $item = $data;
        $this->assertSame($group->id, $item['id']);
        $this->assertSame($group->name, $item['name']);
        $this->assertArrayHasKey('description', $item);
        $this->assertArrayHasKey('runner_count', $item);
        $this->assertSame(0, $item['runner_count']);
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
            'name' => 'api-test-group-' . uniqid('', true),
            'description' => 'Created via API test',
        ]);

        $data = $this->callSuccess($this->ctrl->actionCreate());
        $this->assertSame(201, \Yii::$app->response->statusCode);

        /** @var array<string, mixed> $item */
        $item = $data;
        $this->assertArrayHasKey('id', $item);
        $this->assertSame('Created via API test', $item['description']);
        $this->assertSame(0, $item['runner_count']);
    }

    public function testCreateRejects403WithoutPermission(): void
    {
        $this->authenticateAs('no-create-perm');
        $this->setBody([
            'name' => 'forbidden-group',
        ]);

        $this->ctrl->actionCreate();
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    // -- Update ---------------------------------------------------------------

    public function testUpdateWithValidData(): void
    {
        $this->authenticateWithAdmin();
        $group = $this->createRunnerGroup((int)\Yii::$app->user->id);

        $newName = 'updated-group-' . uniqid('', true);
        $this->setBody(['name' => $newName, 'description' => 'Updated']);
        $data = $this->callSuccess($this->ctrl->actionUpdate($group->id));

        /** @var array<string, mixed> $item */
        $item = $data;
        $this->assertSame($group->id, $item['id']);
        $this->assertSame($newName, $item['name']);
        $this->assertSame('Updated', $item['description']);
    }

    // -- Delete ---------------------------------------------------------------

    public function testDeleteReturnsSuccess(): void
    {
        $this->authenticateWithAdmin();
        $group = $this->createRunnerGroup((int)\Yii::$app->user->id);

        $data = $this->callSuccess($this->ctrl->actionDelete($group->id));
        /** @var array<string, mixed> $payload */
        $payload = $data;
        $this->assertTrue($payload['deleted']);

        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionView($group->id);
    }

    public function testDeleteRefusesWhenRunnersExist(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $group = $this->createRunnerGroup($userId);
        $runner = $this->createRunner($group->id, $userId);

        $result = $this->ctrl->actionDelete($group->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(
            ['error' => ['message' => "Cannot delete \"{$group->name}\": 1 runner(s) still belong to this group."]],
            $result
        );
        $this->assertSame(['error' => ['message' => sprintf(RunnerGroupDeletionService::RUNNERS_REFUSED, $group->name, 1)]], $result);
        $this->assertNotNull(RunnerGroup::findOne($group->id));
        $this->assertNotNull(Runner::findOne($runner->id));
        $this->assertFalse($this->deletionAudited((int)$group->id));
    }

    public function testDeleteRefusesWhenTemplatesExist(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $group = $this->createRunnerGroup($userId);
        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $userId);

        $result = $this->ctrl->actionDelete($group->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(
            ['error' => ['message' => "Cannot delete \"{$group->name}\": 1 job template(s) reference this group."]],
            $result
        );
        $this->assertSame(['error' => ['message' => sprintf(RunnerGroupDeletionService::TEMPLATES_REFUSED, $group->name, 1)]], $result);
        $this->assertNotNull(RunnerGroup::findOne($group->id));
        $this->assertSame((int)$group->id, (int)JobTemplate::findOne($template->id)?->runner_group_id);
        $this->assertFalse($this->deletionAudited((int)$group->id));
    }

    /**
     * Deleted templates do not keep a group: they never run again.
     */
    public function testDeleteSucceedsWhenOnlyDeletedTemplatesReferenceTheGroup(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $group = $this->createRunnerGroup($userId);
        $template = $this->createJobTemplate($this->createProject($userId)->id, $this->createInventory($userId)->id, $group->id, $userId);
        $this->assertTrue($template->softDelete());

        $this->assertSame(['data' => ['deleted' => true]], $this->ctrl->actionDelete($group->id));

        $this->assertNull(RunnerGroup::findOne($group->id));
        $this->assertTrue($this->deletionAudited((int)$group->id));
    }

    // -- Helpers --------------------------------------------------------------

    private function deletionAudited(int $groupId): bool
    {
        return AuditLog::find()
            ->where(['action' => AuditLog::ACTION_RUNNER_GROUP_DELETED, 'object_type' => 'runner_group', 'object_id' => $groupId])
            ->exists();
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
