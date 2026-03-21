<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds a trigger_token column to job_template for inbound webhook triggering.
 *
 * POST /trigger/{token}  triggers a job for the matching template without
 * requiring full Bearer-token API authentication.
 *
 * The token is a 32-byte random hex string (64 hex chars), generated once
 * and stored in plain text. Operators treat it like a secret URL.
 */
class m000015_add_trigger_token_to_job_template extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn(
            '{{%job_template}}',
            'trigger_token',
            $this->string(64)->null()
                ->comment('Random hex token for inbound /trigger/{token} endpoint; null = disabled')
                ->after('skip_tags')
        );

        $this->createIndex(
            'idx_job_template_trigger_token',
            '{{%job_template}}',
            'trigger_token',
            true  // unique
        );
    }

    public function safeDown(): void
    {
        $this->dropIndex('idx_job_template_trigger_token', '{{%job_template}}');
        $this->dropColumn('{{%job_template}}', 'trigger_token');
    }
}
