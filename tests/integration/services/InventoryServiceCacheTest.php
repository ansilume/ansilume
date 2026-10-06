<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Inventory;
use app\services\AnsibleInventoryRunner;
use app\services\InventoryService;
use app\tests\integration\DbTestCase;

/**
 * The parse cache keeps vault notices next to hosts and groups, and caches
 * written before notices existed still render.
 */
class InventoryServiceCacheTest extends DbTestCase
{
    private function inventory(): Inventory
    {
        $user = $this->createUser('cache');
        $inventory = new Inventory();
        $inventory->name = 'cache-' . uniqid();
        $inventory->inventory_type = Inventory::TYPE_STATIC;
        $inventory->content = "all:\n  hosts:\n    web1:\n";
        $inventory->created_by = (int)$user->id;
        $inventory->save(false);

        return $inventory;
    }

    public function testNoticesAreCachedAndReturned(): void
    {
        $runner = new class () extends AnsibleInventoryRunner {
            public function isAvailable(): bool
            {
                return true;
            }

            public function run(string $inventoryPath, ?string $cwd = null): array
            {
                $json = '{"_meta":{"hostvars":{"web1":{"token":{"__ansible_vault":"$ANSIBLE_VAULT;1.1;AES256\\n61"}}}},"all":{"hosts":["web1"]}}';

                return ['stdout' => $json, 'stderr' => '', 'exit_code' => 0, 'error' => null];
            }
        };
        $service = new InventoryService();
        $service->setRunner($runner);
        $inventory = $this->inventory();

        $service->resolveAndCache($inventory);
        $inventory->refresh();
        $cached = $service->getCached($inventory);

        $this->assertNotNull($cached);
        $this->assertSame([sprintf(InventoryService::NOTICE_VAULT_VALUES, 1)], $cached['notices']);
        $this->assertSame('[vault-encrypted]', $cached['hosts']['web1']['token']);
        $this->assertStringNotContainsString('ANSIBLE_VAULT;', (string)$inventory->parsed_hosts);
    }

    public function testCachesFromBeforeNoticesStillRender(): void
    {
        $inventory = $this->inventory();
        $inventory->parsed_hosts = '{"groups":{"all":{"hosts":["web1"],"children":[],"vars":[]}},"hosts":{"web1":[]}}';
        $inventory->parsed_error = null;
        $inventory->parsed_at = time();
        $inventory->save(false);

        $cached = (new InventoryService())->getCached($inventory);

        $this->assertNotNull($cached);
        $this->assertSame([], $cached['notices']);
        $this->assertArrayHasKey('web1', $cached['hosts']);
    }

    public function testACachedErrorHasNoNotices(): void
    {
        $inventory = $this->inventory();
        $inventory->parsed_error = InventoryService::ERROR_ENCRYPTED_SOURCE;
        $inventory->parsed_at = time();
        $inventory->save(false);

        $cached = (new InventoryService())->getCached($inventory);

        $this->assertSame(['groups' => [], 'hosts' => [], 'error' => InventoryService::ERROR_ENCRYPTED_SOURCE, 'notices' => []], $cached);
    }
}
