<?php

declare(strict_types=1);

use yii\db\ColumnSchemaBuilder;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Migration;
use yii\db\Query;

/**
 * Workflow notifications: the options of a notification template, its
 * workflow filter, its delivery outbox and the threads of its status posts.
 *
 * notification_template:
 * - events: VARCHAR(512) becomes VARCHAR(1024). The model accepted 1024
 *   characters, so a template that listened to many events failed with a
 *   server error (SQLSTATE 22001) instead of being saved.
 * - level: all or essential, which workflow events the template delivers;
 *   default all.
 * - workflow_scope: all, or selected: only the runs of the workflow
 *   templates of its workflow filter; default all.
 * - channel_state: JSON the server keeps for the channel, such as a
 *   resolved channel or the result of a check; never edited.
 * - delivers_as: the user whose access decides what the template delivers,
 *   who last changed what or where it delivers (NotificationIdentity); a
 *   foreign key to user, ON DELETE RESTRICT as created_by's. Existing
 *   templates deliver as their creator (created_by). Those whose creator is
 *   inactive are named (INACTIVE_IDENTITIES): they deliver nothing until
 *   someone saves them.
 *
 * notification_template_workflow_template: the workflow filter, a workflow
 * template per row, deleted with its template or its workflow template.
 *
 * notification_delivery: the outbox, one row per template and event, with
 * the event's variables, its kind (message, status, reply), its status
 * (pending, sending, delivered, failed, skipped, superseded), its attempts
 * and the post it created; deleted with its template or its run.
 *
 * notification_thread: the status post of a template for a workflow run,
 * one per template and run; deleted with either.
 *
 * up()/down(), not safeUp(): DDL commits implicitly, so a transaction would
 * not undo a half-applied run. Each step reads the current schema from
 * information_schema and does only what is missing, so a retry after a
 * failure finishes the work, and running it again sends no DDL and prints
 * nothing. Tables and columns are named as stored, not by the models'
 * constants: a migration must keep working when the models change.
 *
 * down() drops the tables and columns. It says how many deliveries were not
 * sent yet (UNSENT_DROPPED): they are never sent; and which templates lose
 * a workflow filter or level essential (OPTIONS_REMOVED): they deliver
 * every workflow event they listen to again. It keeps VARCHAR(1024) for the
 * events while some template lists more than 512 characters
 * (EVENTS_KEPT). Both up and down refresh the schema cache.
 */
class m000091_000000_notification_delivery_and_options extends Migration
{
    /** up(), when the backfill gave templates an inactive creator: sprintf() them as '#<id>', joined with ', '. */
    public const INACTIVE_IDENTITIES = "    > Notification template(s) %s deliver as their creator, whose account is inactive: they deliver nothing until someone saves them.\n";

    /** down(), when deliveries were not finished: sprintf() their number. */
    public const UNSENT_DROPPED = "    > Dropped %d notification delivery(ies) that were not delivered yet: they are never sent.\n";

    /** down(), for templates with a workflow filter or level essential: sprintf() them as '#<id>', joined with ', '. */
    public const OPTIONS_REMOVED = "    > Removed the workflow filter or level of notification template(s) %s: they deliver every workflow event they listen to again.\n";

    /** down(), when events are longer than VARCHAR(512) holds: sprintf() the templates as '#<id>', joined with ', '. */
    public const EVENTS_KEPT = "    > Kept notification_template.events as VARCHAR(1024): template(s) %s list events longer than 512 characters, which VARCHAR(512) cannot store; older releases work with VARCHAR(1024).\n";

    private const TEMPLATES = '{{%notification_template}}';
    private const USERS = '{{%user}}';
    private const FILTER = '{{%notification_template_workflow_template}}';
    private const WORKFLOW_TEMPLATES = '{{%workflow_template}}';
    private const RUNS = '{{%workflow_job}}';
    private const DELIVERIES = '{{%notification_delivery}}';
    private const THREADS = '{{%notification_thread}}';

    private const TABLE_OPTIONS = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';

    /** The length of notification_template.events from up() on, as the model allows it. */
    private const EVENTS_WIDE_LENGTH = 1024;

    /** The length of notification_template.events before up(), as m000044 created it. */
    private const EVENTS_NARROW_LENGTH = 512;

    private const EVENTS_COMMENT = 'Comma-separated event names';

    private const DELIVERS_AS = 'delivers_as';
    private const DELIVERS_AS_COMMENT = 'The user whose access decides what the template delivers: who last changed what or where it delivers';
    private const DELIVERS_AS_INDEX = 'idx-notification_template-delivers_as';
    private const DELIVERS_AS_FK = 'fk-notification_template-delivers_as';

    /** user.status of an active account (User::STATUS_ACTIVE). */
    private const ACTIVE = 10;

    /** The statuses of a delivery that is not finished. */
    private const UNSENT = ['pending', 'sending'];

    public function up(): void
    {
        if ((int)$this->columnLength(self::TEMPLATES, 'events') < self::EVENTS_WIDE_LENGTH) {
            $this->alterColumn(self::TEMPLATES, 'events', $this->string(self::EVENTS_WIDE_LENGTH)->notNull()->comment(self::EVENTS_COMMENT));
        }
        $this->addMissingColumn(self::TEMPLATES, 'channel_state', $this->text()->null()->after('config')
            ->comment('JSON the server keeps for the channel; never edited'));
        $this->addMissingColumn(self::TEMPLATES, 'level', $this->string(16)->notNull()->defaultValue('all')->after('events')
            ->comment('all or essential: the workflow events the template delivers'));
        $this->addMissingColumn(self::TEMPLATES, 'workflow_scope', $this->string(16)->notNull()->defaultValue('all')->after('level')
            ->comment('all, or selected: only the runs of the workflow templates of its workflow filter'));
        $this->addDeliversAs();
        $this->createFilterTable();
        $this->createDeliveryTable();
        $this->createThreadTable();
        $this->connection()->getSchema()->refresh();
    }

    public function down(): void
    {
        $this->reportUnsentDeliveries();
        $this->reportRemovedOptions();
        foreach ([self::THREADS, self::DELIVERIES, self::FILTER] as $table) {
            if ($this->tableExists($table)) {
                $this->dropTable($table);
            }
        }
        if ($this->deleteRuleOf(self::TEMPLATES, self::DELIVERS_AS_FK) !== null) {
            $this->dropForeignKey(self::DELIVERS_AS_FK, self::TEMPLATES);
        }
        if ($this->hasIndex(self::TEMPLATES, self::DELIVERS_AS_INDEX)) {
            $this->dropIndex(self::DELIVERS_AS_INDEX, self::TEMPLATES);
        }
        foreach ([self::DELIVERS_AS, 'workflow_scope', 'level', 'channel_state'] as $column) {
            if ($this->columnLength(self::TEMPLATES, $column) !== null) {
                $this->dropColumn(self::TEMPLATES, $column);
            }
        }
        $this->narrowEvents();
        $this->connection()->getSchema()->refresh();
    }

    /**
     * delivers_as, NOT NULL with its index and foreign key, every template
     * delivering as its creator unless it delivers as a user already. The
     * column is added nullable, filled, then made NOT NULL, so a retry
     * finds the templates a failed run left without one.
     */
    private function addDeliversAs(): void
    {
        $this->addMissingColumn(self::TEMPLATES, self::DELIVERS_AS, $this->integer()->unsigned()->null()->after('created_by')
            ->comment(self::DELIVERS_AS_COMMENT));
        $this->backfillDeliversAs();
        if ($this->isNullable(self::TEMPLATES, self::DELIVERS_AS)) {
            $this->alterColumn(self::TEMPLATES, self::DELIVERS_AS, $this->integer()->unsigned()->notNull()->comment(self::DELIVERS_AS_COMMENT));
        }
        $this->ensureIndex(self::DELIVERS_AS_INDEX, self::TEMPLATES, [self::DELIVERS_AS]);
        $this->ensureForeignKey(self::DELIVERS_AS_FK, self::TEMPLATES, self::DELIVERS_AS, self::USERS, 'RESTRICT');
    }

    /**
     * Every template without an identity delivers as its creator; the ones
     * whose creator is inactive are named.
     */
    private function backfillDeliversAs(): void
    {
        $ids = $this->ids((new Query())->select('id')->from(self::TEMPLATES)->where([self::DELIVERS_AS => null]));
        if ($ids === []) {
            return;
        }
        $this->update(self::TEMPLATES, [self::DELIVERS_AS => new Expression('[[created_by]]')], [self::DELIVERS_AS => null]);
        $inactive = $this->ids((new Query())
            ->select('t.id')
            ->from(['t' => self::TEMPLATES])
            ->leftJoin(['u' => self::USERS], '[[u]].[[id]] = [[t]].[[delivers_as]]')
            ->where(['t.id' => $ids])
            ->andWhere(['or', ['u.id' => null], ['<>', 'u.status', self::ACTIVE]]));
        if ($inactive !== []) {
            echo sprintf(self::INACTIVE_IDENTITIES, self::idList($inactive));
        }
    }

    private function createFilterTable(): void
    {
        if (!$this->tableExists(self::FILTER)) {
            $this->createTable(self::FILTER, [
                'notification_template_id' => $this->integer()->unsigned()->notNull(),
                'workflow_template_id' => $this->integer()->unsigned()->notNull(),
                'PRIMARY KEY ([[notification_template_id]], [[workflow_template_id]])',
            ], self::TABLE_OPTIONS);
        }
        $this->ensureIndex('idx-ntwt-workflow_template', self::FILTER, ['workflow_template_id']);
        $this->ensureForeignKey('fk-ntwt-notification_template', self::FILTER, 'notification_template_id', self::TEMPLATES, 'CASCADE');
        $this->ensureForeignKey('fk-ntwt-workflow_template', self::FILTER, 'workflow_template_id', self::WORKFLOW_TEMPLATES, 'CASCADE');
    }

    private function createDeliveryTable(): void
    {
        if (!$this->tableExists(self::DELIVERIES)) {
            $this->createTable(self::DELIVERIES, [
                'id' => $this->primaryKey()->unsigned(),
                'notification_template_id' => $this->integer()->unsigned()->notNull(),
                'event' => $this->string(64)->notNull(),
                'kind' => $this->string(16)->notNull()->defaultValue('message')->comment('message, status or reply'),
                'workflow_job_id' => $this->integer()->unsigned()->null()->comment('The run whose thread the delivery belongs to'),
                'job_id' => $this->integer()->unsigned()->null(),
                'approval_request_id' => $this->integer()->unsigned()->null(),
                'variables' => $this->text()->notNull()->comment('JSON: the variables of the event when it happened'),
                'status' => $this->string(16)->notNull()->defaultValue('pending')
                    ->comment('pending, sending, delivered, failed, skipped or superseded'),
                'attempts' => $this->integer()->unsigned()->notNull()->defaultValue(0),
                'next_attempt_at' => $this->integer()->notNull(),
                'claimed_at' => $this->integer()->null(),
                'last_error' => $this->string(500)->null(),
                'remote_id' => $this->string(64)->null()->comment('The post the delivery created'),
                'created_at' => $this->integer()->notNull(),
                'updated_at' => $this->integer()->notNull(),
                'finished_at' => $this->integer()->null(),
            ], self::TABLE_OPTIONS);
        }
        $this->ensureIndex('idx-notification_delivery-status-next_attempt', self::DELIVERIES, ['status', 'next_attempt_at']);
        $this->ensureIndex('idx-notification_delivery-template-created', self::DELIVERIES, ['notification_template_id', 'created_at']);
        $this->ensureIndex('idx-notification_delivery-workflow_job', self::DELIVERIES, ['workflow_job_id', 'id']);
        $this->ensureForeignKey('fk-notification_delivery-template', self::DELIVERIES, 'notification_template_id', self::TEMPLATES, 'CASCADE');
        $this->ensureForeignKey('fk-notification_delivery-workflow_job', self::DELIVERIES, 'workflow_job_id', self::RUNS, 'CASCADE');
    }

    private function createThreadTable(): void
    {
        if (!$this->tableExists(self::THREADS)) {
            $this->createTable(self::THREADS, [
                'id' => $this->primaryKey()->unsigned(),
                'notification_template_id' => $this->integer()->unsigned()->notNull(),
                'workflow_job_id' => $this->integer()->unsigned()->notNull(),
                'root_post_id' => $this->string(64)->notNull(),
                'channel_id' => $this->string(64)->notNull(),
                'server' => $this->string(255)->notNull()->comment('The server the post lives on'),
                'created_at' => $this->integer()->notNull(),
                'updated_at' => $this->integer()->notNull(),
            ], self::TABLE_OPTIONS);
        }
        $this->ensureIndex('idx-notification_thread-template-workflow_job', self::THREADS, ['notification_template_id', 'workflow_job_id'], true);
        $this->ensureIndex('idx-notification_thread-workflow_job', self::THREADS, ['workflow_job_id']);
        $this->ensureForeignKey('fk-notification_thread-template', self::THREADS, 'notification_template_id', self::TEMPLATES, 'CASCADE');
        $this->ensureForeignKey('fk-notification_thread-workflow_job', self::THREADS, 'workflow_job_id', self::RUNS, 'CASCADE');
    }

    /**
     * Names the number of deliveries the down migration drops unsent.
     */
    private function reportUnsentDeliveries(): void
    {
        if (!$this->tableExists(self::DELIVERIES)) {
            return;
        }
        $unsent = (int)(new Query())->from(self::DELIVERIES)->where(['status' => self::UNSENT])->count('*', $this->connection());
        if ($unsent > 0) {
            echo sprintf(self::UNSENT_DROPPED, $unsent);
        }
    }

    /**
     * Names the templates whose workflow filter or level essential the
     * down migration removes.
     */
    private function reportRemovedOptions(): void
    {
        $conditions = ['or'];
        if ($this->columnLength(self::TEMPLATES, 'workflow_scope') !== null) {
            $conditions[] = ['workflow_scope' => 'selected'];
        }
        if ($this->columnLength(self::TEMPLATES, 'level') !== null) {
            $conditions[] = ['level' => 'essential'];
        }
        if (count($conditions) === 1) {
            return;
        }
        $ids = $this->ids((new Query())->select('id')->from(self::TEMPLATES)->where($conditions));
        if ($ids !== []) {
            echo sprintf(self::OPTIONS_REMOVED, self::idList($ids));
        }
    }

    /**
     * Back to VARCHAR(512), unless some template lists more events than it
     * holds: then VARCHAR(1024) stays, and the templates are named.
     */
    private function narrowEvents(): void
    {
        if ((int)$this->columnLength(self::TEMPLATES, 'events') <= self::EVENTS_NARROW_LENGTH) {
            return;
        }
        $longer = $this->ids((new Query())
            ->select('id')
            ->from(self::TEMPLATES)
            ->where(['>', new Expression('CHAR_LENGTH([[events]])'), self::EVENTS_NARROW_LENGTH]));
        if ($longer === []) {
            $this->alterColumn(self::TEMPLATES, 'events', $this->string(self::EVENTS_NARROW_LENGTH)->notNull()->comment(self::EVENTS_COMMENT));
            return;
        }
        echo sprintf(self::EVENTS_KEPT, self::idList($longer));
    }

    private function addMissingColumn(string $table, string $column, ColumnSchemaBuilder $type): void
    {
        if ($this->columnLength($table, $column) === null) {
            $this->addColumn($table, $column, $type);
        }
    }

    /**
     * @param list<string> $columns
     */
    private function ensureIndex(string $name, string $table, array $columns, bool $unique = false): void
    {
        if (!$this->hasIndex($table, $name)) {
            $this->createIndex($name, $table, $columns, $unique);
        }
    }

    /**
     * Makes $name the foreign key of $table.$column to $refTable.id with ON
     * DELETE $onDelete and ON UPDATE CASCADE, unless it is already: a key of
     * that name with another delete rule is dropped and added again, as
     * m000083 does it.
     */
    private function ensureForeignKey(string $name, string $table, string $column, string $refTable, string $onDelete): void
    {
        $current = $this->deleteRuleOf($table, $name);
        if ($current === $onDelete) {
            return;
        }
        if ($current !== null) {
            $this->dropForeignKey($name, $table);
        }
        $this->addForeignKey($name, $table, $column, $refTable, 'id', $onDelete, 'CASCADE');
    }

    /**
     * The IDs a query selects, ascending.
     *
     * @return list<int>
     */
    private function ids(Query $query): array
    {
        $ids = array_map(static fn (mixed $id): int => (int)$id, array_values($query->column($this->connection())));
        sort($ids);

        return $ids;
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
     * The row of $column of $table in information_schema.COLUMNS, or null
     * when there is no such column.
     *
     * @return array<string, mixed>|null
     */
    private function columnInfo(string $table, string $column): ?array
    {
        $row = (new Query())
            ->select(['length' => 'CHARACTER_MAXIMUM_LENGTH', 'nullable' => 'IS_NULLABLE'])
            ->from('information_schema.COLUMNS')
            ->where([
                'TABLE_SCHEMA' => new Expression('DATABASE()'),
                'TABLE_NAME' => $this->connection()->getSchema()->getRawTableName($table),
                'COLUMN_NAME' => $column,
            ])
            ->one($this->connection());

        return is_array($row) ? $row : null;
    }

    /**
     * The length of a text column of $table, 0 for a column of another type,
     * or null when there is no such column.
     */
    private function columnLength(string $table, string $column): ?int
    {
        $info = $this->columnInfo($table, $column);

        return $info === null ? null : (int)$info['length'];
    }

    private function isNullable(string $table, string $column): bool
    {
        return ($this->columnInfo($table, $column)['nullable'] ?? null) === 'YES';
    }

    private function tableExists(string $table): bool
    {
        return (new Query())
            ->from('information_schema.TABLES')
            ->where([
                'TABLE_SCHEMA' => new Expression('DATABASE()'),
                'TABLE_NAME' => $this->connection()->getSchema()->getRawTableName($table),
            ])
            ->exists($this->connection());
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
