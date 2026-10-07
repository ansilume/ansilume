<?php

declare(strict_types=1);

namespace app\tests\integration\migrations;

use app\models\Credential;
use app\models\JobTemplateVaultCheck;
use app\models\Project;
use app\models\ProjectVaultEntry;
use app\tests\integration\DbTestCase;
use yii\db\Connection;

/**
 * m000076_000000_add_vault_scan: the vault scan columns of project, the
 * project_vault_entry and job_template_vault_check tables and
 * runner.capabilities. Projects that exist when it runs keep the repository's
 * vault settings ('repository', the behaviour before the release); projects
 * created afterwards are 'Ansilume only'.
 *
 * DDL commits implicitly in MySQL/MariaDB, so the migration cannot run inside
 * the rolled-back test transaction. Therefore:
 *  - the schema the real migration produced in the test database is checked
 *    as it is, including what the foreign keys do on delete;
 *  - safeUp() and safeDown() run with their schema changes recorded instead
 *    of executed, so the backfill of existing projects runs for real inside
 *    the transaction and down can be compared with up;
 *  - up and down run for real against an empty scratch database,
 *    ansilume_migrate_test, which bin/tests-phpunit.sh creates for its own
 *    migration check and lets the database user manage. Without that grant
 *    the round trip is skipped.
 */
class AddVaultScanMigrationTest extends DbTestCase
{
    private const SCRATCH_DB = 'ansilume_migrate_test';

    /** The columns the migration adds to project, in order. */
    private const PROJECT_COLUMNS = ['vault_password_source', 'vault_scanned_at', 'vault_scan_commit', 'vault_scan_summary', 'vault_scan_error'];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/m000076_000000_add_vault_scan.php';
    }

    // -- The migrated test database -------------------------------------------

    public function testProjectHasTheVaultColumns(): void
    {
        $this->assertColumn('{{%project}}', 'vault_password_source', 'string', 16, false, Project::VAULT_SOURCE_ANSILUME);
        $this->assertColumn('{{%project}}', 'vault_scanned_at', 'integer', null, true, null);
        $this->assertColumn('{{%project}}', 'vault_scan_commit', 'string', 64, true, null);
        $this->assertColumn('{{%project}}', 'vault_scan_summary', 'text', null, true, null);
        $this->assertColumn('{{%project}}', 'vault_scan_error', 'text', null, true, null);
    }

    public function testRunnerHasTheCapabilitiesColumn(): void
    {
        $this->assertColumn('{{%runner}}', 'capabilities', 'string', 255, true, null);
    }

    public function testTheVaultEntryTableHoldsLocationsAndFingerprintsOnly(): void
    {
        $schema = $this->tableSchema('{{%project_vault_entry}}');

        $this->assertSame(['id', 'project_id', 'path', 'kind', 'line', 'var_key', 'vault_id', 'format_version', 'fingerprint', 'error'], $schema->columnNames);
        $this->assertSame(['id'], $schema->primaryKey);
        $this->assertColumn('{{%project_vault_entry}}', 'project_id', 'integer', null, false, null);
        $this->assertColumn('{{%project_vault_entry}}', 'path', 'string', 1024, false, null);
        $this->assertColumn('{{%project_vault_entry}}', 'kind', 'string', 8, false, null);
        $this->assertColumn('{{%project_vault_entry}}', 'line', 'integer', null, true, null);
        $this->assertColumn('{{%project_vault_entry}}', 'var_key', 'string', 255, true, null);
        $this->assertColumn('{{%project_vault_entry}}', 'vault_id', 'string', 255, true, null);
        $this->assertColumn('{{%project_vault_entry}}', 'format_version', 'string', 8, true, null);
        $this->assertColumn('{{%project_vault_entry}}', 'fingerprint', 'char', 64, true, null);
        $this->assertColumn('{{%project_vault_entry}}', 'error', 'string', 255, true, null);
        $this->assertTrue($this->tableSchema('{{%project_vault_entry}}')->getColumn('project_id')?->unsigned, 'matches project.id for the foreign key');
        $this->assertIndex('{{%project_vault_entry}}', 'idx_project_vault_entry_project', ['project_id']);
        $this->assertSame(['fk_project_vault_entry_project' => ['project', 'project_id' => 'id']], $schema->foreignKeys);
    }

    public function testTheVaultCheckTableHasOneRowPerTemplate(): void
    {
        $schema = $this->tableSchema('{{%job_template_vault_check}}');

        $this->assertSame(['job_template_id', 'status', 'credential_id', 'relevant_count', 'unopened_count', 'unopened', 'incomplete_reason', 'checked_at', 'scanned_at'], $schema->columnNames);
        $this->assertSame(['job_template_id'], $schema->primaryKey);
        $this->assertColumn('{{%job_template_vault_check}}', 'status', 'string', 32, false, null);
        $this->assertColumn('{{%job_template_vault_check}}', 'credential_id', 'integer', null, true, null);
        $this->assertColumn('{{%job_template_vault_check}}', 'relevant_count', 'integer', null, false, 0);
        $this->assertColumn('{{%job_template_vault_check}}', 'unopened', 'text', null, true, null);
        $this->assertColumn('{{%job_template_vault_check}}', 'incomplete_reason', 'string', 16, true, null);
        $this->assertColumn('{{%job_template_vault_check}}', 'checked_at', 'integer', null, false, null);
        $this->assertColumn('{{%job_template_vault_check}}', 'scanned_at', 'integer', null, true, null);
        $this->assertIndex('{{%job_template_vault_check}}', 'idx_job_template_vault_check_status', ['status']);
        $foreignKeys = $schema->foreignKeys;
        ksort($foreignKeys);
        $this->assertSame([
            'fk_job_template_vault_check_credential' => ['credential', 'credential_id' => 'id'],
            'fk_job_template_vault_check_template' => ['job_template', 'job_template_id' => 'id'],
        ], $foreignKeys);
        $this->assertSame([
            'fk_job_template_vault_check_credential' => 'SET NULL',
            'fk_job_template_vault_check_template' => 'CASCADE',
            'fk_project_vault_entry_project' => 'CASCADE',
        ], $this->deleteRules(\Yii::$app->db));
    }

    /**
     * Rows written after the migration without the column are 'Ansilume
     * only'; the 'repository' backfill was for the projects that existed.
     */
    public function testProjectsCreatedAfterTheMigrationAreAnsilumeOnly(): void
    {
        $userId = (int)$this->createUser('migration')->id;
        $db = \Yii::$app->db;
        $db->createCommand()->insert('{{%project}}', [
            'name' => 'migration-new-' . uniqid(),
            'scm_type' => Project::SCM_TYPE_MANUAL,
            'scm_branch' => 'main',
            'status' => Project::STATUS_NEW,
            'created_by' => $userId,
            'created_at' => time(),
            'updated_at' => time(),
        ])->execute();
        $id = (int)$db->getLastInsertID();

        $this->assertSame(
            Project::VAULT_SOURCE_ANSILUME,
            $db->createCommand('SELECT vault_password_source FROM {{%project}} WHERE id = :id', [':id' => $id])->queryScalar()
        );
    }

    public function testDeletingAProjectDeletesItsVaultEntries(): void
    {
        $userId = (int)$this->createUser('migration')->id;
        $project = $this->createProject($userId);
        $kept = $this->createProject($userId);
        $entry = $this->vaultEntry((int)$project->id);
        $other = $this->vaultEntry((int)$kept->id);

        \Yii::$app->db->createCommand()->delete('{{%project}}', ['id' => $project->id])->execute();

        $this->assertNull(ProjectVaultEntry::findOne($entry->id));
        $this->assertNotNull(ProjectVaultEntry::findOne($other->id), 'only the deleted project\'s entries go');
    }

    public function testDeletingTheCredentialDetachesTheCheckAndDeletingTheTemplateRemovesIt(): void
    {
        $userId = (int)$this->createUser('migration')->id;
        $template = $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
        $credential = $this->createCredential($userId, Credential::TYPE_VAULT);
        $db = \Yii::$app->db;
        $db->createCommand()->insert('{{%job_template_vault_check}}', [
            'job_template_id' => $template->id,
            'status' => JobTemplateVaultCheck::STATUS_OK,
            'credential_id' => $credential->id,
            'checked_at' => time(),
        ])->execute();
        $check = JobTemplateVaultCheck::findOne($template->id);
        $this->assertNotNull($check);
        $this->assertSame(0, (int)$check->relevant_count, 'relevant_count defaults to 0');

        $db->createCommand()->delete('{{%credential}}', ['id' => $credential->id])->execute();
        $check->refresh();
        $this->assertNull($check->credential_id, 'the check outlives its credential');
        $this->assertSame(JobTemplateVaultCheck::STATUS_OK, $check->status);

        $db->createCommand()->delete('{{%job_template}}', ['id' => $template->id])->execute();
        $this->assertNull(JobTemplateVaultCheck::findOne($template->id));
    }

    // -- safeUp()/safeDown() with recorded schema changes ---------------------

    /**
     * Projects that exist when the migration runs keep applying the
     * repository's ansible.cfg vault settings, as before the release.
     */
    public function testUpRecordsExistingProjectsAsRepository(): void
    {
        $userId = (int)$this->createUser('migration')->id;
        $existing = [$this->createProject($userId), $this->createProject($userId)];
        $migration = $this->recordingMigration();

        $this->quietly(static fn () => $migration->safeUp());

        foreach ($existing as $project) {
            $project->refresh();
            $this->assertSame(Project::VAULT_SOURCE_REPOSITORY, $project->vault_password_source);
        }
        $updates = array_keys(array_filter($migration->calls, static fn (array $call): bool => $call[0] === 'update'));
        $this->assertCount(1, $updates, 'one backfill');
        $this->assertSame(['update', '{{%project}}', ['vault_password_source' => Project::VAULT_SOURCE_REPOSITORY]], $migration->calls[$updates[0]]);
        $added = array_search(['addColumn', '{{%project}}', 'vault_password_source'], $this->objectCalls($migration->calls), true);
        $this->assertIsInt($added);
        $this->assertLessThan($updates[0], $added, 'the backfill needs the column');
        $this->assertStringContainsString("NOT NULL DEFAULT 'ansilume'", $this->columnDefinition($migration->calls, '{{%project}}', 'vault_password_source'));
    }

    /**
     * Down removes exactly what up added, newest first; the indexes and
     * foreign keys up creates all belong to the tables down drops. Every
     * object up adds exists in the migrated test database.
     */
    public function testDownRemovesWhatUpAdded(): void
    {
        $migration = $this->recordingMigration();
        $this->quietly(static fn () => $migration->safeUp());
        $up = $migration->calls;
        $migration->calls = [];
        $this->quietly(static fn () => $migration->safeDown());
        $down = $migration->calls;

        $added = array_values(array_filter($this->objectCalls($up), static fn (array $call): bool => in_array($call[0], ['addColumn', 'createTable'], true)));
        $removed = $this->objectCalls($down);
        $inverse = ['addColumn' => 'dropColumn', 'createTable' => 'dropTable'];
        $this->assertSame(
            array_reverse(array_map(static fn (array $call): array => array_merge([$inverse[$call[0]]], array_slice($call, 1)), $added)),
            $removed
        );
        $this->assertSame(
            [
                ['addColumn', '{{%project}}', 'vault_password_source'],
                ['addColumn', '{{%project}}', 'vault_scanned_at'],
                ['addColumn', '{{%project}}', 'vault_scan_commit'],
                ['addColumn', '{{%project}}', 'vault_scan_summary'],
                ['addColumn', '{{%project}}', 'vault_scan_error'],
                ['createTable', '{{%project_vault_entry}}'],
                ['createTable', '{{%job_template_vault_check}}'],
                ['addColumn', '{{%runner}}', 'capabilities'],
            ],
            $added
        );

        $createdTables = array_column(array_filter($added, static fn (array $call): bool => $call[0] === 'createTable'), 1);
        foreach ($up as $call) {
            if (in_array($call[0], ['createIndex', 'addPrimaryKey', 'addForeignKey'], true)) {
                $this->assertContains($call[1], $createdTables, "{$call[0]} {$call[2]} goes with its table");
            }
        }
        foreach ($added as $call) {
            $schema = $this->tableSchema($call[1]);
            if ($call[0] === 'addColumn') {
                $this->assertNotNull($schema->getColumn($call[2]), "{$call[1]}.{$call[2]} exists in the test database");
            }
        }
    }

    // -- A real round trip in the scratch database ----------------------------

    public function testUpAndDownRunAgainstARealSchema(): void
    {
        $db = $this->scratchDatabase();
        try {
            $this->createTablesBeforeTheMigration($db);
            $db->createCommand()->batchInsert('{{%project}}', ['name'], [['existing-1'], ['existing-2']])->execute();
            $migration = new \m000076_000000_add_vault_scan(['db' => $db, 'compact' => true]);

            $this->quietly(static fn () => $migration->safeUp());

            $this->assertSame(
                [Project::VAULT_SOURCE_REPOSITORY, Project::VAULT_SOURCE_REPOSITORY],
                $db->createCommand('SELECT vault_password_source FROM {{%project}} ORDER BY id')->queryColumn(),
                'existing projects keep the repository settings'
            );
            $db->createCommand()->insert('{{%project}}', ['name' => 'created-later'])->execute();
            $this->assertSame(
                Project::VAULT_SOURCE_ANSILUME,
                $db->createCommand("SELECT vault_password_source FROM {{%project}} WHERE name = 'created-later'")->queryScalar()
            );
            $this->assertSame(array_merge(['id', 'name'], self::PROJECT_COLUMNS), $this->columnNames($db, '{{%project}}'));
            $this->assertSame(['id', 'name', 'capabilities'], $this->columnNames($db, '{{%runner}}'));
            $this->assertNotNull($db->getTableSchema('{{%project_vault_entry}}', true));
            $this->assertNotNull($db->getTableSchema('{{%job_template_vault_check}}', true));
            $this->assertSame([
                'fk_job_template_vault_check_credential' => 'SET NULL',
                'fk_job_template_vault_check_template' => 'CASCADE',
                'fk_project_vault_entry_project' => 'CASCADE',
            ], $this->deleteRules($db));

            $this->quietly(static fn () => $migration->safeDown());

            $this->assertSame(['id', 'name'], $this->columnNames($db, '{{%project}}'));
            $this->assertSame(['id', 'name'], $this->columnNames($db, '{{%runner}}'));
            $this->assertNull($db->getTableSchema('{{%project_vault_entry}}', true));
            $this->assertNull($db->getTableSchema('{{%job_template_vault_check}}', true));
            $this->assertSame([], $this->deleteRules($db));
            $this->assertSame(
                ['existing-1', 'existing-2', 'created-later'],
                $db->createCommand('SELECT name FROM {{%project}} ORDER BY id')->queryColumn(),
                'down drops the vault data, never a project'
            );

            // Forward again after a rollback.
            $this->quietly(static fn () => $migration->safeUp());
            $this->assertSame(array_merge(['id', 'name'], self::PROJECT_COLUMNS), $this->columnNames($db, '{{%project}}'));
        } finally {
            $db->close();
            $this->dropScratchDatabase();
        }
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * The migration with its schema changes recorded in $calls instead of
     * executed; data changes (update) are recorded and still run, inside the
     * test transaction.
     */
    private function recordingMigration(): \m000076_000000_add_vault_scan
    {
        return new class (['db' => \Yii::$app->db, 'compact' => true]) extends \m000076_000000_add_vault_scan {
            /** @var list<array<int, mixed>> */
            public array $calls = [];

            public function addColumn($table, $column, $type)
            {
                $this->calls[] = ['addColumn', $table, $column, (string)$type];
            }

            public function dropColumn($table, $column)
            {
                $this->calls[] = ['dropColumn', $table, $column];
            }

            public function createTable($table, $columns, $options = null)
            {
                $this->calls[] = ['createTable', $table, array_keys($columns)];
            }

            public function dropTable($table)
            {
                $this->calls[] = ['dropTable', $table];
            }

            public function createIndex($name, $table, $columns, $unique = false)
            {
                $this->calls[] = ['createIndex', $table, $name];
            }

            public function addPrimaryKey($name, $table, $columns)
            {
                $this->calls[] = ['addPrimaryKey', $table, $name];
            }

            public function addForeignKey($name, $table, $columns, $refTable, $refColumns, $delete = null, $update = null)
            {
                $this->calls[] = ['addForeignKey', $table, $name, $refTable, $delete];
            }

            public function update($table, $columns, $condition = '', $params = [])
            {
                $this->calls[] = ['update', $table, $columns];
                parent::update($table, $columns, $condition, $params);
            }
        };
    }

    /**
     * The recorded calls that add or remove a column or table, as
     * [method, table(, column)].
     *
     * @param list<array<int, mixed>> $calls
     * @return list<array<int, mixed>>
     */
    private function objectCalls(array $calls): array
    {
        $objects = [];
        foreach ($calls as $call) {
            if (in_array($call[0], ['addColumn', 'dropColumn'], true)) {
                $objects[] = [$call[0], $call[1], $call[2]];
            } elseif (in_array($call[0], ['createTable', 'dropTable'], true)) {
                $objects[] = [$call[0], $call[1]];
            }
        }

        return $objects;
    }

    /**
     * @param list<array<int, mixed>> $calls
     */
    private function columnDefinition(array $calls, string $table, string $column): string
    {
        foreach ($calls as $call) {
            if ($call[0] === 'addColumn' && $call[1] === $table && $call[2] === $column) {
                return (string)$call[3];
            }
        }
        $this->fail("{$table}.{$column} is not added");
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

    private function tableSchema(string $table): \yii\db\TableSchema
    {
        $schema = \Yii::$app->db->getTableSchema($table, true);
        $this->assertNotNull($schema, "{$table} exists");

        return $schema;
    }

    private function assertColumn(string $table, string $name, string $type, ?int $size, bool $nullable, mixed $default): void
    {
        $column = $this->tableSchema($table)->getColumn($name);
        $this->assertNotNull($column, "{$table}.{$name} exists");
        $this->assertSame($type, $column->type, "{$table}.{$name} type");
        if ($size !== null) {
            $this->assertSame($size, $column->size, "{$table}.{$name} size");
        }
        $this->assertSame($nullable, $column->allowNull, "{$table}.{$name} nullable");
        $this->assertSame($default, $column->defaultValue, "{$table}.{$name} default");
    }

    /**
     * @param list<string> $columns
     */
    private function assertIndex(string $table, string $name, array $columns): void
    {
        $indexes = [];
        foreach (\Yii::$app->db->getSchema()->getTableIndexes($table, true) as $index) {
            $indexes[(string)$index->name] = $index->columnNames;
        }
        $this->assertArrayHasKey($name, $indexes, "{$table} has index {$name}");
        $this->assertSame($columns, $indexes[$name]);
    }

    /**
     * ON DELETE of the migration's foreign keys, by constraint name.
     *
     * @return array<string, string>
     */
    private function deleteRules(Connection $db): array
    {
        $rows = $db->createCommand(
            'SELECT CONSTRAINT_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS'
            . ' WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN (:entries, :checks) ORDER BY CONSTRAINT_NAME',
            [':entries' => $db->getSchema()->getRawTableName('{{%project_vault_entry}}'), ':checks' => $db->getSchema()->getRawTableName('{{%job_template_vault_check}}')]
        )->queryAll();

        return array_column($rows, 'DELETE_RULE', 'CONSTRAINT_NAME');
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

    private function vaultEntry(int $projectId): ProjectVaultEntry
    {
        $entry = new ProjectVaultEntry();
        $entry->project_id = $projectId;
        $entry->path = 'vars/secrets.yml';
        $entry->kind = ProjectVaultEntry::KIND_FILE;
        $entry->format_version = '1.1';
        $entry->fingerprint = hash('sha256', 'vars/secrets.yml');
        $entry->save(false);

        return $entry;
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

    /**
     * The tables the migration changes or references, reduced to what it
     * needs: unsigned integer ids like the real ones, for the foreign keys.
     */
    private function createTablesBeforeTheMigration(Connection $db): void
    {
        $id = 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $db->createCommand()->createTable('{{%project}}', ['id' => $id, 'name' => 'VARCHAR(128) NOT NULL'], $options)->execute();
        $db->createCommand()->createTable('{{%job_template}}', ['id' => $id], $options)->execute();
        $db->createCommand()->createTable('{{%credential}}', ['id' => $id], $options)->execute();
        $db->createCommand()->createTable('{{%runner}}', ['id' => $id, 'name' => 'VARCHAR(128) NOT NULL'], $options)->execute();
    }
}
