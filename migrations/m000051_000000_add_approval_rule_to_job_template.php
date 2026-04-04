<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds approval_rule_id FK to job_template.
 */
class m000051_000000_add_approval_rule_to_job_template extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn(
            '{{%job_template}}',
            'approval_rule_id',
            $this->integer()->unsigned()->null()->after('runner_group_id')
        );

        $this->addForeignKey(
            'fk-job_template-approval_rule',
            '{{%job_template}}',
            'approval_rule_id',
            '{{%approval_rule}}',
            'id',
            'SET NULL',
            'CASCADE'
        );

        // Extend job.status ENUM with approval statuses
        $this->alterColumn(
            '{{%job}}',
            'status',
            "ENUM('pending','queued','running','succeeded','failed','canceled','timed_out',"
            . "'pending_approval','rejected') NOT NULL DEFAULT 'pending'"
        );
    }

    public function safeDown(): void
    {
        $this->alterColumn(
            '{{%job}}',
            'status',
            "ENUM('pending','queued','running','succeeded','failed','canceled','timed_out')"
            . " NOT NULL DEFAULT 'pending'"
        );
        $this->dropForeignKey('fk-job_template-approval_rule', '{{%job_template}}');
        $this->dropColumn('{{%job_template}}', 'approval_rule_id');
    }
}
