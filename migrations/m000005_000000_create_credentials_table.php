<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates the credentials table.
 * Sensitive values are stored encrypted. The secret_data column holds a JSON
 * blob of encrypted field values — never raw secrets.
 */
class m000005_000000_create_credentials_table extends Migration
{
    public function safeUp(): void
    {
        $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';

        $this->createTable('{{%credential}}', [
            'id'           => $this->primaryKey()->unsigned(),
            'name'         => $this->string(128)->notNull(),
            'description'  => $this->text()->null(),
            'credential_type' => $this->string(32)->notNull()
                ->comment('ssh_key | username_password | vault | token'),
            'username'     => $this->string(128)->null(),
            // Encrypted JSON blob containing sensitive fields (private_key, password, token …)
            'secret_data'  => $this->text()->null(),
            'created_by'   => $this->integer()->unsigned()->notNull(),
            'created_at'   => $this->integer()->unsigned()->notNull(),
            'updated_at'   => $this->integer()->unsigned()->notNull(),
        ], $tableOptions);

        $this->createIndex('idx_credential_type',       '{{%credential}}', 'credential_type');
        $this->createIndex('idx_credential_created_by', '{{%credential}}', 'created_by');

        $this->addForeignKey(
            'fk_credential_created_by',
            '{{%credential}}', 'created_by',
            '{{%user}}', 'id',
            'RESTRICT', 'CASCADE'
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%credential}}');
    }
}
