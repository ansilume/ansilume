<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Refreshes the description of the seeded "Demo — Localhost" inventory to
 * warn operators that playbooks run here target the runner container itself
 * and can leak files into the host repository via the /var/www bind mount.
 *
 * Only updates the row if the description still matches the original seed
 * text, so manually edited inventories are left alone.
 */
class m000059_000000_demo_localhost_warning extends Migration
{
    private const INVENTORY_NAME = 'Demo — Localhost';
    private const OLD_DESCRIPTION = 'Localhost inventory for testing Demo playbooks on the runner host itself.';
    private const NEW_DESCRIPTION = "Localhost inventory for testing Demo playbooks against the runner container itself. "
        . "WARNING: playbooks that install packages or write files will mutate the runner and can leak into the host "
        . "repository via the /var/www bind mount. Use for read-only smoke tests (ping, gather facts) only.";

    public function safeUp(): void
    {
        $this->db->createCommand(
            'UPDATE {{%inventory}}
             SET description = :new, updated_at = :now
             WHERE name = :name AND description = :old',
            [
                ':new' => self::NEW_DESCRIPTION,
                ':old' => self::OLD_DESCRIPTION,
                ':name' => self::INVENTORY_NAME,
                ':now' => time(),
            ]
        )->execute();
    }

    public function safeDown(): void
    {
        $this->db->createCommand(
            'UPDATE {{%inventory}}
             SET description = :old, updated_at = :now
             WHERE name = :name AND description = :new',
            [
                ':old' => self::OLD_DESCRIPTION,
                ':new' => self::NEW_DESCRIPTION,
                ':name' => self::INVENTORY_NAME,
                ':now' => time(),
            ]
        )->execute();
    }
}
