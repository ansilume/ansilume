<?php

declare(strict_types=1);

use yii\db\Migration;

class m000018_000000_create_job_tasks_table extends Migration
{
    public function up(): void
    {
        $this->createTable('{{%job_task}}', [
            'id'          => $this->primaryKey(),
            'job_id'      => $this->integer()->notNull(),
            'sequence'    => $this->integer()->notNull()->defaultValue(0),
            'task_name'   => $this->string(255)->notNull()->defaultValue(''),
            'task_action' => $this->string(128)->notNull()->defaultValue(''),
            'host'        => $this->string(255)->notNull()->defaultValue(''),
            'status'      => $this->string(32)->notNull()->defaultValue('ok'),
            'changed'     => $this->tinyInteger(1)->notNull()->defaultValue(0),
            'duration_ms' => $this->integer()->notNull()->defaultValue(0),
            'created_at'  => $this->integer()->notNull()->defaultValue(0),
        ]);

        $this->createIndex('idx_job_task_job_id', '{{%job_task}}', 'job_id');
    }

    public function down(): void
    {
        $this->dropTable('{{%job_task}}');
    }
}
