<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\UsersController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\User;
use app\services\UserDeletionService;
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

    public function testUpdateRejects403WithoutPermission(): void
    {
        $target = $this->createUser('update-forbidden');
        $email = $target->email;
        $this->authenticateAs('no-update-perm');
        $this->setBody(['email' => 'forbidden-' . uniqid('', true) . '@example.com']);

        $result = $this->ctrl->actionUpdate((int)$target->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $result);
        $target->refresh();
        $this->assertSame($email, $target->email);
    }

    public function testAnUpdateWithoutARoleKeepsTheRolesAndOneWithARoleReplacesThem(): void
    {
        $this->authenticateWithAdmin();
        $target = $this->createUser('role-update');
        $this->assignRole($target, 'operator');

        $this->setBody(['email' => 'role-update-' . uniqid('', true) . '@example.com']);
        $this->callSuccess($this->ctrl->actionUpdate((int)$target->id));
        $this->assertSame(['operator'], $this->roleNames((int)$target->id));

        $this->setBody(['role' => 'viewer']);
        /** @var array<string, mixed> $user */
        $user = $this->callSuccess($this->ctrl->actionUpdate((int)$target->id));
        $this->assertSame('viewer', $user['role']);
        $this->assertSame(['viewer'], $this->roleNames((int)$target->id));
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

    public function testDeleteRejects403WithoutPermission(): void
    {
        $target = $this->createUser('delete-forbidden');
        $this->authenticateAs('no-delete-perm');

        $result = $this->ctrl->actionDelete((int)$target->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $result);
        $this->assertNotNull(User::findOne($target->id));
    }

    public function testDeleteRejectsSelfDeletion(): void
    {
        $admin = $this->authenticateWithAdmin();

        $this->ctrl->actionDelete((int)$admin->id);
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    /**
     * Regression: a user whom other records still refer to (here: a job
     * they launched) failed on the foreign key with a server error, after
     * all their roles had been revoked, so their schedules and triggers
     * stopped launching.
     */
    public function testDeletingAUserOtherRecordsReferToIs409AndKeepsTheirRoles(): void
    {
        $this->authenticateWithAdmin();
        $target = $this->createUser('referenced');
        $this->assignRole($target, 'operator');
        $owner = (int)$this->createUser('owner')->id;
        $template = $this->createJobTemplate(
            $this->createProject($owner)->id,
            $this->createInventory($owner)->id,
            $this->createRunnerGroup($owner)->id,
            $owner
        );
        $this->createJob($template->id, (int)$target->id);

        $result = $this->ctrl->actionDelete((int)$target->id);

        $this->assertSame(409, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => (new UserDeletionService())->refusalMessage($target)]], $result);
        $this->assertNotNull(User::findOne($target->id));
        $this->assertSame(['operator'], $this->roleNames((int)$target->id));
        $this->assertNull($this->deletionAudit((int)$target->id), 'only deletes are audited');
    }

    /**
     * Regression: as above, for a user whose only reference is a trigger
     * token they generated.
     */
    public function testDeletingTheGeneratorOfATriggerTokenIs409(): void
    {
        $this->authenticateWithAdmin();
        $target = $this->createUser('token-generator');
        $this->assignRole($target, 'operator');
        $workflow = $this->createWorkflowTemplate((int)$this->createUser('owner')->id);
        $workflow->generateTriggerToken((int)$target->id);

        $this->ctrl->actionDelete((int)$target->id);

        $this->assertSame(409, \Yii::$app->response->statusCode);
        $this->assertNotNull(User::findOne($target->id));
        $this->assertSame(['operator'], $this->roleNames((int)$target->id));
    }

    public function testDeletingAnUnreferencedUserRemovesTheirRolesAndIsAudited(): void
    {
        $this->authenticateWithAdmin();
        $target = $this->createUser('free');
        $this->assignRole($target, 'operator');

        $result = $this->ctrl->actionDelete((int)$target->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(['data' => ['deleted' => true]], $result);
        $this->assertNull(User::findOne($target->id));
        $this->assertSame([], $this->roleNames((int)$target->id));
        $audit = $this->deletionAudit((int)$target->id);
        $this->assertNotNull($audit);
        $this->assertSame(['username' => $target->username, 'source' => 'api'], json_decode((string)$audit->metadata, true));
    }

    // -- The only superadmin --------------------------------------------------

    public function testDeletingTheOnlySuperadminIs422AndChangesNothing(): void
    {
        $this->authenticateWithAdmin();
        $target = $this->createUser('only-superadmin');
        $this->assignRole($target, 'admin');
        $this->makeTheOnlySuperadmins($target);

        $result = $this->ctrl->actionDelete((int)$target->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Cannot delete the only superadmin.']], $result);
        $this->assertTrue($this->isSuperadmin((int)$target->id));
        $this->assertSame(['admin'], $this->roleNames((int)$target->id));
        $this->assertNull($this->deletionAudit((int)$target->id));
    }

    public function testASuperadminCanBeDeletedWhileAnotherRemains(): void
    {
        $this->authenticateWithAdmin();
        $target = $this->createUser('superadmin');
        $this->assignRole($target, 'admin');
        $this->makeTheOnlySuperadmins($target, $this->createUser('other-superadmin'));

        $result = $this->ctrl->actionDelete((int)$target->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(['data' => ['deleted' => true]], $result);
        $this->assertNull(User::findOne($target->id));
        $this->assertSame([], $this->roleNames((int)$target->id));
    }

    /**
     * Regression: PUT /api/v1/users/{id} with is_superadmin false took the
     * flag from the only superadmin, which the web UI and DELETE refuse.
     */
    public function testDemotingTheOnlySuperadminIs422AndChangesNothing(): void
    {
        $this->authenticateWithAdmin();
        $target = $this->createUser('only-superadmin');
        $this->makeTheOnlySuperadmins($target);
        $email = $target->email;

        $this->setBody(['is_superadmin' => false, 'email' => 'demoted-' . uniqid('', true) . '@example.com']);
        $result = $this->ctrl->actionUpdate((int)$target->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Cannot demote the only superadmin.']], $result);
        $this->assertTrue($this->isSuperadmin((int)$target->id));
        $target->refresh();
        $this->assertSame($email, $target->email, 'nothing of the request is saved');
        $this->assertNull($this->updateAudit((int)$target->id));
    }

    public function testASuperadminCanBeDemotedWhileAnotherRemains(): void
    {
        $this->authenticateWithAdmin();
        $target = $this->createUser('superadmin');
        $this->makeTheOnlySuperadmins($target, $this->createUser('other-superadmin'));

        $this->setBody(['is_superadmin' => false]);
        $result = $this->ctrl->actionUpdate((int)$target->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        /** @var array<string, mixed> $user */
        $user = $this->callSuccess($result);
        $this->assertFalse($user['is_superadmin']);
        $this->assertFalse($this->isSuperadmin((int)$target->id));
        $this->assertNotNull($this->updateAudit((int)$target->id));
    }

    public function testTheOnlySuperadminCanStillBeUpdatedOtherwise(): void
    {
        $this->authenticateWithAdmin();
        $target = $this->createUser('only-superadmin');
        $this->makeTheOnlySuperadmins($target);
        $email = 'kept-' . uniqid('', true) . '@example.com';

        $this->setBody(['is_superadmin' => true, 'email' => $email]);
        $result = $this->ctrl->actionUpdate((int)$target->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        /** @var array<string, mixed> $user */
        $user = $this->callSuccess($result);
        $this->assertTrue($user['is_superadmin']);
        $this->assertSame($email, $user['email']);
        $this->assertTrue($this->isSuperadmin((int)$target->id));
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

    private function assignRole(User $user, string $roleName): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->getRole($roleName);
        $this->assertNotNull($role, $roleName);
        $auth->assign($role, (string)$user->id);
    }

    /**
     * @return list<string>
     */
    private function roleNames(int $userId): array
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $names = array_keys($auth->getRolesByUser((string)$userId));
        sort($names);
        return $names;
    }

    private function deletionAudit(int $userId): ?AuditLog
    {
        return AuditLog::findOne(['action' => AuditLog::ACTION_USER_DELETED, 'object_type' => 'user', 'object_id' => $userId]);
    }

    private function updateAudit(int $userId): ?AuditLog
    {
        return AuditLog::findOne(['action' => AuditLog::ACTION_USER_UPDATED, 'object_type' => 'user', 'object_id' => $userId]);
    }

    /**
     * Makes $users the only superadmins, inside the test's transaction.
     */
    private function makeTheOnlySuperadmins(User ...$users): void
    {
        User::updateAll(['is_superadmin' => 0], ['is_superadmin' => 1]);
        foreach ($users as $user) {
            $user->is_superadmin = true;
            $user->save(false);
        }
        $this->assertSame(count($users), (int)User::find()->where(['is_superadmin' => 1])->count());
    }

    private function isSuperadmin(int $userId): bool
    {
        return (bool)User::find()->select('is_superadmin')->where(['id' => $userId])->scalar();
    }
}
