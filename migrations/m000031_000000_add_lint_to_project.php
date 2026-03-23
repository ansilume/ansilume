<?php

declare(strict_types=1);

use yii\db\Migration;

class m000031_000000_add_lint_to_project extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%project}}', 'lint_output',    $this->text()->null()->after('last_sync_error'));
        $this->addColumn('{{%project}}', 'lint_at',        $this->integer()->unsigned()->null()->after('lint_output'));
        $this->addColumn('{{%project}}', 'lint_exit_code', $this->smallInteger()->null()->after('lint_at'));
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%project}}', 'lint_output');
        $this->dropColumn('{{%project}}', 'lint_at');
        $this->dropColumn('{{%project}}', 'lint_exit_code');
    }
}
