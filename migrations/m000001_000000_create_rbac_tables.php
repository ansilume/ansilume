<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates the RBAC tables required by yii\rbac\DbManager.
 */
class m000001_000000_create_rbac_tables extends Migration
{
    public function safeUp(): void
    {
        $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';

        $this->createTable('{{%auth_rule}}', [
            'name'       => $this->string(64)->notNull(),
            'data'       => $this->text(),
            'created_at' => $this->integer(),
            'updated_at' => $this->integer(),
            'PRIMARY KEY ([[name]])',
        ], $tableOptions);

        $this->createTable('{{%auth_item}}', [
            'name'        => $this->string(64)->notNull(),
            'type'        => $this->smallInteger()->notNull(),
            'description' => $this->text(),
            'rule_name'   => $this->string(64),
            'data'        => $this->text(),
            'created_at'  => $this->integer(),
            'updated_at'  => $this->integer(),
            'PRIMARY KEY ([[name]])',
        ], $tableOptions);

        $this->addForeignKey(
            'fk_auth_item_rule_name',
            '{{%auth_item}}', 'rule_name',
            '{{%auth_rule}}', 'name',
            'SET NULL', 'CASCADE'
        );
        $this->createIndex('idx_auth_item_type', '{{%auth_item}}', 'type');

        $this->createTable('{{%auth_item_child}}', [
            'parent' => $this->string(64)->notNull(),
            'child'  => $this->string(64)->notNull(),
            'PRIMARY KEY ([[parent]], [[child]])',
        ], $tableOptions);

        $this->addForeignKey(
            'fk_auth_item_child_parent',
            '{{%auth_item_child}}', 'parent',
            '{{%auth_item}}', 'name',
            'CASCADE', 'CASCADE'
        );
        $this->addForeignKey(
            'fk_auth_item_child_child',
            '{{%auth_item_child}}', 'child',
            '{{%auth_item}}', 'name',
            'CASCADE', 'CASCADE'
        );

        $this->createTable('{{%auth_assignment}}', [
            'item_name'  => $this->string(64)->notNull(),
            'user_id'    => $this->string(64)->notNull(),
            'created_at' => $this->integer(),
            'PRIMARY KEY ([[item_name]], [[user_id]])',
        ], $tableOptions);

        $this->addForeignKey(
            'fk_auth_assignment_item_name',
            '{{%auth_assignment}}', 'item_name',
            '{{%auth_item}}', 'name',
            'CASCADE', 'CASCADE'
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%auth_assignment}}');
        $this->dropTable('{{%auth_item_child}}');
        $this->dropTable('{{%auth_item}}');
        $this->dropTable('{{%auth_rule}}');
    }
}
