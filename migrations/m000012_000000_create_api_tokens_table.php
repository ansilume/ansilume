<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates the api_tokens table for Bearer-token authentication.
 * Tokens are stored as SHA-256 hashes — the raw token is shown once at creation.
 */
class m000012_000000_create_api_tokens_table extends Migration
{
    public function safeUp(): void
    {
        $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';

        $this->createTable('{{%api_token}}', [
            'id'          => $this->primaryKey()->unsigned(),
            'user_id'     => $this->integer()->unsigned()->notNull(),
            'name'        => $this->string(128)->notNull()->comment('Human-readable label'),
            'token_hash'  => $this->string(64)->notNull()->unique()->comment('SHA-256 hex of the raw token'),
            'last_used_at'=> $this->integer()->unsigned()->null(),
            'expires_at'  => $this->integer()->unsigned()->null()->comment('NULL = never expires'),
            'created_at'  => $this->integer()->unsigned()->notNull(),
        ], $tableOptions);

        $this->createIndex('idx_api_token_user_id',   '{{%api_token}}', 'user_id');
        $this->createIndex('idx_api_token_token_hash','{{%api_token}}', 'token_hash');

        $this->addForeignKey(
            'fk_api_token_user_id',
            '{{%api_token}}', 'user_id',
            '{{%user}}', 'id',
            'CASCADE', 'CASCADE'
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%api_token}}');
    }
}
