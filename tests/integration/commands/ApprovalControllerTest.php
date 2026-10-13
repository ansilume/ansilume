<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\ApprovalController;
use app\components\PendingMigrations;
use app\models\ApprovalRequest;
use app\models\Job;
use app\services\ApprovalService;
use app\tests\integration\DbTestCase;
use app\tests\integration\services\CancellationFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\console\ExitCode;

/**
 * approval/check-timeouts, for own cron setups: it applies the timeout of
 * every expired request (the maintenance run does it in batches) and waits
 * while migrations are pending.
 */
class ApprovalControllerTest extends DbTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;

    private mixed $originalApprovals = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalApprovals = \Yii::$app->getComponents(true)['approvalService'] ?? null;
        $this->recordNotifications();
    }

    protected function tearDown(): void
    {
        \Yii::$app->set('approvalService', $this->originalApprovals);
        $this->restoreNotifications();
        parent::tearDown();
    }

    public function testAppliesTheTimeoutOfAnExpiredRequestAndPrintsTheCount(): void
    {
        $launcher = $this->createUserWithRole('check-timeouts-launcher', 'operator');
        $approver = $this->createUserWithRole('check-timeouts-approver', 'operator');
        ['job' => $job, 'request' => $request] = $this->jobWaitingForApproval(
            $this->cancelFixtureTemplate((int)$launcher->id),
            (int)$launcher->id,
            (int)$approver->id
        );
        ApprovalRequest::updateAll(['expires_at' => time() - 60], ['id' => $request->id]);

        $ctrl = $this->makeController(0);
        $exit = $ctrl->actionCheckTimeouts();

        $this->assertSame(ExitCode::OK, $exit);
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_TIMED_OUT, $request->status);
        $job->refresh();
        $this->assertSame(Job::STATUS_REJECTED, $job->status);
        $this->assertStringContainsString(sprintf(ApprovalController::PROCESSED, 1) . PHP_EOL, $ctrl->captured);
    }

    /**
     * Every expired request at once: the command passes no limit.
     */
    public function testProcessesAllExpiredRequestsWithoutALimit(): void
    {
        $approvals = $this->stubApprovals(250);

        $ctrl = $this->makeController(0);
        $ctrl->actionCheckTimeouts();

        $this->assertSame([0], $approvals->limits, '0 means all');
        $this->assertSame([null], $approvals->deadlines, 'and no time bound');
        $this->assertSame(sprintf(ApprovalController::PROCESSED, 250) . PHP_EOL, $ctrl->captured);
    }

    public function testStaysQuietWhenNothingExpired(): void
    {
        $this->stubApprovals(0);

        $ctrl = $this->makeController(0);

        $this->assertSame(ExitCode::OK, $ctrl->actionCheckTimeouts());
        $this->assertSame('', $ctrl->captured);
    }

    public function testAppliesNothingWhileMigrationsArePending(): void
    {
        $approvals = $this->stubApprovals(3);

        $ctrl = $this->makeController(2);
        $exit = $ctrl->actionCheckTimeouts();

        $this->assertSame(ExitCode::OK, $exit, 'own cron setups stay quiet and healthy');
        $this->assertSame([], $approvals->limits);
        $this->assertSame(sprintf(ApprovalController::MIGRATIONS_PENDING, 2) . PHP_EOL, $ctrl->captured);
    }

    public function testTheRealCommandReadsTheMigrationState(): void
    {
        $approvals = $this->stubApprovals(0);
        $ctrl = new class ('approval', $this->app()) extends ApprovalController {
            public string $captured = '';

            public function stdout($string): int
            {
                $this->captured .= $string;
                return 0;
            }
        };

        $this->assertSame(ExitCode::OK, $ctrl->actionCheckTimeouts());
        $this->assertSame([0], $approvals->limits, 'the test database is up to date');
    }

    /**
     * @return ApprovalController&object{captured: string}
     */
    private function makeController(int $pending): ApprovalController
    {
        return new class ('approval', $this->app(), $pending) extends ApprovalController {
            public string $captured = '';

            /**
             * @param array<string, mixed> $config
             */
            public function __construct($id, $module, private readonly int $pending, array $config = [])
            {
                parent::__construct($id, $module, $config);
            }

            public function stdout($string): int
            {
                $this->captured .= $string;
                return 0;
            }

            protected function pendingMigrations(): PendingMigrations
            {
                return new class ($this->pending) extends PendingMigrations {
                    public function __construct(private readonly int $pending)
                    {
                        parent::__construct();
                    }

                    public function count(): int
                    {
                        return $this->pending;
                    }
                };
            }
        };
    }

    private function app(): \yii\base\Module
    {
        $this->assertNotNull(\Yii::$app);

        return \Yii::$app;
    }

    /**
     * @return ApprovalService&object{limits: list<int>, deadlines: list<float|null>}
     */
    private function stubApprovals(int $processed): ApprovalService
    {
        $stub = new class ($processed) extends ApprovalService {
            /** @var list<int> */
            public array $limits = [];

            /** @var list<float|null> */
            public array $deadlines = [];

            public function __construct(private readonly int $processed)
            {
                parent::__construct();
            }

            public function processTimeouts(int $limit = 0, ?float $deadline = null): int
            {
                $this->limits[] = $limit;
                $this->deadlines[] = $deadline;

                return $this->processed;
            }
        };
        \Yii::$app->set('approvalService', $stub);

        return $stub;
    }
}
