<?php

declare(strict_types=1);

use yii\db\Migration;
use yii\db\Query;

/**
 * user.ldap_sync_disabled: 1 when ldap/sync deactivated the account because
 * the directory no longer had the user. An LDAP sign-in or an ldap/sync that
 * finds the user activates again only such an account, and every other
 * change of an account's status clears the mark
 * (app\components\LdapSyncDisabledBehavior), so an account deactivated in
 * Ansilume (the status toggle, the Edit User form, PUT /api/v1/users/{id})
 * stays inactive whatever the directory says. Up to 2.10.1 a sign-in or
 * ldap/sync activated every inactive LDAP account the directory had, one an
 * admin had deactivated too.
 *
 * The backfill keeps that for the accounts the audit log shows ldap/sync
 * deactivated, and fails closed for every other account: 1 for an inactive
 * LDAP account whose latest audit entry among those that change or record an
 * account's status (STATUS_ACTIONS, latest by ID) is ldap.user.disabled; 0
 * for every other account, also an inactive LDAP account without such an
 * entry, which then stays inactive until an admin activates it.
 *
 * up()/down(), not safeUp(): DDL commits implicitly, so a transaction would
 * not undo a half-applied run. The column is added only when it is missing
 * and the backfill decides every row again, so a run that failed half way
 * finishes when it is retried, and running it again changes nothing. Tables,
 * columns and audit actions are named as stored, not by the models'
 * constants: a migration must keep working when the models change. It prints
 * counts only.
 */
class m000086_000000_add_user_ldap_sync_disabled extends Migration
{
    private const TABLE = '{{%user}}';
    private const COLUMN = 'ldap_sync_disabled';

    /** What up() prints first: how many inactive LDAP accounts it marked. */
    public const MARKED = "    > Marked %d inactive LDAP account(s) that ldap/sync deactivated: an LDAP sign-in or ldap/sync that finds the user activates them again.\n";

    /** What up() prints next: how many other inactive LDAP accounts it left without the mark. */
    public const LEFT_UNMARKED = "    > Left %d other inactive LDAP account(s) unmarked: they stay inactive until an admin activates them.\n";

    /** The audit action of ldap/sync deactivating an account the directory no longer had. */
    private const DISABLED_BY_SYNC = 'ldap.user.disabled';

    /**
     * The audit actions that change or record an account's status, each
     * logged with object_type 'user' and the account's ID as object_id:
     * - ldap.user.disabled: ldap/sync deactivated it;
     * - ldap.user.reenabled: an LDAP sign-in or ldap/sync activated it again;
     * - ldap.user.provisioned: an LDAP sign-in created it, active;
     * - user.created: the web form, the API or setup/admin created it, with
     *   a status;
     * - user.updated: the Edit User form or PUT /api/v1/users/{id} saved it,
     *   its status perhaps changed;
     * - user.status_changed: the status toggle changed it.
     */
    private const STATUS_ACTIONS = [
        'ldap.user.disabled',
        'ldap.user.reenabled',
        'ldap.user.provisioned',
        'user.created',
        'user.updated',
        'user.status_changed',
    ];

    public function up(): void
    {
        if (!$this->hasColumn()) {
            $this->addColumn(
                self::TABLE,
                self::COLUMN,
                $this->boolean()->notNull()->defaultValue(0)->after('last_synced_at')
                    ->comment('1: ldap/sync deactivated the account because the directory no longer had the user')
            );
        }
        $this->backfill();
    }

    public function down(): void
    {
        if ($this->hasColumn()) {
            $this->dropColumn(self::TABLE, self::COLUMN);
        }
    }

    /**
     * Decides the mark of every account: 1 for an inactive LDAP account whose
     * latest status entry is ldap.user.disabled, 0 for every other one (also
     * when the account has no status entry: the comparison with NULL is not
     * true). Prints how many inactive LDAP accounts it marked and how many it
     * did not.
     */
    private function backfill(): void
    {
        [$latest, $params] = $this->db->getQueryBuilder()->build($this->latestStatusAction());
        $this->db->createCommand(
            'UPDATE ' . self::TABLE . ' SET [[' . self::COLUMN . ']] = CASE'
                . ' WHEN [[auth_source]] = :ldap AND [[status]] = :inactive AND (' . $latest . ') = :disabled THEN 1'
                . ' ELSE 0 END',
            $params + [':ldap' => 'ldap', ':inactive' => 0, ':disabled' => self::DISABLED_BY_SYNC]
        )->execute();

        echo sprintf(self::MARKED, $this->inactiveLdapAccounts(1));
        echo sprintf(self::LEFT_UNMARKED, $this->inactiveLdapAccounts(0));
    }

    /**
     * The action of the latest status entry (by ID, the order of insertion)
     * of the account in the outer statement.
     */
    private function latestStatusAction(): Query
    {
        return (new Query())
            ->select('a.action')
            ->from(['a' => '{{%audit_log}}'])
            ->where(['a.object_type' => 'user', 'a.action' => self::STATUS_ACTIONS])
            ->andWhere('[[a]].[[object_id]] = ' . self::TABLE . '.[[id]]')
            ->orderBy(['a.id' => SORT_DESC])
            ->limit(1);
    }

    private function inactiveLdapAccounts(int $mark): int
    {
        return (int)(new Query())
            ->from(self::TABLE)
            ->where(['auth_source' => 'ldap', 'status' => 0, self::COLUMN => $mark])
            ->count('*', $this->db);
    }

    private function hasColumn(): bool
    {
        return $this->db->getTableSchema(self::TABLE, true)?->getColumn(self::COLUMN) !== null;
    }
}
