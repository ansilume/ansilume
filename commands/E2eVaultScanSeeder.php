<?php

declare(strict_types=1);

namespace app\commands;

use app\models\Credential;
use app\models\Inventory;
use app\models\Project;
use app\services\VaultScanService;
use yii\helpers\FileHelper;

/**
 * Fixtures for the vault scan, the vault check of job templates and its
 * warnings: a copy of the repository skeleton tests/fixtures/vault/repo as an
 * open manual project in 'Ansilume only' mode (every role sees it), scanned
 * during the seed, so the scan and the checks exist without a queue worker.
 *
 * - DEV_OK: the dev inventory with the dev password, which opens everything
 *   the template probably loads (status ok).
 * - PROD_WRONG: the prod inventory with the dev password, which does not open
 *   the prod vaults (mismatch). PROD_VAULT opens those, but not the dev
 *   vaults the playbook loads, so it does not fit either.
 * - NO_PASSWORD: the dev inventory without a vault password (missing_password).
 *
 * The copy lives in the runtime directory, which the dev compose file
 * bind-mounts: unlike /tmp it survives a recreated app container. Every seed
 * replaces it and teardown() removes it. Without the fixture (images built
 * without tests/) the seeder skips.
 *
 * Names never contain "e2e-template": other specs match rows by that text.
 */
class E2eVaultScanSeeder
{
    public const PROJECT = 'e2e-vault-scan-project';
    public const DEV_INVENTORY = 'e2e-vault-scan-dev';
    public const PROD_INVENTORY = 'e2e-vault-scan-prod';
    public const DEV_VAULT = 'e2e-vault-scan-devpass';
    public const PROD_VAULT = 'e2e-vault-scan-prodpass';
    public const DEV_OK = 'e2e-vault-scan-dev-ok';
    public const PROD_WRONG = 'e2e-vault-scan-prod-wrong';
    public const NO_PASSWORD = 'e2e-vault-scan-nopass';
    public const CHECKOUT = '@runtime/e2e-vault-scan';

    private const FIXTURE = '@app/tests/fixtures/vault/repo';
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
        $fixture = (string)\Yii::getAlias(self::FIXTURE);
        if (!is_dir($fixture)) {
            ($this->logger)("  Vault scan fixtures skipped: {$fixture} does not exist.\n");
            return;
        }
        $checkout = (string)\Yii::getAlias(self::CHECKOUT);
        $this->copyFixture($fixture, $checkout);

        $project = $this->ensureProject($checkout, $userId);
        $dev = E2eFixtureHelper::inventory(self::DEV_INVENTORY, Inventory::TYPE_FILE, $project->id, $userId, 'inventories/dev/hosts.yml');
        $prod = E2eFixtureHelper::inventory(self::PROD_INVENTORY, Inventory::TYPE_FILE, $project->id, $userId, 'inventories/prod/hosts.yml');
        $devVault = E2eFixtureHelper::credential(self::DEV_VAULT, Credential::TYPE_VAULT, $userId, ['vault_password' => self::DEV_SECRET]);
        E2eFixtureHelper::credential(self::PROD_VAULT, Credential::TYPE_VAULT, $userId, ['vault_password' => self::PROD_SECRET]);
        E2eFixtureHelper::template(self::DEV_OK, [$project->id, $dev->id, $runnerGroupId], $userId, null, [$devVault]);
        E2eFixtureHelper::template(self::PROD_WRONG, [$project->id, $prod->id, $runnerGroupId], $userId, null, [$devVault]);
        E2eFixtureHelper::template(self::NO_PASSWORD, [$project->id, $dev->id, $runnerGroupId], $userId, null, []);

        /** @var VaultScanService $scans */
        $scans = \Yii::$app->get('vaultScanService');
        $result = $scans->scanProject($project) ? 'scanned' : 'not scanned: ' . (string)$project->vault_scan_error;
        ($this->logger)("  Seeded vault scan project {$project->name} (ID {$project->id}) at {$checkout}, {$result}.\n");
    }

    /**
     * Removes the checkout; the e2e- prefix teardown removes the records.
     */
    public function teardown(): void
    {
        $checkout = (string)\Yii::getAlias(self::CHECKOUT);
        if (is_dir($checkout)) {
            FileHelper::removeDirectory($checkout);
            ($this->logger)("  Deleted vault scan checkout {$checkout}.\n");
        }
    }

    /**
     * Replaces the checkout with a fresh copy of the fixture. The seeder runs
     * as root in the app container; the copy then gets the owner of the
     * runtime directory, so the user of a dev checkout can still remove it.
     */
    private function copyFixture(string $fixture, string $checkout): void
    {
        FileHelper::removeDirectory($checkout);
        $owner = E2eFixtureHelper::runtimeOwner();
        $adopt = static function (string $path) use ($owner): void {
            if ($owner !== null) {
                chown($path, $owner['uid']);
                chgrp($path, $owner['gid']);
            }
        };
        FileHelper::copyDirectory($fixture, $checkout, [
            'dirMode' => 0o755,
            'fileMode' => 0o644,
            'afterCopy' => static fn (string $from, string $to) => $adopt($to),
        ]);
        $adopt($checkout);
    }

    private function ensureProject(string $checkout, int $userId): Project
    {
        $project = E2eFixtureHelper::project(self::PROJECT, $userId);
        $project->description = 'E2E copy of tests/fixtures/vault/repo for the vault scan';
        $project->local_path = $checkout;
        $project->vault_password_source = Project::VAULT_SOURCE_ANSILUME;
        $project->save(false);

        return $project;
    }
}
