<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\models\AuditLog;
use app\models\User;
use app\tests\integration\DbTestCase;
use app\tests\unit\docs\ReadsOperatorDocs;
use app\tests\unit\docs\WorkflowStepsUpdateDocsContractTest;
use yii\db\Migration;

/**
 * What docs/updating.md says under **Security: LDAP accounts deactivated in
 * Ansilume** holds on the schema of this release, in the test's
 * transaction: the migration m000086_000000_add_user_ldap_sync_disabled
 * marks exactly the inactive LDAP accounts whose latest status entry is
 * ldap.user.disabled and prints the lines the item shows, and the read-only
 * query lists every activation by a sign-in or ldap/sync whose status entry
 * before was not ldap.user.disabled, with that entry and the account as it
 * is now. The entries are written as the code writes them (object type
 * user, the account's ID); entries of other actions do not count.
 *
 * Regression (wave 5g of 2.11.0): nothing let an operator find the accounts
 * that a sign-in or ldap/sync activated again after an admin had
 * deactivated them, and nothing said which accounts the update marks. Fails
 * on the docs from before, which have neither.
 */
class LdapDeactivationQueryDocsContractTest extends DbTestCase
{
    use ReadsOperatorDocs;
    use RunsTheUpdateSectionQueries;

    /** The comment the query starts with. */
    private const COMMENT = '-- LDAP accounts that a sign-in or ldap/sync activated again after something other than ldap/sync deactivated them';

    /** The columns the query returns, in its order. */
    private const COLUMNS = ['id', 'reenabled_at', 'user_id', 'username', 'status_entry_before', 'entry_at', 'status', 'ldap_sync_disabled'];

    /** The migration that adds and fills the mark. */
    private const MIGRATION = 'm000086_000000_add_user_ldap_sync_disabled';

    /** The item of the section. */
    private const ITEM = '**Security: LDAP accounts deactivated in Ansilume.**';

    /** An audit action that records no status. */
    private const OTHER_ACTION = 'ldap.user.synced';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/' . self::MIGRATION . '.php';
    }

    /**
     * regression: nothing listed the activations that undid a deactivation
     * in Ansilume. The query lists those whose status entry before was not
     * ldap/sync deactivating the account, also where there is none, and
     * leaves out those after ldap.user.disabled, entries of other actions in
     * between included. Its actions are those the migration weighs.
     */
    public function testRegressionTheQueryListsTheActivationsAfterAnythingButLdapSync(): void
    {
        $this->assertSame($this->statusActions(), $this->actionsOfTheQuery(), 'The query weighs the status entries the migration weighs.');

        $bySync = $this->ldapUser('docs-react-sync', User::STATUS_ACTIVE);
        $this->entry(AuditLog::ACTION_LDAP_USER_DISABLED, (int)$bySync->id);
        $this->entry(self::OTHER_ACTION, (int)$bySync->id);
        $afterSync = $this->entry(AuditLog::ACTION_LDAP_USER_REENABLED, (int)$bySync->id);

        $byAdmin = $this->ldapUser('docs-react-admin', User::STATUS_INACTIVE);
        $this->entry(AuditLog::ACTION_LDAP_USER_DISABLED, (int)$byAdmin->id);
        $this->entry(AuditLog::ACTION_USER_STATUS_CHANGED, (int)$byAdmin->id);
        $afterAdmin = $this->entry(AuditLog::ACTION_LDAP_USER_REENABLED, (int)$byAdmin->id, (string)$byAdmin->username);

        $edited = $this->ldapUser('docs-react-edited', User::STATUS_ACTIVE);
        $this->entry(AuditLog::ACTION_LDAP_USER_DISABLED, (int)$edited->id);
        $this->entry(AuditLog::ACTION_USER_UPDATED, (int)$edited->id);
        $afterEdit = $this->entry(AuditLog::ACTION_LDAP_USER_REENABLED, (int)$edited->id);

        $unknown = $this->ldapUser('docs-react-unknown', User::STATUS_ACTIVE);
        $this->entry(self::OTHER_ACTION, (int)$unknown->id);
        $afterNothing = $this->entry(AuditLog::ACTION_LDAP_USER_REENABLED, (int)$unknown->id);

        $goneId = (int)User::find()->max('id') + 1000;
        $this->entry(AuditLog::ACTION_USER_STATUS_CHANGED, $goneId);
        $afterGone = $this->entry(AuditLog::ACTION_LDAP_USER_REENABLED, $goneId);

        $rows = $this->rowsWhere(self::COMMENT, 'id', [$afterSync, $afterAdmin, $afterEdit, $afterNothing, $afterGone]);

        $this->assertSame(self::COLUMNS, array_keys($rows[0]));
        $this->assertSame(
            [
                [$afterAdmin, (int)$byAdmin->id, AuditLog::ACTION_USER_STATUS_CHANGED, User::STATUS_INACTIVE],
                [$afterEdit, (int)$edited->id, AuditLog::ACTION_USER_UPDATED, User::STATUS_ACTIVE],
                [$afterNothing, (int)$unknown->id, null, User::STATUS_ACTIVE],
                [$afterGone, $goneId, AuditLog::ACTION_USER_STATUS_CHANGED, null],
            ],
            array_map(
                static fn (array $row): array => [(int)$row['id'], (int)$row['user_id'], $row['status_entry_before'], $row['status'] === null ? null : (int)$row['status']],
                $rows
            ),
            'Every activation whose status entry before was not ldap.user.disabled, by ID: none after ldap/sync deactivated the account.'
        );
        $this->assertSame((string)$byAdmin->username, $rows[0]['username']);
        $this->assertNull($rows[2]['entry_at'], 'An account without a status entry before.');
    }

    /**
     * What the item says the migration decides: an inactive LDAP account
     * whose latest status entry, by ID, is ldap.user.disabled is marked; one
     * changed after it, even without a change of its status, one without a
     * status entry, an active account and a local account are not. It
     * prints the two lines the item shows, with its counts.
     */
    public function testTheMigrationMarksWhatTheItemSaysAndPrintsItsLines(): void
    {
        $marked = $this->ldapUser('docs-mark-sync', User::STATUS_INACTIVE);
        $this->entry(AuditLog::ACTION_USER_UPDATED, (int)$marked->id);
        $this->entry(AuditLog::ACTION_LDAP_USER_DISABLED, (int)$marked->id);
        $this->entry(self::OTHER_ACTION, (int)$marked->id);

        $changedAfter = $this->ldapUser('docs-mark-edited', User::STATUS_INACTIVE);
        $this->entry(AuditLog::ACTION_LDAP_USER_DISABLED, (int)$changedAfter->id);
        $this->entry(AuditLog::ACTION_USER_UPDATED, (int)$changedAfter->id);

        $withoutEntry = $this->ldapUser('docs-mark-none', User::STATUS_INACTIVE);
        $active = $this->ldapUser('docs-mark-active', User::STATUS_ACTIVE);
        $this->entry(AuditLog::ACTION_LDAP_USER_DISABLED, (int)$active->id);
        $local = $this->createUser('docs-mark-local');
        $local->status = User::STATUS_INACTIVE;
        $local->save(false);
        $this->entry(AuditLog::ACTION_LDAP_USER_DISABLED, (int)$local->id);
        foreach ([$changedAfter, $withoutEntry, $active, $local] as $user) {
            User::updateAll(['ldap_sync_disabled' => 1], ['id' => $user->id]);
        }

        $migration = $this->migration(self::MIGRATION);
        ob_start();
        try {
            $migration->up();
        } finally {
            $printed = (string)ob_get_clean();
        }

        $this->assertSame(
            [(int)$marked->id => 1, (int)$changedAfter->id => 0, (int)$withoutEntry->id => 0, (int)$active->id => 0, (int)$local->id => 0],
            array_map('intval', User::find()->select(['ldap_sync_disabled', 'id'])->where(['id' => [$marked->id, $changedAfter->id, $withoutEntry->id, $active->id, $local->id]])->orderBy(['id' => SORT_ASC])->indexBy('id')->column()),
            'Only the inactive LDAP account whose latest status entry is ldap.user.disabled is marked.'
        );
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $printed) ?: [])));
        $this->assertCount(2, $lines, $printed);
        $item = self::numbersHidden($this->itemText());
        foreach ($lines as $line) {
            $this->assertMentions(self::numbersHidden($line), $item, 'The item of updating.md');
        }
    }

    /**
     * The migration $class (required in setUpBeforeClass()) on the test
     * database, printing only its own lines.
     */
    private function migration(string $class): Migration
    {
        if (!class_exists($class)) {
            $this->fail("No migration {$class}.");
        }
        $migration = new $class(['db' => \Yii::$app->db, 'compact' => true]);
        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    /**
     * The status actions of the migration, in its order.
     *
     * @return list<string>
     */
    private function statusActions(): array
    {
        $actions = (new \ReflectionClassConstant(self::MIGRATION, 'STATUS_ACTIONS'))->getValue();
        $this->assertIsArray($actions);

        return array_values(array_map('strval', $actions));
    }

    /**
     * The actions in the IN list of the query.
     *
     * @return list<string>
     */
    private function actionsOfTheQuery(): array
    {
        if (preg_match('/x\.action IN \(([^)]*)\)/', $this->queryStartingWith(self::COMMENT), $list) !== 1) {
            $this->fail('The query names the status actions.');
        }
        preg_match_all("/'([^']+)'/", $list[1], $actions);

        return $actions[1];
    }

    /**
     * The item of the section on one line.
     */
    private function itemText(): string
    {
        $units = $this->units('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION);
        $item = [];
        foreach ($units as $unit) {
            if ($item === [] && !str_starts_with($unit, self::ITEM)) {
                continue;
            }
            if ($item !== [] && str_starts_with($unit, '**')) {
                break;
            }
            $item[] = $unit;
        }
        $this->assertNotEmpty($item, 'updating.md has the item ' . self::ITEM);

        return implode(' ', $item);
    }

    /**
     * An LDAP account with $status and no mark.
     */
    private function ldapUser(string $suffix, int $status): User
    {
        $user = $this->createUser($suffix);
        $user->markAsLdapManaged();
        $user->ldap_uid = 'guid-' . $user->username;
        $user->ldap_dn = 'uid=' . $user->username . ',dc=test';
        $user->status = $status;
        $user->save(false);
        User::updateAll(['ldap_sync_disabled' => 0], ['id' => $user->id]);

        return $user;
    }

    /**
     * An audit entry of $action on the user $userId, named $username, as the
     * code writes it; its ID.
     */
    private function entry(string $action, int $userId, string $username = 'docs-ldap-user'): int
    {
        $db = \Yii::$app->db;
        $db->createCommand()->insert('{{%audit_log}}', [
            'user_id' => $userId,
            'action' => $action,
            'object_type' => 'user',
            'object_id' => $userId,
            'metadata' => json_encode(['username' => $username]),
            'created_at' => 1760000000,
        ])->execute();

        return (int)$db->getLastInsertID();
    }

    /**
     * Every number as N, as the item shows counts that vary.
     */
    private static function numbersHidden(string $text): string
    {
        return (string)preg_replace('/\d+/', 'N', $text);
    }
}
