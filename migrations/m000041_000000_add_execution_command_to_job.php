<?php

declare(strict_types=1);

use yii\db\Migration;

class m000041_000000_add_execution_command_to_job extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%job}}', 'execution_command', $this->text()->null()->after('runner_payload')->comment('Ansible command built at claim/run time'));
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%job}}', 'execution_command');
    }
}
