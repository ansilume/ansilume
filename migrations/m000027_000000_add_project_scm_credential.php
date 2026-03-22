<?php

declare(strict_types=1);

use yii\db\Migration;

class m000027_000000_add_project_scm_credential extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%project}}', 'scm_credential_id', $this->integer()->null()->after('scm_branch'));
        $this->addForeignKey(
            'fk_project_scm_credential',
            '{{%project}}', 'scm_credential_id',
            '{{%credential}}', 'id',
            'SET NULL'
        );
    }

    public function safeDown(): void
    {
        $this->dropForeignKey('fk_project_scm_credential', '{{%project}}');
        $this->dropColumn('{{%project}}', 'scm_credential_id');
    }
}
