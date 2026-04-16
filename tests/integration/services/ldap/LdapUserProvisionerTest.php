<?php

declare(strict_types=1);

namespace app\tests\integration\services\ldap;

use app\models\ApiToken;
use app\models\AuditLog;
use app\models\User;
use app\services\ldap\LdapAuthResult;
use app\services\ldap\LdapConfig;
use app\services\ldap\LdapUserProvisioner;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for LdapUserProvisioner — covers the full DB-backed
 * provisioning, role reconciliation, re-enable, and disable lifecycle.
 *
 * Each test verifies real ActiveRecord writes, real RBAC manager calls,
 * and real audit log entries — no mocks below the DbTestCase boundary.
 */
class LdapUserProvisionerTest extends DbTestCase
{
    private LdapUserProvisioner $provisioner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisioner = new LdapUserProvisioner();
        // Make sure the operator/viewer/admin roles exist for reconcileRoles.
        $auth = \Yii::$app->authManager;
        foreach (['admin', 'operator', 'viewer'] as $name) {
            if ($auth->getRole($name) === null) {
                $role = $auth->createRole($name);
                $auth->add($role);
            }
        }
    }

    private function makeConfig(bool $autoProvision = true): LdapConfig
    {
        return LdapConfig::fromArray([
            'enabled' => true,
            'host' => 'fake',
            'baseDn' => 'dc=test',
            'autoProvision' => $autoProvision,
        ]);
    }

    private function makeResult(array $overrides = []): LdapAuthResult
    {
        return new LdapAuthResult(
            dn: $overrides['dn'] ?? 'uid=jdoe,dc=test',
            uid: $overrides['uid'] ?? 'guid-' . uniqid('', true),
            username: $overrides['username'] ?? 'jdoe-' . uniqid('', true),
            email: $overrides['email'] ?? 'jdoe@example.com',
            displayName: $overrides['displayName'] ?? 'John Doe',
            groups: $overrides['groups'] ?? [],
            roles: $overrides['roles'] ?? [],
        );
    }

    private function makeLdapUser(string $usernameSuffix = ''): User
    {
        $user = new User();
        $user->username = 'ldap-prov-' . $usernameSuffix . uniqid('', true);
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

    // ── resolveUser ──────────────────────────────────────────────────────────

    public function testResolveUserPrefersLookupByUid(): void
    {
        $user = $this->makeLdapUser();
        $result = $this->makeResult(['uid' => (string)$user->ldap_uid, 'username' => 'different-name']);

        $resolved = $this->provisioner->resolveUser($result);
        $this->assertNotNull($resolved);
        $this->assertSame($user->id, $resolved->id);
    }

    public function testResolveUserFallsBackToUsername(): void
    {
        $user = $this->makeLdapUser();
        // Different uid → forces username path
        $result = $this->makeResult([
            'uid' => 'completely-different-uid',
            'username' => $user->username,
        ]);

        $resolved = $this->provisioner->resolveUser($result);
        $this->assertNotNull($resolved);
        $this->assertSame($user->id, $resolved->id);
    }

    public function testResolveUserDoesNotMatchLocalAccountByUsername(): void
    {
        // Create a local user (auth_source=local). Even with matching username,
        // resolveUser must NOT return it — that would let LDAP hijack a local
        // username collision.
        $local = $this->createUser('collision');
        $result = $this->makeResult(['uid' => '', 'username' => $local->username]);

        $resolved = $this->provisioner->resolveUser($result);
        $this->assertNull($resolved);
    }

    public function testResolveUserReturnsNullForUnknownUser(): void
    {
        $result = $this->makeResult(['uid' => 'no-such-uid', 'username' => 'no-such-user']);
        $this->assertNull($this->provisioner->resolveUser($result));
    }

    // ── provisionOrUpdate (new user) ─────────────────────────────────────────

    public function testProvisionCreatesNewUserWhenAutoProvisionEnabled(): void
    {
        $result = $this->makeResult(['username' => 'new-user-' . uniqid('', true), 'roles' => ['operator']]);
        $config = $this->makeConfig(true);

        $user = $this->provisioner->provisionOrUpdate($result, $config);
        $this->assertNotNull($user);
        $this->assertSame($result->username, $user->username);
        $this->assertSame($result->email, $user->email);
        $this->assertSame($result->dn, $user->ldap_dn);
        $this->assertSame($result->uid, $user->ldap_uid);
        $this->assertTrue($user->isLdap());
        $this->assertSame(User::AUTH_SOURCE_LDAP, $user->auth_source);
        $this->assertSame(User::LDAP_PASSWORD_SENTINEL, $user->password_hash);
        $this->assertSame(User::STATUS_ACTIVE, (int)$user->status);
        $this->assertNotEmpty($user->auth_key);
        $this->assertNotNull($user->last_synced_at);
    }

    public function testProvisionRefusesNewUserWhenAutoProvisionDisabled(): void
    {
        $result = $this->makeResult(['username' => 'no-create-' . uniqid('', true)]);
        $config = $this->makeConfig(false);

        $this->assertNull($this->provisioner->provisionOrUpdate($result, $config));
    }

    public function testProvisionFallsBackEmailToLocalDomainWhenMissing(): void
    {
        $result = $this->makeResult(['email' => '', 'username' => 'noemail-' . uniqid('', true)]);
        $config = $this->makeConfig(true);

        $user = $this->provisioner->provisionOrUpdate($result, $config);
        $this->assertNotNull($user);
        $this->assertStringEndsWith('@ldap.local', (string)$user->email);
    }

    public function testProvisionLogsAuditEventForNewUser(): void
    {
        $result = $this->makeResult(['username' => 'audited-new-' . uniqid('', true), 'roles' => ['admin']]);
        $config = $this->makeConfig(true);

        $user = $this->provisioner->provisionOrUpdate($result, $config);
        $this->assertNotNull($user);

        $entry = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_LDAP_USER_PROVISIONED, 'object_id' => $user->id])
            ->one();
        $this->assertNotNull($entry, 'Provisioning a new LDAP user must emit an audit event.');
    }

    public function testProvisionAssignsMappedRolesOnCreate(): void
    {
        $result = $this->makeResult([
            'username' => 'rolecreate-' . uniqid('', true),
            'roles' => ['operator'],
        ]);
        $config = $this->makeConfig(true);

        $user = $this->provisioner->provisionOrUpdate($result, $config);
        $this->assertNotNull($user);

        $assignments = \Yii::$app->authManager->getAssignments((int)$user->id);
        $this->assertArrayHasKey('operator', $assignments);
    }

    // ── provisionOrUpdate (existing user) ────────────────────────────────────

    public function testProvisionUpdatesExistingUserAttributes(): void
    {
        $user = $this->makeLdapUser();
        $oldEmail = $user->email;
        $result = $this->makeResult([
            'uid' => (string)$user->ldap_uid,
            'username' => $user->username,
            'email' => 'updated@new.example.com',
            'dn' => 'uid=moved,dc=test',
        ]);
        $config = $this->makeConfig(true);

        $persisted = $this->provisioner->provisionOrUpdate($result, $config);
        $this->assertNotNull($persisted);
        $this->assertSame('updated@new.example.com', $persisted->email);
        $this->assertSame('uid=moved,dc=test', $persisted->ldap_dn);
        $this->assertNotSame($oldEmail, $persisted->email);
    }

    public function testProvisionLogsSyncedAuditWhenAttributesChange(): void
    {
        $user = $this->makeLdapUser();
        $result = $this->makeResult([
            'uid' => (string)$user->ldap_uid,
            'username' => $user->username,
            'email' => 'changed@example.com',
            'dn' => (string)$user->ldap_dn,
        ]);
        $config = $this->makeConfig(true);

        $this->provisioner->provisionOrUpdate($result, $config);

        $entry = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_LDAP_USER_SYNCED, 'object_id' => $user->id])
            ->one();
        $this->assertNotNull($entry, 'Email change must trigger a synced audit event.');
    }

    public function testProvisionDoesNotLogSyncedAuditWhenNothingChanged(): void
    {
        $user = $this->makeLdapUser();
        $result = $this->makeResult([
            'uid' => (string)$user->ldap_uid,
            'username' => $user->username,
            'email' => $user->email,
            'dn' => (string)$user->ldap_dn,
        ]);
        $config = $this->makeConfig(true);

        $this->provisioner->provisionOrUpdate($result, $config);

        $entry = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_LDAP_USER_SYNCED, 'object_id' => $user->id])
            ->one();
        $this->assertNull($entry, 'No-op syncs must NOT emit a synced audit event.');
    }

    public function testProvisionReEnablesPreviouslyDisabledUser(): void
    {
        $user = $this->makeLdapUser();
        $user->status = User::STATUS_INACTIVE;
        $user->save(false);

        $result = $this->makeResult([
            'uid' => (string)$user->ldap_uid,
            'username' => $user->username,
        ]);
        $config = $this->makeConfig(true);

        $persisted = $this->provisioner->provisionOrUpdate($result, $config);
        $this->assertNotNull($persisted);
        $this->assertSame(User::STATUS_ACTIVE, (int)$persisted->status);

        $entry = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_LDAP_USER_REENABLED, 'object_id' => $user->id])
            ->one();
        $this->assertNotNull($entry, 'Re-enable must emit ACTION_LDAP_USER_REENABLED.');
    }

    public function testProvisionPinsAuthSourceAndSentinelOnExistingUser(): void
    {
        // Defense-in-depth: even if an admin manually set auth_source=local
        // and a real bcrypt hash, the next sync must restore LDAP state.
        $user = $this->makeLdapUser();
        $user->auth_source = User::AUTH_SOURCE_LOCAL;
        $user->password_hash = '$2y$13$tampered.with.real.hash.here.fake.bcrypt.value.aaaaa';
        $user->save(false);

        $result = $this->makeResult([
            'uid' => (string)$user->ldap_uid,
            'username' => $user->username,
        ]);
        $persisted = $this->provisioner->provisionOrUpdate($result, $this->makeConfig(true));
        $this->assertNotNull($persisted);
        $this->assertSame(User::AUTH_SOURCE_LDAP, $persisted->auth_source);
        $this->assertSame(User::LDAP_PASSWORD_SENTINEL, $persisted->password_hash);
    }

    // ── reconcileRoles ───────────────────────────────────────────────────────

    public function testReconcileRolesAddsAndRemovesAssignments(): void
    {
        $user = $this->createUser('reconcile');
        $auth = \Yii::$app->authManager;
        $auth->assign($auth->getRole('viewer'), $user->id);

        $diff = $this->provisioner->reconcileRoles($user->id, ['operator', 'admin']);

        $this->assertContains('operator', $diff['added']);
        $this->assertContains('admin', $diff['added']);
        $this->assertContains('viewer', $diff['removed']);

        $assignments = $auth->getAssignments($user->id);
        $this->assertArrayHasKey('operator', $assignments);
        $this->assertArrayHasKey('admin', $assignments);
        $this->assertArrayNotHasKey('viewer', $assignments);
    }

    public function testReconcileRolesIsIdempotent(): void
    {
        $user = $this->createUser('idempotent');
        $auth = \Yii::$app->authManager;
        $auth->assign($auth->getRole('operator'), $user->id);

        $diff = $this->provisioner->reconcileRoles($user->id, ['operator']);
        $this->assertSame([], $diff['added']);
        $this->assertSame([], $diff['removed']);
    }

    public function testReconcileRolesSkipsUnknownRoleNames(): void
    {
        $user = $this->createUser('unknown-role');
        $diff = $this->provisioner->reconcileRoles($user->id, ['no-such-role-xyz']);
        // Unknown roles are skipped silently — they don't end up in 'added'.
        $this->assertNotContains('no-such-role-xyz', $diff['added']);
    }

    public function testReconcileRolesLogsAuditWhenChanged(): void
    {
        $user = $this->makeLdapUser();
        $result = $this->makeResult([
            'uid' => (string)$user->ldap_uid,
            'username' => $user->username,
            'roles' => ['admin'],
        ]);

        $this->provisioner->provisionOrUpdate($result, $this->makeConfig(true));

        $entry = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_LDAP_ROLES_CHANGED, 'object_id' => $user->id])
            ->one();
        $this->assertNotNull($entry, 'Role changes must emit ACTION_LDAP_ROLES_CHANGED.');
    }

    // ── disableMissingUser ────────────────────────────────────────────────────

    public function testDisableMissingUserDeactivatesAccount(): void
    {
        $user = $this->makeLdapUser();
        $oldKey = (string)$user->auth_key;

        $disabled = $this->provisioner->disableMissingUser($user, 'gone from directory');
        $this->assertTrue($disabled);

        $refreshed = User::findOne($user->id);
        $this->assertSame(User::STATUS_INACTIVE, (int)$refreshed->status);
        $this->assertNotSame($oldKey, (string)$refreshed->auth_key, 'auth_key must rotate to invalidate sessions.');
    }

    public function testDisableMissingUserDeletesApiTokens(): void
    {
        $user = $this->makeLdapUser();
        $token = new ApiToken();
        $token->user_id = $user->id;
        $token->name = 'ldap-revoke-test';
        $token->token_hash = hash('sha256', 'tok-' . uniqid('', true));
        $token->created_at = time();
        $token->save(false);

        $this->provisioner->disableMissingUser($user, 'gone');

        $this->assertNull(
            ApiToken::find()->where(['user_id' => $user->id])->one(),
            'API tokens must be deleted when an LDAP user is disabled.'
        );
    }

    public function testDisableMissingUserRevokesAllRoleAssignments(): void
    {
        $user = $this->makeLdapUser();
        $auth = \Yii::$app->authManager;
        $auth->assign($auth->getRole('admin'), $user->id);

        $this->provisioner->disableMissingUser($user, 'gone');

        $this->assertSame([], $auth->getAssignments($user->id));
    }

    public function testDisableMissingUserLogsAuditEvent(): void
    {
        $user = $this->makeLdapUser();
        $this->provisioner->disableMissingUser($user, 'directory deletion');

        $entry = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_LDAP_USER_DISABLED, 'object_id' => $user->id])
            ->one();
        $this->assertNotNull($entry);
    }

    public function testDisableMissingUserIsIdempotent(): void
    {
        $user = $this->makeLdapUser();
        $user->status = User::STATUS_INACTIVE;
        $user->save(false);

        $this->assertFalse(
            $this->provisioner->disableMissingUser($user, 'already inactive'),
            'Disabling an already-inactive user must be a no-op.'
        );
    }
}
