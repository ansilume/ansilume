<?php

declare(strict_types=1);

namespace app\tests\integration\migrations;

use app\tests\integration\DbTestCase;
use yii\db\ColumnSchema;
use yii\db\Connection;
use yii\db\Query;

/**
 * m000093_000000_add_notification_delivery_refs: the project and the job
 * template a delivery's event is about (notification_delivery.project_id,
 * job_template_id), which the notification worker needs to check again,
 * when it delivers, that the user the template delivers as may see the
 * event (NotificationVisibility: a project event by its project, a
 * schedule event by its job template's project), and the index on
 * finished_at with which the maintenance run purges finished deliveries.
 *
 * DDL commits implicitly in MySQL/MariaDB. The schema the real migration
 * produced in the test database is checked as it is. up() and down() run
 * for real in the scratch database ansilume_migrate_test (bin/tests-phpunit.sh
 * grants it; without the grant those tests are skipped), on a table under a
 * prefix of their own.
 */
class NotificationDeliveryRefsMigrationTest extends DbTestCase
{
    private const SCRATCH_DB = 'ansilume_migrate_test';

    /** The columns the migration adds: name => [the column it follows, its comment]. */
    private const COLUMNS = [
        'project_id' => ['approval_request_id', 'The project a project event is about'],
        'job_template_id' => ['project_id', 'The job template a schedule event is about'],
    ];

    private const INDEX = 'idx-notification_delivery-finished_at';

    private ?Connection $scratch = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/m000093_000000_add_notification_delivery_refs.php';
    }

    protected function tearDown(): void
    {
        $scratch = $this->scratch;
        if ($scratch !== null) {
            $scratch->createCommand('DROP TABLE IF EXISTS {{%notification_delivery}}')->execute();
            $scratch->close();
            $this->scratch = null;
        }
        parent::tearDown();
    }

    public function testTheTestDatabaseHasTheMigratedSchema(): void
    {
        $this->assertMigratedSchema(\Yii::$app->db);
        $this->assertSame(self::INDEX, \m000093_000000_add_notification_delivery_refs::INDEX);
    }

    public function testUpChangesNothingOnAMigratedSchema(): void
    {
        $migration = $this->recordingMigration(\Yii::$app->db);

        $migration->up();

        $this->assertSame([], $migration->calls);
    }

    /**
     * The deliveries a release with m000091 left keep their columns and get
     * no project or job template: they were written before the columns.
     */
    public function testUpAddsTheColumnsAndTheIndexToTheSchemaOfM000091(): void
    {
        $db = $this->scratchDatabase();
        $this->createDeliveryTable($db);
        $id = $this->insertDelivery($db);

        $this->migration($db)->up();

        $this->assertMigratedSchema($db);
        $row = (new Query())->from('{{%notification_delivery}}')->where(['id' => $id])->one($db);
        $this->assertIsArray($row);
        $this->assertNull($row['project_id']);
        $this->assertNull($row['job_template_id']);
        $this->assertSame('17', (string)$row['approval_request_id']);

        $again = $this->recordingMigration($db);
        $again->up();
        $this->assertSame([], $again->calls, 'a second run sends no DDL');
    }

    /**
     * DDL commits implicitly, so a run can fail half way: here after
     * project_id was added. The next container start runs the migration
     * again; it must finish the work, not fail.
     */
    public function testARetryFinishesWhatAFailedRunLeft(): void
    {
        $db = $this->scratchDatabase();
        $this->createDeliveryTable($db);
        $db->createCommand()->addColumn(
            '{{%notification_delivery}}',
            'project_id',
            "INT UNSIGNED NULL COMMENT 'The project a project event is about' AFTER [[approval_request_id]]"
        )->execute();

        $migration = $this->recordingMigration($db, true);
        $migration->up();

        $this->assertSame(['addColumn job_template_id', 'createIndex ' . self::INDEX], $migration->calls);
        $this->assertMigratedSchema($db);
    }

    public function testDownRemovesTheColumnsAndTheIndexAndUpWorksAgain(): void
    {
        $db = $this->scratchDatabase();
        $this->createDeliveryTable($db);
        $this->migration($db)->up();

        $this->migration($db)->down();

        foreach (array_keys(self::COLUMNS) as $name) {
            $this->assertNull($this->column($db, $name), "{$name} is gone");
        }
        $this->assertArrayNotHasKey(self::INDEX, $this->indexesOf($db));
        $again = $this->recordingMigration($db);
        $again->down();
        $this->assertSame([], $again->calls, 'a second down has nothing to do');

        $this->migration($db)->up();
        $this->assertMigratedSchema($db);
    }

    // -- Helpers ----------------------------------------------------------------

    private function assertMigratedSchema(Connection $db): void
    {
        foreach (self::COLUMNS as $name => [$after, $comment]) {
            $column = $this->column($db, $name);
            $this->assertNotNull($column, "notification_delivery.{$name} exists");
            $this->assertSame('integer', $column->type, $name);
            $this->assertTrue($column->unsigned, "{$name} unsigned");
            $this->assertTrue($column->allowNull, "{$name} null");
            $this->assertNull($column->defaultValue, "{$name} default");
            $this->assertSame($comment, $column->comment, "{$name} comment");
            $this->assertSame($name, $this->columnAfter($db, $after), "{$name} follows {$after}");
        }
        $this->assertSame([['finished_at'], false], $this->indexesOf($db)[self::INDEX] ?? null, self::INDEX);
    }

    private function migration(Connection $db): \m000093_000000_add_notification_delivery_refs
    {
        return new \m000093_000000_add_notification_delivery_refs(['db' => $db, 'compact' => true]);
    }

    /**
     * The migration with every schema change recorded; run as well when
     * $run is true.
     *
     * @return \m000093_000000_add_notification_delivery_refs&object{calls: list<string>}
     */
    private function recordingMigration(Connection $db, bool $run = false): \m000093_000000_add_notification_delivery_refs
    {
        return new class (['db' => $db, 'compact' => true], $run) extends \m000093_000000_add_notification_delivery_refs {
            /** @var list<string> */
            public array $calls = [];

            /**
             * @param array<string, mixed> $config
             */
            public function __construct(array $config, private readonly bool $run)
            {
                parent::__construct($config);
            }

            /**
             * @param string $table
             * @param string $column
             * @param mixed $type
             */
            public function addColumn($table, $column, $type): void
            {
                $this->calls[] = "addColumn {$column}";
                if ($this->run) {
                    parent::addColumn($table, $column, $type);
                }
            }

            /**
             * @param string $table
             * @param string $column
             */
            public function dropColumn($table, $column): void
            {
                $this->calls[] = "dropColumn {$column}";
            }

            /**
             * @param string $name
             * @param string $table
             * @param string|list<string> $columns
             * @param bool $unique
             */
            public function createIndex($name, $table, $columns, $unique = false): void
            {
                $this->calls[] = "createIndex {$name}";
                if ($this->run) {
                    parent::createIndex($name, $table, $columns, $unique);
                }
            }

            /**
             * @param string $name
             * @param string $table
             */
            public function dropIndex($name, $table): void
            {
                $this->calls[] = "dropIndex {$name}";
            }
        };
    }

    /**
     * notification_delivery as m000091 creates it, reduced to the columns
     * the migration places a column after or indexes, and those a row needs.
     */
    private function createDeliveryTable(Connection $db): void
    {
        $db->createCommand()->createTable('{{%notification_delivery}}', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'notification_template_id' => 'INT UNSIGNED NOT NULL',
            'event' => 'VARCHAR(64) NOT NULL',
            'workflow_job_id' => 'INT UNSIGNED NULL',
            'job_id' => 'INT UNSIGNED NULL',
            'approval_request_id' => 'INT UNSIGNED NULL',
            'variables' => 'TEXT NOT NULL',
            'status' => "VARCHAR(16) NOT NULL DEFAULT 'pending'",
            'next_attempt_at' => 'INT NOT NULL',
            'created_at' => 'INT NOT NULL',
            'updated_at' => 'INT NOT NULL',
            'finished_at' => 'INT NULL',
        ], 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci')->execute();
    }

    private function insertDelivery(Connection $db): int
    {
        $db->createCommand()->insert('{{%notification_delivery}}', [
            'notification_template_id' => 3,
            'event' => 'approval.requested',
            'approval_request_id' => 17,
            'variables' => '{}',
            'next_attempt_at' => 1700000000,
            'created_at' => 1700000000,
            'updated_at' => 1700000000,
        ])->execute();

        return (int)$db->getLastInsertID();
    }

    private function column(Connection $db, string $name): ?ColumnSchema
    {
        return $db->getTableSchema('{{%notification_delivery}}', true)?->getColumn($name);
    }

    /**
     * The name of the column that follows $column in notification_delivery.
     */
    private function columnAfter(Connection $db, string $column): ?string
    {
        $names = $db->getTableSchema('{{%notification_delivery}}', true)?->getColumnNames() ?? [];
        $position = array_search($column, $names, true);

        return $position === false ? null : ($names[$position + 1] ?? null);
    }

    /**
     * The indexes of notification_delivery: name => [columns in their order, unique].
     *
     * @return array<string, array{0: list<string>, 1: bool}>
     */
    private function indexesOf(Connection $db): array
    {
        $rows = (new Query())
            ->select(['name' => 'INDEX_NAME', 'column' => 'COLUMN_NAME', 'nonUnique' => 'NON_UNIQUE'])
            ->from('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table', [
                ':table' => $db->getSchema()->getRawTableName('{{%notification_delivery}}'),
            ])
            ->orderBy(['INDEX_NAME' => SORT_ASC, 'SEQ_IN_INDEX' => SORT_ASC])
            ->all($db);
        $indexes = [];
        foreach ($rows as $row) {
            $name = (string)$row['name'];
            $indexes[$name][0][] = (string)$row['column'];
            $indexes[$name][1] = (int)$row['nonUnique'] === 0;
        }

        return $indexes;
    }

    /**
     * A connection to the scratch database, created when missing, whose
     * tables carry a prefix of this test; skips the test when the database
     * user may not create it.
     */
    private function scratchDatabase(): Connection
    {
        $main = \Yii::$app->db;
        $serverDsn = (string)preg_replace('/;?dbname=[^;]*/', '', $main->dsn);
        $server = new Connection(['dsn' => $serverDsn, 'username' => $main->username, 'password' => $main->password, 'charset' => 'utf8mb4']);
        try {
            $server->createCommand('CREATE DATABASE IF NOT EXISTS ' . self::SCRATCH_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')->execute();
        } catch (\yii\db\Exception) {
            $this->markTestSkipped('The database user may not create ' . self::SCRATCH_DB . ' (bin/tests-phpunit.sh grants it).');
        } finally {
            $server->close();
        }
        $this->scratch = new Connection([
            'dsn' => $serverDsn . ';dbname=' . self::SCRATCH_DB,
            'username' => $main->username,
            'password' => $main->password,
            'charset' => 'utf8mb4',
            'tablePrefix' => 'm93_' . bin2hex(random_bytes(3)) . '_',
        ]);

        return $this->scratch;
    }
}
