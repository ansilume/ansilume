<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\HealthController;
use app\tests\integration\DbTestCase;
use yii\console\ExitCode;

/**
 * Integration tests for the console HealthController.
 *
 * Verifies that health/check reports accurate status for database, Redis,
 * migrations, RBAC roles, runtime directories, and user accounts.
 */
class HealthControllerTest extends DbTestCase
{
    private function makeController(): HealthController
    {
        return new class ('health', \Yii::$app) extends HealthController {
            /** @var string */
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

    public function testCheckReturnsOkWhenHealthy(): void
    {
        $ctrl = $this->makeController();
        $result = $ctrl->actionCheck();

        $this->assertSame(ExitCode::OK, $result);
        $this->assertStringContainsString('[health] db: ok', $ctrl->captured);
        $this->assertStringContainsString('[health] redis: ok', $ctrl->captured);
        $this->assertStringContainsString('[health] status: ok', $ctrl->captured);
    }

    public function testCheckReportsRbacRoles(): void
    {
        $ctrl = $this->makeController();
        $ctrl->actionCheck();

        $this->assertStringContainsString('[health] rbac: ok', $ctrl->captured);
    }

    public function testCheckReportsRuntimeDirs(): void
    {
        $ctrl = $this->makeController();
        $ctrl->actionCheck();

        $this->assertStringContainsString('[health] dirs: ok', $ctrl->captured);
    }

    public function testCheckReportsMigrations(): void
    {
        $ctrl = $this->makeController();
        $ctrl->actionCheck();

        $this->assertStringContainsString('[health] migrations: ok', $ctrl->captured);
    }

    public function testCheckReportsUserCount(): void
    {
        $ctrl = $this->makeController();
        $ctrl->actionCheck();

        // Test DB may or may not have users — just check the line exists
        $this->assertMatchesRegularExpression('/\[health\] users: /', $ctrl->captured);
    }
}
