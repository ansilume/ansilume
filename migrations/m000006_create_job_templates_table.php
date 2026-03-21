<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates the job_templates table.
 */
class m000006_create_job_templates_table extends Migration
{
    public function safeUp(): void
    {
        $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';

        $this->createTable('{{%job_template}}', [
            'id'            => $this->primaryKey()->unsigned(),
            'name'          => $this->string(128)->notNull(),
            'description'   => $this->text()->null(),
            'project_id'    => $this->integer()->unsigned()->notNull(),
            'inventory_id'  => $this->integer()->unsigned()->notNull(),
            'credential_id' => $this->integer()->unsigned()->null(),
            'playbook'      => $this->string(512)->notNull()
                ->comment('Relative path to playbook within the project'),
            'extra_vars'    => $this->text()->null()
                ->comment('JSON object with default extra variables'),
            'verbosity'     => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
            'forks'         => $this->smallInteger()->unsigned()->notNull()->defaultValue(5),
            'become'        => $this->boolean()->notNull()->defaultValue(false),
            'become_method' => $this->string(32)->notNull()->defaultValue('sudo'),
            'become_user'   => $this->string(64)->notNull()->defaultValue('root'),
            'limit'         => $this->string(255)->null()
                ->comment('Host limit expression'),
            'tags'          => $this->string(512)->null(),
            'skip_tags'     => $this->string(512)->null(),
            'created_by'    => $this->integer()->unsigned()->notNull(),
            'created_at'    => $this->integer()->unsigned()->notNull(),
            'updated_at'    => $this->integer()->unsigned()->notNull(),
        ], $tableOptions);

        $this->createIndex('idx_jt_project_id',    '{{%job_template}}', 'project_id');
        $this->createIndex('idx_jt_inventory_id',  '{{%job_template}}', 'inventory_id');
        $this->createIndex('idx_jt_credential_id', '{{%job_template}}', 'credential_id');
        $this->createIndex('idx_jt_created_by',    '{{%job_template}}', 'created_by');

        $this->addForeignKey('fk_jt_project_id',    '{{%job_template}}', 'project_id',    '{{%project}}',    'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_jt_inventory_id',  '{{%job_template}}', 'inventory_id',  '{{%inventory}}',  'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_jt_credential_id', '{{%job_template}}', 'credential_id', '{{%credential}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk_jt_created_by',    '{{%job_template}}', 'created_by',    '{{%user}}',       'id', 'RESTRICT', 'CASCADE');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%job_template}}');
    }
}
