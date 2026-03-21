<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates the schedules table for cron-based job execution.
 *
 * cron_expression follows standard 5-field cron syntax: min hour dom mon dow
 * Example: "0 2 * * *" = every day at 02:00 UTC
 */
class m000013_create_schedules_table extends Migration
{
    public function safeUp(): void
    {
        $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';

        $this->createTable('{{%schedule}}', [
            'id'              => $this->primaryKey()->unsigned(),
            'name'            => $this->string(128)->notNull(),
            'job_template_id' => $this->integer()->unsigned()->notNull(),
            'cron_expression' => $this->string(64)->notNull()
                ->comment('Standard 5-field cron: min hour dom mon dow'),
            'timezone'        => $this->string(64)->notNull()->defaultValue('UTC'),
            'extra_vars'      => $this->text()->null()
                ->comment('JSON extra vars override for scheduled runs'),
            'enabled'         => $this->boolean()->notNull()->defaultValue(true),
            'last_run_at'     => $this->integer()->unsigned()->null(),
            'next_run_at'     => $this->integer()->unsigned()->null()
                ->comment('Pre-computed UTC timestamp of next execution'),
            'created_by'      => $this->integer()->unsigned()->notNull(),
            'created_at'      => $this->integer()->unsigned()->notNull(),
            'updated_at'      => $this->integer()->unsigned()->notNull(),
        ], $tableOptions);

        $this->createIndex('idx_schedule_template_id', '{{%schedule}}', 'job_template_id');
        $this->createIndex('idx_schedule_enabled',     '{{%schedule}}', 'enabled');
        $this->createIndex('idx_schedule_next_run_at', '{{%schedule}}', 'next_run_at');

        $this->addForeignKey(
            'fk_schedule_template_id',
            '{{%schedule}}', 'job_template_id',
            '{{%job_template}}', 'id',
            'CASCADE', 'CASCADE'
        );
        $this->addForeignKey(
            'fk_schedule_created_by',
            '{{%schedule}}', 'created_by',
            '{{%user}}', 'id',
            'RESTRICT', 'CASCADE'
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%schedule}}');
    }
}
