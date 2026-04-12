<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\WebhooksController;
use app\models\ApiToken;
use app\models\User;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * Integration tests for the Webhooks API controller.
 *
 * Exercises authentication, authorization, CRUD operations, and events
 * array handling against a real database with transactions rolled back
 * after each test.
 */
class WebhooksControllerTest extends WebControllerTestCase
{
    private WebhooksController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new WebhooksController('api/v1/webhooks', \Yii::$app);
    }

    // -- Authorization (403) --------------------------------------------------

    public function testIndexRejects403WithoutPermission(): void
    {
        $this->authenticateAs('no-admin');
        $this->ctrl->actionIndex();
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    // -- Index ----------------------------------------------------------------

    public function testIndexReturnsPaginatedList(): void
    {
        $admin = $this->authenticateWithAdmin();
        $this->createWebhook((int)$admin->id);
        $this->createWebhook((int)$admin->id);

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
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('url', $first);
        $this->assertArrayHasKey('events', $first);
        $this->assertArrayHasKey('enabled', $first);
    }

    // -- View -----------------------------------------------------------------

    public function testViewReturnsWebhook(): void
    {
        $admin = $this->authenticateWithAdmin();
        $webhook = $this->createWebhook((int)$admin->id);

        $data = $this->callSuccess($this->ctrl->actionView((int)$webhook->id));
        $this->assertSame(200, \Yii::$app->response->statusCode);

        /** @var array<string, mixed> $wh */
        $wh = $data;
        $this->assertSame((int)$webhook->id, $wh['id']);
        $this->assertArrayHasKey('name', $wh);
        $this->assertArrayHasKey('url', $wh);
        $this->assertArrayHasKey('events', $wh);
        $this->assertArrayHasKey('enabled', $wh);
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
            'name' => 'api-test-webhook',
            'url' => 'https://example.com/hook',
            'events' => 'job.success,job.failure',
            'enabled' => true,
        ]);

        $data = $this->callSuccess($this->ctrl->actionCreate());
        $this->assertSame(201, \Yii::$app->response->statusCode);

        /** @var array<string, mixed> $wh */
        $wh = $data;
        $this->assertSame('api-test-webhook', $wh['name']);
        $this->assertSame('https://example.com/hook', $wh['url']);
        $this->assertSame('job.success,job.failure', $wh['events']);
        $this->assertTrue($wh['enabled']);
    }

    public function testCreateWithEventsAsArray(): void
    {
        $this->authenticateWithAdmin();
        $this->setBody([
            'name' => 'array-events-webhook',
            'url' => 'https://example.com/hook2',
            'events' => ['job.success', 'job.failure'],
            'enabled' => true,
        ]);

        $data = $this->callSuccess($this->ctrl->actionCreate());
        $this->assertSame(201, \Yii::$app->response->statusCode);

        /** @var array<string, mixed> $wh */
        $wh = $data;
        $this->assertSame('job.success,job.failure', $wh['events']);
    }

    // -- Update ---------------------------------------------------------------

    public function testUpdateWithValidData(): void
    {
        $admin = $this->authenticateWithAdmin();
        $webhook = $this->createWebhook((int)$admin->id);

        $this->setBody([
            'name' => 'updated-webhook',
            'url' => 'https://example.com/updated',
            'enabled' => false,
        ]);
        $data = $this->callSuccess($this->ctrl->actionUpdate((int)$webhook->id));
        $this->assertSame(200, \Yii::$app->response->statusCode);

        /** @var array<string, mixed> $wh */
        $wh = $data;
        $this->assertSame('updated-webhook', $wh['name']);
        $this->assertSame('https://example.com/updated', $wh['url']);
        $this->assertFalse($wh['enabled']);
    }

    // -- Delete ---------------------------------------------------------------

    public function testDeleteReturnsSuccess(): void
    {
        $admin = $this->authenticateWithAdmin();
        $webhook = $this->createWebhook((int)$admin->id);

        $data = $this->callSuccess($this->ctrl->actionDelete((int)$webhook->id));

        /** @var array<string, mixed> $payload */
        $payload = $data;
        $this->assertTrue($payload['deleted']);

        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionView((int)$webhook->id);
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
