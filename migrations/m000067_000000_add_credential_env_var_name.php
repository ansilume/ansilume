<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Add `env_var_name` to credential so TYPE_TOKEN credentials can specify
 * a custom environment variable name for the injected secret.
 *
 * Without this, every token credential's secret ended up at the same
 * hardcoded `ANSILUME_CREDENTIAL_TOKEN` — fine for a single token per
 * run, broken as soon as a job template references two tokens.
 */
class m000067_000000_add_credential_env_var_name extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn(
            '{{%credential}}',
            'env_var_name',
            $this->string(128)->null()->after('username')
                ->comment('Optional env var name for TYPE_TOKEN; falls back to ANSILUME_CREDENTIAL_TOKEN.')
        );
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%credential}}', 'env_var_name');
    }
}
