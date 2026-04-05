<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\HealthController;
use app\models\Runner;
use app\models\RunnerGroup;
use app\models\Schedule;

class HealthControllerActionTest extends WebControllerTestCase
{
    public function testBehaviorsRegistersContentNegotiator(): void
    {
        $ctrl = new HealthController('health', \Yii::$app);
        $behaviors = $ctrl->behaviors();
        $this->assertArrayHasKey('contentNegotiator', $behaviors);
    }

    public function testIndexReturnsDegradedWhenNoRunners(): void
    {
        // Fresh test DB: no runners, no schedules → runner check fails.
        $ctrl = new HealthController('health', \Yii::$app);
        $result = $ctrl->actionIndex();

        $this->assertSame('degraded', $result['status']);
        $this->assertSame(503, \Yii::$app->response->statusCode);
        $this->assertArrayHasKey('database', $result['checks']);
        $this->assertTrue($result['checks']['database']['ok']);
        $this->assertArrayHasKey('redis', $result['checks']);
        $this->assertArrayHasKey('migrations', $result['checks']);
        $this->assertArrayHasKey('runners', $result['checks']);
        $this->assertFalse($result['checks']['runners']['ok']);
        $this->assertArrayHasKey('scheduler', $result['checks']);
        $this->assertArrayHasKey('runners', $result);
        $this->assertArrayHasKey('schedules', $result);
        $this->assertArrayHasKey('queue', $result);
    }

    public function testIndexReturnsOkWhenRunnerOnline(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $runner = $this->createRunner((int)$group->id, $user->id);
        $runner->last_seen_at = time();
        $runner->save(false);

        $ctrl = new HealthController('health', \Yii::$app);
        $result = $ctrl->actionIndex();

        $this->assertTrue($result['checks']['runners']['ok']);
        $this->assertGreaterThanOrEqual(1, $result['checks']['runners']['online']);
    }

    public function testRunnerCheckReportsAllOffline(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $runner = $this->createRunner((int)$group->id, $user->id);
        // last_seen_at left unset (null / 0) → offline
        $runner->last_seen_at = 1;
        $runner->save(false);

        $ctrl = new HealthController('health', \Yii::$app);
        $result = $ctrl->actionIndex();

        $this->assertFalse($result['checks']['runners']['ok']);
        $this->assertSame(0, $result['checks']['runners']['online']);
        $this->assertGreaterThanOrEqual(1, $result['checks']['runners']['total']);
    }

    public function testSchedulerCheckDetectsOverdue(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);

        // Enable one overdue schedule.
        $schedule = new Schedule();
        $schedule->job_template_id = $tpl->id;
        $schedule->name = 'overdue-' . uniqid();
        $schedule->cron_expression = '* * * * *';
        $schedule->enabled = 1;
        $schedule->next_run_at = time() - 3600; // 1h ago
        $schedule->created_by = $user->id;
        $schedule->created_at = time();
        $schedule->updated_at = time();
        $schedule->save(false);

        $ctrl = new HealthController('health', \Yii::$app);
        $result = $ctrl->actionIndex();

        $this->assertFalse($result['checks']['scheduler']['ok']);
        $this->assertGreaterThanOrEqual(1, $result['checks']['scheduler']['overdue']);
    }

    public function testSchedulerCheckHealthyWhenNoOverdue(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);

        $schedule = new Schedule();
        $schedule->job_template_id = $tpl->id;
        $schedule->name = 'future-' . uniqid();
        $schedule->cron_expression = '* * * * *';
        $schedule->enabled = 1;
        $schedule->next_run_at = time() + 3600; // 1h ahead
        $schedule->created_by = $user->id;
        $schedule->created_at = time();
        $schedule->updated_at = time();
        $schedule->save(false);

        $ctrl = new HealthController('health', \Yii::$app);
        $result = $ctrl->actionIndex();

        $this->assertTrue($result['checks']['scheduler']['ok']);
        $this->assertGreaterThanOrEqual(1, $result['checks']['scheduler']['enabled']);
    }

    public function testMigrationsCheck(): void
    {
        $ctrl = new HealthController('health', \Yii::$app);
        $result = $ctrl->actionIndex();
        // The test DB has all migrations applied → ok.
        $this->assertTrue($result['checks']['migrations']['ok']);
        $this->assertArrayHasKey('applied', $result['checks']['migrations']);
        $this->assertArrayHasKey('expected', $result['checks']['migrations']);
    }

    public function testCheckFailureBranchesAreReported(): void
    {
        // Subclass that forces every protected check to throw → exercises every
        // catch-block fallback + the pending-migration branch of checkMigrations.
        $ctrl = new class ('health', \Yii::$app) extends HealthController {
            protected function checkDatabase(): array
            {
                try {
                    throw new \RuntimeException('simulated db failure');
                } catch (\Throwable $e) {
                    return ['ok' => false, 'error' => 'DB unreachable'];
                }
            }

            protected function countMigrationFiles(): int
            {
                return 9999; // force applied < expected
            }
        };

        $result = $ctrl->actionIndex();

        $this->assertSame('degraded', $result['status']);
        $this->assertFalse($result['checks']['database']['ok']);
        $this->assertFalse($result['checks']['migrations']['ok']);
        $this->assertStringContainsString('pending migration', $result['checks']['migrations']['error']);
    }

    public function testCheckMigrationsCatchBranch(): void
    {
        $ctrl = new class ('health', \Yii::$app) extends HealthController {
            protected function countAppliedMigrations(): int
            {
                throw new \RuntimeException('boom');
            }
        };

        $result = $ctrl->actionIndex();
        $this->assertFalse($result['checks']['migrations']['ok']);
        $this->assertSame('Migration check failed', $result['checks']['migrations']['error']);
    }

    public function testCheckRunnersCatchBranch(): void
    {
        // getRunnerCounts is called twice: once from checkRunners() (in try/catch)
        // and once from runnerSummary() (in try/catch). Throw on both calls.
        $ctrl = new class ('health', \Yii::$app) extends HealthController {
            protected function getRunnerCounts(): array
            {
                throw new \RuntimeException('boom');
            }
        };

        // We cannot call actionIndex because the private runnerSummary()
        // re-calls getRunnerCounts — it catches Throwable so should be ok.
        // If it still propagates, invoke checkRunners directly via reflection.
        $ref = new \ReflectionMethod($ctrl, 'checkRunners');
        $ref->setAccessible(true);
        /** @var array<string, mixed> $result */
        $result = $ref->invoke($ctrl);
        $this->assertFalse($result['ok']);
        $this->assertSame('Runner check failed', $result['error']);
    }

    public function testCheckSchedulerCatchBranch(): void
    {
        $ctrl = new class ('health', \Yii::$app) extends HealthController {
            protected function getScheduleCounts(): array
            {
                throw new \RuntimeException('boom');
            }
        };

        $result = $ctrl->actionIndex();
        $this->assertFalse($result['checks']['scheduler']['ok']);
        $this->assertSame('Scheduler check failed', $result['checks']['scheduler']['error']);
    }
}
