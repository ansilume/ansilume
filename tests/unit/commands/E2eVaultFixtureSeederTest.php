<?php

declare(strict_types=1);

namespace app\tests\unit\commands;

use app\commands\E2eVaultFixtureSeeder;
use app\components\ProcessStarter;
use app\helpers\FileHelper;
use app\tests\unit\ProcessStartFailure;
use PHPUnit\Framework\TestCase;

/**
 * Regression (PHP 8.3): without ansible-vault the seeder skips the vault
 * fixtures, as it promises, and the specs that need them skip too. But
 * proc_open() reported the missing ansible-vault with a warning
 * (posix_spawn), which threw: php yii e2e/seed aborted, and the E2E setup
 * with it.
 */
class E2eVaultFixtureSeederTest extends TestCase
{
    public function testTheVaultFixturesAreSkippedWithoutAnsibleVault(): void
    {
        $dir = sys_get_temp_dir() . '/ansilume_e2e_vault_' . uniqid('', true);
        $log = '';
        $seeder = new E2eVaultFixtureSeeder(static function (string $line) use (&$log): void {
            $log .= $line;
        }, $dir);

        $path = ProcessStartFailure::emptyPath();
        try {
            $seeder->seed(1, 1);
        } finally {
            $path->restore();
            FileHelper::removeDirectory($dir);
        }

        $this->assertSame('  Vault fixtures skipped: ' . sprintf(ProcessStarter::NOT_STARTED, 'ansible-vault', ProcessStartFailure::NO_SUCH_FILE) . "\n", $log);
    }
}
