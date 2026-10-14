<?php

declare(strict_types=1);

namespace app\tests\integration\migrations;

use app\tests\integration\DbTestCase;
use yii\db\Connection;

/**
 * m000086_000000_add_user_ldap_sync_disabled: user.ldap_sync_disabled
 * (boolean, NOT NULL, default 0), backfilled from the audit log, failing
 * closed: 1 only for an inactive LDAP account whose latest entry among the
 * actions that change or record an account's status (by ID) is
 * ldap.user.disabled, so that only the accounts ldap/sync deactivated come
 * back with the user's next LDAP sign-in or ldap/sync (CLAUDE.md security
 * rule 15); 0 for every other account.
 *
 * DDL commits implicitly in MySQL/MariaDB, so the schema the real migration
 * produced in the test database is checked as it is, and up and down run
 * for real against an empty scratch database, ansilume_migrate_test, which
 * bin/tests-phpunit.sh creates and lets the database user manage (without
 * that grant those tests are skipped).
 */
class AddUserLdapSyncDisabledMigrationTest extends DbTestCase
{
    private const SCRATCH_DB = 'ansilume_migrate_test';

    /** The columns of the scratch user table before the migration. */
    private const USER_COLUMNS = ['id', 'username', 'auth_source', 'last_synced_at', 'status'];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/m000086_000000_add_user_ldap_sync_disabled.php';
    }

    public function testTheUserTableHasTheMark(): void
    {
        $column = \Yii::$app->db->getTableSchema('{{%user}}', true)?->getColumn('ldap_sync_disabled');
        $this->assertNotNull($column, 'user.ldap_sync_disabled exists');
        $this->assertSame('tinyint(1)', strtolower($column->dbType));
        $this->assertFalse($column->allowNull);
        $this->assertSame(0, (int)$column->defaultValue);
    }

    /**
     * Each case is one account with its status entries, oldest first.
     *
     * @return array<string, array{0: string, 1: int, 2: list<string>, 3: bool}>
     */
    public static function backfillProvider(): array
    {
        return [
            'ldap.user.disabled is the latest status entry' => ['ldap', 0, ['user.created', 'ldap.user.disabled'], true],
            'a user.status_changed after it' => ['ldap', 0, ['ldap.user.disabled', 'user.status_changed'], false],
            'a user.updated after it' => ['ldap', 0, ['ldap.user.disabled', 'user.updated'], false],
            'an ldap.user.provisioned after it' => ['ldap', 0, ['ldap.user.disabled', 'ldap.user.provisioned'], false],
            'a user.created after it' => ['ldap', 0, ['ldap.user.disabled', 'user.created'], false],
            'deactivated, activated again, deactivated again by ldap/sync' => ['ldap', 0, ['ldap.user.disabled', 'ldap.user.reenabled', 'ldap.user.disabled'], true],
            'activated again by the directory, then deactivated in Ansilume' => ['ldap', 0, ['ldap.user.disabled', 'ldap.user.reenabled', 'user.status_changed'], false],
            'entries after it that record no status' => ['ldap', 0, ['ldap.user.disabled', 'ldap.user.synced', 'ldap.user.roles_changed', 'ldap.login.failed'], true],
            'no status entry' => ['ldap', 0, [], false],
            'only entries that record no status' => ['ldap', 0, ['ldap.user.synced'], false],
            'a local user' => ['local', 0, ['ldap.user.disabled'], false],
            'an active user' => ['ldap', 10, ['ldap.user.disabled'], false],
        ];
    }

    /**
     * @dataProvider backfillProvider
     *
     * @param list<string> $actions
     */
    public function testUpBackfillsTheMarkFailingClosed(string $authSource, int $status, array $actions, bool $marked): void
    {
        $db = $this->scratchDatabase();
        try {
            $this->createTablesBeforeTheMigration($db);
            $userId = $this->insertUser($db, $authSource, $status);
            foreach ($actions as $action) {
                $this->insertEntry($db, $action, $userId);
            }

            $output = $this->captureOutput(fn () => $this->migration($db)->up());

            $this->assertSame($marked, $this->markOf($db, $userId));
            $inactiveLdap = $authSource === 'ldap' && $status === 0;
            $this->assertSame(
                sprintf(\m000086_000000_add_user_ldap_sync_disabled::MARKED, $marked ? 1 : 0)
                    . sprintf(\m000086_000000_add_user_ldap_sync_disabled::LEFT_UNMARKED, $inactiveLdap && !$marked ? 1 : 0),
                $output
            );
            $this->assertStringStartsWith('    > Marked ' . ($marked ? 1 : 0) . ' inactive LDAP account(s)', $output);
        } finally {
            $db->close();
            $this->dropScratchDatabase();
        }
    }

    /**
     * Only the account's own entries count: one of another object with the
     * same ID, or of another account, does not.
     */
    public function testOnlyTheEntriesOfTheAccountCount(): void
    {
        $db = $this->scratchDatabase();
        try {
            $this->createTablesBeforeTheMigration($db);
            $account = $this->insertUser($db, 'ldap', 0);
            $other = $this->insertUser($db, 'ldap', 0);
            $this->insertEntry($db, 'ldap.user.disabled', $account, 'project');
            $this->insertEntry($db, 'ldap.user.disabled', $other);
            $this->insertEntry($db, 'user.status_changed', $account);
            $this->insertEntry($db, 'ldap.user.disabled', $account + 1000);

            $this->quietly(fn () => $this->migration($db)->up());

            $this->assertFalse($this->markOf($db, $account));
            $this->assertTrue($this->markOf($db, $other));
        } finally {
            $db->close();
            $this->dropScratchDatabase();
        }
    }

    /**
     * The latest entry is the one with the highest ID, the order the audit
     * log was written in, whatever their times say.
     */
    public function testTheLatestEntryIsTheOneWithTheHighestId(): void
    {
        $db = $this->scratchDatabase();
        try {
            $this->createTablesBeforeTheMigration($db);
            $bySync = $this->insertUser($db, 'ldap', 0);
            $inAnsilume = $this->insertUser($db, 'ldap', 0);
            $this->insertEntry($db, 'user.status_changed', $bySync, 'user', 1700000900);
            $this->insertEntry($db, 'ldap.user.disabled', $bySync, 'user', 1700000100);
            $this->insertEntry($db, 'ldap.user.disabled', $inAnsilume, 'user', 1700000900);
            $this->insertEntry($db, 'user.status_changed', $inAnsilume, 'user', 1700000100);

            $this->quietly(fn () => $this->migration($db)->up());

            $this->assertTrue($this->markOf($db, $bySync));
            $this->assertFalse($this->markOf($db, $inAnsilume));
        } finally {
            $db->close();
            $this->dropScratchDatabase();
        }
    }

    /**
     * A run that failed after adding the column finishes when it is
     * retried, and every further run decides each mark again from the audit
     * log, changing nothing that is right.
     */
    public function testARetryFinishesTheBackfillAndRunningItAgainChangesNothing(): void
    {
        $db = $this->scratchDatabase();
        try {
            $this->createTablesBeforeTheMigration($db);
            $bySync = $this->insertUser($db, 'ldap', 0);
            $this->insertEntry($db, 'ldap.user.disabled', $bySync);
            $unmarked = $this->insertUser($db, 'ldap', 0);
            $db->createCommand()->addColumn('{{%user}}', 'ldap_sync_disabled', 'TINYINT(1) NOT NULL DEFAULT 0')->execute();
            $db->createCommand()->update('{{%user}}', ['ldap_sync_disabled' => 1], ['id' => $unmarked])->execute();

            $this->quietly(fn () => $this->migration($db)->up());

            $this->assertSame(array_merge(self::USER_COLUMNS, ['ldap_sync_disabled']), $this->columnNames($db), 'no second column');
            $this->assertTrue($this->markOf($db, $bySync), 'the backfill ran');
            $this->assertFalse($this->markOf($db, $unmarked), 'a mark the audit log does not show is cleared');

            $again = $this->captureOutput(fn () => $this->migration($db)->up());

            $this->assertTrue($this->markOf($db, $bySync));
            $this->assertFalse($this->markOf($db, $unmarked));
            $this->assertStringStartsWith('    > Marked 1 inactive LDAP account(s)', $again);
            $this->assertStringContainsString('    > Left 1 other inactive LDAP account(s) unmarked', $again);
        } finally {
            $db->close();
            $this->dropScratchDatabase();
        }
    }

    public function testTheColumnComesAfterLastSyncedAtAndDownRemovesIt(): void
    {
        $db = $this->scratchDatabase();
        try {
            $this->createTablesBeforeTheMigration($db);
            $migration = $this->migration($db);
            $this->quietly(static fn () => $migration->up());
            $this->assertSame(['id', 'username', 'auth_source', 'last_synced_at', 'ldap_sync_disabled', 'status'], $this->columnNames($db));

            $migration->down();
            $this->assertSame(self::USER_COLUMNS, $this->columnNames($db));

            $migration->down();
            $this->assertSame(self::USER_COLUMNS, $this->columnNames($db), 'a second down() changes nothing');

            $this->quietly(static fn () => $migration->up());
            $this->assertContains('ldap_sync_disabled', $this->columnNames($db), 'forward again');
        } finally {
            $db->close();
            $this->dropScratchDatabase();
        }
    }

    // -- Helpers ----------------------------------------------------------------

    private function migration(Connection $db): \m000086_000000_add_user_ldap_sync_disabled
    {
        return new \m000086_000000_add_user_ldap_sync_disabled(['db' => $db, 'compact' => true]);
    }

    /**
     * The user and audit_log tables as the earlier migrations left them,
     * reduced to what the migration reads.
     */
    private function createTablesBeforeTheMigration(Connection $db): void
    {
        $id = 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $db->createCommand()->createTable('{{%user}}', [
            'id' => $id,
            'username' => 'VARCHAR(64) NOT NULL',
            'auth_source' => "VARCHAR(16) NOT NULL DEFAULT 'local'",
            'last_synced_at' => 'INT UNSIGNED NULL',
            'status' => 'SMALLINT NOT NULL DEFAULT 10',
        ], $options)->execute();
        $db->createCommand()->createTable('{{%audit_log}}', [
            'id' => $id,
            'user_id' => 'INT UNSIGNED NULL',
            'action' => 'VARCHAR(128) NOT NULL',
            'object_type' => 'VARCHAR(64) NULL',
            'object_id' => 'INT UNSIGNED NULL',
            'created_at' => 'INT UNSIGNED NOT NULL',
        ], $options)->execute();
        $db->createCommand()->createIndex('idx_audit_log_object', '{{%audit_log}}', ['object_type', 'object_id'])->execute();
    }

    private function insertUser(Connection $db, string $authSource, int $status): int
    {
        $db->createCommand()->insert('{{%user}}', [
            'username' => 'm86-' . uniqid('', true),
            'auth_source' => $authSource,
            'status' => $status,
        ])->execute();

        return (int)$db->getLastInsertID();
    }

    private function insertEntry(Connection $db, string $action, int $objectId, string $objectType = 'user', int $createdAt = 1700000000): void
    {
        $db->createCommand()->insert('{{%audit_log}}', [
            'action' => $action,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'created_at' => $createdAt,
        ])->execute();
    }

    private function markOf(Connection $db, int $userId): bool
    {
        $mark = $db->createCommand('SELECT ldap_sync_disabled FROM {{%user}} WHERE id = :id', [':id' => $userId])->queryScalar();
        $this->assertNotFalse($mark, "user #{$userId}");
        $this->assertContains((int)$mark, [0, 1]);

        return (int)$mark === 1;
    }

    /**
     * @return list<string>
     */
    private function columnNames(Connection $db): array
    {
        $schema = $db->getTableSchema('{{%user}}', true);
        $this->assertNotNull($schema);

        return $schema->columnNames;
    }

    private function quietly(callable $call): void
    {
        $this->captureOutput($call);
    }

    private function captureOutput(callable $call): string
    {
        ob_start();
        try {
            $call();
        } finally {
            $output = (string)ob_get_clean();
        }

        return $output;
    }

    /**
     * A connection to a freshly created, empty scratch database; skips the
     * test when the database user may not create it.
     */
    private function scratchDatabase(): Connection
    {
        $server = $this->serverConnection();
        try {
            $server->createCommand('DROP DATABASE IF EXISTS ' . self::SCRATCH_DB)->execute();
            $server->createCommand('CREATE DATABASE ' . self::SCRATCH_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')->execute();
        } catch (\yii\db\Exception $e) {
            $this->markTestSkipped('The database user may not create ' . self::SCRATCH_DB . ' (bin/tests-phpunit.sh grants it): ' . $e->getMessage());
        } finally {
            $server->close();
        }
        $main = \Yii::$app->db;

        return new Connection([
            'dsn' => $this->serverDsn() . ';dbname=' . self::SCRATCH_DB,
            'username' => $main->username,
            'password' => $main->password,
            'charset' => 'utf8mb4',
        ]);
    }

    private function dropScratchDatabase(): void
    {
        $server = $this->serverConnection();
        try {
            $server->createCommand('DROP DATABASE IF EXISTS ' . self::SCRATCH_DB)->execute();
        } finally {
            $server->close();
        }
    }

    /**
     * A connection of its own, outside the test transaction: DDL commits
     * implicitly.
     */
    private function serverConnection(): Connection
    {
        $main = \Yii::$app->db;

        return new Connection([
            'dsn' => $this->serverDsn(),
            'username' => $main->username,
            'password' => $main->password,
            'charset' => 'utf8mb4',
        ]);
    }

    private function serverDsn(): string
    {
        return (string)preg_replace('/;?dbname=[^;]*/', '', \Yii::$app->db->dsn);
    }
}
