<?php

declare(strict_types=1);

namespace app\tests\integration\migrations;

use app\tests\integration\DbTestCase;
use yii\db\Connection;

/**
 * m000094_000000_add_lint_queued_at: job_template.lint_queued_at and
 * project.lint_queued_at, INT UNSIGNED NULL, after lint_exit_code: when a
 * lint run was requested whose result is not stored yet (LintService).
 *
 * DDL commits implicitly, so a half-applied run cannot be rolled back: each
 * column is added only when it is missing, and a retry after a failure
 * succeeds instead of failing on the duplicate column at every container
 * start. The schema the real migration produced in the test database is
 * checked as it is; up and down run for real against an empty scratch
 * database, ansilume_migrate_test, which bin/tests-phpunit.sh creates and
 * lets the database user manage (without that grant those tests are
 * skipped).
 */
class AddLintQueuedAtMigrationTest extends DbTestCase
{
    private const SCRATCH_DB = 'ansilume_migrate_test';

    /** The columns of both tables before the migration, as m000029 and m000031 left the lint ones. */
    private const BEFORE = ['id', 'lint_output', 'lint_at', 'lint_exit_code', 'name'];

    /** The columns after it: the new one right after lint_exit_code. */
    private const AFTER = ['id', 'lint_output', 'lint_at', 'lint_exit_code', 'lint_queued_at', 'name'];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/m000094_000000_add_lint_queued_at.php';
    }

    public function testJobTemplateAndProjectHaveTheColumn(): void
    {
        $this->assertSame(['{{%job_template}}', '{{%project}}'], \m000094_000000_add_lint_queued_at::TABLES);
        foreach (\m000094_000000_add_lint_queued_at::TABLES as $table) {
            $column = \Yii::$app->db->getTableSchema($table, true)?->getColumn('lint_queued_at');
            $this->assertNotNull($column, "{$table}.lint_queued_at exists");
            $this->assertSame('integer', $column->type, "{$table}: Unix seconds, as lint_at");
            $this->assertTrue($column->unsigned);
            $this->assertTrue($column->allowNull, 'null: no lint run is pending');
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
            $this->assertSame(self::AFTER, $this->columnNames($db, '{{%job_template}}'));
            $this->assertSame(self::AFTER, $this->columnNames($db, '{{%project}}'));

            $this->quietly(static fn () => $migration->up());
            $this->assertSame(self::AFTER, $this->columnNames($db, '{{%project}}'), 'no duplicate column');
        } finally {
            $db->close();
            $this->dropScratchDatabase();
        }
    }

    /**
     * A run that failed after adding the first column succeeds when the
     * container starts again, and keeps what is stored.
     */
    public function testARetryAfterAHalfAppliedRunAddsTheMissingColumn(): void
    {
        $db = $this->scratchDatabase();
        try {
            $this->createTablesBeforeTheMigration($db);
            $db->createCommand()->addColumn('{{%job_template}}', 'lint_queued_at', 'INT UNSIGNED NULL AFTER lint_exit_code')->execute();
            $db->createCommand()->insert('{{%job_template}}', ['name' => 'kept', 'lint_queued_at' => 1700000000])->execute();

            $this->quietly(fn () => $this->migration($db)->up());

            $this->assertSame(self::AFTER, $this->columnNames($db, '{{%project}}'));
            $this->assertSame('1700000000', (string)$db->createCommand('SELECT lint_queued_at FROM {{%job_template}}')->queryScalar(), 'what is stored stays');
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
            $this->assertSame(self::BEFORE, $this->columnNames($db, '{{%job_template}}'));
            $this->assertSame(self::BEFORE, $this->columnNames($db, '{{%project}}'));

            $this->quietly(static fn () => $migration->down());
            $this->assertSame(self::BEFORE, $this->columnNames($db, '{{%project}}'));

            $this->quietly(static fn () => $migration->up());
            $this->assertSame(self::AFTER, $this->columnNames($db, '{{%job_template}}'), 'forward again');
        } finally {
            $db->close();
            $this->dropScratchDatabase();
        }
    }

    private function migration(Connection $db): \m000094_000000_add_lint_queued_at
    {
        return new \m000094_000000_add_lint_queued_at(['db' => $db, 'compact' => true]);
    }

    private function createTablesBeforeTheMigration(Connection $db): void
    {
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        foreach (['{{%job_template}}', '{{%project}}'] as $table) {
            $db->createCommand()->createTable($table, [
                'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
                'lint_output' => 'TEXT NULL',
                'lint_at' => 'INT UNSIGNED NULL',
                'lint_exit_code' => 'SMALLINT NULL',
                'name' => 'VARCHAR(128) NULL',
            ], $options)->execute();
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
