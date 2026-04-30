<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Inbound trigger token for workflow templates — mirror of the
 * job_template.trigger_token column. Allows external systems
 * (CI pipelines, monitoring, internal tooling) to fire a workflow
 * via `POST /trigger/fire-workflow?token=...` without a session
 * cookie or API token.
 *
 * The column stores a SHA-256 hex of the raw token; the raw value is
 * shown to the operator exactly once after generation and cannot be
 * recovered. Indexed for fast lookups in the trigger handler.
 */
class m000073_000000_add_workflow_template_trigger_token extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn(
            '{{%workflow_template}}',
            'trigger_token',
            $this->string(64)->null()->defaultValue(null)
                ->comment('SHA-256 hex of the raw inbound-trigger token; null = trigger disabled.')
        );
        $this->createIndex(
            'idx_workflow_template_trigger_token',
            '{{%workflow_template}}',
            'trigger_token'
        );
    }

    public function safeDown(): void
    {
        $this->dropIndex('idx_workflow_template_trigger_token', '{{%workflow_template}}');
        $this->dropColumn('{{%workflow_template}}', 'trigger_token');
    }
}
