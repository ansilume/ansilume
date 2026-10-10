<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Columns for team-scoped workflows and triggers:
 *
 * - workflow_job_step.error_message: why a step failed without a job, for
 *   example because the user the workflow runs as may not launch the step's
 *   job template. Shown on the workflow job page and in the API.
 * - workflow_template.trigger_token_created_by and
 *   job_template.trigger_token_created_by: the user who generated the
 *   current trigger token. A trigger runs as that user. NULL for tokens
 *   generated before this migration, which keep running as the template's
 *   creator.
 *
 * The user FKs restrict deletes like every other created_by column.
 */
class m000077_000000_add_workflow_team_scoping extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn(
            '{{%workflow_job_step}}',
            'error_message',
            $this->string(500)->null()->defaultValue(null)
        );

        foreach (['workflow_template', 'job_template'] as $table) {
            $this->addColumn(
                "{{%{$table}}}",
                'trigger_token_created_by',
                $this->integer()->unsigned()->null()->defaultValue(null)
            );
            $this->addForeignKey(
                "fk-{$table}-trigger_token_created_by",
                "{{%{$table}}}",
                'trigger_token_created_by',
                '{{%user}}',
                'id',
                'RESTRICT',
                'CASCADE'
            );
        }
    }

    public function safeDown(): void
    {
        foreach (['job_template', 'workflow_template'] as $table) {
            $this->dropForeignKey("fk-{$table}-trigger_token_created_by", "{{%{$table}}}");
            $this->dropColumn("{{%{$table}}}", 'trigger_token_created_by');
        }
        $this->dropColumn('{{%workflow_job_step}}', 'error_message');
    }
}
