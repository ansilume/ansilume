<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates the audit_logs table.
 * Immutable append-only record of meaningful user and system actions.
 */
class m000009_create_audit_logs_table extends Migration
{
    public function safeUp(): void
    {
        $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';

        $this->createTable('{{%audit_log}}', [
            'id'          => $this->bigPrimaryKey()->unsigned(),
            'user_id'     => $this->integer()->unsigned()->null()
                ->comment('NULL for system-generated events'),
            'action'      => $this->string(128)->notNull()
                ->comment('Dot-notated event name, e.g. job.launched'),
            'object_type' => $this->string(64)->null(),
            'object_id'   => $this->integer()->unsigned()->null(),
            'metadata'    => $this->text()->null()
                ->comment('JSON context; never contains raw secrets'),
            'ip_address'  => $this->string(45)->null(),
            'user_agent'  => $this->string(512)->null(),
            'created_at'  => $this->integer()->unsigned()->notNull(),
        ], $tableOptions);

        $this->createIndex('idx_audit_log_user_id',     '{{%audit_log}}', 'user_id');
        $this->createIndex('idx_audit_log_action',      '{{%audit_log}}', 'action');
        $this->createIndex('idx_audit_log_object',      '{{%audit_log}}', ['object_type', 'object_id']);
        $this->createIndex('idx_audit_log_created_at',  '{{%audit_log}}', 'created_at');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%audit_log}}');
    }
}
