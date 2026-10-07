<?php

declare(strict_types=1);

namespace app\commands;

use app\models\Inventory;

/**
 * Fixtures for the rule that file and dynamic inventories must come from the
 * job template's project. Two open projects: SOURCE owns a file, a dynamic
 * and a static inventory; LEGACY in TARGET uses SOURCE's file inventory,
 * saved before that was rejected (read-only fixture for the warning, the
 * list filter and viewer RBAC).
 */
class E2eInventoryProjectSeeder
{
    public const SOURCE_PROJECT = 'e2e-xproj-source';
    public const TARGET_PROJECT = 'e2e-xproj-target';
    public const FILE_INVENTORY = 'e2e-xproj-file-inv';
    public const DYNAMIC_INVENTORY = 'e2e-xproj-dynamic-inv';
    public const STATIC_INVENTORY = 'e2e-xproj-static-inv';
    public const LEGACY = 'e2e-xproj-legacy';

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(int $userId, int $runnerGroupId): void
    {
        $source = E2eFixtureHelper::project(self::SOURCE_PROJECT, $userId);
        $target = E2eFixtureHelper::project(self::TARGET_PROJECT, $userId);
        $file = E2eFixtureHelper::inventory(self::FILE_INVENTORY, Inventory::TYPE_FILE, $source->id, $userId, 'hosts.yml');
        E2eFixtureHelper::inventory(self::DYNAMIC_INVENTORY, Inventory::TYPE_DYNAMIC, $source->id, $userId, 'inventory.py');
        E2eFixtureHelper::inventory(
            self::STATIC_INVENTORY,
            Inventory::TYPE_STATIC,
            $source->id,
            $userId,
            "all:\n  hosts:\n    e2e-xproj-host:\n      ansible_host: 192.0.2.31\n"
        );
        E2eFixtureHelper::template(self::LEGACY, [$target->id, $file->id, $runnerGroupId], $userId, null, []);
        ($this->logger)("  Seeded cross-project inventory fixtures (projects {$source->id} and {$target->id}).\n");
    }
}
