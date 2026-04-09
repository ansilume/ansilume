<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Makes job.job_template_id nullable so workflow approval steps can create
 * placeholder Job records without a real job template.
 */
class m000062_000000_make_job_template_id_nullable extends Migration
{
    public function safeUp(): void
    {
        // Drop the existing foreign key first
        $this->dropForeignKey('fk_job_template_id', '{{%job}}');

        // Make the column nullable
        $this->alterColumn('{{%job}}', 'job_template_id', $this->integer()->unsigned()->null());

        // Re-add the foreign key with SET NULL on delete
        $this->addForeignKey(
            'fk_job_template_id',
            '{{%job}}',
            'job_template_id',
            '{{%job_template}}',
            'id',
            'SET NULL',
            'CASCADE'
        );
    }

    public function safeDown(): void
    {
        $this->dropForeignKey('fk_job_template_id', '{{%job}}');

        // Set any NULL values to avoid constraint violation
        $this->update('{{%job}}', ['job_template_id' => 0], ['job_template_id' => null]);

        $this->alterColumn('{{%job}}', 'job_template_id', $this->integer()->unsigned()->notNull());

        $this->addForeignKey(
            'fk_job_template_id',
            '{{%job}}',
            'job_template_id',
            '{{%job_template}}',
            'id',
            'RESTRICT',
            'CASCADE'
        );
    }
}
