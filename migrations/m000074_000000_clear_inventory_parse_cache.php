<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Clears the cached "Parse Inventory" results of every inventory.
 *
 * Before vault isolation the server-side parse honoured a project's
 * ansible.cfg vault settings. Caches of file and dynamic inventories can
 * therefore hold decrypted values from vaulted group_vars, host_vars or
 * inventory files whose password was reachable on the server (a committed
 * password file or script, or a server-side ANSIBLE_VAULT_PASSWORD_FILE).
 * Static caches only hold inline vault ciphertext, which the parse now
 * masks; they are cleared too so that every cache is rebuilt the same way.
 * Rebuilding is one click on the inventory page.
 */
class m000074_000000_clear_inventory_parse_cache extends Migration
{
    public function safeUp(): void
    {
        $this->update(
            '{{%inventory}}',
            ['parsed_hosts' => null, 'parsed_error' => null, 'parsed_at' => null],
            ['not', ['parsed_at' => null]]
        );
    }

    public function safeDown(): void
    {
        // Nothing to restore: the cache is rebuilt by the next "Parse Inventory".
    }
}
