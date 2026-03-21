<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates the webhooks table for outbound event notifications.
 *
 * Webhooks are fired when job events occur (e.g. job.success, job.failure).
 * Each delivery is signed with HMAC-SHA256 using the stored secret.
 */
class m000014_000000_create_webhooks_table extends Migration
{
    public function safeUp(): void
    {
        $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';

        $this->createTable('{{%webhook}}', [
            'id'          => $this->primaryKey()->unsigned(),
            'name'        => $this->string(128)->notNull(),
            'url'         => $this->string(512)->notNull(),
            'secret'      => $this->string(128)->null()
                ->comment('HMAC-SHA256 signing secret; null = unsigned'),
            'events'      => $this->string(255)->notNull()
                ->comment('Comma-separated event names: job.success,job.failure,job.started'),
            'enabled'     => $this->boolean()->notNull()->defaultValue(true),
            'created_by'  => $this->integer()->unsigned()->notNull(),
            'created_at'  => $this->integer()->unsigned()->notNull(),
            'updated_at'  => $this->integer()->unsigned()->notNull(),
        ], $tableOptions);

        $this->createIndex('idx_webhook_enabled', '{{%webhook}}', 'enabled');

        $this->addForeignKey(
            'fk_webhook_created_by',
            '{{%webhook}}', 'created_by',
            '{{%user}}', 'id',
            'RESTRICT', 'CASCADE'
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%webhook}}');
    }
}
