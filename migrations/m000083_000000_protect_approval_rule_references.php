<?php

declare(strict_types=1);

use yii\db\Connection;
use yii\db\Expression;
use yii\db\Migration;
use yii\db\Query;

/**
 * Protects the approval rules that are in use, and keeps the history of
 * those that are deleted:
 *
 * - job_template.approval_rule_id: ON DELETE SET NULL becomes RESTRICT.
 *   Deleting a rule silently ungated every job template that used it.
 * - workflow_step.approval_rule_id: gets an index and a foreign key
 *   (RESTRICT); it had neither. IDs of rules that no longer exist are
 *   cleared first and listed. Such an approval step fails as before ("The
 *   approval rule of this step no longer exists."), and an admin assigns it
 *   a rule again.
 * - approval_request.approval_rule_id: nullable, and ON DELETE CASCADE
 *   becomes SET NULL. Deleting a rule deleted all its requests with their
 *   decisions, pending ones included; the resolved requests of a deleted
 *   rule now keep their history.
 *
 * ApprovalRuleWriteService refuses to delete a rule that is in use. It locks
 * the rule row while it checks and deletes, and the foreign keys stop an
 * assignment that would slip in anyway.
 *
 * DDL commits implicitly in MySQL and MariaDB, so a transaction would not
 * undo a half-applied run. Each step checks the current schema instead and
 * does only what is missing, so a retry after a failure finishes the work
 * rather than failing at every container start. Neither database changes a
 * foreign key in place: it is dropped and added again, and a run that failed
 * in between adds it on the next start.
 */
class m000083_000000_protect_approval_rule_references extends Migration
{
    private const RULE_TABLE = '{{%approval_rule}}';
    private const TEMPLATE_TABLE = '{{%job_template}}';
    private const STEP_TABLE = '{{%workflow_step}}';
    private const REQUEST_TABLE = '{{%approval_request}}';
    private const COLUMN = 'approval_rule_id';
    private const TEMPLATE_FK = 'fk-job_template-approval_rule';
    private const STEP_FK = 'fk-workflow_step-approval_rule';
    private const STEP_INDEX = 'idx-workflow_step-approval_rule';
    private const REQUEST_FK = 'fk-approval_request-rule';

    public function up(): bool
    {
        $this->clearUnknownRuleIds(self::TEMPLATE_TABLE, 'job template');
        $this->setForeignKey(self::TEMPLATE_FK, self::TEMPLATE_TABLE, 'RESTRICT');

        $this->clearUnknownRuleIds(self::STEP_TABLE, 'workflow step');
        if (!$this->hasIndex(self::STEP_TABLE, self::STEP_INDEX)) {
            $this->createIndex(self::STEP_INDEX, self::STEP_TABLE, self::COLUMN);
        }
        $this->setForeignKey(self::STEP_FK, self::STEP_TABLE, 'RESTRICT');

        $this->keepRequestsOfDeletedRules();

        return true;
    }

    public function down(): bool
    {
        $this->deleteRequestsWithRules();

        if ($this->deleteRuleOf(self::STEP_TABLE, self::STEP_FK) !== null) {
            $this->dropForeignKey(self::STEP_FK, self::STEP_TABLE);
        }
        if ($this->hasIndex(self::STEP_TABLE, self::STEP_INDEX)) {
            $this->dropIndex(self::STEP_INDEX, self::STEP_TABLE);
        }

        $this->setForeignKey(self::TEMPLATE_FK, self::TEMPLATE_TABLE, 'SET NULL');

        return true;
    }

    /**
     * Clears the rule IDs in $table that point to no rule, which the
     * foreign key would refuse, and lists them. job_template had a foreign
     * key all along, so only a run that failed after dropping it can leave
     * any there.
     */
    private function clearUnknownRuleIds(string $table, string $label): void
    {
        $rows = (new Query())
            ->select(['t.id', 't.approval_rule_id'])
            ->from(['t' => $table])
            ->leftJoin(['r' => self::RULE_TABLE], '[[r]].[[id]] = [[t]].[[approval_rule_id]]')
            ->where(['not', ['t.approval_rule_id' => null]])
            ->andWhere(['r.id' => null])
            ->orderBy(['t.id' => SORT_ASC])
            ->all($this->connection());
        if ($rows === []) {
            return;
        }
        $this->update($table, [self::COLUMN => null], ['id' => array_column($rows, 'id')]);
        foreach ($rows as $row) {
            echo "    > Cleared approval rule #{$row['approval_rule_id']}, which does not exist, from {$label} #{$row['id']}.\n";
        }
    }

    /**
     * Makes $name the foreign key of $table.approval_rule_id to
     * approval_rule with ON DELETE $onDelete, unless it is already.
     */
    private function setForeignKey(string $name, string $table, string $onDelete): void
    {
        $current = $this->deleteRuleOf($table, $name);
        if ($current === $onDelete) {
            return;
        }
        if ($current !== null) {
            $this->dropForeignKey($name, $table);
        }
        $this->addForeignKey($name, $table, self::COLUMN, self::RULE_TABLE, 'id', $onDelete, 'CASCADE');
    }

    /**
     * approval_request.approval_rule_id: nullable with ON DELETE SET NULL.
     * The key goes first, as the column cannot change while a key uses it.
     */
    private function keepRequestsOfDeletedRules(): void
    {
        $current = $this->deleteRuleOf(self::REQUEST_TABLE, self::REQUEST_FK);
        if ($current === 'SET NULL') {
            return;
        }
        if ($current !== null) {
            $this->dropForeignKey(self::REQUEST_FK, self::REQUEST_TABLE);
        }
        if (!$this->requestRuleIsNullable()) {
            $this->alterColumn(self::REQUEST_TABLE, self::COLUMN, 'INT UNSIGNED NULL');
        }
        $this->addForeignKey(self::REQUEST_FK, self::REQUEST_TABLE, self::COLUMN, self::RULE_TABLE, 'id', 'SET NULL', 'CASCADE');
    }

    /**
     * Back to NOT NULL with ON DELETE CASCADE. The requests that lost their
     * rule cannot stay in that schema, which deleted a rule's requests with
     * it: they are deleted, with their decisions.
     */
    private function deleteRequestsWithRules(): void
    {
        $current = $this->deleteRuleOf(self::REQUEST_TABLE, self::REQUEST_FK);
        $nullable = $this->requestRuleIsNullable();
        if ($current === 'CASCADE' && !$nullable) {
            return;
        }
        if ($current !== null) {
            $this->dropForeignKey(self::REQUEST_FK, self::REQUEST_TABLE);
        }
        if ($nullable) {
            $deleted = $this->connection()->createCommand()->delete(self::REQUEST_TABLE, [self::COLUMN => null])->execute();
            echo "    > Deleted {$deleted} approval request(s) of deleted approval rules, with their decisions.\n";
            $this->alterColumn(self::REQUEST_TABLE, self::COLUMN, 'INT UNSIGNED NOT NULL');
        }
        $this->addForeignKey(self::REQUEST_FK, self::REQUEST_TABLE, self::COLUMN, self::RULE_TABLE, 'id', 'CASCADE', 'CASCADE');
    }

    /**
     * The ON DELETE rule of foreign key $name of $table (RESTRICT, SET NULL,
     * CASCADE, ...), or null when the table has no such key.
     */
    private function deleteRuleOf(string $table, string $name): ?string
    {
        $rule = (new Query())
            ->select('DELETE_RULE')
            ->from('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where([
                'CONSTRAINT_SCHEMA' => new Expression('DATABASE()'),
                'TABLE_NAME' => $this->connection()->getSchema()->getRawTableName($table),
                'CONSTRAINT_NAME' => $name,
            ])
            ->scalar($this->connection());

        return is_string($rule) ? $rule : null;
    }

    private function hasIndex(string $table, string $name): bool
    {
        return (new Query())
            ->from('information_schema.STATISTICS')
            ->where([
                'TABLE_SCHEMA' => new Expression('DATABASE()'),
                'TABLE_NAME' => $this->connection()->getSchema()->getRawTableName($table),
                'INDEX_NAME' => $name,
            ])
            ->exists($this->connection());
    }

    private function requestRuleIsNullable(): bool
    {
        return (bool)$this->connection()->getTableSchema(self::REQUEST_TABLE, true)?->getColumn(self::COLUMN)?->allowNull;
    }

    private function connection(): Connection
    {
        /** @var Connection $db init() resolved the configured connection */
        $db = $this->db;

        return $db;
    }
}
