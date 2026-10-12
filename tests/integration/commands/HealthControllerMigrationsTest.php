<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\HealthController;
use app\components\PendingMigrations;
use app\tests\integration\DbTestCase;
use yii\console\ExitCode;

/**
 * The migrations line of health/check, read through PendingMigrations,
 * which schedule/run checks too.
 */
class HealthControllerMigrationsTest extends DbTestCase
{
    public function testAllMigrationsAppliedIsHealthy(): void
    {
        $ctrl = $this->makeController(new class () extends PendingMigrations {
            public function expectedCount(): int
            {
                return 80;
            }

            public function appliedCount(): int
            {
                return 80;
            }
        });

        $ctrl->actionCheck();

        $this->assertStringContainsString("[health] migrations: ok (80/80)\n", $ctrl->captured);
    }

    public function testPendingMigrationsMakeTheCheckFail(): void
    {
        $ctrl = $this->makeController(new class () extends PendingMigrations {
            public function expectedCount(): int
            {
                return 81;
            }

            public function appliedCount(): int
            {
                return 79;
            }
        });

        $result = $ctrl->actionCheck();

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $result);
        $this->assertStringContainsString("[health] migrations: 2 pending (79/81)\n", $ctrl->captured);
        $this->assertStringContainsString('[health] status: degraded', $ctrl->captured);
    }

    public function testAnUnreadableMigrationTableIsReported(): void
    {
        $ctrl = $this->makeController(new class () extends PendingMigrations {
            public function appliedCount(): int
            {
                throw new \RuntimeException('migration table unreadable');
            }
        });

        $result = $ctrl->actionCheck();

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $result);
        $this->assertStringContainsString('[health] migrations: error — migration table unreadable', $ctrl->captured);
    }

    /**
     * By default the check reads the application's migrations.
     */
    public function testTheCheckReadsTheApplicationsMigrations(): void
    {
        $method = new \ReflectionMethod(HealthController::class, 'pendingMigrations');
        $migrations = $method->invoke(new HealthController('health', \Yii::$app));
        $files = glob((string)\Yii::getAlias('@app/migrations') . '/m*.php');
        $this->assertNotFalse($files);

        $this->assertInstanceOf(PendingMigrations::class, $migrations);
        $this->assertSame(count($files), $migrations->expectedCount());
    }

    private function makeController(PendingMigrations $migrations): HealthController
    {
        return new class ('health', \Yii::$app, $migrations) extends HealthController {
            public string $captured = '';

            /**
             * @param array<string, mixed> $config
             */
            public function __construct($id, $module, private readonly PendingMigrations $migrations, array $config = [])
            {
                parent::__construct($id, $module, $config);
            }

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

            protected function pendingMigrations(): PendingMigrations
            {
                return $this->migrations;
            }
        };
    }
}
