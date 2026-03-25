<?php

declare(strict_types=1);

use yii\db\Migration;

class m000032_000000_create_job_artifact_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%job_artifact}}', [
            'id'          => $this->primaryKey(),
            'job_id'      => $this->integer()->unsigned()->notNull(),
            'filename'    => $this->string(255)->notNull(),
            'display_name' => $this->string(255)->notNull(),
            'mime_type'   => $this->string(128)->notNull()->defaultValue('application/octet-stream'),
            'size_bytes'  => $this->bigInteger()->notNull()->defaultValue(0),
            'storage_path' => $this->string(512)->notNull(),
            'created_at'  => $this->integer()->notNull(),
        ]);

        $this->addForeignKey(
            'fk_job_artifact_job',
            '{{%job_artifact}}',
            'job_id',
            '{{%job}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->createIndex('idx_job_artifact_job_id', '{{%job_artifact}}', 'job_id');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%job_artifact}}');
    }
}
