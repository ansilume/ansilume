<?php

declare(strict_types=1);

namespace app\tests\integration\migrations;

use app\models\JobTemplate;
use app\models\WorkflowTemplate;
use app\tests\integration\DbTestCase;
use yii\db\IntegrityException;

/**
 * m000077_000000_add_workflow_team_scoping: workflow_job_step.error_message
 * and the trigger_token_created_by columns of workflow_template and
 * job_template.
 *
 * DDL commits implicitly in MySQL/MariaDB, so the schema the real migration
 * produced in the test database is checked as it is, and safeUp()/safeDown()
 * run with their schema changes recorded instead of executed.
 */
class AddWorkflowTeamScopingMigrationTest extends DbTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/m000077_000000_add_workflow_team_scoping.php';
    }

    public function testWorkflowJobStepRecordsWhyAStepFailed(): void
    {
        $column = $this->column('{{%workflow_job_step}}', 'error_message');

        $this->assertSame('string', $column->type);
        $this->assertSame(500, $column->size);
        $this->assertTrue($column->allowNull);
        $this->assertNull($column->defaultValue);
    }

    public function testTemplatesRecordWhoGeneratedTheTriggerToken(): void
    {
        foreach (['workflow_template', 'job_template'] as $table) {
            $column = $this->column("{{%{$table}}}", 'trigger_token_created_by');
            $this->assertSame('integer', $column->type, $table);
            $this->assertTrue($column->unsigned, "{$table}: matches user.id for the foreign key");
            $this->assertTrue($column->allowNull, $table);
            $this->assertNull($column->defaultValue, $table);
            $this->assertSame(
                ['RESTRICT', 'user'],
                $this->foreignKey($table, "fk-{$table}-trigger_token_created_by"),
                "{$table}: deleting the token creator is restricted like every created_by"
            );
        }
    }

    public function testATokenCreatorCannotBeDeletedWhileTheTokenExists(): void
    {
        $owner = $this->createUser('fk_owner');
        $creator = $this->createUser('fk_creator');
        $workflow = $this->createWorkflowTemplate($owner->id);
        $workflow->generateTriggerToken($creator->id);

        try {
            $creator->delete();
            $this->fail('expected the foreign key to restrict the delete');
        } catch (IntegrityException) {
            $this->assertNotNull(WorkflowTemplate::findOne($workflow->id));
        }

        $workflow->revokeTriggerToken();
        $this->assertSame(1, $creator->delete());
        $this->assertNull(JobTemplate::findOne(['trigger_token_created_by' => $creator->id]));
    }

    public function testDownRemovesWhatUpAdded(): void
    {
        $migration = $this->recordingMigration();
        $migration->safeUp();
        $up = $migration->calls;
        $migration->calls = [];
        $migration->safeDown();
        $down = $migration->calls;

        $this->assertSame([
            ['addColumn', '{{%workflow_job_step}}', 'error_message'],
            ['addColumn', '{{%workflow_template}}', 'trigger_token_created_by'],
            ['addForeignKey', '{{%workflow_template}}', 'fk-workflow_template-trigger_token_created_by'],
            ['addColumn', '{{%job_template}}', 'trigger_token_created_by'],
            ['addForeignKey', '{{%job_template}}', 'fk-job_template-trigger_token_created_by'],
        ], $up);
        $this->assertSame([
            ['dropForeignKey', '{{%job_template}}', 'fk-job_template-trigger_token_created_by'],
            ['dropColumn', '{{%job_template}}', 'trigger_token_created_by'],
            ['dropForeignKey', '{{%workflow_template}}', 'fk-workflow_template-trigger_token_created_by'],
            ['dropColumn', '{{%workflow_template}}', 'trigger_token_created_by'],
            ['dropColumn', '{{%workflow_job_step}}', 'error_message'],
        ], $down);
    }

    private function column(string $table, string $name): \yii\db\ColumnSchema
    {
        $schema = \Yii::$app->db->getTableSchema($table, true);
        $this->assertNotNull($schema, "{$table} exists");
        $column = $schema->getColumn($name);
        $this->assertNotNull($column, "{$table}.{$name} exists");
        return $column;
    }

    /**
     * @return array{0: string, 1: string} delete rule and referenced table
     */
    private function foreignKey(string $table, string $name): array
    {
        $db = \Yii::$app->db;
        $row = $db->createCommand(
            'SELECT DELETE_RULE, REFERENCED_TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS'
            . ' WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = :table AND CONSTRAINT_NAME = :name',
            [':table' => $db->getSchema()->getRawTableName("{{%{$table}}}"), ':name' => $name]
        )->queryOne();
        $this->assertIsArray($row, "{$name} exists");
        return [(string)$row['DELETE_RULE'], (string)$row['REFERENCED_TABLE_NAME']];
    }

    private function recordingMigration(): \m000077_000000_add_workflow_team_scoping
    {
        return new class (['db' => \Yii::$app->db, 'compact' => true]) extends \m000077_000000_add_workflow_team_scoping {
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
}
