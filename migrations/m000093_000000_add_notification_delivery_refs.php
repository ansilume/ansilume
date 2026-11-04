<?php

declare(strict_types=1);

use yii\db\ColumnSchemaBuilder;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Migration;
use yii\db\Query;

/**
 * notification_delivery: the project and the job template a delivery's
 * event is about, and an index for the purge of finished deliveries.
 *
 * - project_id: the project of a project event (project.sync_failed,
 *   project.sync_succeeded);
 * - job_template_id: the job template of a schedule event
 *   (schedule.failed_to_launch).
 *
 * Together with workflow_job_id, job_id and approval_request_id (m000091)
 * they name every object an event can be about: the notification worker
 * checks again, when it delivers, that the user the template delivers as
 * may still see the event (NotificationVisibility), a project event by its
 * project, a schedule event by its job template's project. Without them it
 * could check only the permission of such an event. Neither has a foreign
 * key, as job_id and approval_request_id have none: a delivery outlives
 * the object it is about, and is skipped when that object is gone.
 *
 * - idx-notification_delivery-finished_at: the maintenance run deletes the
 *   deliveries that finished more than 30 days ago, every minute; the
 *   index finds them without reading the others.
 *
 * Rows written before have neither ref.
 *
 * up()/down(), not safeUp(): DDL commits implicitly, so a transaction would
 * not undo a half-applied run. Each step reads the current schema from
 * information_schema and does only what is missing, so a retry after a
 * failure finishes the work, and running it again sends no DDL. Both
 * refresh the schema cache.
 */
class m000093_000000_add_notification_delivery_refs extends Migration
{
    /** The index of the purge of finished deliveries. */
    public const INDEX = 'idx-notification_delivery-finished_at';

    private const DELIVERIES = '{{%notification_delivery}}';

    public function up(): void
    {
        $this->addMissingColumn('project_id', $this->integer()->unsigned()->null()->after('approval_request_id')
            ->comment('The project a project event is about'));
        $this->addMissingColumn('job_template_id', $this->integer()->unsigned()->null()->after('project_id')
            ->comment('The job template a schedule event is about'));
        if (!$this->hasIndex()) {
            $this->createIndex(self::INDEX, self::DELIVERIES, ['finished_at']);
        }
        $this->connection()->getSchema()->refresh();
    }

    public function down(): void
    {
        if ($this->hasIndex()) {
            $this->dropIndex(self::INDEX, self::DELIVERIES);
        }
        foreach (['job_template_id', 'project_id'] as $column) {
            if ($this->hasColumn($column)) {
                $this->dropColumn(self::DELIVERIES, $column);
            }
        }
        $this->connection()->getSchema()->refresh();
    }

    private function addMissingColumn(string $column, ColumnSchemaBuilder $type): void
    {
        if (!$this->hasColumn($column)) {
            $this->addColumn(self::DELIVERIES, $column, $type);
        }
    }

    private function hasColumn(string $column): bool
    {
        return (new Query())
            ->from('information_schema.COLUMNS')
            ->where([
                'TABLE_SCHEMA' => new Expression('DATABASE()'),
                'TABLE_NAME' => $this->connection()->getSchema()->getRawTableName(self::DELIVERIES),
                'COLUMN_NAME' => $column,
            ])
            ->exists($this->connection());
    }

    private function hasIndex(): bool
    {
        return (new Query())
            ->from('information_schema.STATISTICS')
            ->where([
                'TABLE_SCHEMA' => new Expression('DATABASE()'),
                'TABLE_NAME' => $this->connection()->getSchema()->getRawTableName(self::DELIVERIES),
                'INDEX_NAME' => self::INDEX,
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
