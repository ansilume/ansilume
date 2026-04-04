<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates approval_rule, approval_request, and approval_decision tables.
 */
class m000050_000000_create_approval_tables extends Migration
{
    public function safeUp(): void
    {
        // -- approval_rule --------------------------------------------------------
        $this->createTable('{{%approval_rule}}', [
            'id' => $this->primaryKey()->unsigned(),
            'name' => $this->string(128)->notNull(),
            'description' => $this->text()->null(),
            'job_template_id' => $this->integer()->unsigned()->null(),
            'required_approvals' => $this->integer()->notNull()->defaultValue(1),
            'timeout_minutes' => $this->integer()->null(),
            'timeout_action' => $this->string(16)->notNull()->defaultValue('reject'),
            'approver_type' => $this->string(16)->notNull()->comment('role, team, users'),
            'approver_config' => $this->text()->notNull()->comment('JSON'),
            'created_by' => $this->integer()->unsigned()->notNull(),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ]);

        $this->addForeignKey(
            'fk-approval_rule-created_by',
            '{{%approval_rule}}',
            'created_by',
            '{{%user}}',
            'id',
            'RESTRICT',
            'CASCADE'
        );

        // -- approval_request -----------------------------------------------------
        $this->createTable('{{%approval_request}}', [
            'id' => $this->primaryKey()->unsigned(),
            'job_id' => $this->integer()->unsigned()->notNull(),
            'approval_rule_id' => $this->integer()->unsigned()->notNull(),
            'status' => $this->string(32)->notNull()->defaultValue('pending'),
            'requested_at' => $this->integer()->notNull(),
            'resolved_at' => $this->integer()->null(),
            'expires_at' => $this->integer()->null(),
        ]);

        $this->createIndex('idx-approval_request-job', '{{%approval_request}}', 'job_id', true);
        $this->createIndex('idx-approval_request-status', '{{%approval_request}}', 'status');

        $this->addForeignKey(
            'fk-approval_request-job',
            '{{%approval_request}}',
            'job_id',
            '{{%job}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            'fk-approval_request-rule',
            '{{%approval_request}}',
            'approval_rule_id',
            '{{%approval_rule}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        // -- approval_decision ----------------------------------------------------
        $this->createTable('{{%approval_decision}}', [
            'id' => $this->primaryKey()->unsigned(),
            'approval_request_id' => $this->integer()->unsigned()->notNull(),
            'user_id' => $this->integer()->unsigned()->notNull(),
            'decision' => $this->string(16)->notNull()->comment('approved or rejected'),
            'comment' => $this->text()->null(),
            'created_at' => $this->integer()->notNull(),
        ]);

        $this->createIndex(
            'idx-approval_decision-unique',
            '{{%approval_decision}}',
            ['approval_request_id', 'user_id'],
            true
        );

        $this->addForeignKey(
            'fk-approval_decision-request',
            '{{%approval_decision}}',
            'approval_request_id',
            '{{%approval_request}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            'fk-approval_decision-user',
            '{{%approval_decision}}',
            'user_id',
            '{{%user}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%approval_decision}}');
        $this->dropTable('{{%approval_request}}');
        $this->dropTable('{{%approval_rule}}');
    }
}
