<?php

declare(strict_types=1);

namespace app\tests\integration\api;

use app\controllers\api\v1\LdapController;
use app\models\AuditLog;
use app\models\User;
use app\services\ldap\FakeLdapClient;
use app\services\ldap\LdapConfig;
use app\services\ldap\LdapService;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * Integration tests for /api/v1/admin/ldap/test.
 *
 * The endpoint runs through the action method directly so beforeAction's
 * Bearer-token check is skipped — the same approach used by the other API
 * tests that exercise controller logic without HTTP plumbing.
 */
class LdapApiTest extends WebControllerTestCase
{
    /** @var array<string, mixed>|null */
    private ?array $originalLdapParams = null;

    private ?LdapService $originalLdapService = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalLdapParams = is_array(\Yii::$app->params['ldap'] ?? null)
            ? (array)\Yii::$app->params['ldap']
            : null;
        $this->originalLdapService = \Yii::$app->has('ldapService') ? \Yii::$app->get('ldapService') : null;
    }

    protected function tearDown(): void
    {
        if ($this->originalLdapParams !== null) {
            \Yii::$app->params['ldap'] = $this->originalLdapParams;
        } else {
            unset(\Yii::$app->params['ldap']);
        }
        if ($this->originalLdapService !== null) {
            \Yii::$app->set('ldapService', $this->originalLdapService);
        }
        parent::tearDown();
    }

    private function defaultParams(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'host' => 'fake',
            'baseDn' => 'dc=test',
            'attrUsername' => 'uid',
            'attrEmail' => 'mail',
            'attrUid' => 'entryUUID',
        ], $overrides);
    }

    private function configureLdap(array $params, ?FakeLdapClient $client = null): FakeLdapClient
    {
        \Yii::$app->params['ldap'] = $params;
        $svc = new LdapService();
        $client ??= new FakeLdapClient(LdapConfig::fromArray($params));
        $svc->client = $client;
        \Yii::$app->set('ldapService', $svc);
        return $client;
    }

    private function makeAdmin(): User
    {
        $user = $this->createUser('ldap-admin');
        $user->is_superadmin = true;
        $user->save(false);
        return $user;
    }

    private function makeViewer(): User
    {
        return $this->createUser('ldap-viewer');
    }

    private function makeController(): LdapController
    {
        return new LdapController('admin/ldap', \Yii::$app);
    }

    // ── RBAC ──────────────────────────────────────────────────────────────────

    public function testNonAdminGets403(): void
    {
        $this->configureLdap($this->defaultParams());
        $this->loginAs($this->makeViewer());

        $ctrl = $this->makeController();
        $result = $ctrl->actionTest();

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame('Forbidden.', $result['error']['message']);
    }

    // ── connection diagnostic ───────────────────────────────────────────────

    public function testGetReturnsConnectionDiagnostic(): void
    {
        $this->configureLdap($this->defaultParams());
        $this->loginAs($this->makeAdmin());

        $ctrl = $this->makeController();
        $result = $ctrl->actionTest();

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('connection', $result['data']);
        $this->assertTrue($result['data']['connection']['enabled']);
        $this->assertSame('fake', $result['data']['connection']['host']);
        $this->assertSame('dc=test', $result['data']['connection']['base_dn']);
        $this->assertTrue($result['data']['connection']['service_bind']);
        $this->assertNull($result['data']['user'], 'No user lookup requested → user payload is null.');
    }

    public function testReportsBindFailure(): void
    {
        $client = $this->configureLdap($this->defaultParams());
        $client->failServiceBind(true);
        $this->loginAs($this->makeAdmin());

        $ctrl = $this->makeController();
        $result = $ctrl->actionTest();

        $this->assertArrayHasKey('data', $result);
        $this->assertFalse($result['data']['connection']['service_bind']);
        $this->assertNotNull($result['data']['connection']['error']);
    }

    // ── user lookup (POST without password) ──────────────────────────────────

    public function testUserLookupReturnsFoundResult(): void
    {
        $client = $this->configureLdap($this->defaultParams([
            'roleMapping' => ['Admins' => 'admin'],
        ]));
        $client->addUser('jdoe', 'uid=jdoe,dc=test', 'pw', [
            'uid' => 'jdoe',
            'mail' => 'jdoe@example.com',
            'displayName' => 'John Doe',
            'entryUUID' => 'guid-1',
        ]);
        $client->addGroupMembership('uid=jdoe,dc=test', 'Admins');

        $this->loginAs($this->makeAdmin());
        $this->setPost(['username' => 'jdoe']);

        $ctrl = $this->makeController();
        $result = $ctrl->actionTest();

        $this->assertArrayHasKey('user', $result['data']);
        $this->assertTrue($result['data']['user']['found']);
        $this->assertSame('uid=jdoe,dc=test', $result['data']['user']['dn']);
        $this->assertSame('jdoe', $result['data']['user']['username']);
        $this->assertSame('jdoe@example.com', $result['data']['user']['email']);
        $this->assertSame(['Admins'], $result['data']['user']['groups']);
        $this->assertSame(['admin'], $result['data']['user']['mapped_roles']);
    }

    public function testUserLookupReturnsNotFoundResult(): void
    {
        $this->configureLdap($this->defaultParams());
        $this->loginAs($this->makeAdmin());
        $this->setPost(['username' => 'ghost']);

        $ctrl = $this->makeController();
        $result = $ctrl->actionTest();

        $this->assertArrayHasKey('user', $result['data']);
        $this->assertFalse($result['data']['user']['found']);
        $this->assertNotNull($result['data']['user']['error']);
    }

    // ── full authenticate (POST with password) ───────────────────────────────

    public function testAuthenticateSuccessIncludesFullResult(): void
    {
        $client = $this->configureLdap($this->defaultParams());
        $client->addUser('jdoe', 'uid=jdoe,dc=test', 'right', [
            'uid' => 'jdoe',
            'mail' => 'jdoe@example.com',
            'entryUUID' => 'guid-1',
        ]);

        $this->loginAs($this->makeAdmin());
        $this->setPost(['username' => 'jdoe', 'password' => 'right']);

        $ctrl = $this->makeController();
        $result = $ctrl->actionTest();

        $this->assertTrue($result['data']['user']['found']);
        $this->assertSame('jdoe', $result['data']['user']['username']);
    }

    public function testAuthenticateFailureReportsNotFound(): void
    {
        $client = $this->configureLdap($this->defaultParams());
        $client->addUser('jdoe', 'uid=jdoe,dc=test', 'right', [
            'uid' => 'jdoe',
            'mail' => 'jdoe@example.com',
            'entryUUID' => 'guid-1',
        ]);

        $this->loginAs($this->makeAdmin());
        $this->setPost(['username' => 'jdoe', 'password' => 'wrong']);

        $ctrl = $this->makeController();
        $result = $ctrl->actionTest();

        $this->assertFalse($result['data']['user']['found']);
    }

    // ── audit ─────────────────────────────────────────────────────────────────

    public function testEveryInvocationIsAudited(): void
    {
        $this->configureLdap($this->defaultParams());
        $this->loginAs($this->makeAdmin());

        $ctrl = $this->makeController();
        $ctrl->actionTest();

        $entry = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_LDAP_TEST_PERFORMED])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertNotNull($entry);
        $metadata = json_decode((string)$entry->metadata, true);
        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('service_bind', $metadata);
        $this->assertArrayHasKey('password_provided', $metadata);
        $this->assertArrayHasKey('user_found', $metadata);
    }

    public function testAuditNeverContainsPassword(): void
    {
        $this->configureLdap($this->defaultParams());
        $this->loginAs($this->makeAdmin());
        $this->setPost(['username' => 'jdoe', 'password' => 'super-secret-do-not-log']);

        $ctrl = $this->makeController();
        $ctrl->actionTest();

        $entry = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_LDAP_TEST_PERFORMED])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertNotNull($entry);
        $this->assertStringNotContainsString(
            'super-secret-do-not-log',
            (string)$entry->metadata,
            'Audit log must NEVER persist the test password in plaintext.'
        );
    }

    // ── 503 when service is missing ──────────────────────────────────────────

    public function testReturns503WhenLdapServiceUnregistered(): void
    {
        \Yii::$app->set('ldapService', null);
        $this->loginAs($this->makeAdmin());

        $ctrl = $this->makeController();
        $result = $ctrl->actionTest();

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(503, \Yii::$app->response->statusCode);
    }
}
