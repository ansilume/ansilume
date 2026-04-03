<?php

declare(strict_types=1);

use yii\db\Migration;

class m000043_000000_add_index_project_scm_credential extends Migration
{
    public function safeUp(): void
    {
        $this->createIndex('idx_project_scm_credential', '{{%project}}', 'scm_credential_id');
    }

    public function safeDown(): void
    {
        $this->dropIndex('idx_project_scm_credential', '{{%project}}');
    }
}
