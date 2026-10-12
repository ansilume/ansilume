<?php

declare(strict_types=1);

namespace app\tests\integration\migrations;

use app\tests\integration\DbTestCase;
use yii\db\Connection;

/**
 * m000080_000000_add_secret_extra_vars: job.secret_extra_vars and
 * schedule.secret_extra_vars, MEDIUMTEXT NULL.
 *
 * DDL commits implicitly, so a half-applied run cannot be rolled back: each
 * column is added only when it is missing, and a retry after a failure
 * succeeds instead of failing on the duplicate column at every container
 * start. The schema the real migration produced in the test database is
 * checked as it is; up and down run for real against an empty scratch
 * database, ansilume_migrate_test, which bin/tests-phpunit.sh creates and
 * lets the database user manage (without that grant the round trip is
 * skipped).
 */
class AddSecretExtraVarsMigrationTest extends DbTestCase
{
    private const SCRATCH_DB = 'ansilume_migrate_test';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/m000080_000000_add_secret_extra_vars.php';
    }

    public function testJobAndScheduleHaveTheColumn(): void
    {
        foreach (['{{%job}}', '{{%schedule}}'] as $table) {
            $column = \Yii::$app->db->getTableSchema($table, true)?->getColumn('secret_extra_vars');
            $this->assertNotNull($column, "{$table}.secret_extra_vars exists");
            $this->assertSame('mediumtext', strtolower($column->dbType), "{$table}: room for the sealed answers");
            $this->assertTrue($column->allowNull);
            $this->assertNull($column->defaultValue);
        }
    }

    public function testUpAddsBothColumnsAndARetryChangesNothing(): void
    {
        $db = $this->scratchDatabase();
        try {
            $this->createTablesBeforeTheMigration($db);
            $migration = $this->migration($db);

            $this->quietly(static fn () => $migration->up());
            $this->assertSame(['id', 'extra_vars', 'secret_extra_vars'], $this->columnNames($db, '{{%job}}'));
            $this->assertSame(['id', 'extra_vars', 'secret_extra_vars'], $this->columnNames($db, '{{%schedule}}'));

            $this->quietly(static fn () => $migration->up());
            $this->assertSame(['id', 'extra_vars', 'secret_extra_vars'], $this->columnNames($db, '{{%job}}'), 'no duplicate column');
        } finally {
            $db->close();
            $this->dropScratchDatabase();
        }
    }

    /**
     * Regression guard: a run that failed after adding the first column
     * must succeed when the container starts again.
     */
    public function testARetryAfterAHalfAppliedRunAddsTheMissingColumn(): void
    {
        $db = $this->scratchDatabase();
        try {
            $this->createTablesBeforeTheMigration($db);
            $db->createCommand()->addColumn('{{%job}}', 'secret_extra_vars', 'MEDIUMTEXT NULL')->execute();
            $db->createCommand()->insert('{{%job}}', ['extra_vars' => '{"a":1}', 'secret_extra_vars' => 'kept'])->execute();

            $this->quietly(fn () => $this->migration($db)->up());

            $this->assertSame(['id', 'extra_vars', 'secret_extra_vars'], $this->columnNames($db, '{{%schedule}}'));
            $this->assertSame('kept', $db->createCommand('SELECT secret_extra_vars FROM {{%job}}')->queryScalar(), 'existing data stays');
        } finally {
            $db->close();
            $this->dropScratchDatabase();
        }
    }

    public function testDownRemovesTheColumnsAndCanRunTwice(): void
    {
        $db = $this->scratchDatabase();
        try {
            $this->createTablesBeforeTheMigration($db);
            $migration = $this->migration($db);
            $this->quietly(static fn () => $migration->up());

            $this->quietly(static fn () => $migration->down());
            $this->assertSame(['id', 'extra_vars'], $this->columnNames($db, '{{%job}}'));
            $this->assertSame(['id', 'extra_vars'], $this->columnNames($db, '{{%schedule}}'));

            $this->quietly(static fn () => $migration->down());
            $this->assertSame(['id', 'extra_vars'], $this->columnNames($db, '{{%job}}'));

            $this->quietly(static fn () => $migration->up());
            $this->assertSame(['id', 'extra_vars', 'secret_extra_vars'], $this->columnNames($db, '{{%job}}'), 'forward again');
        } finally {
            $db->close();
            $this->dropScratchDatabase();
        }
    }

    // -- Helpers ----------------------------------------------------------------

    private function migration(Connection $db): \m000080_000000_add_secret_extra_vars
    {
        return new \m000080_000000_add_secret_extra_vars(['db' => $db, 'compact' => true]);
    }

    private function createTablesBeforeTheMigration(Connection $db): void
    {
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        foreach (['{{%job}}', '{{%schedule}}'] as $table) {
            $db->createCommand()->createTable($table, ['id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', 'extra_vars' => 'TEXT NULL'], $options)->execute();
        }
    }

    /**
     * @return list<string>
     */
    private function columnNames(Connection $db, string $table): array
    {
        $schema = $db->getTableSchema($table, true);
        $this->assertNotNull($schema, "{$table} exists");

        return $schema->columnNames;
    }

    private function quietly(callable $call): void
    {
        ob_start();
        try {
            $call();
        } finally {
            ob_end_clean();
        }
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
            // The statement and the server's error, so a missing grant and a
            // collision with another run on the same scratch name read apart.
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
