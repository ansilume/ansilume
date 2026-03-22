<?php

declare(strict_types=1);

use yii\db\Migration;

class m000028_000000_add_project_sync_error extends Migration
{
    public function up(): void
    {
        $this->addColumn('{{%project}}', 'last_sync_error', $this->text()->null()->after('last_synced_at'));
    }

    public function down(): void
    {
        $this->dropColumn('{{%project}}', 'last_sync_error');
    }
}
