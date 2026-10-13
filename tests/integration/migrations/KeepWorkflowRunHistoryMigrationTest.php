<?php

declare(strict_types=1);

namespace app\tests\integration\migrations;

use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\tests\integration\DbTestCase;
use yii\db\Connection;

/**
 * m000084_000000_keep_workflow_run_history: workflow_job_step keeps its rows
 * when their step is deleted (workflow_step_id NULL, foreign key ON DELETE
 * SET NULL) and the step's name and type (step_name, step_type, backfilled);
 * workflow_job gets extra_vars and secret_extra_vars.
 *
 * DDL commits implicitly in MySQL/MariaDB, so:
 *  - the schema the real migration produced in the test database is checked
 *    as it is, and up() runs there with its schema changes recorded instead
 *    of executed (the backfill runs for real, inside the test transaction);
 *  - up and down run for real against an empty scratch database,
 *    ansilume_migrate_test, which bin/tests-phpunit.sh creates and lets the
 *    database user manage (without that grant those tests are skipped),
 *    with a run that exists before the migration and whose step is deleted
 *    afterwards, and with every state a failed run can leave behind.
 */
class KeepWorkflowRunHistoryMigrationTest extends DbTestCase
{
    private const SCRATCH_DB = 'ansilume_migrate_test';
    private const STEP_KEY = 'fk-wjs-workflow_step';

    /** The columns of the scratch tables before the migration. */
    private const EXECUTION_COLUMNS = ['id', 'workflow_job_id', 'workflow_step_id', 'status'];
    private const RUN_COLUMNS = ['id', 'status'];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/m000084_000000_keep_workflow_run_history.php';
    }

    // -- The migrated test database -------------------------------------------

    public function testTheExecutionKeepsItsStepAsANullableReferenceWithASnapshot(): void
    {
        $db = \Yii::$app->db;
        $stepId = $this->column($db, '{{%workflow_job_step}}', 'workflow_step_id');
        $this->assertTrue($stepId->allowNull);
        $this->assertTrue($stepId->unsigned, 'matches workflow_step.id for the foreign key');
        $this->assertSame(['SET NULL', 'CASCADE', 'workflow_step'], $this->stepKey($db));

        foreach (['step_name' => 128, 'step_type' => 16] as $name => $size) {
            $column = $this->column($db, '{{%workflow_job_step}}', $name);
            $this->assertSame('string', $column->type, $name);
            $this->assertSame($size, $column->size, "{$name}: as long as the workflow_step column it copies");
            $this->assertTrue($column->allowNull, $name);
            $this->assertNull($column->defaultValue, $name);
        }
    }

    public function testTheRunHasColumnsForItsLaunchExtraVars(): void
    {
        $db = \Yii::$app->db;
        $public = $this->column($db, '{{%workflow_job}}', 'extra_vars');
        $this->assertSame('text', strtolower($public->dbType));
        $this->assertTrue($public->allowNull);
        $sealed = $this->column($db, '{{%workflow_job}}', 'secret_extra_vars');
        $this->assertSame('mediumtext', strtolower($sealed->dbType), 'room for the sealed answers, as job.secret_extra_vars');
        $this->assertTrue($sealed->allowNull);
        $this->assertNull($sealed->defaultValue);
    }

    /**
     * On a migrated database up() changes no schema, and its backfill fills
     * only executions without a snapshot.
     */
    public function testUpOnAMigratedDatabaseOnlyFillsMissingSnapshots(): void
    {
        $userId = (int)$this->createUser('m84')->id;
        $workflow = $this->createWorkflowTemplate($userId);
        $pause = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $run = new WorkflowJob();
        $run->workflow_template_id = (int)$workflow->id;
        $run->launched_by = $userId;
        $run->status = WorkflowJob::STATUS_RUNNING;
        $this->assertTrue($run->save(false));
        $missing = $this->execution((int)$run->id, (int)$pause->id);
        $kept = $this->execution((int)$run->id, (int)$pause->id);
        WorkflowJobStep::updateAll(['step_name' => null, 'step_type' => null], ['id' => $missing->id]);
        WorkflowJobStep::updateAll(['step_name' => 'renamed since'], ['id' => $kept->id]);
        $migration = $this->recordingMigration();

        $this->quietly(static fn () => $migration->up());

        $this->assertSame([], $migration->calls, 'no schema change');
        $this->assertSame(['step-0', WorkflowStep::TYPE_PAUSE], $this->snapshotOf((int)$missing->id));
        $this->assertSame(['renamed since', WorkflowStep::TYPE_PAUSE], $this->snapshotOf((int)$kept->id), 'a snapshot is never overwritten');
    }

    // -- Real runs in the scratch database ------------------------------------

    /**
     * The run that existed before the migration keeps the execution of a
     * step deleted afterwards, with the step's name and type. The regression
     * itself (ON DELETE CASCADE deleted the history) is pinned on the real
     * schema by WorkflowJobStepHistoryTest.
     */
    public function testUpKeepsTheHistoryOfARunWhoseStepIsDeletedLater(): void
    {
        $db = $this->scratchDatabase();
        try {
            $this->createTablesBeforeTheMigration($db);
            $rows = $this->runWithExecutions($db);

            $this->quietly(fn () => $this->migration($db)->up());

            $this->assertMigrated($db);
            $this->assertSame(
                [['deploy', 'job'], ['approve', 'approval'], ['legacy', 'JOB']],
                $this->snapshots($db),
                'backfilled from the steps, legacy types as stored'
            );

            $db->createCommand()->delete('{{%workflow_step}}', ['id' => $rows['steps'][0]])->execute();

            $this->assertSame(
                [[null, 'deploy', 'job'], [$rows['steps'][1], 'approve', 'approval'], [$rows['steps'][2], 'legacy', 'JOB']],
                $this->executions($db),
                'the execution of the deleted step is kept'
            );
        } finally {
            $db->close();
            $this->dropScratchDatabase();
        }
    }

    public function testRunningUpAgainChangesNothing(): void
    {
        $db = $this->scratchDatabase();
        try {
            $this->createTablesBeforeTheMigration($db);
            $this->runWithExecutions($db);
            $this->quietly(fn () => $this->migration($db)->up());
            $before = $this->executions($db);

            $this->quietly(fn () => $this->migration($db)->up());

            $this->assertMigrated($db);
            $this->assertSame($before, $this->executions($db));
        } finally {
            $db->close();
            $this->dropScratchDatabase();
        }
    }

    /**
     * down() restores NOT NULL and ON DELETE CASCADE and deletes the
     * executions of deleted steps, which that schema cannot hold; up() works
     * again afterwards.
     */
    public function testDownRestoresTheCascadeWithoutTheExecutionsOfDeletedSteps(): void
    {
        $db = $this->scratchDatabase();
        try {
            $this->createTablesBeforeTheMigration($db);
            $rows = $this->runWithExecutions($db);
            $this->quietly(fn () => $this->migration($db)->up());
            $db->createCommand()->delete('{{%workflow_step}}', ['id' => $rows['steps'][0]])->execute();

            $output = $this->captureOutput(fn () => $this->migration($db)->down());

            $this->assertStringContainsString('Deleted 1 execution(s) of deleted workflow steps', $output);
            $this->assertNotMigrated($db);
            $this->assertSame([$rows['steps'][1], $rows['steps'][2]], $this->stepIds($db));

            $again = $this->captureOutput(fn () => $this->migration($db)->down());
            $this->assertStringNotContainsString('Deleted', $again, 'a second down() changes nothing');
            $this->assertNotMigrated($db);

            $this->quietly(fn () => $this->migration($db)->up());
            $this->assertMigrated($db);
        } finally {
            $db->close();
            $this->dropScratchDatabase();
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function halfAppliedUpProvider(): array
    {
        return [
            'the columns were added, the key is still CASCADE' => ['columns'],
            'the key was dropped, the column is still NOT NULL' => ['key dropped'],
            'the column is nullable, the key is missing, a step was deleted meanwhile' => ['nullable without key'],
        ];
    }

    /**
     * A run that failed half way finishes when the container starts again.
     *
     * @dataProvider halfAppliedUpProvider
     */
    public function testARetryAfterAHalfAppliedUpFinishes(string $state): void
    {
        $db = $this->scratchDatabase();
        try {
            $this->createTablesBeforeTheMigration($db);
            $rows = $this->runWithExecutions($db);
            $this->leaveHalfApplied($db, $state, $rows['steps'][2]);

            $this->quietly(fn () => $this->migration($db)->up());

            $this->assertMigrated($db);
            $expected = $state === 'nullable without key' ? [$rows['steps'][0], $rows['steps'][1], null] : $rows['steps'];
            $this->assertSame($expected, $this->stepIds($db), 'every execution is kept');
        } finally {
            $db->close();
            $this->dropScratchDatabase();
        }
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function halfAppliedDownProvider(): array
    {
        return [
            'the key was dropped, the column is still nullable' => [true],
            'the key was dropped, the column is NOT NULL again' => [false],
        ];
    }

    /**
     * @dataProvider halfAppliedDownProvider
     */
    public function testARetryAfterAHalfAppliedDownFinishes(bool $nullable): void
    {
        $db = $this->scratchDatabase();
        try {
            $this->createTablesBeforeTheMigration($db);
            $rows = $this->runWithExecutions($db);
            $this->quietly(fn () => $this->migration($db)->up());
            $db->createCommand()->dropForeignKey(self::STEP_KEY, '{{%workflow_job_step}}')->execute();
            if (!$nullable) {
                $db->createCommand()->alterColumn('{{%workflow_job_step}}', 'workflow_step_id', 'INT(11) UNSIGNED NOT NULL')->execute();
            }

            $this->quietly(fn () => $this->migration($db)->down());

            $this->assertNotMigrated($db);
            $this->assertSame($rows['steps'], $this->stepIds($db));
        } finally {
            $db->close();
            $this->dropScratchDatabase();
        }
    }

    // -- Helpers --------------------------------------------------------------

    private function migration(Connection $db): \m000084_000000_keep_workflow_run_history
    {
        return new \m000084_000000_keep_workflow_run_history(['db' => $db, 'compact' => true]);
    }

    /**
     * The migration on the test database with its schema changes recorded
     * in $calls instead of executed.
     */
    private function recordingMigration(): \m000084_000000_keep_workflow_run_history
    {
        return new class (['db' => \Yii::$app->db, 'compact' => true]) extends \m000084_000000_keep_workflow_run_history {
            /** @var list<array{0: string, 1: string, 2: string}> */
            public array $calls = [];

            public function addColumn($table, $column, $type)
            {
                $this->calls[] = ['addColumn', $table, $column];
            }

            public function dropColumn($table, $column)
            {
                $this->calls[] = ['dropColumn', $table, $column];
            }

            public function alterColumn($table, $column, $type)
            {
                $this->calls[] = ['alterColumn', $table, $column];
            }

            public function addForeignKey($name, $table, $columns, $refTable, $refColumns, $delete = null, $update = null)
            {
                $this->calls[] = ['addForeignKey', $table, $name];
            }

            public function dropForeignKey($name, $table)
            {
                $this->calls[] = ['dropForeignKey', $table, $name];
            }
        };
    }

    /**
     * The tables as m000054 and m000077 left them, reduced to what the
     * migration needs: unsigned ids, both foreign keys ON DELETE CASCADE.
     */
    private function createTablesBeforeTheMigration(Connection $db): void
    {
        $id = 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $db->createCommand()->createTable('{{%workflow_step}}', ['id' => $id, 'name' => 'VARCHAR(128) NOT NULL', 'step_type' => 'VARCHAR(16) NOT NULL'], $options)->execute();
        $db->createCommand()->createTable('{{%workflow_job}}', ['id' => $id, 'status' => "VARCHAR(32) NOT NULL DEFAULT 'running'"], $options)->execute();
        $db->createCommand()->createTable('{{%workflow_job_step}}', [
            'id' => $id,
            'workflow_job_id' => 'INT(11) UNSIGNED NOT NULL',
            'workflow_step_id' => 'INT(11) UNSIGNED NOT NULL',
            'status' => "VARCHAR(32) NOT NULL DEFAULT 'pending'",
        ], $options)->execute();
        $db->createCommand()->addForeignKey('fk-wjs-workflow_job', '{{%workflow_job_step}}', 'workflow_job_id', '{{%workflow_job}}', 'id', 'CASCADE', 'CASCADE')->execute();
        $db->createCommand()->addForeignKey(self::STEP_KEY, '{{%workflow_job_step}}', 'workflow_step_id', '{{%workflow_step}}', 'id', 'CASCADE', 'CASCADE')->execute();
    }

    /**
     * A finished run with one execution of each of three steps: a job step,
     * an approval step and a step of a legacy type.
     *
     * @return array{run: int, steps: list<int>}
     */
    private function runWithExecutions(Connection $db): array
    {
        $steps = [];
        foreach ([['deploy', 'job'], ['approve', 'approval'], ['legacy', 'JOB']] as [$name, $type]) {
            $db->createCommand()->insert('{{%workflow_step}}', ['name' => $name, 'step_type' => $type])->execute();
            $steps[] = (int)$db->getLastInsertID();
        }
        $db->createCommand()->insert('{{%workflow_job}}', ['status' => 'succeeded'])->execute();
        $run = (int)$db->getLastInsertID();
        foreach ($steps as $stepId) {
            $db->createCommand()->insert('{{%workflow_job_step}}', ['workflow_job_id' => $run, 'workflow_step_id' => $stepId, 'status' => 'succeeded'])->execute();
        }

        return ['run' => $run, 'steps' => $steps];
    }

    /**
     * The state a run of up() leaves when it fails at the given point.
     */
    private function leaveHalfApplied(Connection $db, string $state, int $stepToDelete): void
    {
        $db->createCommand()->addColumn('{{%workflow_job_step}}', 'step_name', 'VARCHAR(128) NULL')->execute();
        $db->createCommand()->addColumn('{{%workflow_job_step}}', 'step_type', 'VARCHAR(16) NULL')->execute();
        if ($state === 'columns') {
            return;
        }
        $db->createCommand()->dropForeignKey(self::STEP_KEY, '{{%workflow_job_step}}')->execute();
        if ($state === 'key dropped') {
            return;
        }
        $db->createCommand()->alterColumn('{{%workflow_job_step}}', 'workflow_step_id', 'INT(11) UNSIGNED NULL')->execute();
        // Without the key, nothing touches the executions of a deleted step.
        $db->createCommand()->delete('{{%workflow_step}}', ['id' => $stepToDelete])->execute();
    }

    private function assertMigrated(Connection $db): void
    {
        $this->assertSame(array_merge(self::EXECUTION_COLUMNS, ['step_name', 'step_type']), $this->columnNames($db, '{{%workflow_job_step}}'));
        $this->assertSame(array_merge(self::RUN_COLUMNS, ['extra_vars', 'secret_extra_vars']), $this->columnNames($db, '{{%workflow_job}}'));
        $this->assertTrue($this->column($db, '{{%workflow_job_step}}', 'workflow_step_id')->allowNull);
        $this->assertSame(['SET NULL', 'CASCADE', 'workflow_step'], $this->stepKey($db));
        $this->assertSame('mediumtext', strtolower($this->column($db, '{{%workflow_job}}', 'secret_extra_vars')->dbType));
        $this->assertSame('text', strtolower($this->column($db, '{{%workflow_job}}', 'extra_vars')->dbType));
    }

    private function assertNotMigrated(Connection $db): void
    {
        $this->assertSame(self::EXECUTION_COLUMNS, $this->columnNames($db, '{{%workflow_job_step}}'));
        $this->assertSame(self::RUN_COLUMNS, $this->columnNames($db, '{{%workflow_job}}'));
        $this->assertFalse($this->column($db, '{{%workflow_job_step}}', 'workflow_step_id')->allowNull);
        $this->assertSame(['CASCADE', 'CASCADE', 'workflow_step'], $this->stepKey($db));
    }

    /**
     * ON DELETE, ON UPDATE and the referenced table of the step's foreign
     * key; [] when there is none.
     *
     * @return list<string>
     */
    private function stepKey(Connection $db): array
    {
        $row = $db->createCommand(
            'SELECT DELETE_RULE, UPDATE_RULE, REFERENCED_TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS'
            . ' WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = :table AND CONSTRAINT_NAME = :name',
            [':table' => $db->getSchema()->getRawTableName('{{%workflow_job_step}}'), ':name' => self::STEP_KEY]
        )->queryOne();

        return is_array($row) ? [(string)$row['DELETE_RULE'], (string)$row['UPDATE_RULE'], (string)$row['REFERENCED_TABLE_NAME']] : [];
    }

    /**
     * @return list<array{0: string|null, 1: string|null}>
     */
    private function snapshots(Connection $db): array
    {
        return array_map(
            static fn (array $row): array => [self::textOrNull($row['step_name']), self::textOrNull($row['step_type'])],
            $db->createCommand('SELECT step_name, step_type FROM {{%workflow_job_step}} ORDER BY id')->queryAll()
        );
    }

    /**
     * @return list<array{0: int|null, 1: string|null, 2: string|null}>
     */
    private function executions(Connection $db): array
    {
        return array_map(
            static fn (array $row): array => [
                $row['workflow_step_id'] === null ? null : (int)$row['workflow_step_id'],
                self::textOrNull($row['step_name']),
                self::textOrNull($row['step_type']),
            ],
            $db->createCommand('SELECT workflow_step_id, step_name, step_type FROM {{%workflow_job_step}} ORDER BY id')->queryAll()
        );
    }

    /**
     * @return list<int|null>
     */
    private function stepIds(Connection $db): array
    {
        return array_map(
            static fn (mixed $id): ?int => $id === null ? null : (int)$id,
            $db->createCommand('SELECT workflow_step_id FROM {{%workflow_job_step}} ORDER BY id')->queryColumn()
        );
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function snapshotOf(int $executionId): array
    {
        $row = \Yii::$app->db->createCommand('SELECT step_name, step_type FROM {{%workflow_job_step}} WHERE id = :id', [':id' => $executionId])->queryOne();
        $this->assertIsArray($row);

        return [self::textOrNull($row['step_name']), self::textOrNull($row['step_type'])];
    }

    private function execution(int $runId, int $stepId): WorkflowJobStep
    {
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = $runId;
        $execution->workflow_step_id = $stepId;
        $execution->status = WorkflowJobStep::STATUS_RUNNING;
        $this->assertTrue($execution->save(false));

        return $execution;
    }

    private static function textOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string)$value;
    }

    private function column(Connection $db, string $table, string $name): \yii\db\ColumnSchema
    {
        $column = $db->getTableSchema($table, true)?->getColumn($name);
        $this->assertNotNull($column, "{$table}.{$name} exists");

        return $column;
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
