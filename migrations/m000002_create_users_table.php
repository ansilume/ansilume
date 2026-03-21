<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates the users table.
 */
class m000002_create_users_table extends Migration
{
    public function safeUp(): void
    {
        $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';

        $this->createTable('{{%user}}', [
            'id'              => $this->primaryKey()->unsigned(),
            'username'        => $this->string(64)->notNull()->unique(),
            'email'           => $this->string(255)->notNull()->unique(),
            'password_hash'   => $this->string(255)->notNull(),
            'auth_key'        => $this->string(32)->notNull(),
            'password_reset_token' => $this->string(255)->null()->defaultValue(null),
            'status'          => $this->smallInteger()->notNull()->defaultValue(10),
            'is_superadmin'   => $this->boolean()->notNull()->defaultValue(false),
            'created_at'      => $this->integer()->unsigned()->notNull(),
            'updated_at'      => $this->integer()->unsigned()->notNull(),
        ], $tableOptions);

        $this->createIndex('idx_user_status', '{{%user}}', 'status');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%user}}');
    }
}
