<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Updates the selftest project's local_path from /var/www/selftest
 * to /var/www/ansible/selftest after the directory was moved.
 */
class m000039_000000_move_selftest_to_ansible_dir extends Migration
{
    public function safeUp(): void
    {
        $affected = $this->db->createCommand()
            ->update(
                '{{%project}}',
                ['local_path' => '/var/www/ansible/selftest'],
                ['local_path' => '/var/www/selftest', 'name' => 'Selftest']
            )
            ->execute();

        if ($affected > 0) {
            echo "    > Updated selftest project path to /var/www/ansible/selftest.\n";
        } else {
            echo "    > Selftest project not found or already updated — skipping.\n";
        }
    }

    public function safeDown(): void
    {
        $this->db->createCommand()
            ->update(
                '{{%project}}',
                ['local_path' => '/var/www/selftest'],
                ['local_path' => '/var/www/ansible/selftest', 'name' => 'Selftest']
            )
            ->execute();
    }
}
