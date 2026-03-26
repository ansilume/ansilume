<?php

declare(strict_types=1);

namespace app\tests\unit\controllers;

use app\controllers\HealthController;
use PHPUnit\Framework\TestCase;

/**
 * Tests for HealthController check logic.
 *
 * The health endpoint checks database, Redis, runner availability, and scheduler.
 * These tests stub getRunnerCounts() and getScheduleCounts() to control state.
 */
class HealthControllerTest extends TestCase
{
    /**
     * Build a HealthController with stubbed runner/schedule counts.
     */
    private function makeController(
        array $runnerCounts,
        array $scheduleCounts = ['enabled' => 0, 'overdue' => 0],
        bool $dbOk = true,
        bool $redisOk = true,
        bool $migrationsOk = true,
    ): HealthController {
        return new class('health', \Yii::$app, $runnerCounts, $scheduleCounts, $dbOk, $redisOk, $migrationsOk) extends HealthController {
            public int    $capturedStatus = 0;
            private array $fakeRunnerCounts;
            private array $fakeScheduleCounts;
            private bool  $fakeDbOk;
            private bool  $fakeRedisOk;
            private bool  $fakeMigrationsOk;

            public function __construct($id, $module, array $rc, array $sc, bool $dbOk, bool $redisOk, bool $migrationsOk) {
                parent::__construct($id, $module);
                $this->fakeRunnerCounts   = $rc;
                $this->fakeScheduleCounts = $sc;
                $this->fakeDbOk           = $dbOk;
                $this->fakeRedisOk        = $redisOk;
                $this->fakeMigrationsOk   = $migrationsOk;
            }

            protected function getRunnerCounts(): array { return $this->fakeRunnerCounts; }
            protected function getScheduleCounts(): array { return $this->fakeScheduleCounts; }
            protected function setHttpStatus(int $code): void { $this->capturedStatus = $code; }
            protected function checkDatabase(): array { return $this->fakeDbOk ? ['ok' => true, 'latency_ms' => null] : ['ok' => false, 'error' => 'DB unreachable']; }
            protected function checkRedis(): array { return $this->fakeRedisOk ? ['ok' => true] : ['ok' => false, 'error' => 'Redis unreachable']; }
            protected function checkMigrations(): array { return $this->fakeMigrationsOk ? ['ok' => true, 'applied' => 36, 'expected' => 36] : ['ok' => false, 'error' => '3 pending migration(s)', 'applied' => 33, 'expected' => 36]; }

            public function testCheckRunners(): array { return $this->checkRunners(); }
            public function testCheckScheduler(): array { return $this->checkScheduler(); }
        };
    }

    // ── checkRunners() ─────────────────────────────────────────────────────

    public function testCheckRunnersReturnsFalseWhenNoRunners(): void
    {
        $ctrl = $this->makeController(['total' => 0, 'online' => 0, 'offline' => 0]);
        $result = $ctrl->testCheckRunners();

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testCheckRunnersReturnsFalseWhenAllOffline(): void
    {
        $ctrl = $this->makeController(['total' => 4, 'online' => 0, 'offline' => 4]);
        $result = $ctrl->testCheckRunners();

        $this->assertFalse($result['ok']);
    }

    public function testCheckRunnersReturnsTrueWhenRunnersOnline(): void
    {
        $ctrl = $this->makeController(['total' => 4, 'online' => 2, 'offline' => 2]);
        $result = $ctrl->testCheckRunners();

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['online']);
        $this->assertSame(4, $result['total']);
    }

    // ── runChecks() structure ──────────────────────────────────────────────

    public function testRunChecksIncludesAllKeys(): void
    {
        $ctrl = $this->makeController(['total' => 1, 'online' => 1, 'offline' => 0]);
        $ref  = new \ReflectionMethod($ctrl, 'runChecks');
        $ref->setAccessible(true);

        $checks = $ref->invoke($ctrl);

        $this->assertArrayHasKey('database', $checks);
        $this->assertArrayHasKey('redis', $checks);
        $this->assertArrayHasKey('migrations', $checks);
        $this->assertArrayHasKey('runners', $checks);
        $this->assertArrayHasKey('scheduler', $checks);
    }

    // ── Full action — HTTP status ──────────────────────────────────────────

    public function testActionIndexReturnsOkWhenRunnersOnline(): void
    {
        $ctrl = $this->makeController(['total' => 2, 'online' => 2, 'offline' => 0]);
        $response = $ctrl->actionIndex();

        $this->assertSame('ok', $response['status']);
        $this->assertSame(200, $ctrl->capturedStatus);
    }

    public function testActionIndexReturnsDegradedWhenNoRunnersOnline(): void
    {
        $ctrl = $this->makeController(['total' => 2, 'online' => 0, 'offline' => 2]);
        $response = $ctrl->actionIndex();

        $this->assertSame('degraded', $response['status']);
        $this->assertSame(503, $ctrl->capturedStatus);
        $this->assertFalse($response['checks']['runners']['ok']);
    }

    public function testActionIndexReturnsDegradedWhenNoRunnersRegistered(): void
    {
        $ctrl = $this->makeController(['total' => 0, 'online' => 0, 'offline' => 0]);
        $response = $ctrl->actionIndex();

        $this->assertSame('degraded', $response['status']);
        $this->assertSame(503, $ctrl->capturedStatus);
    }

    // ── Response structure ─────────────────────────────────────────────────

    public function testResponseIncludesRunnersSection(): void
    {
        $ctrl = $this->makeController(['total' => 4, 'online' => 2, 'offline' => 2]);
        $response = $ctrl->actionIndex();

        $this->assertArrayHasKey('runners', $response);
        $this->assertSame(4, $response['runners']['total']);
        $this->assertSame(2, $response['runners']['online']);
        $this->assertSame(2, $response['runners']['offline']);
    }

    public function testResponseIncludesQueueSection(): void
    {
        $ctrl = $this->makeController(['total' => 1, 'online' => 1, 'offline' => 0]);
        $response = $ctrl->actionIndex();

        $this->assertArrayHasKey('queue', $response);
        $this->assertArrayHasKey('pending', $response['queue']);
        $this->assertArrayHasKey('running', $response['queue']);
    }

    public function testResponseIncludesSchedulesSection(): void
    {
        $ctrl = $this->makeController(
            ['total' => 1, 'online' => 1, 'offline' => 0],
            ['enabled' => 3, 'overdue' => 0],
        );
        $response = $ctrl->actionIndex();

        $this->assertArrayHasKey('schedules', $response);
        $this->assertArrayHasKey('total', $response['schedules']);
        $this->assertArrayHasKey('enabled', $response['schedules']);
    }

    // ── migrations ────────────────────────────────────────────────────

    public function testActionIndexReturnsDegradedWhenMigrationsPending(): void
    {
        $ctrl = $this->makeController(
            ['total' => 2, 'online' => 2, 'offline' => 0],
            ['enabled' => 0, 'overdue' => 0],
            true,
            true,
            false,
        );
        $response = $ctrl->actionIndex();

        $this->assertSame('degraded', $response['status']);
        $this->assertSame(503, $ctrl->capturedStatus);
        $this->assertFalse($response['checks']['migrations']['ok']);
    }

    // ── checkScheduler() ────────────────────────────────────────────────

    public function testCheckSchedulerOkWhenNoEnabledSchedules(): void
    {
        $ctrl = $this->makeController(
            ['total' => 1, 'online' => 1, 'offline' => 0],
            ['enabled' => 0, 'overdue' => 0],
        );
        $result = $ctrl->testCheckScheduler();

        $this->assertTrue($result['ok']);
    }

    public function testCheckSchedulerOkWhenNoOverdue(): void
    {
        $ctrl = $this->makeController(
            ['total' => 1, 'online' => 1, 'offline' => 0],
            ['enabled' => 5, 'overdue' => 0],
        );
        $result = $ctrl->testCheckScheduler();

        $this->assertTrue($result['ok']);
        $this->assertSame(5, $result['enabled']);
    }

    public function testCheckSchedulerFailsWhenOverdue(): void
    {
        $ctrl = $this->makeController(
            ['total' => 1, 'online' => 1, 'offline' => 0],
            ['enabled' => 3, 'overdue' => 2],
        );
        $result = $ctrl->testCheckScheduler();

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame(2, $result['overdue']);
    }

    public function testActionIndexReturnsDegradedWhenSchedulerOverdue(): void
    {
        $ctrl = $this->makeController(
            ['total' => 2, 'online' => 2, 'offline' => 0],
            ['enabled' => 1, 'overdue' => 1],
        );
        $response = $ctrl->actionIndex();

        $this->assertSame('degraded', $response['status']);
        $this->assertSame(503, $ctrl->capturedStatus);
        $this->assertFalse($response['checks']['scheduler']['ok']);
    }
}
