<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates workflow_job and workflow_job_step tables for workflow execution.
 */
class m000054_000000_create_workflow_job_tables extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%workflow_job}}', [
            'id' => $this->primaryKey()->unsigned(),
            'workflow_template_id' => $this->integer()->unsigned()->notNull(),
            'status' => $this->string(32)->notNull()->defaultValue('running'),
            'launched_by' => $this->integer()->unsigned()->notNull(),
            'current_step_id' => $this->integer()->unsigned()->null(),
            'started_at' => $this->integer()->null(),
            'finished_at' => $this->integer()->null(),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ]);

        $this->addForeignKey(
            'fk-workflow_job-template',
            '{{%workflow_job}}',
            'workflow_template_id',
            '{{%workflow_template}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            'fk-workflow_job-launched_by',
            '{{%workflow_job}}',
            'launched_by',
            '{{%user}}',
            'id',
            'RESTRICT',
            'CASCADE'
        );

        $this->createIndex('idx-workflow_job-status', '{{%workflow_job}}', 'status');

        $this->createTable('{{%workflow_job_step}}', [
            'id' => $this->primaryKey()->unsigned(),
            'workflow_job_id' => $this->integer()->unsigned()->notNull(),
            'workflow_step_id' => $this->integer()->unsigned()->notNull(),
            'job_id' => $this->integer()->unsigned()->null(),
            'status' => $this->string(32)->notNull()->defaultValue('pending'),
            'started_at' => $this->integer()->null(),
            'finished_at' => $this->integer()->null(),
            'output_vars' => $this->text()->null()->comment('JSON'),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ]);

        $this->addForeignKey(
            'fk-wjs-workflow_job',
            '{{%workflow_job_step}}',
            'workflow_job_id',
            '{{%workflow_job}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            'fk-wjs-workflow_step',
            '{{%workflow_job_step}}',
            'workflow_step_id',
            '{{%workflow_step}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->createIndex('idx-wjs-job', '{{%workflow_job_step}}', 'job_id');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%workflow_job_step}}');
        $this->dropTable('{{%workflow_job}}');
    }
}
