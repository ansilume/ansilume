<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\LdapController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\User;
use app\services\ldap\FakeLdapClient;
use app\services\ldap\LdapConfig;
use app\services\ldap\LdapService;
use app\services\ldap\PhpLdapClient;
use app\tests\integration\DbTestCase;
use yii\console\ExitCode;

/**
 * Integration tests for the console LdapController.
 *
 * Drives the command through real Yii service-locator wiring so config,
 * provisioning, and audit calls all run against the test database.
 *
 * ldap/sync disables an account only when the directory's search ran and
 * found no entry. Up to 2.10.1 it took every failed lookup for "missing in
 * directory": a directory outage during a scheduled run disabled every LDAP
 * account, superadmins included, revoked their roles, deleted their API
 * tokens, and the run reported errors=0 with exit code 0.
 */
class LdapControllerTest extends DbTestCase
{
    /** @var array<string, string> */
    private const ROLE_MAPPING = ['Admins' => 'admin', 'Ops' => 'operator'];

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

    /**
     * An active LDAP user with a role and an API token, a superadmin when
     * asked: what ldap/sync must keep while the directory cannot be read.
     */
    private function makeLdapUserWithAccess(string $usernameSuffix, string $role, bool $superadmin = false): User
    {
        $user = $this->makeLdapUser($usernameSuffix);
        $user->is_superadmin = $superadmin;
        $user->save(false);
        $auth = \Yii::$app->authManager;
        $auth->assign($auth->getRole($role), $user->id);
        ApiToken::generate((int)$user->id, 'cli-ldap-sync-token');
        return $user;
    }

    private function addToDirectory(FakeLdapClient $client, User $user, string $group = '', string $email = ''): void
    {
        $client->addUser((string)$user->username, (string)$user->ldap_dn, 'pw', [
            'uid' => (string)$user->username,
            'mail' => $email !== '' ? $email : (string)$user->email,
            'entryUUID' => (string)$user->ldap_uid,
        ]);
        if ($group !== '') {
            $client->addGroupMembership((string)$user->ldap_dn, $group);
        }
    }

    /**
     * @return list<string>
     */
    private function rolesOf(int $userId): array
    {
        $names = array_map('strval', array_keys(\Yii::$app->authManager->getAssignments($userId)));
        sort($names);
        return $names;
    }

    private function ldapUserCount(): int
    {
        return (int)User::find()->where(['auth_source' => User::AUTH_SOURCE_LDAP])->count();
    }

    private function lastAuditId(): int
    {
        return (int)AuditLog::find()->max('id');
    }

    /**
     * The user is as they were: active, the same superadmin flag, email,
     * roles and API token, and no ldap.user.disabled entry names them.
     *
     * @param list<string> $roles
     */
    private function assertUnchanged(User $user, array $roles, string $why): void
    {
        $fresh = User::findOne($user->id);
        $this->assertNotNull($fresh);
        $this->assertSame(User::STATUS_ACTIVE, (int)$fresh->status, $why);
        $this->assertSame((bool)$user->is_superadmin, (bool)$fresh->is_superadmin, $why);
        $this->assertSame($roles, $this->rolesOf((int)$user->id), $why);
        $this->assertSame(1, (int)ApiToken::find()->where(['user_id' => $user->id])->count(), $why);
        $this->assertSame((string)$user->email, (string)$fresh->email, $why);
        $this->assertSame((string)$user->auth_key, (string)$fresh->auth_key, $why . ' Sessions stay valid.');
        $disabled = AuditLog::find()->where(['action' => AuditLog::ACTION_LDAP_USER_DISABLED, 'object_id' => $user->id])->count();
        $this->assertSame(0, (int)$disabled, $why);
    }

    private function assertNoUserDisabledSince(int $auditId): void
    {
        $disabled = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_LDAP_USER_DISABLED])
            ->andWhere(['>', 'id', $auditId])
            ->count();
        $this->assertSame(0, (int)$disabled, 'ldap/sync disabled nobody.');
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
        $this->assertSame('[ldap] Lookup failed: ' . LdapService::USER_NOT_FOUND . "\n", $ctrl->errors);
    }

    public function testTestUserNamesAFailedSearchInsteadOfAMissingUser(): void
    {
        $client = $this->configureLdap($this->defaultParams());
        $client->addUser('jdoe', 'uid=jdoe,dc=test', 'pw', ['uid' => 'jdoe']);
        $client->failSearch(true);
        $ctrl = $this->makeController();

        $exit = $ctrl->actionTestUser('jdoe');

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $exit);
        $this->assertSame('[ldap] Lookup failed: ' . FakeLdapClient::SEARCH_FAILURE . "\n", $ctrl->errors);
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
        // Deactivated by ldap/sync, which sets the mark in the same save: an
        // account deactivated in Ansilume stays inactive
        // (LdapSyncLeavesAnsilumeDeactivationTest).
        $user = $this->makeLdapUser();
        $user->status = User::STATUS_INACTIVE;
        $user->ldap_sync_disabled = true;
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
        // Deactivated by ldap/sync, which sets the mark in the same save: an
        // account deactivated in Ansilume stays inactive
        // (LdapSyncLeavesAnsilumeDeactivationTest).
        $user = $this->makeLdapUser();
        $user->status = User::STATUS_INACTIVE;
        $user->ldap_sync_disabled = true;
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

    /**
     * regression: a failed service bind answered "not found in directory":
     * ldap/sync disabled every LDAP account, the LDAP superadmin's too
     * ("missing in directory → DISABLE (Simulated service bind failure.)"),
     * revoked their roles, deleted their API tokens, and reported
     * "disabled=1 errors=0" with exit code 0.
     */
    public function testRegressionAFailedServiceBindStopsTheRunAndDisablesNobody(): void
    {
        $chief = $this->makeLdapUserWithAccess('chief-', 'admin', true);
        $operator = $this->makeLdapUserWithAccess('ops-', 'operator');
        $client = $this->configureLdap($this->defaultParams(['roleMapping' => self::ROLE_MAPPING]));
        $this->addToDirectory($client, $chief, 'Admins');
        $this->addToDirectory($client, $operator, 'Ops');
        $client->failServiceBind(true);
        $total = $this->ldapUserCount();
        $auditId = $this->lastAuditId();

        $ctrl = $this->makeController();
        $exit = $ctrl->actionSync();

        $this->assertUnchanged($chief, ['admin'], 'The LDAP superadmin keeps their account while the directory cannot be read.');
        $this->assertUnchanged($operator, ['operator'], 'Every LDAP user keeps their account while the directory cannot be read.');
        $this->assertNoUserDisabledSince($auditId);
        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $exit);
        $this->assertStringContainsString("[ldap] Done. updated=0 disabled=0 re-enabled=0 errors=1\n", $ctrl->captured);
        $this->assertStringNotContainsString('DISABLE', $ctrl->captured);
        $this->assertSame(sprintf(LdapController::SYNC_BIND_FAILED, FakeLdapClient::SERVICE_BIND_FAILURE, $total), $ctrl->errors);
    }

    /**
     * regression: a failed user search answered "not found in directory"
     * too, so ldap/sync disabled the account.
     */
    public function testRegressionAFailedSearchLeavesEveryUserUnchanged(): void
    {
        $chief = $this->makeLdapUserWithAccess('chief-', 'admin', true);
        $operator = $this->makeLdapUserWithAccess('ops-', 'operator');
        $client = $this->configureLdap($this->defaultParams(['roleMapping' => self::ROLE_MAPPING]));
        $this->addToDirectory($client, $chief, 'Admins');
        $this->addToDirectory($client, $operator, 'Ops');
        $client->failSearch(true);
        $total = $this->ldapUserCount();
        $auditId = $this->lastAuditId();

        $ctrl = $this->makeController();
        $exit = $ctrl->actionSync();

        $this->assertUnchanged($chief, ['admin'], 'A failed search says nothing about the LDAP superadmin.');
        $this->assertUnchanged($operator, ['operator'], 'A failed search says nothing about the user.');
        $this->assertNoUserDisabledSince($auditId);
        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $exit);
        $this->assertStringContainsString("[ldap] Done. updated=0 disabled=0 re-enabled=0 errors={$total}\n", $ctrl->captured);
        $this->assertStringContainsString(sprintf(LdapController::SYNC_LOOKUP_FAILED, $chief->username, FakeLdapClient::SEARCH_FAILURE), $ctrl->errors);
        $this->assertStringContainsString(sprintf(LdapController::SYNC_LOOKUP_FAILED, $operator->username, FakeLdapClient::SEARCH_FAILURE), $ctrl->errors);
    }

    /**
     * regression: a failed group search answered the user with no group, so
     * ldap/sync revoked the roles their groups map to.
     */
    public function testRegressionAFailedGroupSearchRevokesNoRole(): void
    {
        $chief = $this->makeLdapUserWithAccess('chief-', 'admin', true);
        $client = $this->configureLdap($this->defaultParams(['roleMapping' => self::ROLE_MAPPING]));
        $this->addToDirectory($client, $chief, 'Admins', 'chief-new@example.com');
        $client->failGroupSearch(true);
        $auditId = $this->lastAuditId();

        $ctrl = $this->makeController();
        $exit = $ctrl->actionSync();

        $this->assertUnchanged($chief, ['admin'], 'A failed group search changes neither the roles nor the email.');
        $roleChanges = AuditLog::find()
            ->where(['action' => [AuditLog::ACTION_LDAP_ROLES_CHANGED, AuditLog::ACTION_LDAP_USER_SYNCED], 'object_id' => $chief->id])
            ->andWhere(['>', 'id', $auditId])
            ->count();
        $this->assertSame(0, (int)$roleChanges);
        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $exit);
        $this->assertStringContainsString(sprintf(LdapController::SYNC_LOOKUP_FAILED, $chief->username, FakeLdapClient::GROUP_SEARCH_FAILURE), $ctrl->errors);
    }

    /**
     * regression: with LDAP enabled but its host not set, every lookup
     * answered null, so ldap/sync disabled every LDAP account.
     */
    public function testRegressionSyncChangesNothingWhileLdapIsNotFullyConfigured(): void
    {
        $chief = $this->makeLdapUserWithAccess('chief-', 'admin', true);
        $this->configureLdap(['enabled' => true, 'baseDn' => 'dc=test']);
        $auditId = $this->lastAuditId();

        $ctrl = $this->makeController();
        $exit = $ctrl->actionSync();

        $this->assertUnchanged($chief, ['admin'], 'A configuration that is not usable changes nobody.');
        $this->assertNoUserDisabledSince($auditId);
        $this->assertSame(ExitCode::CONFIG, $exit);
        $this->assertSame('', $ctrl->captured, 'No user is looked up.');
        $this->assertSame(LdapController::SYNC_NOT_USABLE, $ctrl->errors);
    }

    public function testAUserTheDirectoryNoLongerHasIsStillDisabled(): void
    {
        $gone = $this->makeLdapUserWithAccess('gone-', 'operator');
        $stays = $this->makeLdapUserWithAccess('stays-', 'operator');
        $client = $this->configureLdap($this->defaultParams(['roleMapping' => self::ROLE_MAPPING]));
        $this->addToDirectory($client, $stays, 'Ops');

        $ctrl = $this->makeController();
        $exit = $ctrl->actionSync();

        $this->assertSame(ExitCode::OK, $exit);
        $this->assertStringContainsString("  - {$gone->username}: missing in directory → DISABLE (" . LdapService::USER_NOT_FOUND . ")\n", $ctrl->captured);
        $this->assertStringContainsString(' errors=0', $ctrl->captured);
        $this->assertSame('', $ctrl->errors);
        $fresh = User::findOne($gone->id);
        $this->assertNotNull($fresh);
        $this->assertSame(User::STATUS_INACTIVE, (int)$fresh->status);
        $this->assertSame([], $this->rolesOf((int)$gone->id), 'Its roles are revoked.');
        $this->assertSame(0, (int)ApiToken::find()->where(['user_id' => $gone->id])->count(), 'Its API tokens are deleted.');
        $audit = AuditLog::find()->where(['action' => AuditLog::ACTION_LDAP_USER_DISABLED, 'object_id' => $gone->id])->one();
        $this->assertNotNull($audit);
        $this->assertSame(LdapService::USER_NOT_FOUND, json_decode((string)$audit->metadata, true)['reason']);
        $this->assertUnchanged($stays, ['operator'], 'A user the directory has keeps their access.');
    }

    /**
     * regression: the outage itself, with the production client: no
     * directory listens, so the service bind fails ("Can't contact LDAP
     * server"), and ldap/sync disabled every LDAP account with that reason.
     */
    public function testRegressionADirectoryThatCannotBeReachedDisablesNobody(): void
    {
        $chief = $this->makeLdapUserWithAccess('chief-', 'admin', true);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $port = (int)substr((string)strrchr((string)stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        \Yii::$app->params['ldap'] = $this->defaultParams(['host' => '127.0.0.1', 'port' => $port, 'timeout' => 2]);
        $svc = new LdapService();
        $svc->client = ['class' => PhpLdapClient::class];
        \Yii::$app->set('ldapService', $svc);
        $total = $this->ldapUserCount();
        $auditId = $this->lastAuditId();

        $ctrl = $this->makeController();
        $exit = $ctrl->actionSync();

        $this->assertUnchanged($chief, ['admin'], 'An LDAP superadmin keeps their account while the directory is down.');
        $this->assertNoUserDisabledSince($auditId);
        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $exit);
        $this->assertSame(sprintf(LdapController::SYNC_BIND_FAILED, "Service account bind failed: Can't contact LDAP server", $total), $ctrl->errors);
    }

    public function testTheRunStopsAtTheFirstServiceBindThatFails(): void
    {
        $this->makeLdapUser('earlier-');
        $later = $this->makeLdapUserWithAccess('later-', 'operator');
        $params = $this->defaultParams(['roleMapping' => self::ROLE_MAPPING]);
        $client = new class (LdapConfig::fromArray($params)) extends FakeLdapClient {
            public int $binds = 0;

            public function bindServiceAccount(): bool
            {
                $this->binds++;
                $this->failServiceBind($this->binds > 1);
                return parent::bindServiceAccount();
            }
        };
        $this->configureLdap($params, $client);
        $this->addToDirectory($client, $later, 'Ops', 'later-new@example.com');
        $total = $this->ldapUserCount();

        $ctrl = $this->makeController();
        $exit = $ctrl->actionSync();

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $exit);
        $this->assertSame(2, $client->binds, 'No lookup after the failed bind.');
        $this->assertStringContainsString(sprintf(LdapController::SYNC_BIND_FAILED, FakeLdapClient::SERVICE_BIND_FAILURE, $total - 1), $ctrl->errors);
        $this->assertStringContainsString(' errors=1', $ctrl->captured);
        $this->assertUnchanged($later, ['operator'], 'The users after the failed bind are left as they are.');
    }

    public function testADryRunReportsAFailedLookupAndExitsNonZero(): void
    {
        $user = $this->makeLdapUserWithAccess('dry-', 'operator');
        $client = $this->configureLdap($this->defaultParams());
        $client->failSearch(true);
        $ctrl = $this->makeController();
        $ctrl->dryRun = true;

        $exit = $ctrl->actionSync();

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $exit);
        $this->assertStringContainsString('(dry-run)', $ctrl->captured);
        $this->assertStringContainsString(sprintf(LdapController::SYNC_LOOKUP_FAILED, $user->username, FakeLdapClient::SEARCH_FAILURE), $ctrl->errors);
        $this->assertUnchanged($user, ['operator'], 'A dry run changes nothing.');
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
