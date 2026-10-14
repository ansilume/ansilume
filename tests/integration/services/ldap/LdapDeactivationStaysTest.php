<?php

declare(strict_types=1);

namespace app\tests\integration\services\ldap;

use app\models\AuditLog;
use app\models\User;
use app\services\ldap\LdapAuthResult;
use app\services\ldap\LdapConfig;
use app\services\ldap\LdapUserProvisioner;
use app\tests\integration\DbTestCase;

/**
 * An account deactivated in Ansilume stays inactive whatever the directory
 * says (CLAUDE.md security rule 15): LdapUserProvisioner activates again
 * only an account that ldap/sync deactivated because the directory no longer
 * had the user (user.ldap_sync_disabled), which disableMissingUser() marks.
 *
 * Up to 2.10.1 provisionOrUpdate() activated every inactive LDAP account the
 * directory had, so the next LDAP sign-in or ldap/sync undid an admin's
 * deactivation (ldap.user.reenabled).
 */
class LdapDeactivationStaysTest extends DbTestCase
{
    private LdapUserProvisioner $provisioner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisioner = new LdapUserProvisioner();
        $auth = \Yii::$app->authManager;
        foreach (['operator', 'viewer'] as $name) {
            if ($auth->getRole($name) === null) {
                $auth->add($auth->createRole($name));
            }
        }
    }

    /**
     * regression: an account an admin deactivated was activated again by the
     * directory. It stays inactive and unmarked; what the directory says
     * (DN, email, the time of the sync, the roles) is still taken.
     */
    public function testRegressionAnAccountDeactivatedInAnsilumeStaysInactive(): void
    {
        $user = $this->ldapUser('admin-off-');
        $this->assignRole((int)$user->id, 'viewer');
        $this->deactivateInAnsilume($user);
        $auditId = $this->lastAuditId();
        $before = time();

        $persisted = $this->provisioner->provisionOrUpdate(
            $this->resultFor($user, ['dn' => 'uid=moved,ou=people,dc=test', 'email' => 'moved@example.com', 'roles' => ['operator']]),
            $this->config()
        );

        $this->assertNotNull($persisted);
        $stored = User::findOne($user->id);
        $this->assertNotNull($stored);
        $this->assertSame(User::STATUS_INACTIVE, (int)$stored->status, 'the directory does not activate it');
        $this->assertFalse((bool)$stored->ldap_sync_disabled);
        $this->assertSame('uid=moved,ou=people,dc=test', $stored->ldap_dn);
        $this->assertSame('moved@example.com', $stored->email);
        $this->assertGreaterThanOrEqual($before, (int)$stored->last_synced_at);
        $this->assertSame(['operator'], array_keys(\Yii::$app->authManager->getAssignments($user->id)), 'the roles follow the directory');
        $this->assertSame([], $this->actionsSince($auditId, AuditLog::ACTION_LDAP_USER_REENABLED, (int)$user->id));
        $this->assertSame(1, count($this->actionsSince($auditId, AuditLog::ACTION_LDAP_USER_SYNCED, (int)$user->id)), 'the changes are audited');
    }

    /**
     * The account ldap/sync deactivated comes back once the directory has the
     * user again, its mark cleared in the same save.
     */
    public function testAnAccountLdapSyncDeactivatedIsActivatedAgain(): void
    {
        $user = $this->ldapUser('gone-');
        $this->assertTrue($this->provisioner->disableMissingUser($user, 'User not found in directory.'));
        $auditId = $this->lastAuditId();

        $persisted = $this->provisioner->provisionOrUpdate($this->resultFor($user), $this->config());

        $this->assertNotNull($persisted);
        $stored = User::findOne($user->id);
        $this->assertNotNull($stored);
        $this->assertSame(User::STATUS_ACTIVE, (int)$stored->status);
        $this->assertFalse((bool)$stored->ldap_sync_disabled);
        $this->assertCount(1, $this->actionsSince($auditId, AuditLog::ACTION_LDAP_USER_REENABLED, (int)$user->id));
    }

    public function testDisableMissingUserMarksTheAccountItDeactivates(): void
    {
        $user = $this->ldapUser('missing-');

        $this->assertTrue($this->provisioner->disableMissingUser($user, 'User not found in directory.'));

        $stored = User::findOne($user->id);
        $this->assertNotNull($stored);
        $this->assertSame(User::STATUS_INACTIVE, (int)$stored->status);
        $this->assertTrue((bool)$stored->ldap_sync_disabled);
    }

    /**
     * An account that is inactive already is skipped as before, its mark as
     * it was: one deactivated in Ansilume stays unmarked.
     */
    public function testDisableMissingUserLeavesTheMarkOfAnInactiveAccountAlone(): void
    {
        $inAnsilume = $this->ldapUser('skip-admin-');
        $this->deactivateInAnsilume($inAnsilume);
        $bySync = $this->ldapUser('skip-sync-');
        $this->provisioner->disableMissingUser($bySync, 'User not found in directory.');

        $this->assertFalse($this->provisioner->disableMissingUser($inAnsilume, 'User not found in directory.'));
        $this->assertFalse($this->provisioner->disableMissingUser($bySync, 'User not found in directory.'));

        $this->assertFalse((bool)User::findOne($inAnsilume->id)?->ldap_sync_disabled);
        $this->assertTrue((bool)User::findOne($bySync->id)?->ldap_sync_disabled);
    }

    public function testStaysInactiveOnlyForAnInactiveAccountWithoutTheMark(): void
    {
        $active = $this->ldapUser('active-');
        $bySync = $this->ldapUser('by-sync-');
        $this->provisioner->disableMissingUser($bySync, 'User not found in directory.');
        $inAnsilume = $this->ldapUser('in-ansilume-');
        $this->deactivateInAnsilume($inAnsilume);

        $this->assertFalse($this->provisioner->staysInactive($active));
        $this->assertFalse($this->provisioner->staysInactive($this->reload($bySync)));
        $this->assertTrue($this->provisioner->staysInactive($this->reload($inAnsilume)));
    }

    private function config(): LdapConfig
    {
        return LdapConfig::fromArray(['enabled' => true, 'host' => 'fake', 'baseDn' => 'dc=test', 'autoProvision' => true]);
    }

    /**
     * @param array{dn?: string, email?: string, roles?: list<string>} $overrides
     */
    private function resultFor(User $user, array $overrides = []): LdapAuthResult
    {
        return new LdapAuthResult(
            dn: $overrides['dn'] ?? (string)$user->ldap_dn,
            uid: (string)$user->ldap_uid,
            username: (string)$user->username,
            email: $overrides['email'] ?? (string)$user->email,
            displayName: 'Directory User',
            groups: [],
            roles: $overrides['roles'] ?? [],
        );
    }

    private function ldapUser(string $prefix): User
    {
        $user = new User();
        $user->username = 'ldap-stay-' . $prefix . uniqid('', true);
        $user->email = $user->username . '@ldap.local';
        $user->markAsLdapManaged();
        $user->ldap_uid = 'guid-' . $user->username;
        $user->ldap_dn = 'uid=' . $user->username . ',dc=test';
        $user->generateAuthKey();
        $user->status = User::STATUS_ACTIVE;
        $user->save(false);

        return $user;
    }

    /**
     * Deactivated as the status toggle does it: a save of the status.
     */
    private function deactivateInAnsilume(User $user): void
    {
        $user->status = User::STATUS_INACTIVE;
        $user->save(false);
    }

    private function reload(User $user): User
    {
        $fresh = User::findOne($user->id);
        $this->assertNotNull($fresh);

        return $fresh;
    }

    private function assignRole(int $userId, string $name): void
    {
        $auth = \Yii::$app->authManager;
        $role = $auth->getRole($name);
        $this->assertNotNull($role);
        $auth->assign($role, $userId);
    }

    private function lastAuditId(): int
    {
        return (int)AuditLog::find()->max('id');
    }

    /**
     * @return list<int> the IDs of the entries of $action for the user after $auditId
     */
    private function actionsSince(int $auditId, string $action, int $userId): array
    {
        return array_map('intval', AuditLog::find()
            ->select('id')
            ->where(['action' => $action, 'object_type' => 'user', 'object_id' => $userId])
            ->andWhere(['>', 'id', $auditId])
            ->column());
    }
}
