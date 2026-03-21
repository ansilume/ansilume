<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates the inventories table.
 */
class m000004_000000_create_inventories_table extends Migration
{
    public function safeUp(): void
    {
        $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';

        $this->createTable('{{%inventory}}', [
            'id'          => $this->primaryKey()->unsigned(),
            'name'        => $this->string(128)->notNull(),
            'description' => $this->text()->null(),
            'inventory_type' => $this->string(32)->notNull()->defaultValue('static')
                ->comment('static | dynamic | file'),
            'content'     => $this->text()->null()
                ->comment('Inline INI/YAML content for static inventories'),
            'source_path' => $this->string(512)->null()
                ->comment('Path within a project for file-based inventories'),
            'project_id'  => $this->integer()->unsigned()->null(),
            'created_by'  => $this->integer()->unsigned()->notNull(),
            'created_at'  => $this->integer()->unsigned()->notNull(),
            'updated_at'  => $this->integer()->unsigned()->notNull(),
        ], $tableOptions);

        $this->createIndex('idx_inventory_project_id',  '{{%inventory}}', 'project_id');
        $this->createIndex('idx_inventory_created_by',  '{{%inventory}}', 'created_by');

        $this->addForeignKey(
            'fk_inventory_project_id',
            '{{%inventory}}', 'project_id',
            '{{%project}}', 'id',
            'SET NULL', 'CASCADE'
        );
        $this->addForeignKey(
            'fk_inventory_created_by',
            '{{%inventory}}', 'created_by',
            '{{%user}}', 'id',
            'RESTRICT', 'CASCADE'
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%inventory}}');
    }
}
