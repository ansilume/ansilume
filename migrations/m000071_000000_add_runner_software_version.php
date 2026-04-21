<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Add a `software_version` column to `runner`. Populated by the runner
 * on every heartbeat / claim request so the server can tell operators
 * when a runner is lagging behind the server (= needs its image pulled
 * or rebuilt). NULL means a pre-upgrade runner that doesn't yet send
 * the field — the UI treats that as "unknown".
 */
class m000071_000000_add_runner_software_version extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%runner}}', 'software_version', $this->string(32)->null()->defaultValue(null));
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%runner}}', 'software_version');
    }
}
