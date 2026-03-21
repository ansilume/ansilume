<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates the projects table.
 */
class m000003_000000_create_projects_table extends Migration
{
    public function safeUp(): void
    {
        $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';

        $this->createTable('{{%project}}', [
            'id'          => $this->primaryKey()->unsigned(),
            'name'        => $this->string(128)->notNull(),
            'description' => $this->text()->null(),
            'scm_type'    => $this->string(32)->notNull()->defaultValue('git'),
            'scm_url'     => $this->string(512)->null(),
            'scm_branch'  => $this->string(128)->notNull()->defaultValue('main'),
            'local_path'  => $this->string(512)->null()->comment('Resolved path on worker filesystem'),
            'status'      => $this->string(32)->notNull()->defaultValue('new'),
            'last_synced_at' => $this->integer()->unsigned()->null(),
            'created_by'  => $this->integer()->unsigned()->notNull(),
            'created_at'  => $this->integer()->unsigned()->notNull(),
            'updated_at'  => $this->integer()->unsigned()->notNull(),
        ], $tableOptions);

        $this->createIndex('idx_project_status',     '{{%project}}', 'status');
        $this->createIndex('idx_project_created_by', '{{%project}}', 'created_by');

        $this->addForeignKey(
            'fk_project_created_by',
            '{{%project}}', 'created_by',
            '{{%user}}', 'id',
            'RESTRICT', 'CASCADE'
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%project}}');
    }
}
