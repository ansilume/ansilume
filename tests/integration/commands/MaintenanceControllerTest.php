<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\MaintenanceController;
use app\services\ArtifactService;
use app\services\MaintenanceService;
use app\tests\integration\DbTestCase;
use yii\console\ExitCode;

/**
 * Integration tests for the console MaintenanceController.
 */
class MaintenanceControllerTest extends DbTestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/ansilume_maintenance_cmd_' . uniqid('', true);
        mkdir($this->tempDir, 0750, true);
        \Yii::$app->cache->flush();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        \Yii::$app->cache->flush();
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    /**
     * @return MaintenanceController&object{captured: string}
     */
    private function makeController(): MaintenanceController
    {
        return new class ('maintenance', \Yii::$app) extends MaintenanceController {
            public string $captured = '';

            public function stdout($string): int
            {
                $this->captured .= $string;
                return 0;
            }

            public function stderr($string): int
            {
                $this->captured .= $string;
                return 0;
            }
        };
    }

    private function makeArtifactService(): ArtifactService
    {
        $svc = new ArtifactService();
        $svc->storagePath = $this->tempDir . '/storage';
        return $svc;
    }

    public function testRunPrintsTaskNameWhenSomethingExecutes(): void
    {
        $svc = $this->makeArtifactService();
        // Seed an orphan so the cleanup actually does something visible.
        $orphanDir = $this->tempDir . '/storage/job_999';
        mkdir($orphanDir, 0750, true);
        file_put_contents($orphanDir . '/o.txt', 'x');

        \Yii::$app->set('artifactService', $svc);
        $maintenance = new MaintenanceService();
        $maintenance->artifactCleanupIntervalSeconds = 3600;
        \Yii::$app->set('maintenanceService', $maintenance);

        $ctrl = $this->makeController();
        $exit = $ctrl->actionRun();

        $this->assertSame(ExitCode::OK, $exit);
        $this->assertStringContainsString('ran: artifact-cleanup', $ctrl->captured);
        $this->assertStringContainsString('orphans=1', $ctrl->captured);
    }

    public function testRunStaysQuietWhenNothingIsDue(): void
    {
        \Yii::$app->set('artifactService', $this->makeArtifactService());

        $maintenance = new MaintenanceService();
        $maintenance->artifactCleanupIntervalSeconds = 0; // disabled
        \Yii::$app->set('maintenanceService', $maintenance);

        $ctrl = $this->makeController();
        $exit = $ctrl->actionRun();

        $this->assertSame(ExitCode::OK, $exit);
        $this->assertSame('', $ctrl->captured, 'no output expected when no task ran');
    }

    public function testRunStaysQuietOnSecondInvocationDueToCooldown(): void
    {
        \Yii::$app->set('artifactService', $this->makeArtifactService());

        $maintenance = new MaintenanceService();
        $maintenance->artifactCleanupIntervalSeconds = 3600;
        \Yii::$app->set('maintenanceService', $maintenance);

        // First call: cleanup runs (no orphans → orphans=0, but the task did run).
        $first = $this->makeController();
        $first->actionRun();
        $this->assertStringContainsString('ran: artifact-cleanup', $first->captured);

        // Second call: cooldown is in effect → no output.
        $second = $this->makeController();
        $exit = $second->actionRun();
        $this->assertSame(ExitCode::OK, $exit);
        $this->assertSame('', $second->captured);
    }
}
