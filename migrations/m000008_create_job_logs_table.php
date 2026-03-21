<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates the job_logs table for incremental stdout/stderr storage.
 */
class m000008_create_job_logs_table extends Migration
{
    public function safeUp(): void
    {
        $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';

        $this->createTable('{{%job_log}}', [
            'id'         => $this->bigPrimaryKey()->unsigned(),
            'job_id'     => $this->integer()->unsigned()->notNull(),
            'stream'     => $this->string(16)->notNull()->defaultValue('stdout')
                ->comment('stdout | stderr'),
            'content'    => $this->text()->notNull(),
            'sequence'   => $this->integer()->unsigned()->notNull()->defaultValue(0)
                ->comment('Chunk ordering within the job'),
            'created_at' => $this->integer()->unsigned()->notNull(),
        ], $tableOptions);

        $this->createIndex('idx_job_log_job_id',   '{{%job_log}}', 'job_id');
        $this->createIndex('idx_job_log_sequence', '{{%job_log}}', ['job_id', 'sequence']);

        $this->addForeignKey(
            'fk_job_log_job_id',
            '{{%job_log}}', 'job_id',
            '{{%job}}', 'id',
            'CASCADE', 'CASCADE'
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%job_log}}');
    }
}
