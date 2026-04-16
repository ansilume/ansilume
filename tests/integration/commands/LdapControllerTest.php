<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\LdapController;
use app\models\User;
use app\services\ldap\FakeLdapClient;
use app\services\ldap\LdapConfig;
use app\services\ldap\LdapService;
use app\tests\integration\DbTestCase;
use yii\console\ExitCode;

/**
 * Integration tests for the console LdapController.
 *
 * Drives the command through real Yii service-locator wiring so config,
 * provisioning, and audit calls all run against the test database.
 */
class LdapControllerTest extends DbTestCase
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

    /**
     * @return LdapController&object{captured: string, errors: string}
     */
    private function makeController(): LdapController
    {
        return new class ('ldap', \Yii::$app) extends LdapController {
            public string $captured = '';
            public string $errors = '';

            public function stdout($string): int
            {
                $this->captured .= $string;
                return 0;
            }

            public function stderr($string): int
            {
                $this->errors .= $string;
                return 0;
            }
        };
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

    private function defaultParams(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'host' => 'fake',
            'baseDn' => 'dc=test',
            'attrUsername' => 'uid',
            'attrEmail' => 'mail',
            'attrUid' => 'entryUUID',
            'autoProvision' => true,
        ], $overrides);
    }

    private function makeLdapUser(string $usernameSuffix = ''): User
    {
        $user = new User();
        $user->username = 'cli-ldap-' . $usernameSuffix . uniqid('', true);
        $user->email = $user->username . '@ldap.local';
        $user->markAsLdapManaged();
        $user->ldap_uid = 'guid-' . $user->username;
        $user->ldap_dn = 'uid=' . $user->username . ',dc=test';
        $user->generateAuthKey();
        $user->status = User::STATUS_ACTIVE;
        $user->created_at = time();
        $user->updated_at = time();
        $user->save(false);
        return $user;
    }

    // ── test-connection ───────────────────────────────────────────────────────

    public function testTestConnectionPrintsDiagnosticAndExitsZero(): void
    {
        $this->configureLdap($this->defaultParams());
        $ctrl = $this->makeController();

        $exit = $ctrl->actionTestConnection();
        $this->assertSame(ExitCode::OK, $exit);
        $this->assertStringContainsString('Enabled:', $ctrl->captured);
        $this->assertStringContainsString('Host:', $ctrl->captured);
        $this->assertStringContainsString('Service bind:', $ctrl->captured);
        $this->assertStringContainsString('OK', $ctrl->captured);
    }

    public function testTestConnectionReturnsErrorWhenBindFails(): void
    {
        $client = $this->configureLdap($this->defaultParams());
        $client->failServiceBind(true);

        $ctrl = $this->makeController();
        $exit = $ctrl->actionTestConnection();
        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $exit);
        $this->assertStringContainsString('FAILED', $ctrl->captured);
        $this->assertStringContainsString('Error:', $ctrl->errors);
    }

    public function testTestConnectionReturnsErrorWhenLdapDisabled(): void
    {
        $this->configureLdap(['enabled' => false]);
        $ctrl = $this->makeController();
        $exit = $ctrl->actionTestConnection();

        // diagnose() reports an error → command returns non-zero.
        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $exit);
        $this->assertStringContainsString('LDAP not enabled', $ctrl->errors);
    }

    // ── test-user ─────────────────────────────────────────────────────────────

    public function testTestUserPrintsLookupSuccess(): void
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

        $ctrl = $this->makeController();
        $exit = $ctrl->actionTestUser('jdoe');

        $this->assertSame(ExitCode::OK, $exit);
        $this->assertStringContainsString('uid=jdoe,dc=test', $ctrl->captured);
        $this->assertStringContainsString('jdoe@example.com', $ctrl->captured);
        $this->assertStringContainsString('Admins', $ctrl->captured);
        $this->assertStringContainsString('admin', $ctrl->captured);
    }

    public function testTestUserPrintsNoneWhenUserHasNoGroups(): void
    {
        $client = $this->configureLdap($this->defaultParams());
        $client->addUser('lonely', 'uid=lonely,dc=test', 'pw', [
            'uid' => 'lonely',
            'mail' => 'lonely@example.com',
        ]);

        $ctrl = $this->makeController();
        $exit = $ctrl->actionTestUser('lonely');

        $this->assertSame(ExitCode::OK, $exit);
        $this->assertStringContainsString('Groups:       (none)', $ctrl->captured);
        $this->assertStringContainsString('Mapped roles: (none)', $ctrl->captured);
    }

    public function testTestUserReturnsErrorWhenUserNotFound(): void
    {
        $this->configureLdap($this->defaultParams());
        $ctrl = $this->makeController();

        $exit = $ctrl->actionTestUser('ghost');
        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $exit);
        $this->assertStringContainsString('Lookup failed', $ctrl->errors);
    }

    // ── sync ──────────────────────────────────────────────────────────────────

    public function testSyncSkipsWhenLdapDisabled(): void
    {
        $this->configureLdap(['enabled' => false]);
        $ctrl = $this->makeController();

        $exit = $ctrl->actionSync();
        $this->assertSame(ExitCode::OK, $exit);
        $this->assertStringContainsString('LDAP is disabled', $ctrl->errors);
    }

    public function testSyncDisablesUserMissingFromDirectory(): void
    {
        $user = $this->makeLdapUser();
        $this->configureLdap($this->defaultParams());

        $ctrl = $this->makeController();
        $exit = $ctrl->actionSync();

        $this->assertSame(ExitCode::OK, $exit);
        $this->assertStringContainsString('missing in directory', $ctrl->captured);
        $this->assertStringContainsString('DISABLE', $ctrl->captured);

        $refreshed = User::findOne($user->id);
        $this->assertSame(User::STATUS_INACTIVE, (int)$refreshed->status);
    }

    public function testSyncSkipsAlreadyInactiveMissingUser(): void
    {
        $user = $this->makeLdapUser();
        $user->status = User::STATUS_INACTIVE;
        $user->save(false);

        $this->configureLdap($this->defaultParams());
        $ctrl = $this->makeController();

        $exit = $ctrl->actionSync();
        $this->assertSame(ExitCode::OK, $exit);
        $this->assertStringContainsString('already inactive', $ctrl->captured);

        $refreshed = User::findOne($user->id);
        $this->assertSame(User::STATUS_INACTIVE, (int)$refreshed->status);
    }

    public function testSyncDryRunDoesNotMutateDatabase(): void
    {
        $user = $this->makeLdapUser();
        $this->configureLdap($this->defaultParams());

        $ctrl = $this->makeController();
        $ctrl->dryRun = true;
        $exit = $ctrl->actionSync();

        $this->assertSame(ExitCode::OK, $exit);
        $this->assertStringContainsString('dry-run', $ctrl->captured);

        $refreshed = User::findOne($user->id);
        $this->assertSame(
            User::STATUS_ACTIVE,
            (int)$refreshed->status,
            'dry-run must not change account state'
        );
    }

    public function testSyncUpdatesUserPresentInDirectory(): void
    {
        $user = $this->makeLdapUser();
        $client = $this->configureLdap($this->defaultParams());
        $client->addUser($user->username, (string)$user->ldap_dn, 'pw', [
            'uid' => $user->username,
            'mail' => 'fresh@example.com',
            'entryUUID' => (string)$user->ldap_uid,
        ]);

        $ctrl = $this->makeController();
        $exit = $ctrl->actionSync();

        $this->assertSame(ExitCode::OK, $exit);
        $refreshed = User::findOne($user->id);
        $this->assertSame('fresh@example.com', $refreshed->email);
    }

    public function testSyncReEnablesUserPresentAgain(): void
    {
        $user = $this->makeLdapUser();
        $user->status = User::STATUS_INACTIVE;
        $user->save(false);

        $client = $this->configureLdap($this->defaultParams());
        $client->addUser($user->username, (string)$user->ldap_dn, 'pw', [
            'uid' => $user->username,
            'mail' => $user->email,
            'entryUUID' => (string)$user->ldap_uid,
        ]);

        $ctrl = $this->makeController();
        $exit = $ctrl->actionSync();
        $this->assertSame(ExitCode::OK, $exit);

        $refreshed = User::findOne($user->id);
        $this->assertSame(User::STATUS_ACTIVE, (int)$refreshed->status);
    }

    public function testSyncDryRunReportsReEnableForInactiveUser(): void
    {
        $user = $this->makeLdapUser();
        $user->status = User::STATUS_INACTIVE;
        $user->save(false);

        $client = $this->configureLdap($this->defaultParams());
        $client->addUser($user->username, (string)$user->ldap_dn, 'pw', [
            'uid' => $user->username,
            'mail' => $user->email,
            'entryUUID' => (string)$user->ldap_uid,
        ]);

        $ctrl = $this->makeController();
        $ctrl->dryRun = true;
        $ctrl->actionSync();

        $this->assertStringContainsString('would RE-ENABLE', $ctrl->captured);
    }

    public function testSyncReportsTotalsInSummaryLine(): void
    {
        $present = $this->makeLdapUser('present-');
        $missing = $this->makeLdapUser('missing-');

        $client = $this->configureLdap($this->defaultParams());
        $client->addUser($present->username, (string)$present->ldap_dn, 'pw', [
            'uid' => $present->username,
            'mail' => $present->email,
            'entryUUID' => (string)$present->ldap_uid,
        ]);

        $ctrl = $this->makeController();
        $ctrl->actionSync();

        $this->assertStringContainsString('updated=', $ctrl->captured);
        $this->assertStringContainsString('disabled=', $ctrl->captured);
        $this->assertStringContainsString('errors=', $ctrl->captured);
    }
}
