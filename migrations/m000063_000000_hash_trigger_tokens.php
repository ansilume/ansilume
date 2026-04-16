<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Hashes the trigger_token column in job_template.
 *
 * Previously stored as plain text (64-char random hex). Now stored as SHA-256
 * hex of the raw token, matching the ApiToken pattern. The SHA-256 hex is also
 * 64 chars, so the column definition does not change.
 *
 * Existing external integrations continue to work because the raw token they
 * already hold is re-hashed and compared on lookup.
 */
class m000063_000000_hash_trigger_tokens extends Migration
{
    public function safeUp(): void
    {
        // Re-hash any existing plaintext tokens in place. Tokens that look like
        // they are already SHA-256 hashes cannot be distinguished from plaintext
        // here (both are 64 hex chars), so we hash once: any existing row that
        // has never been touched by the new code path will be migrated to its
        // hash exactly once by this migration.
        $rows = $this->db->createCommand(
            'SELECT id, trigger_token FROM {{%job_template}} WHERE trigger_token IS NOT NULL'
        )->queryAll();

        foreach ($rows as $row) {
            $raw = (string)$row['trigger_token'];
            if ($raw === '') {
                continue;
            }
            $hash = hash('sha256', $raw);
            $this->db->createCommand()
                ->update('{{%job_template}}', ['trigger_token' => $hash], ['id' => (int)$row['id']])
                ->execute();
        }

        // Refresh the column comment to reflect the new semantics.
        $this->alterColumn(
            '{{%job_template}}',
            'trigger_token',
            $this->string(64)->null()
                ->comment('SHA-256 hex of raw trigger token for /trigger/{token}; null = disabled')
        );
    }

    public function safeDown(): void
    {
        // Cannot recover the raw tokens from their hashes. The best we can do
        // is null them out so operators regenerate fresh ones.
        $this->update('{{%job_template}}', ['trigger_token' => null], ['IS NOT', 'trigger_token', null]);

        $this->alterColumn(
            '{{%job_template}}',
            'trigger_token',
            $this->string(64)->null()
                ->comment('Random hex token for inbound /trigger/{token} endpoint; null = disabled')
        );
    }
}
