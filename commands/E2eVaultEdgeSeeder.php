<?php

declare(strict_types=1);

namespace app\commands;

use app\models\Credential;
use app\models\Inventory;
use app\models\Project;
use app\services\VaultScanService;
use yii\helpers\FileHelper;

/**
 * Fixtures for the vault check results beyond ok, mismatch and a missing
 * password: a small manual project in 'Ansilume and repository' mode whose
 * ansible.cfg sets vault_id_match (and supplies no password), built from the
 * vault fixtures at seed time and scanned during the seed.
 *
 * - LABELLED: an inventory whose vault has the vault ID prod, with the prod
 *   password. It opens the vault, but vault_id_match keeps Ansible from trying
 *   the template's password on it (mismatch, reason vault_id_match).
 * - DAMAGED: an inventory whose vault has a trailing space, which Ansible
 *   cannot read with any password (damaged).
 * - ODD_NAME: an inventory whose vault file name is not valid UTF-8, so
 *   Ansilume cannot check it (incomplete, reason file_name).
 *
 * The vault passwords are those of E2eVaultScanSeeder. The checkout lives in
 * the runtime directory and teardown() removes it. Without the fixtures
 * (images built without tests/) the seeder skips.
 */
class E2eVaultEdgeSeeder
{
    public const PROJECT = 'e2e-vault-edge-project';
    public const LABELLED = 'e2e-vault-edge-labelled';
    public const DAMAGED = 'e2e-vault-edge-damaged';
    public const ODD_NAME = 'e2e-vault-edge-oddname';
    public const CHECKOUT = '@runtime/e2e-vault-edge';

    private const FIXTURES = '@app/tests/fixtures/vault';
    /** Dummy secrets of the fixture vaults, see tests/fixtures/vault/README.md. */
    private const DEV_SECRET = 'ansilume-test-dummy-dev';
    private const PROD_SECRET = 'ansilume-test-dummy-prod';

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(int $userId, int $runnerGroupId): void
    {
        $fixtures = (string)\Yii::getAlias(self::FIXTURES);
        if (!is_file($fixtures . '/file-1.2-prod.yml')) {
            ($this->logger)("  Vault edge fixtures skipped: {$fixtures} does not exist.\n");
            return;
        }
        $checkout = (string)\Yii::getAlias(self::CHECKOUT);
        $this->buildCheckout($fixtures, $checkout);

        $project = E2eFixtureHelper::project(self::PROJECT, $userId);
        $project->description = 'E2E vault check edge cases: vault_id_match, a damaged vault, a file name that is not UTF-8';
        $project->local_path = $checkout;
        $project->vault_password_source = Project::VAULT_SOURCE_REPOSITORY;
        $project->save(false);
        $dev = E2eFixtureHelper::credential(E2eVaultScanSeeder::DEV_VAULT, Credential::TYPE_VAULT, $userId, ['vault_password' => self::DEV_SECRET]);
        $prod = E2eFixtureHelper::credential(E2eVaultScanSeeder::PROD_VAULT, Credential::TYPE_VAULT, $userId, ['vault_password' => self::PROD_SECRET]);
        foreach ([self::LABELLED => ['labelled', $prod], self::DAMAGED => ['damaged', $dev], self::ODD_NAME => ['oddname', $dev]] as $name => [$directory, $vault]) {
            $inventory = E2eFixtureHelper::inventory($name, Inventory::TYPE_FILE, (int)$project->id, $userId, "inventories/{$directory}/hosts.yml");
            E2eFixtureHelper::template($name, [(int)$project->id, (int)$inventory->id, $runnerGroupId], $userId, $vault, []);
        }

        /** @var VaultScanService $scans */
        $scans = \Yii::$app->get('vaultScanService');
        $result = $scans->scanProject($project) ? 'scanned' : 'not scanned: ' . (string)$project->vault_scan_error;
        ($this->logger)("  Seeded vault edge project {$project->name} (ID {$project->id}) at {$checkout}, {$result}.\n");
    }

    /**
     * Removes the checkout; the e2e- prefix teardown removes the records.
     */
    public function teardown(): void
    {
        $checkout = (string)\Yii::getAlias(self::CHECKOUT);
        if (is_dir($checkout)) {
            FileHelper::removeDirectory($checkout);
            ($this->logger)("  Deleted vault edge checkout {$checkout}.\n");
        }
    }

    /**
     * Writes a fresh checkout. Files get the owner of the runtime directory
     * when the seeder runs as root, so the user of a dev checkout can still
     * remove them.
     */
    private function buildCheckout(string $fixtures, string $checkout): void
    {
        FileHelper::removeDirectory($checkout);
        $hosts = "---\nall:\n  hosts:\n    edge-web1:\n      ansible_connection: local\n";
        $files = [
            'ansible.cfg' => "[defaults]\nvault_id_match = True\n",
            'site.yml' => "---\n- hosts: all\n  gather_facts: false\n  tasks: []\n",
            'inventories/labelled/hosts.yml' => $hosts,
            'inventories/labelled/group_vars/all/vault.yml' => (string)file_get_contents($fixtures . '/file-1.2-prod.yml'),
            'inventories/damaged/hosts.yml' => $hosts,
            'inventories/damaged/group_vars/all/vault.yml' => (string)file_get_contents($fixtures . '/malformed-trailing-space.yml'),
            'inventories/oddname/hosts.yml' => $hosts,
            "inventories/oddname/group_vars/all/odd-\xFF.yml" => (string)file_get_contents($fixtures . '/file-1.1.yml'),
        ];
        $owner = E2eFixtureHelper::runtimeOwner();
        foreach ($files as $relative => $content) {
            $path = $checkout . '/' . $relative;
            FileHelper::createDirectory(dirname($path), 0o755);
            file_put_contents($path, $content);
            chmod($path, 0o644);
        }
        if ($owner !== null) {
            foreach (array_merge([$checkout], FileHelper::findDirectories($checkout), FileHelper::findFiles($checkout)) as $path) {
                chown($path, $owner['uid']);
                chgrp($path, $owner['gid']);
            }
        }
    }
}
