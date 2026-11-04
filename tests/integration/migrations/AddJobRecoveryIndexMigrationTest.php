<?php

declare(strict_types=1);

namespace app\tests\integration\migrations;

use app\tests\integration\DbTestCase;
use yii\db\Connection;

/**
 * m000092_000000_add_job_recovery_index: the index
 * idx_job_template_status_check_started on job (job_template_id, status,
 * check_mode, started_at), with which RecoveredFailures reads one index
 * entry per failure: the start of the template's latest success that was
 * no dry run, and the first one after a failure's end. Without it, on
 * 300,000 jobs, the dashboard's Failed Jobs panel took 313 milliseconds
 * instead of 2.6, and the recovering jobs of a page 1.3 seconds instead of
 * 1.6 milliseconds.
 *
 * DDL commits implicitly in MySQL/MariaDB, so the index the real migration
 * made in the test database is checked as it is, and up and down run for
 * real against an empty scratch database, ansilume_migrate_test, which
 * bin/tests-phpunit.sh creates and lets the database user manage (without
 * that grant those tests are skipped).
 */
class AddJobRecoveryIndexMigrationTest extends DbTestCase
{
    private const SCRATCH_DB = 'ansilume_migrate_test';

    private const INDEX = 'idx_job_template_status_check_started';

    private const COLUMNS = ['job_template_id', 'status', 'check_mode', 'started_at'];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/m000092_000000_add_job_recovery_index.php';
    }

    public function testTheJobTableHasTheIndex(): void
    {
        $this->assertSame(self::COLUMNS, $this->indexColumns(\Yii::$app->db));
        $this->assertSame(self::INDEX, \m000092_000000_add_job_recovery_index::INDEX);
    }

    public function testUpAddsTheIndexAndDownRemovesIt(): void
    {
        $db = $this->scratchDatabase();
        try {
            $db->createCommand()->createTable('{{%job}}', [
                'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
                'job_template_id' => 'INT UNSIGNED NULL',
                'status' => "VARCHAR(32) NOT NULL DEFAULT 'pending'",
                'check_mode' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'started_at' => 'INT UNSIGNED NULL',
            ], 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci')->execute();
            $migration = new \m000092_000000_add_job_recovery_index(['db' => $db, 'compact' => true]);

            $this->quietly(fn () => $migration->up());
            $this->assertSame(self::COLUMNS, $this->indexColumns($db));

            $this->quietly(fn () => $migration->down());
            $this->assertSame([], $this->indexColumns($db));
        } finally {
            $db->close();
            $this->dropScratchDatabase();
        }
    }

    /**
     * The columns of the index on job, in their order; [] without it.
     *
     * @return list<string>
     */
    private function indexColumns(Connection $db): array
    {
        $columns = [];
        foreach ($db->createCommand('SHOW INDEX FROM {{%job}}')->queryAll() as $row) {
            if ($row['Key_name'] === self::INDEX) {
                $columns[(int)$row['Seq_in_index']] = (string)$row['Column_name'];
            }
        }
        ksort($columns);

        return array_values($columns);
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
