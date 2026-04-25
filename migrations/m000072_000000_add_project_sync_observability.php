<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Project-sync observability: gives the maintenance sweeper a timestamp to
 * compare against, and gives the UI a streaming log to render so operators
 * stop having to guess whether a stuck SYNCING project is actually doing
 * work or whether the worker died on it.
 *
 *   - project.sync_started_at: stamped when the status flips to SYNCING.
 *     Used by MaintenanceService::runStaleSyncSweep() to identify rows that
 *     have been in SYNCING for too long and should be marked ERROR.
 *
 *   - project_sync_log: per-project incremental log lines from the git
 *     subprocess, plus sweeper notes. Mirrors the shape of {{%job_log}}
 *     so the UI polling pattern carries over.
 */
class m000072_000000_add_project_sync_observability extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn(
            '{{%project}}',
            'sync_started_at',
            $this->integer()->unsigned()->null()->defaultValue(null)
                ->comment('UNIX timestamp when status last flipped to SYNCING; cleared on terminal transition.')
        );
        $this->createIndex(
            'idx_project_status_sync_started',
            '{{%project}}',
            ['status', 'sync_started_at']
        );

        $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';

        $this->createTable('{{%project_sync_log}}', [
            'id'         => $this->bigPrimaryKey()->unsigned(),
            'project_id' => $this->integer()->unsigned()->notNull(),
            'stream'     => $this->string(16)->notNull()->defaultValue('stdout')
                ->comment('stdout | stderr | system'),
            'content'    => $this->text()->notNull(),
            'sequence'   => $this->integer()->unsigned()->notNull()->defaultValue(0)
                ->comment('Chunk ordering within the project sync run'),
            'created_at' => $this->integer()->unsigned()->notNull(),
        ], $tableOptions);

        $this->createIndex(
            'idx_project_sync_log_project_seq',
            '{{%project_sync_log}}',
            ['project_id', 'sequence']
        );

        $this->addForeignKey(
            'fk_project_sync_log_project_id',
            '{{%project_sync_log}}',
            'project_id',
            '{{%project}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%project_sync_log}}');
        $this->dropIndex('idx_project_status_sync_started', '{{%project}}');
        $this->dropColumn('{{%project}}', 'sync_started_at');
    }
}
