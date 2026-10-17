<?php

declare(strict_types=1);

use yii\db\Connection;
use yii\db\Expression;
use yii\db\Migration;
use yii\db\Query;

/**
 * Approval rules on inventories, and what required an approval request:
 *
 * - inventory.content: TEXT becomes MEDIUMTEXT. The inventory form accepts
 *   262,144 characters, but TEXT holds 65,535 bytes, so a longer static
 *   inventory failed with a server error (SQLSTATE 22001) instead of being
 *   saved.
 * - inventory.parsed_hosts, the JSON that Parse Inventory caches: TEXT
 *   becomes MEDIUMTEXT too. The JSON is longer than the content, much
 *   longer when group variables are copied to every host, so caching the
 *   parse of 1,200 hosts, or of 150 hosts with group variables, failed with
 *   a server error (SQLSTATE 22001, driver error 1406).
 * - inventory.approval_rule_id: the approval rule every launch with the
 *   inventory waits for (null: none), with an index and a foreign key to
 *   approval_rule (ON DELETE RESTRICT: a rule an inventory uses cannot be
 *   deleted). IDs of rules that no longer exist, which only a failed earlier
 *   run can leave, are cleared first and listed (CLEARED).
 * - approval_request.required_by: what required the request (job_template,
 *   inventory or approval_step), default job_template. The requests of
 *   workflow approval steps are marked approval_step (MARKED): their job is
 *   found through its workflow step execution, by the step type that the
 *   execution recorded, else by the type of its step, compared as binary.
 *   Requests of placeholders whose execution is gone stay job_template.
 *
 * up()/down(), not safeUp(): DDL commits implicitly, so a transaction would
 * not undo a half-applied run. Each step reads the current schema from
 * information_schema and does only what is missing, so a retry after a
 * failure finishes the work, and running it again sends no DDL and prints
 * nothing. Tables and columns are named as stored, not by the models'
 * constants: a migration must keep working when the models change.
 *
 * Down removes the rules of inventories, naming them (RULES_REMOVED): their
 * job templates then launch without approval unless they have a rule of
 * their own. It keeps MEDIUMTEXT while some content is longer than TEXT can
 * store (CONTENT_KEPT); 2.11.1 works with MEDIUMTEXT. It narrows
 * parsed_hosts to TEXT again, after it cleared the parse caches longer
 * than TEXT can store, naming their inventories (PARSED_CLEARED): a cache,
 * which the next Parse Inventory writes again. Both up and down refresh
 * the schema cache.
 */
class m000088_000000_add_inventory_approval_rules extends Migration
{
    /** up(), per inventory: sprintf() the rule ID, then the inventory ID. */
    public const CLEARED = "    > Cleared approval rule #%d, which does not exist, from inventory #%d.\n";

    /** up(), when the backfill marked requests: sprintf() their number. */
    public const MARKED = "    > Marked %d approval request(s) of workflow approval steps as required by an approval step.\n";

    /**
     * down(), when inventories had rules: sprintf() their number, then each
     * as '#<inventory id> (rule #<rule id>)', joined with ', '.
     */
    public const RULES_REMOVED = "    > Removed the approval rule of %d inventory(ies): %s. Their job templates launch without approval unless they have a rule of their own.\n";

    /** down(), when content is longer than TEXT can store: sprintf() the inventories as '#<id>', joined with ', '. */
    public const CONTENT_KEPT = "    > Kept inventory.content as MEDIUMTEXT: inventory(ies) %s hold more than 65535 bytes, which TEXT cannot store; 2.11.1 works with MEDIUMTEXT.\n";

    /** down(), when parse caches are longer than TEXT can store: sprintf() the inventories as '#<id>', joined with ', '. */
    public const PARSED_CLEARED = "    > Cleared the parsed hosts of inventory(ies) %s: they hold more than 65535 bytes, which TEXT cannot store.\n";

    private const INVENTORY_TABLE = '{{%inventory}}';
    private const RULE_TABLE = '{{%approval_rule}}';
    private const REQUEST_TABLE = '{{%approval_request}}';
    private const RULE_COLUMN = 'approval_rule_id';
    private const CONTENT_COLUMN = 'content';
    private const PARSED_COLUMN = 'parsed_hosts';
    private const REQUIRED_BY_COLUMN = 'required_by';
    private const RULE_INDEX = 'idx-inventory-approval_rule';
    private const RULE_FK = 'fk-inventory-approval_rule';

    /** The type of inventory.content from up() on; Yii has no builder for MEDIUMTEXT. */
    private const CONTENT_MEDIUMTEXT = "MEDIUMTEXT NULL COMMENT 'Inline INI/YAML content for static inventories'";

    /** The type of inventory.content before up(), as m000004 created it. */
    private const CONTENT_TEXT = "TEXT NULL COMMENT 'Inline INI/YAML content for static inventories'";

    /** The type of inventory.parsed_hosts from up() on. */
    private const PARSED_MEDIUMTEXT = "MEDIUMTEXT NULL COMMENT 'Cached JSON output of ansible-inventory parse'";

    /** The type of inventory.parsed_hosts before up(), as m000038 created it. */
    private const PARSED_TEXT = "TEXT NULL COMMENT 'Cached JSON output of ansible-inventory parse'";

    /** The columns of a parse cache, which down() clears where TEXT cannot store it. */
    private const PARSE_CACHE = ['parsed_hosts' => null, 'parsed_error' => null, 'parsed_at' => null];

    /** The most bytes a TEXT column stores. */
    private const TEXT_MAX_BYTES = 65535;

    /**
     * Marks the requests of workflow approval steps. A placeholder job of an
     * approval step has no job template, but neither has a job of a purged
     * template, so the step type decides: the one its execution recorded,
     * else its step's, compared as binary.
     */
    private const BACKFILL_SQL = 'UPDATE {{%approval_request}} [[r]]'
        . ' INNER JOIN {{%workflow_job_step}} [[wjs]] ON [[wjs]].[[job_id]] = [[r]].[[job_id]]'
        . ' LEFT JOIN {{%workflow_step}} [[ws]] ON [[ws]].[[id]] = [[wjs]].[[workflow_step_id]]'
        . ' SET [[r]].[[required_by]] = :step'
        . ' WHERE [[r]].[[required_by]] <> :step'
        . " AND CAST(COALESCE(NULLIF([[wjs]].[[step_type]], ''), [[ws]].[[step_type]], '') AS BINARY) = :approval";

    public function up(): void
    {
        if ($this->columnType(self::INVENTORY_TABLE, self::CONTENT_COLUMN) !== 'mediumtext') {
            $this->alterColumn(self::INVENTORY_TABLE, self::CONTENT_COLUMN, self::CONTENT_MEDIUMTEXT);
        }
        if ($this->columnType(self::INVENTORY_TABLE, self::PARSED_COLUMN) !== 'mediumtext') {
            $this->alterColumn(self::INVENTORY_TABLE, self::PARSED_COLUMN, self::PARSED_MEDIUMTEXT);
        }
        if ($this->columnType(self::INVENTORY_TABLE, self::RULE_COLUMN) === null) {
            $this->addColumn(
                self::INVENTORY_TABLE,
                self::RULE_COLUMN,
                $this->integer()->unsigned()->null()->after('project_id')
                    ->comment('The approval rule every launch with this inventory waits for; null: none')
            );
        }
        $this->clearUnknownRuleIds();
        if (!$this->hasIndex(self::INVENTORY_TABLE, self::RULE_INDEX)) {
            $this->createIndex(self::RULE_INDEX, self::INVENTORY_TABLE, self::RULE_COLUMN);
        }
        $this->restrictRuleDeletes();
        if ($this->columnType(self::REQUEST_TABLE, self::REQUIRED_BY_COLUMN) === null) {
            $this->addColumn(
                self::REQUEST_TABLE,
                self::REQUIRED_BY_COLUMN,
                $this->string(16)->notNull()->defaultValue('job_template')->after('approval_rule_id')
                    ->comment('What required the request: job_template, inventory or approval_step')
            );
        }
        $this->markApprovalStepRequests();
        $this->connection()->getSchema()->refresh();
    }

    public function down(): void
    {
        if ($this->columnType(self::INVENTORY_TABLE, self::RULE_COLUMN) !== null) {
            $this->reportRemovedRules();
            if ($this->deleteRuleOf(self::INVENTORY_TABLE, self::RULE_FK) !== null) {
                $this->dropForeignKey(self::RULE_FK, self::INVENTORY_TABLE);
            }
            if ($this->hasIndex(self::INVENTORY_TABLE, self::RULE_INDEX)) {
                $this->dropIndex(self::RULE_INDEX, self::INVENTORY_TABLE);
            }
            $this->dropColumn(self::INVENTORY_TABLE, self::RULE_COLUMN);
        }
        if ($this->columnType(self::REQUEST_TABLE, self::REQUIRED_BY_COLUMN) !== null) {
            $this->dropColumn(self::REQUEST_TABLE, self::REQUIRED_BY_COLUMN);
        }
        if ($this->columnType(self::INVENTORY_TABLE, self::CONTENT_COLUMN) === 'mediumtext') {
            $this->narrowContent();
        }
        if ($this->columnType(self::INVENTORY_TABLE, self::PARSED_COLUMN) === 'mediumtext') {
            $this->narrowParsedHosts();
        }
        $this->connection()->getSchema()->refresh();
    }

    /**
     * Clears the rule IDs of inventories that point to no rule, which the
     * foreign key would refuse, and lists them. Only a run that failed after
     * adding the column and before adding the key can leave any.
     */
    private function clearUnknownRuleIds(): void
    {
        $rows = (new Query())
            ->select(['i.id', 'i.approval_rule_id'])
            ->from(['i' => self::INVENTORY_TABLE])
            ->leftJoin(['r' => self::RULE_TABLE], '[[r]].[[id]] = [[i]].[[approval_rule_id]]')
            ->where(['not', ['i.approval_rule_id' => null]])
            ->andWhere(['r.id' => null])
            ->orderBy(['i.id' => SORT_ASC])
            ->all($this->connection());
        if ($rows === []) {
            return;
        }
        $this->update(self::INVENTORY_TABLE, [self::RULE_COLUMN => null], ['id' => array_column($rows, 'id')]);
        foreach ($rows as $row) {
            echo sprintf(self::CLEARED, (int)$row['approval_rule_id'], (int)$row['id']);
        }
    }

    /**
     * Makes RULE_FK the foreign key of inventory.approval_rule_id to
     * approval_rule with ON DELETE RESTRICT, unless it is already: a key of
     * that name with another rule is dropped and added again, as m000083
     * does it.
     */
    private function restrictRuleDeletes(): void
    {
        $current = $this->deleteRuleOf(self::INVENTORY_TABLE, self::RULE_FK);
        if ($current === 'RESTRICT') {
            return;
        }
        if ($current !== null) {
            $this->dropForeignKey(self::RULE_FK, self::INVENTORY_TABLE);
        }
        $this->addForeignKey(self::RULE_FK, self::INVENTORY_TABLE, self::RULE_COLUMN, self::RULE_TABLE, 'id', 'RESTRICT', 'CASCADE');
    }

    /**
     * Marks the requests of workflow approval steps as required by an
     * approval step (BACKFILL_SQL), and says how many when there were any.
     */
    private function markApprovalStepRequests(): void
    {
        $marked = $this->connection()->createCommand(
            self::BACKFILL_SQL,
            [':step' => 'approval_step', ':approval' => 'approval']
        )->execute();
        if ($marked > 0) {
            echo sprintf(self::MARKED, $marked);
        }
    }

    /**
     * Names the inventories whose rule the down migration removes.
     */
    private function reportRemovedRules(): void
    {
        $rows = (new Query())
            ->select(['id', self::RULE_COLUMN])
            ->from(self::INVENTORY_TABLE)
            ->where(['not', [self::RULE_COLUMN => null]])
            ->orderBy(['id' => SORT_ASC])
            ->all($this->connection());
        if ($rows === []) {
            return;
        }
        $names = array_map(
            static fn (array $row): string => sprintf('#%d (rule #%d)', (int)$row['id'], (int)$row[self::RULE_COLUMN]),
            $rows
        );
        echo sprintf(self::RULES_REMOVED, count($rows), implode(', ', $names));
    }

    /**
     * Back to TEXT, unless some content is longer than TEXT can store: then
     * MEDIUMTEXT stays, and the inventories are named.
     */
    private function narrowContent(): void
    {
        $longer = $this->idsLongerThanText(self::CONTENT_COLUMN);
        if ($longer === []) {
            $this->alterColumn(self::INVENTORY_TABLE, self::CONTENT_COLUMN, self::CONTENT_TEXT);
            return;
        }
        echo sprintf(self::CONTENT_KEPT, self::idList($longer));
    }

    /**
     * Back to TEXT. A parse cache longer than TEXT can store is cleared
     * first, the hosts, the error and the time of the parse, and the
     * inventories are named: it is only a cache, which the next Parse
     * Inventory writes again.
     */
    private function narrowParsedHosts(): void
    {
        $longer = $this->idsLongerThanText(self::PARSED_COLUMN);
        if ($longer !== []) {
            $this->update(self::INVENTORY_TABLE, self::PARSE_CACHE, ['id' => $longer]);
            echo sprintf(self::PARSED_CLEARED, self::idList($longer));
        }
        $this->alterColumn(self::INVENTORY_TABLE, self::PARSED_COLUMN, self::PARSED_TEXT);
    }

    /**
     * The IDs of the inventories whose $column holds more bytes than TEXT
     * can store, ascending.
     *
     * @return list<int>
     */
    private function idsLongerThanText(string $column): array
    {
        $ids = (new Query())
            ->select('id')
            ->from(self::INVENTORY_TABLE)
            ->where(['>', new Expression('LENGTH([[' . $column . ']])'), self::TEXT_MAX_BYTES])
            ->orderBy(['id' => SORT_ASC])
            ->column($this->connection());

        return array_map(static fn (mixed $id): int => (int)$id, array_values($ids));
    }

    /**
     * $ids as '#<id>', joined with ', '.
     *
     * @param list<int> $ids
     */
    private static function idList(array $ids): string
    {
        return implode(', ', array_map(static fn (int $id): string => '#' . $id, $ids));
    }

    /**
     * The data type of $column of $table as information_schema names it
     * (text, mediumtext, int, varchar, ...), or null when there is no such
     * column.
     */
    private function columnType(string $table, string $column): ?string
    {
        $type = (new Query())
            ->select('DATA_TYPE')
            ->from('information_schema.COLUMNS')
            ->where([
                'TABLE_SCHEMA' => new Expression('DATABASE()'),
                'TABLE_NAME' => $this->connection()->getSchema()->getRawTableName($table),
                'COLUMN_NAME' => $column,
            ])
            ->scalar($this->connection());

        return is_string($type) ? strtolower($type) : null;
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

    private function connection(): Connection
    {
        /** @var Connection $db init() resolved the configured connection */
        $db = $this->db;

        return $db;
    }
}
