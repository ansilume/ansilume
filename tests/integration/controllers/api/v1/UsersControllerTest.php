<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\UsersController;
use app\models\ApiToken;
use app\models\User;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * Integration tests for the Users API controller.
 *
 * Exercises authentication, authorization, CRUD operations, password
 * handling, sensitive field redaction, and delete guards against a real
 * database with transactions rolled back after each test.
 */
class UsersControllerTest extends WebControllerTestCase
{
    private UsersController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new UsersController('api/v1/users', \Yii::$app);
    }

    // -- Authorization (403) --------------------------------------------------

    public function testIndexRejects403WithoutPermission(): void
    {
        $this->authenticateAs('no-perm');
        $this->ctrl->actionIndex();
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    // -- Index ----------------------------------------------------------------

    public function testIndexReturnsPaginatedList(): void
    {
        $this->authenticateWithAdmin();

        $result = $this->ctrl->actionIndex();
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('meta', $result);

        /** @var array{total: int, page: int, per_page: int, pages: int} $meta */
        $meta = $result['meta'];
        $this->assertGreaterThanOrEqual(1, $meta['total']);
        $this->assertSame(25, $meta['per_page']);

        /** @var array<int, array<string, mixed>> $data */
        $data = $result['data'];
        $first = $data[0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('username', $first);
        $this->assertArrayHasKey('email', $first);
        $this->assertArrayHasKey('status', $first);
    }

    // -- View -----------------------------------------------------------------

    public function testViewReturnsUser(): void
    {
        $admin = $this->authenticateWithAdmin();

        $data = $this->callSuccess($this->ctrl->actionView((int)$admin->id));
        $this->assertSame(200, \Yii::$app->response->statusCode);

        /** @var array<string, mixed> $user */
        $user = $data;
        $this->assertSame((int)$admin->id, $user['id']);
        $this->assertArrayHasKey('username', $user);
        $this->assertArrayHasKey('email', $user);
        $this->assertArrayHasKey('role', $user);
    }

    public function testViewReturns404(): void
    {
        $this->authenticateWithAdmin();
        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionView(999999);
    }

    public function testViewNeverExposesPasswordHash(): void
    {
        $admin = $this->authenticateWithAdmin();

        $data = $this->callSuccess($this->ctrl->actionView((int)$admin->id));

        /** @var array<string, mixed> $user */
        $user = $data;
        $this->assertArrayNotHasKey('password_hash', $user);
        $this->assertArrayNotHasKey('auth_key', $user);
        $this->assertArrayNotHasKey('totp_secret', $user);
        $this->assertArrayNotHasKey('recovery_codes', $user);
    }

    // -- Create ---------------------------------------------------------------

    public function testCreateWithValidData(): void
    {
        $this->authenticateWithAdmin();
        $this->setBody([
            'username' => 'api-new-user',
            'email' => 'api-new@example.com',
            'password' => 'securepassword',
            'status' => User::STATUS_ACTIVE,
            'role' => 'viewer',
        ]);

        $data = $this->callSuccess($this->ctrl->actionCreate());
        $this->assertSame(201, \Yii::$app->response->statusCode);

        /** @var array<string, mixed> $user */
        $user = $data;
        $this->assertSame('api-new-user', $user['username']);
        $this->assertSame('api-new@example.com', $user['email']);
        $this->assertSame('viewer', $user['role']);
    }

    public function testCreateRejects422WithoutPassword(): void
    {
        $this->authenticateWithAdmin();
        $this->setBody([
            'username' => 'no-pass-user',
            'email' => 'nopass@example.com',
        ]);

        $this->ctrl->actionCreate();
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    public function testCreateRejects422WithShortPassword(): void
    {
        $this->authenticateWithAdmin();
        $this->setBody([
            'username' => 'short-pass-user',
            'email' => 'shortpass@example.com',
            'password' => 'ab',
        ]);

        $this->ctrl->actionCreate();
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    // -- Update ---------------------------------------------------------------

    public function testUpdateWithValidData(): void
    {
        $admin = $this->authenticateWithAdmin();
        $target = $this->createUser('update-target');

        $this->setBody([
            'username' => 'updated-username',
            'email' => 'updated@example.com',
        ]);
        $data = $this->callSuccess($this->ctrl->actionUpdate((int)$target->id));
        $this->assertSame(200, \Yii::$app->response->statusCode);

        /** @var array<string, mixed> $user */
        $user = $data;
        $this->assertSame('updated-username', $user['username']);
        $this->assertSame('updated@example.com', $user['email']);
    }

    public function testUpdateWithPasswordChange(): void
    {
        $admin = $this->authenticateWithAdmin();
        $target = $this->createUser('pw-change');

        $oldHash = $target->password_hash;

        $this->setBody([
            'password' => 'newpassword123',
        ]);
        $this->ctrl->actionUpdate((int)$target->id);
        $this->assertSame(200, \Yii::$app->response->statusCode);

        $target->refresh();
        $this->assertNotSame($oldHash, $target->password_hash);
    }

    // -- Delete ---------------------------------------------------------------

    public function testDeleteReturnsSuccess(): void
    {
        $this->authenticateWithAdmin();
        $target = $this->createUser('delete-target');

        $data = $this->callSuccess($this->ctrl->actionDelete((int)$target->id));

        /** @var array<string, mixed> $payload */
        $payload = $data;
        $this->assertTrue($payload['deleted']);

        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionView((int)$target->id);
    }

    public function testDeleteRejectsSelfDeletion(): void
    {
        $admin = $this->authenticateWithAdmin();

        $this->ctrl->actionDelete((int)$admin->id);
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
    private function authenticateWithAdmin(): User
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

        return $user;
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
