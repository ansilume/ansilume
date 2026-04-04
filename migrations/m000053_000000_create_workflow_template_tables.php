<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates workflow_template and workflow_step tables.
 */
class m000053_000000_create_workflow_template_tables extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%workflow_template}}', [
            'id' => $this->primaryKey()->unsigned(),
            'name' => $this->string(128)->notNull(),
            'description' => $this->text()->null(),
            'created_by' => $this->integer()->unsigned()->notNull(),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
            'deleted_at' => $this->integer()->null(),
        ]);

        $this->addForeignKey(
            'fk-workflow_template-created_by',
            '{{%workflow_template}}',
            'created_by',
            '{{%user}}',
            'id',
            'RESTRICT',
            'CASCADE'
        );

        $this->createTable('{{%workflow_step}}', [
            'id' => $this->primaryKey()->unsigned(),
            'workflow_template_id' => $this->integer()->unsigned()->notNull(),
            'name' => $this->string(128)->notNull(),
            'step_order' => $this->integer()->notNull()->defaultValue(0),
            'step_type' => $this->string(16)->notNull()->comment('job, approval, pause'),
            'job_template_id' => $this->integer()->unsigned()->null(),
            'approval_rule_id' => $this->integer()->unsigned()->null(),
            'on_success_step_id' => $this->integer()->unsigned()->null(),
            'on_failure_step_id' => $this->integer()->unsigned()->null(),
            'on_always_step_id' => $this->integer()->unsigned()->null(),
            'extra_vars_template' => $this->text()->null()->comment('JSON variable mapping'),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ]);

        $this->addForeignKey(
            'fk-workflow_step-template',
            '{{%workflow_step}}',
            'workflow_template_id',
            '{{%workflow_template}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->createIndex(
            'idx-workflow_step-order',
            '{{%workflow_step}}',
            ['workflow_template_id', 'step_order']
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%workflow_step}}');
        $this->dropTable('{{%workflow_template}}');
    }
}
