<?php

declare(strict_types=1);

use yii\db\Migration;

class m000042_000000_add_check_mode_to_job extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%job}}', 'check_mode', $this->boolean()->notNull()->defaultValue(0)->after('verbosity'));
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%job}}', 'check_mode');
    }
}
