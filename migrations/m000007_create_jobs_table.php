<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates the jobs table.
 * Statuses: pending | queued | running | succeeded | failed | canceled
 */
class m000007_create_jobs_table extends Migration
{
    public function safeUp(): void
    {
        $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';

        $this->createTable('{{%job}}', [
            'id'              => $this->primaryKey()->unsigned(),
            'job_template_id' => $this->integer()->unsigned()->notNull(),
            'status'          => $this->string(32)->notNull()->defaultValue('pending'),
            'extra_vars'      => $this->text()->null()
                ->comment('Launch-time overrides (JSON)'),
            'limit'           => $this->string(255)->null(),
            'verbosity'       => $this->smallInteger()->unsigned()->null(),
            // Snapshot of execution input for auditability
            'runner_payload'  => $this->text()->null()
                ->comment('JSON snapshot of the full execution parameters'),
            'launched_by'     => $this->integer()->unsigned()->notNull(),
            'queued_at'       => $this->integer()->unsigned()->null(),
            'started_at'      => $this->integer()->unsigned()->null(),
            'finished_at'     => $this->integer()->unsigned()->null(),
            'exit_code'       => $this->smallInteger()->null(),
            'pid'             => $this->integer()->unsigned()->null(),
            'created_at'      => $this->integer()->unsigned()->notNull(),
            'updated_at'      => $this->integer()->unsigned()->notNull(),
        ], $tableOptions);

        $this->createIndex('idx_job_status',          '{{%job}}', 'status');
        $this->createIndex('idx_job_template_id',     '{{%job}}', 'job_template_id');
        $this->createIndex('idx_job_launched_by',     '{{%job}}', 'launched_by');
        $this->createIndex('idx_job_created_at',      '{{%job}}', 'created_at');

        $this->addForeignKey('fk_job_template_id', '{{%job}}', 'job_template_id', '{{%job_template}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_job_launched_by', '{{%job}}', 'launched_by',     '{{%user}}',         'id', 'RESTRICT', 'CASCADE');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%job}}');
    }
}
