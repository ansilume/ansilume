<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\models\ApiToken;
use app\models\AuditLog;
use app\models\User;
use app\services\ldap\LdapService;
use app\services\ldap\LdapUserProvisioner;
use app\tests\integration\DbTestCase;
use app\tests\unit\docs\ReadsOperatorDocs;

/**
 * The read-only query that docs/updating.md gives under **ldap/sync and a
 * directory that cannot be read** runs on the schema of this release, in
 * the test's transaction, and lists what its text says: every
 * ldap.user.disabled entry, by ID, with the username and the reason it
 * recorded, and the account's status and superadmin flag as they are now;
 * a deleted account has no status. The entries are written as ldap/sync
 * writes them (LdapUserProvisioner::disableMissingUser(), with
 * LdapService::USER_NOT_FOUND, which ldap/sync passes now), and as 2.10.1
 * wrote them while the service bind failed. Not listed: other actions.
 *
 * Regression (wave 5f of 2.11.0): nothing let an operator find the accounts
 * that ldap/sync deactivated while the directory could not be read. Fails
 * on the docs from before, which have no such query.
 */
class LdapSyncQueryDocsContractTest extends DbTestCase
{
    use ReadsOperatorDocs;
    use RunsTheUpdateSectionQueries;

    /** The comment the query starts with. */
    private const COMMENT = '-- Accounts that ldap/sync disabled, with the reason it recorded';

    /** The columns the query returns, in its order. */
    private const COLUMNS = ['id', 'disabled_at', 'user_id', 'username', 'reason', 'status', 'is_superadmin'];

    /** The reason 2.10.1 recorded while the service bind failed. */
    private const BIND_ERROR = "Service account bind failed: Can't contact LDAP server";

    public function testRegressionTheQueryListsTheAccountsThatLdapSyncDisabledWithTheirReason(): void
    {
        $missing = $this->ldapUser('docs-ldap-missing', false);
        $superadmin = $this->ldapUser('docs-ldap-superadmin', true);
        $token = ApiToken::generate((int)$missing->id, 'docs-ldap-token');
        $this->assertNotEmpty($token['raw']);

        $before = (int)AuditLog::find()->max('id');
        $this->assertTrue($this->provisioner()->disableMissingUser($missing, LdapService::USER_NOT_FOUND));
        $byTheSync = (int)AuditLog::find()->max('id');
        $this->assertGreaterThan($before, $byTheSync);
        $byTheOldSync = $this->entry(AuditLog::ACTION_LDAP_USER_DISABLED, (int)$superadmin->id, ['username' => $superadmin->username, 'reason' => self::BIND_ERROR]);
        $gone = $this->entry(AuditLog::ACTION_LDAP_USER_DISABLED, (int)(User::find()->max('id')) + 1000, ['username' => 'docs-ldap-gone', 'reason' => LdapService::USER_NOT_FOUND]);
        $other = $this->entry(AuditLog::ACTION_LDAP_USER_REENABLED, (int)$superadmin->id, ['username' => $superadmin->username]);

        $rows = $this->rowsWhere(self::COMMENT, 'id', [$byTheSync, $byTheOldSync, $gone, $other]);

        $this->assertSame([$byTheSync, $byTheOldSync, $gone], array_map(static fn (array $row): int => (int)$row['id'], $rows), 'The ldap.user.disabled entries, by ID.');
        $this->assertSame(self::COLUMNS, array_keys($rows[0]));
        $this->assertSame(
            [
                [(int)$missing->id, (string)$missing->username, LdapService::USER_NOT_FOUND, User::STATUS_INACTIVE, 0],
                [(int)$superadmin->id, (string)$superadmin->username, self::BIND_ERROR, User::STATUS_ACTIVE, 1],
            ],
            array_map(static fn (array $row): array => [(int)$row['user_id'], $row['username'], $row['reason'], (int)$row['status'], (int)$row['is_superadmin']], array_slice($rows, 0, 2)),
            'The reason it recorded, and the account as it is now.'
        );
        $this->assertNull($rows[2]['status'], 'A deleted account has no status.');
        $this->assertSame('docs-ldap-gone', $rows[2]['username']);
        $this->assertFalse(ApiToken::find()->where(['user_id' => $missing->id])->exists(), 'Its API tokens are gone, as step 13 says.');
    }

    private function provisioner(): LdapUserProvisioner
    {
        /** @var LdapUserProvisioner $provisioner */
        $provisioner = \Yii::$app->get('ldapUserProvisioner');

        return $provisioner;
    }

    /**
     * An active LDAP user, a superadmin when $superadmin.
     */
    private function ldapUser(string $suffix, bool $superadmin): User
    {
        $user = $this->createUser($suffix);
        $user->markAsLdapManaged();
        $user->ldap_uid = 'guid-' . $user->username;
        $user->ldap_dn = 'uid=' . $user->username . ',dc=test';
        $user->is_superadmin = $superadmin;
        $user->save(false);

        return $user;
    }

    /**
     * An audit entry of $action on the user $userId; its ID.
     *
     * @param array<string, mixed> $metadata
     */
    private function entry(string $action, int $userId, array $metadata): int
    {
        $db = \Yii::$app->db;
        $db->createCommand()->insert('{{%audit_log}}', [
            'user_id' => $userId,
            'action' => $action,
            'object_type' => 'user',
            'object_id' => $userId,
            'metadata' => json_encode($metadata),
            'created_at' => 1760000000,
        ])->execute();

        return (int)$db->getLastInsertID();
    }
}
