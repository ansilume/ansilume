<?php

declare(strict_types=1);

use yii\db\Migration;
use yii\db\Query;

/**
 * Keeps the history of workflow runs when their steps change, and gives a
 * run a place for the extra vars it was launched with.
 *
 * workflow_job_step, one row per step a run executed:
 * - workflow_step_id becomes nullable, and its foreign key sets it to NULL
 *   when the step is deleted. It used to delete the row (ON DELETE CASCADE):
 *   replacing the steps of a workflow (API PUT with steps) or removing one
 *   (web) deleted the executions of every run, finished or running.
 * - step_name and step_type: the step's name and type when it ran, copied
 *   from workflow_step for the existing rows (a legacy type such as JOB is
 *   copied as stored), so a run still shows its steps once they are gone.
 *
 * workflow_job:
 * - extra_vars (TEXT, a JSON object): the extra vars the run was launched
 *   with, the password answers replaced by $encrypted$;
 * - secret_extra_vars (MEDIUMTEXT): those password answers, sealed like
 *   job.secret_extra_vars (m000080).
 *
 * up()/down(), not safeUp(): DDL commits implicitly, so a transaction would
 * not undo a half-applied run. Every part checks the current state first
 * instead: a run that failed half way finishes when it is retried, and
 * running it again changes nothing.
 *
 * down() restores NOT NULL and ON DELETE CASCADE. That schema cannot hold
 * the executions of deleted steps (workflow_step_id NULL), so down() deletes
 * them, as the old foreign key did with their steps, and prints how many.
 */
class m000084_000000_keep_workflow_run_history extends Migration
{
    private const STEPS = '{{%workflow_step}}';
    private const EXECUTIONS = '{{%workflow_job_step}}';
    private const RUNS = '{{%workflow_job}}';
    private const STEP_KEY = 'fk-wjs-workflow_step';
    private const STEP_ID = 'workflow_step_id';

    public function up(): void
    {
        $this->addMissingColumn(self::EXECUTIONS, 'step_name', $this->string(128)->null()->comment('workflow_step.name when the step ran'));
        $this->addMissingColumn(self::EXECUTIONS, 'step_type', $this->string(16)->null()->comment('workflow_step.step_type when the step ran'));
        $this->backfillSnapshots();
        $this->keepExecutionsOfDeletedSteps();
        $this->addMissingColumn(self::RUNS, 'extra_vars', $this->text()->null()->comment('JSON'));
        $this->addMissingColumn(self::RUNS, 'secret_extra_vars', 'MEDIUMTEXT NULL');
    }

    public function down(): void
    {
        $this->dropExistingColumn(self::RUNS, 'secret_extra_vars');
        $this->dropExistingColumn(self::RUNS, 'extra_vars');
        $this->deleteExecutionsWithDeletedSteps();
        $this->dropExistingColumn(self::EXECUTIONS, 'step_type');
        $this->dropExistingColumn(self::EXECUTIONS, 'step_name');
    }

    /**
     * Copy the name and type of the step into the executions that have no
     * copy yet; every execution still has its step at this point.
     */
    private function backfillSnapshots(): void
    {
        $this->execute(
            'UPDATE {{%workflow_job_step}} wjs INNER JOIN {{%workflow_step}} ws ON ws.[[id]] = wjs.[[workflow_step_id]]'
            . ' SET wjs.[[step_name]] = COALESCE(wjs.[[step_name]], ws.[[name]]),'
            . ' wjs.[[step_type]] = COALESCE(wjs.[[step_type]], ws.[[step_type]])'
            . ' WHERE wjs.[[step_name]] IS NULL OR wjs.[[step_type]] IS NULL'
        );
    }

    /**
     * workflow_step_id NULL instead of a deleted row: drop the key, make the
     * column nullable, add the key back with ON DELETE SET NULL (MariaDB
     * changes a foreign key only by dropping and adding it).
     */
    private function keepExecutionsOfDeletedSteps(): void
    {
        $rule = $this->stepKeyDeleteRule();
        if ($rule === 'SET NULL') {
            return;
        }
        if ($rule !== null) {
            $this->dropForeignKey(self::STEP_KEY, self::EXECUTIONS);
        }
        if (!$this->stepIdIsNullable()) {
            $this->alterColumn(self::EXECUTIONS, self::STEP_ID, $this->integer()->unsigned()->null());
        }
        // Steps deleted while a failed run had left the table without its
        // key: SET NULL, as the key would have done.
        $this->update(self::EXECUTIONS, [self::STEP_ID => null], ['and', ['not', [self::STEP_ID => null]], ['not exists', $this->stepOfExecution()]]);
        $this->addForeignKey(self::STEP_KEY, self::EXECUTIONS, self::STEP_ID, self::STEPS, 'id', 'SET NULL', 'CASCADE');
    }

    /**
     * Back to NOT NULL and ON DELETE CASCADE, without the executions whose
     * step was deleted.
     */
    private function deleteExecutionsWithDeletedSteps(): void
    {
        $rule = $this->stepKeyDeleteRule();
        if ($rule === 'CASCADE' && !$this->stepIdIsNullable()) {
            return;
        }
        if ($rule !== null) {
            $this->dropForeignKey(self::STEP_KEY, self::EXECUTIONS);
        }
        $deleted = $this->db->createCommand()->delete(
            self::EXECUTIONS,
            ['or', [self::STEP_ID => null], ['not exists', $this->stepOfExecution()]]
        )->execute();
        echo "    > Deleted {$deleted} execution(s) of deleted workflow steps: the restored schema cannot hold them.\n";
        if ($this->stepIdIsNullable()) {
            $this->alterColumn(self::EXECUTIONS, self::STEP_ID, $this->integer()->unsigned()->notNull());
        }
        $this->addForeignKey(self::STEP_KEY, self::EXECUTIONS, self::STEP_ID, self::STEPS, 'id', 'CASCADE', 'CASCADE');
    }

    /**
     * The step of the execution in the outer statement.
     */
    private function stepOfExecution(): Query
    {
        return (new Query())
            ->from(['ws' => self::STEPS])
            ->where('ws.[[id]] = {{%workflow_job_step}}.[[workflow_step_id]]');
    }

    /**
     * ON DELETE of the step's foreign key, or null when there is none.
     */
    private function stepKeyDeleteRule(): ?string
    {
        $rule = $this->db->createCommand(
            'SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS'
            . ' WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = :table AND CONSTRAINT_NAME = :name',
            [':table' => $this->db->getSchema()->getRawTableName(self::EXECUTIONS), ':name' => self::STEP_KEY]
        )->queryScalar();

        return is_string($rule) ? $rule : null;
    }

    private function stepIdIsNullable(): bool
    {
        return $this->db->getTableSchema(self::EXECUTIONS, true)?->getColumn(self::STEP_ID)?->allowNull === true;
    }

    /**
     * @param string|\yii\db\ColumnSchemaBuilder $type
     */
    private function addMissingColumn(string $table, string $column, $type): void
    {
        if (!$this->hasColumn($table, $column)) {
            $this->addColumn($table, $column, $type);
        }
    }

    private function dropExistingColumn(string $table, string $column): void
    {
        if ($this->hasColumn($table, $column)) {
            $this->dropColumn($table, $column);
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->getTableSchema($table, true)?->getColumn($column) !== null;
    }
}
