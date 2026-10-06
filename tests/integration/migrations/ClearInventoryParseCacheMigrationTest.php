<?php

declare(strict_types=1);

namespace app\tests\integration\migrations;

use app\models\Inventory;
use app\tests\integration\DbTestCase;

/**
 * Parse caches written before vault isolation can hold decrypted vault
 * values; the migration clears every cache and nothing else.
 */
class ClearInventoryParseCacheMigrationTest extends DbTestCase
{
    private function inventory(string $type, ?string $parsedHosts, ?string $parsedError, ?int $parsedAt): Inventory
    {
        $inventory = new Inventory();
        $inventory->name = 'migration-' . uniqid();
        $inventory->inventory_type = $type;
        $inventory->content = $type === Inventory::TYPE_STATIC ? "all:\n  hosts:\n    web1:\n" : null;
        $inventory->source_path = $type === Inventory::TYPE_STATIC ? null : 'inventory/hosts.yml';
        $inventory->parsed_hosts = $parsedHosts;
        $inventory->parsed_error = $parsedError;
        $inventory->parsed_at = $parsedAt;
        $inventory->created_by = (int)$this->createUser('migration')->id;
        $inventory->save(false);

        return $inventory;
    }

    public function testSafeUpClearsEveryParseCache(): void
    {
        $leaky = $this->inventory(Inventory::TYPE_FILE, '{"hosts":{"web1":{"secret_var":"PLAINTEXT"}}}', null, time() - 60);
        $failed = $this->inventory(Inventory::TYPE_DYNAMIC, null, 'ansible-inventory failed (exit 1)', time() - 60);
        $static = $this->inventory(Inventory::TYPE_STATIC, '{"hosts":{"web1":{"token":{"__ansible_vault":"x"}}}}', null, time() - 60);
        $never = $this->inventory(Inventory::TYPE_STATIC, null, null, null);

        require_once dirname(__DIR__, 3) . '/migrations/m000074_000000_clear_inventory_parse_cache.php';
        $migration = new \m000074_000000_clear_inventory_parse_cache(['db' => \Yii::$app->db, 'compact' => true]);
        ob_start();
        try {
            $migration->safeUp();
        } finally {
            ob_end_clean();
        }

        foreach ([$leaky, $failed, $static, $never] as $inventory) {
            $inventory->refresh();
            $this->assertNull($inventory->parsed_hosts);
            $this->assertNull($inventory->parsed_error);
            $this->assertNull($inventory->parsed_at);
        }
        $this->assertSame("all:\n  hosts:\n    web1:\n", $static->content, 'only the cache is touched');
        $this->assertSame('inventory/hosts.yml', $leaky->source_path);
    }
}
