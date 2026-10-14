<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\LdapController;
use app\models\AuditLog;
use app\models\User;
use app\services\ldap\FakeLdapClient;
use app\services\ldap\LdapConfig;
use app\services\ldap\LdapService;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * What web/openapi.yaml says about the user lookup of
 * POST /api/v1/admin/ldap/test (the description of the operation, and
 * found and error of LdapDiagnosticResult.user) is what LdapController
 * answers through LdapService: without a password, "User not found in
 * directory." only for a user the directory does not have, and the failure
 * of the service bind, the user search or the group search otherwise; with
 * a password, the answers of a sign-in.
 *
 * Regression (wave 5f of 2.11.0): the spec said nothing of what found and
 * error mean, and the API answered a failed user search as a user the
 * directory does not have, and a failed group search as a user in no
 * group. Fails on the spec from before.
 */
class OpenApiLdapContractTest extends WebControllerTestCase
{
    use OpenApiSchemaAssertions;
    use OpenApiWorkflowAssertions;

    private const PATH = '/api/v1/admin/ldap/test';

    /** The answer of authenticate() to a wrong password. */
    private const WRONG_PASSWORD = 'User bind failed (invalid credentials).';

    /** @var array<string, mixed>|null */
    private ?array $originalLdapParams = null;

    private ?LdapService $originalLdapService = null;

    protected function setUp(): void
    {
        parent::setUp();
        $params = \Yii::$app->params['ldap'] ?? null;
        $this->originalLdapParams = is_array($params) ? $params : null;
        $service = \Yii::$app->has('ldapService') ? \Yii::$app->get('ldapService') : null;
        $this->originalLdapService = $service instanceof LdapService ? $service : null;
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

    /**
     * regression: the spec said nothing of what found and error mean. They
     * say what the API answers, which these lookups show.
     */
    public function testRegressionTheSpecSaysWhatFoundAndErrorMeanAsTheApiAnswersThem(): void
    {
        $this->assertSame(
            'True when the directory has the user and, with a `password`, the password is right. False otherwise, with `error` saying why, such as the error of a failed service bind. Without a `password`, `'
                . LdapService::USER_NOT_FOUND . '` means that the user search ran and found no entry; a user search or a group search that fails answers that failure instead, such as `User search failed: No such object` or `Group search failed: Operations error`. With a `password`, a wrong one answers `'
                . self::WRONG_PASSWORD . '`, a user search that finds no entry or fails answers `' . LdapService::USER_NOT_FOUND . '`, and a failed group search answers the user as found, without groups.',
            $this->specText(['components', 'schemas', 'LdapDiagnosticResult', 'properties', 'user', 'properties', 'found', 'description'])
        );
        $this->assertSame(
            'Set when found=false: why the user was not found, or why the lookup failed.',
            $this->specText(['components', 'schemas', 'LdapDiagnosticResult', 'properties', 'user', 'properties', 'error', 'description'])
        );
        $this->assertStringContainsString(
            'Without a `password`, a lookup that fails, whether the service bind, the user search or the group search, answers `found: false` with that failure as `error`; `'
                . LdapService::USER_NOT_FOUND . '` means that the directory has no such user.',
            $this->specText(['paths', self::PATH, 'post', 'description'])
        );

        $this->assertSame(['found' => false, 'error' => LdapService::USER_NOT_FOUND], $this->lookup(['username' => 'ghost']), 'A user the directory does not have.');
        $this->assertSame(['found' => false, 'error' => FakeLdapClient::SERVICE_BIND_FAILURE], $this->lookup(['username' => 'jdoe'], 'failServiceBind'), 'A failed service bind.');
        $this->assertSame(['found' => false, 'error' => FakeLdapClient::SEARCH_FAILURE], $this->lookup(['username' => 'jdoe'], 'failSearch'), 'A failed user search.');
        $this->assertSame(['found' => false, 'error' => FakeLdapClient::GROUP_SEARCH_FAILURE], $this->lookup(['username' => 'jdoe'], 'failGroupSearch'), 'A failed group search.');
        $this->assertFalse($this->lastTestEntry()['user_found'] ?? null, 'The audit entry records user_found: false.');

        $this->assertSame(['found' => false, 'error' => self::WRONG_PASSWORD], $this->lookup(['username' => 'jdoe', 'password' => 'wrong']), 'A wrong password.');
        $this->assertSame(['found' => false, 'error' => LdapService::USER_NOT_FOUND], $this->lookup(['username' => 'jdoe', 'password' => 'right'], 'failSearch'), 'A failed user search, with a password.');
        $signIn = $this->lookup(['username' => 'jdoe', 'password' => 'right'], 'failGroupSearch');
        $this->assertTrue($signIn['found'] ?? null, 'A failed group search, with a password: found.');
        $this->assertSame([], $signIn['groups'] ?? null, 'Without groups.');
    }

    /**
     * The user part of the answer of POST /api/v1/admin/ldap/test with
     * $body, as an admin, against a directory that has jdoe (password
     * "right") in the group Admins, whose client fails as $failure names.
     *
     * @param array<string, string> $body
     * @param 'failServiceBind'|'failSearch'|'failGroupSearch'|null $failure the method of FakeLdapClient that makes it fail
     * @return array<string, mixed>
     */
    private function lookup(array $body, ?string $failure = null): array
    {
        $params = ['enabled' => true, 'host' => 'fake', 'baseDn' => 'dc=test', 'attrUsername' => 'uid', 'attrEmail' => 'mail', 'attrUid' => 'entryUUID', 'roleMapping' => ['Admins' => 'admin']];
        \Yii::$app->params['ldap'] = $params;
        $client = new FakeLdapClient(LdapConfig::fromArray($params));
        $client->addUser('jdoe', 'uid=jdoe,dc=test', 'right', ['uid' => 'jdoe', 'mail' => 'jdoe@example.com', 'entryUUID' => 'guid-jdoe']);
        $client->addGroupMembership('uid=jdoe,dc=test', 'Admins');
        if ($failure !== null) {
            $client->{$failure}();
        }
        $service = new LdapService();
        $service->client = $client;
        \Yii::$app->set('ldapService', $service);
        $admin = $this->createUser('spec-ldap-admin');
        $admin->is_superadmin = true;
        $admin->save(false);
        $this->loginAs($admin);
        $this->setPost($body);
        \Yii::$app->response->statusCode = 200;

        $result = (new LdapController('admin/ldap', $this->app()))->actionTest();
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $data = $result['data'] ?? null;
        $this->assertIsArray($data);
        $user = $data['user'] ?? null;
        $this->assertIsArray($user);

        return $user;
    }

    /**
     * The metadata of the newest ldap.test.performed entry.
     *
     * @return array<string, mixed>
     */
    private function lastTestEntry(): array
    {
        $entry = AuditLog::find()->where(['action' => AuditLog::ACTION_LDAP_TEST_PERFORMED])->orderBy(['id' => SORT_DESC])->one();
        $this->assertInstanceOf(AuditLog::class, $entry);
        $metadata = json_decode((string)$entry->metadata, true);
        $this->assertIsArray($metadata);

        return $metadata;
    }
}
