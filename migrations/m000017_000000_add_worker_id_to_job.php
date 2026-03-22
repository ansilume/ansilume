<?php

declare(strict_types=1);

use yii\db\Migration;

class m000017_000000_add_worker_id_to_job extends Migration
{
    public function up(): void
    {
        $this->addColumn('{{%job}}', 'worker_id', $this->string(128)->null()->after('pid'));
    }

    public function down(): void
    {
        $this->dropColumn('{{%job}}', 'worker_id');
    }
}
