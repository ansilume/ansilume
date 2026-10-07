<?php

declare(strict_types=1);

namespace app\commands;

use app\models\Credential;
use app\models\Inventory;
use app\models\Project;

/**
 * Fixtures for the one-vault-password rule and the bulk assignment, all in
 * an open project so every role sees them:
 *
 * - LEGACY and REPAIR hold two vault passwords, saved before that was
 *   rejected. LEGACY is read-only (warning specs, viewer RBAC); REPAIR is
 *   fixed by job-templates/vault-rule.spec.ts.
 * - BULK_NONE has no vault password, BULK_OTHER has VAULT_B; the
 *   credentials/vault-assign.spec.ts assigns VAULT_A to both.
 * - BULK_BETA sits in the e2e-beta-proj project of team beta, which the
 *   operator cannot even see, so the operator's picker must not list it.
 *   The "may see but not change" case is e2e-alpha-viewed-tmpl from
 *   E2eTeamScopingSeeder.
 *
 * Names never contain "e2e-template": other specs match rows by that text.
 */
class E2eVaultAssignmentSeeder
{
    public const PROJECT = 'e2e-vault-assign-project';
    public const INVENTORY = 'e2e-vault-assign-inventory';
    public const SSH = 'e2e-vault-assign-ssh';
    public const VAULT_A = 'e2e-vault-assign-a';
    public const VAULT_B = 'e2e-vault-assign-b';
    public const LEGACY = 'e2e-two-vaults-legacy';
    public const REPAIR = 'e2e-two-vaults-fixme';
    public const BULK_NONE = 'e2e-vault-bulk-none';
    public const BULK_OTHER = 'e2e-vault-bulk-other';
    public const BULK_BETA = 'e2e-vault-bulk-beta';

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(int $userId, int $runnerGroupId): void
    {
        $project = E2eFixtureHelper::project(self::PROJECT, $userId);
        $inventory = E2eFixtureHelper::inventory(
            self::INVENTORY,
            Inventory::TYPE_STATIC,
            $project->id,
            $userId,
            "all:\n  hosts:\n    e2e-vault-assign-host:\n      ansible_host: 192.0.2.30\n"
        );
        $ssh = E2eFixtureHelper::credential(self::SSH, Credential::TYPE_SSH_KEY, $userId, [
            'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ne2e-placeholder\n-----END OPENSSH PRIVATE KEY-----\n",
        ], 'deploy');
        $vaultA = E2eFixtureHelper::credential(self::VAULT_A, Credential::TYPE_VAULT, $userId, ['vault_password' => 'e2e-vault-a-placeholder']);
        $vaultB = E2eFixtureHelper::credential(self::VAULT_B, Credential::TYPE_VAULT, $userId, ['vault_password' => 'e2e-vault-b-placeholder']);

        $parents = [$project->id, $inventory->id, $runnerGroupId];
        E2eFixtureHelper::template(self::LEGACY, $parents, $userId, $ssh, [$vaultA, $vaultB]);
        E2eFixtureHelper::template(self::REPAIR, $parents, $userId, $ssh, [$vaultA, $vaultB]);
        E2eFixtureHelper::template(self::BULK_NONE, $parents, $userId, $ssh, []);
        E2eFixtureHelper::template(self::BULK_OTHER, $parents, $userId, $ssh, [$vaultB]);

        $beta = Project::findOne(['name' => 'e2e-beta-proj']);
        $betaInventory = Inventory::findOne(['name' => 'e2e-beta-inv']);
        if ($beta !== null && $betaInventory !== null) {
            E2eFixtureHelper::template(self::BULK_BETA, [$beta->id, $betaInventory->id, $runnerGroupId], $userId, $ssh, []);
        }
        ($this->logger)("  Seeded vault rule and assignment fixtures (project ID {$project->id}).\n");
    }
}
