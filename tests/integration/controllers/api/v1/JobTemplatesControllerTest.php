<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\JobTemplatesController;
use app\models\ApiToken;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * Integration tests for the Job Templates API controller.
 *
 * Exercises authentication, authorization, CRUD operations, validation,
 * and 404 handling against a real database (rolled back after each test).
 */
class JobTemplatesControllerTest extends WebControllerTestCase
{
    private JobTemplatesController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new JobTemplatesController('api/v1/job-templates', \Yii::$app);
    }

    // -- Index ----------------------------------------------------------------

    public function testIndexReturnsPaginatedList(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);
        $group = $this->createRunnerGroup($userId);
        $this->createJobTemplate($project->id, $inventory->id, $group->id, $userId);

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

    public function testViewReturnsTemplate(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);
        $group = $this->createRunnerGroup($userId);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $userId);

        $data = $this->callSuccess($this->ctrl->actionView($template->id));
        /** @var array<string, mixed> $item */
        $item = $data;
        $this->assertSame($template->id, $item['id']);
        $this->assertSame($template->name, $item['name']);
        $this->assertSame($project->id, $item['project_id']);
        $this->assertSame($inventory->id, $item['inventory_id']);
        $this->assertArrayHasKey('playbook', $item);
        $this->assertArrayHasKey('verbosity', $item);
        $this->assertArrayHasKey('forks', $item);
        $this->assertArrayHasKey('become', $item);
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

        $this->setBody([
            'name' => 'api-test-template-' . uniqid('', true),
            'project_id' => $project->id,
            'inventory_id' => $inventory->id,
            'playbook' => 'deploy.yml',
            'runner_group_id' => $group->id,
        ]);

        $data = $this->callSuccess($this->ctrl->actionCreate());
        $this->assertSame(201, \Yii::$app->response->statusCode);

        /** @var array<string, mixed> $item */
        $item = $data;
        $this->assertArrayHasKey('id', $item);
        $this->assertSame('deploy.yml', $item['playbook']);
        $this->assertSame($project->id, $item['project_id']);
        $this->assertSame($inventory->id, $item['inventory_id']);
    }

    public function testCreateRejects422OnMissingRequiredFields(): void
    {
        $this->authenticateWithAdmin();
        $this->setBody([
            'playbook' => 'deploy.yml',
        ]);

        $this->ctrl->actionCreate();
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    public function testCreateRejects403WithoutPermission(): void
    {
        $this->authenticateAs('no-create-perm');
        $this->setBody([
            'name' => 'forbidden-template',
            'playbook' => 'deploy.yml',
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

        $newName = 'updated-template-' . uniqid('', true);
        $this->setBody(['name' => $newName]);
        $data = $this->callSuccess($this->ctrl->actionUpdate($template->id));

        /** @var array<string, mixed> $item */
        $item = $data;
        $this->assertSame($template->id, $item['id']);
        $this->assertSame($newName, $item['name']);
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

        $data = $this->callSuccess($this->ctrl->actionDelete($template->id));
        /** @var array<string, mixed> $payload */
        $payload = $data;
        $this->assertTrue($payload['deleted']);

        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionView($template->id);
    }

    /**
     * Regression: the API hard-deleted templates while the web UI soft-deletes
     * them, so jobs lost their template link.
     */
    public function testDeleteIsASoftDeleteLikeTheWebUi(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $template = $this->createJobTemplate(
            $this->createProject($userId)->id,
            $this->createInventory($userId)->id,
            $this->createRunnerGroup($userId)->id,
            $userId
        );

        $this->ctrl->actionDelete($template->id);

        $row = \app\models\JobTemplate::findWithDeleted()->where(['id' => $template->id])->one();
        $this->assertNotNull($row, 'the row stays');
        $this->assertNotNull($row->deleted_at);
    }

    // -- Credentials -----------------------------------------------------------

    /**
     * @return array{project: int, inventory: int, group: int}
     */
    private function templateParents(int $userId): array
    {
        return [
            'project' => $this->createProject($userId)->id,
            'inventory' => $this->createInventory($userId)->id,
            'group' => $this->createRunnerGroup($userId)->id,
        ];
    }

    public function testCreateAcceptsAdditionalCredentialsInOrder(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $parents = $this->templateParents($userId);
        $primary = $this->createCredential($userId, \app\models\Credential::TYPE_SSH_KEY);
        $vault = $this->createCredential($userId, \app\models\Credential::TYPE_VAULT);
        $token = $this->createCredential($userId);
        $this->setBody([
            'name' => 'api-creds-' . uniqid('', true),
            'project_id' => $parents['project'],
            'inventory_id' => $parents['inventory'],
            'runner_group_id' => $parents['group'],
            'playbook' => 'site.yml',
            'credential_id' => $primary->id,
            'credential_ids' => [$token->id, $vault->id],
        ]);

        /** @var array<string, mixed> $item */
        $item = $this->callSuccess($this->ctrl->actionCreate());

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame($parents['group'], $item['runner_group_id']);
        $this->assertSame([$token->id, $vault->id], $item['credential_ids']);
        $this->assertSame([$primary->id, $token->id, $vault->id], array_column($item['credentials'], 'id'));
        $this->assertSame(['primary', 'additional', 'additional'], array_column($item['credentials'], 'role'));
    }

    /**
     * Regression: changing credential_id over the API left the old primary
     * attached as an additional credential.
     */
    public function testChangingThePrimaryDetachesTheOldOne(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $parents = $this->templateParents($userId);
        $old = $this->createCredential($userId, \app\models\Credential::TYPE_SSH_KEY);
        $new = $this->createCredential($userId, \app\models\Credential::TYPE_SSH_KEY);
        $template = $this->createJobTemplate($parents['project'], $parents['inventory'], $parents['group'], $userId);
        $this->setBody(['credential_id' => $old->id, 'credential_ids' => []]);
        $this->ctrl->actionUpdate($template->id);

        $this->setBody(['credential_id' => $new->id]);
        /** @var array<string, mixed> $item */
        $item = $this->callSuccess($this->ctrl->actionUpdate($template->id));

        $this->assertSame([$new->id], array_column($item['credentials'], 'id'));
        $this->assertSame([], $item['credential_ids']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidCredentialBodyProvider(): array
    {
        return [
            'credential_ids not a list' => [['credential_ids' => 'abc'], 'credential_ids must be an array of credential IDs.'],
            'credential_ids an object' => [['credential_ids' => ['a' => 1]], 'credential_ids must be an array of credential IDs.'],
            'unknown additional credential' => [['credential_ids' => [999999999]], 'Credential #999999999 does not exist.'],
            // Regression: answered 500 (foreign key violation) instead of 422.
            'unknown primary credential' => [['credential_id' => 999999999], 'The selected credential does not exist.'],
        ];
    }

    /**
     * @dataProvider invalidCredentialBodyProvider
     * @param array<string, mixed> $body
     */
    public function testInvalidCredentialsAre422(array $body, string $message): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $parents = $this->templateParents($userId);
        $template = $this->createJobTemplate($parents['project'], $parents['inventory'], $parents['group'], $userId);
        $this->setBody($body);

        $result = $this->ctrl->actionUpdate($template->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $message]], $result);
    }

    public function testCreateRejectsCredentialIdsThatAreNotAList(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $parents = $this->templateParents($userId);
        $before = \app\models\JobTemplate::find()->count();
        $this->setBody([
            'name' => 'api-bad-credential-ids',
            'project_id' => $parents['project'],
            'inventory_id' => $parents['inventory'],
            'runner_group_id' => $parents['group'],
            'playbook' => 'deploy.yml',
            'credential_ids' => 'abc',
        ]);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'credential_ids must be an array of credential IDs.']], $result);
        $this->assertSame($before, \app\models\JobTemplate::find()->count());
    }

    public function testDeleteReturns404(): void
    {
        $this->authenticateWithAdmin();
        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionDelete(999999);
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
