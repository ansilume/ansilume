<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\AuditLogsController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\User;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * Integration tests for the Audit Logs API controller.
 *
 * Exercises authentication, authorization, filtering, and metadata
 * decoding against a real database with transactions rolled back
 * after each test.
 */
class AuditLogsControllerTest extends WebControllerTestCase
{
    private AuditLogsController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new AuditLogsController('api/v1/audit-logs', \Yii::$app);
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
        $admin = $this->authenticateWithAdmin();
        $this->insertAuditLog((int)$admin->id, 'test.index', 'test');
        $this->insertAuditLog((int)$admin->id, 'test.index', 'test');

        $result = $this->ctrl->actionIndex();
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('meta', $result);

        /** @var array{total: int, page: int, per_page: int, pages: int} $meta */
        $meta = $result['meta'];
        $this->assertGreaterThanOrEqual(2, $meta['total']);
        $this->assertSame(25, $meta['per_page']);

        /** @var array<int, array<string, mixed>> $data */
        $data = $result['data'];
        $first = $data[0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('action', $first);
        $this->assertArrayHasKey('user_id', $first);
        $this->assertArrayHasKey('object_type', $first);
    }

    // -- Index filters --------------------------------------------------------

    public function testIndexFiltersByAction(): void
    {
        $admin = $this->authenticateWithAdmin();
        $this->insertAuditLog((int)$admin->id, 'filter.by.action', 'test');
        $this->insertAuditLog((int)$admin->id, 'other.action', 'test');

        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setQueryParams(['action' => 'filter.by.action']);

        $result = $this->ctrl->actionIndex();

        /** @var array<int, array<string, mixed>> $data */
        $data = $result['data'];
        foreach ($data as $entry) {
            $this->assertSame('filter.by.action', $entry['action']);
        }
    }

    public function testIndexFiltersByUserId(): void
    {
        $admin = $this->authenticateWithAdmin();
        $other = $this->createUser('other-user');
        $this->insertAuditLog((int)$other->id, 'user.filter.test', 'test');
        $this->insertAuditLog((int)$admin->id, 'admin.action', 'test');

        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setQueryParams(['user_id' => (string)$other->id]);

        $result = $this->ctrl->actionIndex();

        /** @var array<int, array<string, mixed>> $data */
        $data = $result['data'];
        foreach ($data as $entry) {
            $this->assertSame((int)$other->id, $entry['user_id']);
        }
    }

    public function testIndexFiltersByObjectType(): void
    {
        $admin = $this->authenticateWithAdmin();
        $this->insertAuditLog((int)$admin->id, 'type.filter', 'credential');
        $this->insertAuditLog((int)$admin->id, 'type.filter', 'project');

        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setQueryParams(['object_type' => 'credential']);

        $result = $this->ctrl->actionIndex();

        /** @var array<int, array<string, mixed>> $data */
        $data = $result['data'];
        foreach ($data as $entry) {
            $this->assertSame('credential', $entry['object_type']);
        }
    }

    // -- View -----------------------------------------------------------------

    public function testViewReturnsAuditLog(): void
    {
        $admin = $this->authenticateWithAdmin();
        $log = $this->insertAuditLog((int)$admin->id, 'test.view', 'test');

        $data = $this->callSuccess($this->ctrl->actionView((int)$log->id));
        $this->assertSame(200, \Yii::$app->response->statusCode);

        /** @var array<string, mixed> $entry */
        $entry = $data;
        $this->assertSame((int)$log->id, $entry['id']);
        $this->assertSame('test.view', $entry['action']);
        $this->assertSame('test', $entry['object_type']);
    }

    public function testViewReturns404(): void
    {
        $this->authenticateWithAdmin();
        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionView(999999);
    }

    public function testViewDecodesMetadataAsJson(): void
    {
        $admin = $this->authenticateWithAdmin();
        $log = $this->insertAuditLog(
            (int)$admin->id,
            'test.metadata',
            'test',
            json_encode(['key' => 'value', 'nested' => ['a' => 1]])
        );

        $data = $this->callSuccess($this->ctrl->actionView((int)$log->id));

        /** @var array<string, mixed> $entry */
        $entry = $data;
        $this->assertIsArray($entry['metadata']);

        /** @var array<string, mixed> $metadata */
        $metadata = $entry['metadata'];
        $this->assertSame('value', $metadata['key']);
        $this->assertIsArray($metadata['nested']);

        /** @var array<string, int> $nested */
        $nested = $metadata['nested'];
        $this->assertSame(1, $nested['a']);
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * Insert an audit log entry directly into the database.
     */
    private function insertAuditLog(
        int $userId,
        string $action,
        string $objectType,
        ?string $metadata = null
    ): AuditLog {
        $log = new AuditLog();
        $log->action = $action;
        $log->user_id = $userId;
        $log->object_type = $objectType;
        $log->object_id = 1;
        $log->metadata = $metadata ?? json_encode(['key' => 'value']);
        $log->ip_address = '127.0.0.1';
        $log->created_at = time();
        $log->save(false);
        return $log;
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
