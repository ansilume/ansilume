<?php

declare(strict_types=1);

use yii\db\Migration;

class m000019_000000_add_has_changes_to_job extends Migration
{
    public function up(): void
    {
        $this->addColumn('{{%job}}', 'has_changes', $this->tinyInteger(1)->notNull()->defaultValue(0)->after('worker_id'));
    }

    public function down(): void
    {
        $this->dropColumn('{{%job}}', 'has_changes');
    }
}
