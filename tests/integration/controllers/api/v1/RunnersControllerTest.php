<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\RunnersController;
use app\models\ApiToken;
use app\models\Job;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * Integration tests for the Runners API controller.
 *
 * Covers list, view, move, delete, regenerate-token, and permission checks
 * against a real database (rolled back after each test).
 */
class RunnersControllerTest extends WebControllerTestCase
{
    private RunnersController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new RunnersController('api/v1/runners', \Yii::$app);
    }

    // -- Index ----------------------------------------------------------------

    public function testIndexReturnsPaginatedList(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $group = $this->createRunnerGroup($userId);
        $this->createRunner($group->id, $userId);

        $result = $this->ctrl->actionIndex();
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertGreaterThanOrEqual(1, $result['meta']['total']);
    }

    public function testIndexFiltersByGroupId(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $group1 = $this->createRunnerGroup($userId);
        $group2 = $this->createRunnerGroup($userId);
        $this->createRunner($group1->id, $userId);
        $this->createRunner($group2->id, $userId);

        \Yii::$app->request->setQueryParams(['group_id' => $group1->id]);
        $result = $this->ctrl->actionIndex();
        $this->assertSame(1, $result['meta']['total']);
    }

    // -- View -----------------------------------------------------------------

    public function testViewReturnsRunner(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $group = $this->createRunnerGroup($userId);
        $runner = $this->createRunner($group->id, $userId);

        $data = $this->callSuccess($this->ctrl->actionView($runner->id));
        /** @var array<string, mixed> $item */
        $item = $data;
        $this->assertSame($runner->id, $item['id']);
        $this->assertSame($runner->name, $item['name']);
        $this->assertSame($group->id, $item['runner_group_id']);
        $this->assertArrayHasKey('runner_group_name', $item);
        $this->assertArrayHasKey('is_online', $item);
        $this->assertArrayHasKey('last_seen_at', $item);
    }

    public function testViewReturns404(): void
    {
        $this->authenticateWithAdmin();
        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionView(999999);
    }

    // -- Move -----------------------------------------------------------------

    public function testMoveRunnerToAnotherGroup(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $group1 = $this->createRunnerGroup($userId);
        $group2 = $this->createRunnerGroup($userId);
        $runner = $this->createRunner($group1->id, $userId);

        $this->setBody(['target_group_id' => $group2->id]);
        $data = $this->callSuccess($this->ctrl->actionMove($runner->id));
        /** @var array<string, mixed> $item */
        $item = $data;
        $this->assertSame($group2->id, $item['runner_group_id']);
    }

    public function testMoveRejects422WhenSameGroup(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $group = $this->createRunnerGroup($userId);
        $runner = $this->createRunner($group->id, $userId);

        $this->setBody(['target_group_id' => $group->id]);
        $this->ctrl->actionMove($runner->id);
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    public function testMoveRejects404WhenTargetGroupNotFound(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $group = $this->createRunnerGroup($userId);
        $runner = $this->createRunner($group->id, $userId);

        $this->setBody(['target_group_id' => 999999]);
        $this->ctrl->actionMove($runner->id);
        $this->assertSame(404, \Yii::$app->response->statusCode);
    }

    public function testMoveRejects422WhenMissingTargetGroupId(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $group = $this->createRunnerGroup($userId);
        $runner = $this->createRunner($group->id, $userId);

        $this->setBody([]);
        $this->ctrl->actionMove($runner->id);
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    public function testMoveRejects422WhenRunnerHasActiveJobs(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $group1 = $this->createRunnerGroup($userId);
        $group2 = $this->createRunnerGroup($userId);
        $runner = $this->createRunner($group1->id, $userId);

        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group1->id, $userId);
        $job = $this->createJob($template->id, $userId, Job::STATUS_RUNNING);
        $job->runner_id = $runner->id;
        $job->save(false);

        $this->setBody(['target_group_id' => $group2->id]);
        $this->ctrl->actionMove($runner->id);
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    public function testMoveRejects403WithoutPermission(): void
    {
        $this->authenticateAs('no-move-perm');
        $this->setBody(['target_group_id' => 1]);
        $this->ctrl->actionMove(1);
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    // -- Delete ---------------------------------------------------------------

    public function testDeleteReturnsSuccess(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $group = $this->createRunnerGroup($userId);
        $runner = $this->createRunner($group->id, $userId);

        $data = $this->callSuccess($this->ctrl->actionDelete($runner->id));
        /** @var array<string, mixed> $payload */
        $payload = $data;
        $this->assertTrue($payload['deleted']);
    }

    public function testDeleteRejects403WithoutPermission(): void
    {
        $this->authenticateAs('no-delete-perm');
        $this->ctrl->actionDelete(1);
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    // -- Regenerate Token -----------------------------------------------------

    public function testRegenerateTokenReturnsNewToken(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $group = $this->createRunnerGroup($userId);
        $runner = $this->createRunner($group->id, $userId);
        $oldHash = $runner->token_hash;

        $data = $this->callSuccess($this->ctrl->actionRegenerateToken($runner->id));
        /** @var array<string, mixed> $payload */
        $payload = $data;
        $this->assertArrayHasKey('token', $payload);
        $this->assertArrayHasKey('runner', $payload);
        $this->assertNotEmpty($payload['token']);

        // Verify hash changed
        $runner->refresh();
        $this->assertNotSame($oldHash, $runner->token_hash);
    }

    public function testRegenerateTokenRejects403WithoutPermission(): void
    {
        $this->authenticateAs('no-regen-perm');
        $this->ctrl->actionRegenerateToken(1);
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * @param array<string, mixed> $result
     */
    private function callSuccess(array $result): mixed
    {
        $this->assertArrayHasKey('data', $result);
        return $result['data'];
    }

    private function authenticateAs(string $label): void
    {
        $user = $this->createUser($label);
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }

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
