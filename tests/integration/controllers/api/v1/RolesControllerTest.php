<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\RolesController;
use app\models\ApiToken;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * Integration tests for the Roles API controller.
 *
 * Exercises authentication, authorization, CRUD operations, system-role
 * protection, and the permission-catalog endpoint against a real auth
 * manager with real database transactions (rolled back after each test).
 */
class RolesControllerTest extends WebControllerTestCase
{
    private RolesController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new RolesController('api/v1/roles', \Yii::$app);
    }

    // -- Authentication -------------------------------------------------------

    public function testIndexRejects401WithoutToken(): void
    {
        $this->expectException(\yii\web\UnauthorizedHttpException::class);
        $this->ctrl->beforeAction(new \yii\base\Action('index', $this->ctrl));
    }

    public function testIndexRejects401WithInvalidToken(): void
    {
        \Yii::$app->request->headers->set('Authorization', 'Bearer bogus-token');
        $this->expectException(\yii\web\UnauthorizedHttpException::class);
        $this->ctrl->beforeAction(new \yii\base\Action('index', $this->ctrl));
    }

    // -- Authorization (403) --------------------------------------------------

    public function testIndexReturns403WithoutPermission(): void
    {
        $this->authenticateAs('viewer-no-role');
        $this->ctrl->actionIndex();
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    public function testCreateReturns403WithoutPermission(): void
    {
        $this->authenticateAs('viewer-no-create');
        $this->ctrl->actionCreate();
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    public function testUpdateReturns403WithoutPermission(): void
    {
        $this->authenticateAs('viewer-no-update');
        $this->ctrl->actionUpdate('viewer');
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    public function testDeleteReturns403WithoutPermission(): void
    {
        $this->authenticateAs('viewer-no-delete');
        $this->ctrl->actionDelete('viewer');
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    public function testPermissionsReturns403WithoutPermission(): void
    {
        $this->authenticateAs('viewer-no-perms');
        $this->ctrl->actionPermissions();
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    // -- Index ----------------------------------------------------------------

    public function testIndexReturnsSystemRoles(): void
    {
        $this->authenticateWithAdmin();
        $data = $this->callSuccess($this->ctrl->actionIndex());
        $this->assertIsArray($data);

        /** @var array<int, array<string, mixed>> $list */
        $list = $data;
        $names = array_column($list, 'name');
        $this->assertContains('viewer', $names);
        $this->assertContains('operator', $names);
        $this->assertContains('admin', $names);
    }

    public function testIndexSummaryShape(): void
    {
        $this->authenticateWithAdmin();
        $data = $this->callSuccess($this->ctrl->actionIndex());
        /** @var array<int, array<string, mixed>> $list */
        $list = $data;
        $first = $list[0];

        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('description', $first);
        $this->assertArrayHasKey('is_system', $first);
        $this->assertArrayHasKey('permission_count', $first);
        $this->assertArrayHasKey('user_count', $first);
    }

    // -- View -----------------------------------------------------------------

    public function testViewReturnsRoleDetail(): void
    {
        $this->authenticateWithAdmin();
        $data = $this->callSuccess($this->ctrl->actionView('admin'));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        /** @var array<string, mixed> $role */
        $role = $data;
        $this->assertSame('admin', $role['name']);
        $this->assertTrue($role['is_system']);
        $this->assertIsArray($role['direct_permissions']);
        $this->assertIsArray($role['effective_permissions']);
        $this->assertIsArray($role['user_ids']);
    }

    public function testViewReturns404ForUnknownRole(): void
    {
        $this->authenticateWithAdmin();
        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionView('nonexistent-role');
    }

    // -- Create ---------------------------------------------------------------

    public function testCreateCustomRole(): void
    {
        $this->authenticateWithAdmin();
        $this->setBody([
            'name' => 'test-api-role',
            'description' => 'Created via API test',
            'permissions' => ['job.view', 'project.view'],
        ]);

        $data = $this->callSuccess($this->ctrl->actionCreate());
        $this->assertSame(201, \Yii::$app->response->statusCode);

        /** @var array<string, mixed> $role */
        $role = $data;
        $this->assertSame('test-api-role', $role['name']);
        $this->assertFalse($role['is_system']);
        /** @var string[] $perms */
        $perms = $role['direct_permissions'];
        $this->assertContains('job.view', $perms);
        $this->assertContains('project.view', $perms);

        $this->cleanupRole('test-api-role');
    }

    public function testCreateRejectsInvalidName(): void
    {
        $this->authenticateWithAdmin();
        $this->setBody(['name' => 'INVALID NAME!', 'permissions' => ['job.view']]);
        $this->ctrl->actionCreate();
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    public function testCreateRejectsDuplicateName(): void
    {
        $this->authenticateWithAdmin();
        $this->setBody(['name' => 'viewer', 'permissions' => ['job.view']]);
        $this->ctrl->actionCreate();
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    public function testCreateRejectsEmptyName(): void
    {
        $this->authenticateWithAdmin();
        $this->setBody(['name' => '', 'permissions' => ['job.view']]);
        $this->ctrl->actionCreate();
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    public function testCreateRejectsReservedName(): void
    {
        $this->authenticateWithAdmin();
        $this->setBody(['name' => 'superadmin', 'permissions' => ['job.view']]);
        $this->ctrl->actionCreate();
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    // -- Update ---------------------------------------------------------------

    public function testUpdateCustomRole(): void
    {
        $this->authenticateWithAdmin();

        // Create
        $this->setBody([
            'name' => 'test-update-role',
            'description' => 'Before',
            'permissions' => ['job.view'],
        ]);
        $this->ctrl->actionCreate();

        // Update
        \Yii::$app->response->statusCode = 200;
        $this->setBody([
            'name' => 'test-update-role',
            'description' => 'After',
            'permissions' => ['job.view', 'project.view'],
        ]);
        $data = $this->callSuccess($this->ctrl->actionUpdate('test-update-role'));
        $this->assertSame(200, \Yii::$app->response->statusCode);

        /** @var array<string, mixed> $role */
        $role = $data;
        $this->assertSame('After', $role['description']);
        /** @var string[] $perms */
        $perms = $role['direct_permissions'];
        $this->assertContains('project.view', $perms);

        $this->cleanupRole('test-update-role');
    }

    public function testUpdateSystemRolePermissions(): void
    {
        $this->authenticateWithAdmin();

        // Get current viewer permissions
        $viewData = $this->callSuccess($this->ctrl->actionView('viewer'));
        /** @var array<string, mixed> $before */
        $before = $viewData;
        /** @var string[] $currentPerms */
        $currentPerms = $before['direct_permissions'];

        // Add analytics.export to viewer
        $newPerms = array_merge($currentPerms, ['analytics.export']);
        \Yii::$app->response->statusCode = 200;
        $this->setBody(['name' => 'viewer', 'permissions' => $newPerms]);
        $updated = $this->callSuccess($this->ctrl->actionUpdate('viewer'));

        /** @var array<string, mixed> $updatedRole */
        $updatedRole = $updated;
        /** @var string[] $updatedPerms */
        $updatedPerms = $updatedRole['direct_permissions'];
        $this->assertContains('analytics.export', $updatedPerms);

        // Restore original
        \Yii::$app->response->statusCode = 200;
        $this->setBody(['name' => 'viewer', 'permissions' => $currentPerms]);
        $this->ctrl->actionUpdate('viewer');
    }

    public function testUpdateReturns404ForUnknownRole(): void
    {
        $this->authenticateWithAdmin();
        $this->setBody(['name' => 'ghost', 'permissions' => ['job.view']]);
        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionUpdate('ghost');
    }

    // -- Delete ---------------------------------------------------------------

    public function testDeleteCustomRole(): void
    {
        $this->authenticateWithAdmin();

        // Create first
        $this->setBody(['name' => 'test-delete-role', 'permissions' => ['job.view']]);
        $this->ctrl->actionCreate();

        // Delete
        \Yii::$app->response->statusCode = 200;
        $data = $this->callSuccess($this->ctrl->actionDelete('test-delete-role'));

        /** @var array<string, mixed> $payload */
        $payload = $data;
        $this->assertTrue($payload['deleted']);

        // Verify gone
        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionView('test-delete-role');
    }

    public function testDeleteRejectsSystemRoleAdmin(): void
    {
        $this->authenticateWithAdmin();
        $this->ctrl->actionDelete('admin');
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    public function testDeleteRejectsSystemRoleViewer(): void
    {
        $this->authenticateWithAdmin();
        $this->ctrl->actionDelete('viewer');
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    public function testDeleteRejectsSystemRoleOperator(): void
    {
        $this->authenticateWithAdmin();
        $this->ctrl->actionDelete('operator');
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    public function testDeleteReturns404ForUnknownRole(): void
    {
        $this->authenticateWithAdmin();
        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionDelete('nonexistent-role');
    }

    // -- Permissions catalog --------------------------------------------------

    public function testPermissionsReturnsGroupedCatalog(): void
    {
        $this->authenticateWithAdmin();
        $data = $this->callSuccess($this->ctrl->actionPermissions());
        $this->assertIsArray($data);
        /** @var array<string, mixed> $groups */
        $groups = $data;
        $this->assertNotEmpty($groups);
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

    private function cleanupRole(string $name): void
    {
        $auth = \Yii::$app->authManager;
        if ($auth === null) {
            return;
        }
        $role = $auth->getRole($name);
        if ($role !== null) {
            $auth->remove($role);
        }
    }
}
