<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\PendingMigrations;
use app\models\AuditLog;
use app\models\JobArtifact;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\Project;
use app\models\ProjectSyncLog;
use app\services\ArtifactService;
use app\services\MaintenanceService;
use app\services\notification\NotificationDeliveryService;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\services\notification\RecordingNotificationQueue;
use yii\log\Logger;

/**
 * Integration tests for MaintenanceService.
 *
 * The Yii test app uses ArrayCache, so the cooldown is per-process and
 * naturally reset between tests by the framework. Each test deliberately
 * flushes the cache in setUp to keep the cooldown state predictable.
 */
class MaintenanceServiceTest extends DbTestCase
{
    use RestoresComponents;

    private string $tempDir;

    private RecordingNotificationQueue $notificationQueue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notificationQueue = new RecordingNotificationQueue();
        $this->swapComponent('notificationQueue', $this->notificationQueue);
        $this->keepComponent('notificationDeliveryService');
        $this->keepComponent('artifactService');
        $this->tempDir = sys_get_temp_dir() . '/ansilume_maintenance_int_' . uniqid('', true);
        mkdir($this->tempDir, 0750, true);
        \Yii::$app->cache->flush();
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
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

    private function makeArtifactService(int $retentionDays = 0): ArtifactService
    {
        $svc = new ArtifactService();
        $svc->storagePath = $this->tempDir . '/storage';
        $svc->retentionDays = $retentionDays;
        return $svc;
    }

    public function testRunIfDueRunsArtifactCleanupOnFirstCall(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $svc = $this->makeArtifactService(7);
        $sourceDir = $this->tempDir . '/source';
        mkdir($sourceDir, 0750, true);
        file_put_contents($sourceDir . '/old.txt', 'old');
        $svc->collectFromDirectory($job, $sourceDir);

        $artifact = JobArtifact::find()->where(['job_id' => $job->id])->one();
        $artifact->created_at = time() - (10 * 86400);
        $artifact->save(false);

        \Yii::$app->set('artifactService', $svc);

        $maintenance = new MaintenanceService();
        $maintenance->artifactCleanupIntervalSeconds = 3600;

        $report = $maintenance->runIfDue();

        $this->assertSame(['artifact-cleanup'], $report['ran']);
        // stale-sync-sweep skips on every tick when there's nothing to recover,
        // the approval timeouts when no request expired, the sweep of deleted
        // job templates when it finds nothing, and the survey password scrub
        // when no finished job has answers.
        $this->assertSame(
            [
                ['task' => 'stale-sync-sweep', 'reason' => 'none-stale'],
                ['task' => MaintenanceService::TASK_APPROVAL_TIMEOUTS, 'reason' => MaintenanceService::SKIP_NONE_EXPIRED],
                ['task' => MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP, 'reason' => MaintenanceService::SKIP_NONE_FOUND],
                ['task' => MaintenanceService::TASK_NOTIFICATION_SWEEP, 'reason' => MaintenanceService::SKIP_NONE_FOUND],
                ['task' => 'survey-secret-scrub', 'reason' => 'done'],
            ],
            $report['skipped']
        );
        $this->assertSame(1, $report['results']['artifact-cleanup']['expired']);
        $this->assertSame(0, $report['results']['artifact-cleanup']['by_count']);
        $this->assertSame(0, $report['results']['artifact-cleanup']['quota_trimmed']);
        $this->assertSame(0, $report['results']['artifact-cleanup']['orphans']);

        // The cleanup actually executed — the expired row is gone and an
        // audit entry is on file.
        $this->assertCount(0, $svc->getArtifacts($job));
        $this->assertGreaterThan(
            0,
            (int)AuditLog::find()->where(['action' => AuditLog::ACTION_ARTIFACT_EXPIRED])->count()
        );
    }

    public function testRunIfDueSkipsWhenCooldownActive(): void
    {
        \Yii::$app->set('artifactService', $this->makeArtifactService(0));

        $maintenance = new MaintenanceService();
        $maintenance->artifactCleanupIntervalSeconds = 3600;

        $first = $maintenance->runIfDue();
        $second = $maintenance->runIfDue();

        $this->assertSame(['artifact-cleanup'], $first['ran']);
        $this->assertSame([], $second['ran'], 'second invocation must be throttled');
        // stale-sync-sweep runs on every tick, so it always shows up in `skipped`
        // when there's nothing to recover. Match by task name only.
        $skippedTasks = array_column($second['skipped'], 'task');
        $this->assertContains('artifact-cleanup', $skippedTasks);
        $artifactSkip = array_values(array_filter($second['skipped'], fn ($s) => $s['task'] === 'artifact-cleanup'));
        $this->assertSame('cooldown', $artifactSkip[0]['reason']);
    }

    public function testRunIfDueReportsDisabledWhenIntervalZero(): void
    {
        \Yii::$app->set('artifactService', $this->makeArtifactService(0));

        $maintenance = new MaintenanceService();
        $maintenance->artifactCleanupIntervalSeconds = 0;

        $report = $maintenance->runIfDue();

        $this->assertSame([], $report['ran']);
        $skippedTasks = array_column($report['skipped'], 'task');
        $this->assertContains('artifact-cleanup', $skippedTasks);
        $artifactSkip = array_values(array_filter($report['skipped'], fn ($s) => $s['task'] === 'artifact-cleanup'));
        $this->assertSame('disabled', $artifactSkip[0]['reason']);
    }

    public function testRunIfDueRunsOrphanCleanupEvenWithRetentionDisabled(): void
    {
        $svc = $this->makeArtifactService(0);
        $orphanDir = $this->tempDir . '/storage/job_999';
        mkdir($orphanDir, 0750, true);
        file_put_contents($orphanDir . '/orphan.txt', 'data');

        \Yii::$app->set('artifactService', $svc);

        $maintenance = new MaintenanceService();
        $maintenance->artifactCleanupIntervalSeconds = 3600;

        $report = $maintenance->runIfDue();

        $this->assertSame(['artifact-cleanup'], $report['ran']);
        $this->assertSame(0, $report['results']['artifact-cleanup']['expired']);
        $this->assertSame(0, $report['results']['artifact-cleanup']['by_count']);
        $this->assertSame(0, $report['results']['artifact-cleanup']['quota_trimmed']);
        $this->assertSame(1, $report['results']['artifact-cleanup']['orphans']);
        $this->assertFalse(file_exists($orphanDir . '/orphan.txt'));
    }

    public function testRunIfDueReportsAllFourCounters(): void
    {
        \Yii::$app->set('artifactService', $this->makeArtifactService(0));

        $maintenance = new MaintenanceService();
        $maintenance->artifactCleanupIntervalSeconds = 3600;

        $report = $maintenance->runIfDue();

        $this->assertSame(['artifact-cleanup'], $report['ran']);
        $this->assertSame(
            ['expired', 'by_count', 'quota_trimmed', 'orphans'],
            array_keys($report['results']['artifact-cleanup'])
        );
        $this->assertSame(0, $report['results']['artifact-cleanup']['expired']);
        $this->assertSame(0, $report['results']['artifact-cleanup']['by_count']);
        $this->assertSame(0, $report['results']['artifact-cleanup']['quota_trimmed']);
        $this->assertSame(0, $report['results']['artifact-cleanup']['orphans']);
    }

    public function testRunIfDueActuallyInvokesJobCountAndQuotaTrim(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);

        $svc = $this->makeArtifactService(0);
        // Seed three jobs with one artifact each, deterministic ordering.
        for ($i = 0; $i < 3; $i++) {
            $job = $this->createJob($template->id, $user->id);
            $dir = $this->tempDir . '/src' . $i;
            mkdir($dir, 0750, true);
            file_put_contents($dir . '/a.txt', str_repeat('x', 100));
            $svc->collectFromDirectory($job, $dir);
            $artifact = JobArtifact::find()->where(['job_id' => $job->id])->one();
            $artifact->created_at = 1_000_000 + $i;
            $artifact->save(false);
        }
        $svc->maxJobsWithArtifacts = 2; // Oldest job will be trimmed.

        \Yii::$app->set('artifactService', $svc);

        $maintenance = new MaintenanceService();
        $maintenance->artifactCleanupIntervalSeconds = 3600;

        $report = $maintenance->runIfDue();

        $this->assertSame(1, $report['results']['artifact-cleanup']['by_count']);
        $this->assertSame(0, $report['results']['artifact-cleanup']['quota_trimmed']);
    }

    // -------------------------------------------------------------------------
    // The sweep of notification deliveries
    // -------------------------------------------------------------------------

    /**
     * Every tick sweeps the notification outbox
     * (NotificationDeliveryService::sweep()), without a cooldown: a
     * delivery whose message was lost is pushed again within a minute or
     * two. It reports its counts when it changed something, and is quiet
     * (none-found) otherwise.
     */
    public function testEveryTickSweepsTheNotificationOutbox(): void
    {
        $template = $this->createNotificationTemplate((int)$this->createUser('maint-sweep')->id);
        $lost = new NotificationDelivery();
        $lost->notification_template_id = (int)$template->id;
        $lost->event = NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN;
        $lost->setVariableMap([]);
        $lost->next_attempt_at = time() - 300;
        $this->assertTrue($lost->save());
        $maintenance = $this->maintenanceWithoutOtherTasks();

        $first = $maintenance->runIfDue();
        $second = $maintenance->runIfDue();

        $this->assertSame([MaintenanceService::TASK_NOTIFICATION_SWEEP], $first['ran']);
        $this->assertSame(['repushed' => 1, 'reset' => 0, 'purged' => 0], $first['results'][MaintenanceService::TASK_NOTIFICATION_SWEEP]);
        $this->assertSame([(int)$lost->id], $this->notificationQueue->pushedIds());
        $this->assertSame([], $second['ran'], 'pushed again a minute ago: not yet again');
        $this->assertContains(['task' => MaintenanceService::TASK_NOTIFICATION_SWEEP, 'reason' => MaintenanceService::SKIP_NONE_FOUND], $second['skipped']);
        $this->assertSame('notification-sweep', MaintenanceService::TASK_NOTIFICATION_SWEEP);
    }

    /**
     * The sweep reads and changes the outbox, which a pending migration may
     * not have made yet: it waits while migrations are pending, as the
     * other tasks that change jobs do.
     */
    public function testTheNotificationSweepWaitsWhileMigrationsArePending(): void
    {
        $sweeps = $this->recordingSweeps();

        $report = $this->maintenanceWithoutOtherTasks(2)->runIfDue();

        $this->assertSame([], $sweeps->calls);
        $this->assertContains(['task' => MaintenanceService::TASK_NOTIFICATION_SWEEP, 'reason' => MaintenanceService::SKIP_MIGRATIONS_PENDING], $report['skipped']);
    }

    /**
     * The sweep gets the deadline the tasks that change jobs share: it starts
     * no further batch once the tick's budget is used up.
     */
    public function testTheNotificationSweepGetsTheSharedDeadline(): void
    {
        $sweeps = $this->recordingSweeps();
        $maintenance = $this->maintenanceWithoutOtherTasks();
        $maintenance->jobTaskBudgetSeconds = 45.0;
        $before = microtime(true);

        $maintenance->runIfDue();
        $maintenance->jobTaskBudgetSeconds = 0;
        $maintenance->runIfDue();

        $this->assertCount(2, $sweeps->calls);
        [$now, $deadline] = $sweeps->calls[0];
        $this->assertEqualsWithDelta(time(), $now, 2);
        $this->assertNotNull($deadline);
        $this->assertGreaterThanOrEqual($before + 45.0, $deadline);
        $this->assertLessThanOrEqual(microtime(true) + 45.0, $deadline);
        $this->assertNull($sweeps->calls[1][1], 'no budget: no deadline');
    }

    /**
     * An error of the sweep is logged by its class only, never its message
     * (a database error's carries the SQL statement with its values); the
     * next tick sweeps again.
     */
    public function testANotificationSweepErrorIsLoggedWithoutItsMessage(): void
    {
        $sweeps = $this->recordingSweeps(new \yii\db\Exception("SQLSTATE[HY000]: UPDATE notification_delivery SET last_error = 'maint-marker-8810'"));
        $maintenance = $this->maintenanceWithoutOtherTasks();

        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $report = $maintenance->runIfDue();
            $maintenance->runIfDue();
        } finally {
            \Yii::setLogger($previous);
        }

        $this->assertContains(['task' => MaintenanceService::TASK_NOTIFICATION_SWEEP, 'reason' => MaintenanceService::SKIP_ERROR], $report['skipped']);
        $this->assertCount(2, $sweeps->calls, 'retried on the next tick');
        $warnings = [];
        foreach ($logger->messages as $message) {
            $this->assertStringNotContainsString('maint-marker-8810', is_string($message[0]) ? $message[0] : '');
            if ($message[1] === Logger::LEVEL_WARNING && $message[2] === MaintenanceService::class) {
                $warnings[] = $message[0];
            }
        }
        $this->assertSame(array_fill(0, 2, sprintf(MaintenanceService::TASK_FAILED, 'the sweep of notification deliveries', \yii\db\Exception::class)), $warnings);
    }

    /**
     * A MaintenanceService that runs only the tasks of every tick: no
     * artifact cleanup, no survey scrub, no sweep of deleted templates;
     * $pendingMigrations answers the migration check.
     */
    private function maintenanceWithoutOtherTasks(int $pendingMigrations = 0): MaintenanceService
    {
        $maintenance = new class ($pendingMigrations) extends MaintenanceService {
            public function __construct(private readonly int $pending)
            {
                parent::__construct();
            }

            protected function pendingMigrations(): PendingMigrations
            {
                return new class ($this->pending) extends PendingMigrations {
                    public function __construct(private readonly int $answer)
                    {
                        parent::__construct();
                    }

                    public function count(): int
                    {
                        return $this->answer;
                    }
                };
            }
        };
        $maintenance->artifactCleanupIntervalSeconds = 0;
        $maintenance->surveySecretScrubBudgetSeconds = 0;
        $maintenance->deletedTemplateSweepIntervalSeconds = 0;

        return $maintenance;
    }

    /**
     * The delivery service as a double whose sweep() records its now and
     * deadline, answers nothing done, or throws $failWith.
     *
     * @return NotificationDeliveryService&object{calls: list<array{0: int, 1: float|null}>}
     */
    private function recordingSweeps(?\Throwable $failWith = null): NotificationDeliveryService
    {
        $double = new class ($failWith) extends NotificationDeliveryService {
            /** @var list<array{0: int, 1: float|null}> */
            public array $calls = [];

            public function __construct(private readonly ?\Throwable $failWith)
            {
                parent::__construct();
            }

            public function sweep(int $now, ?float $deadline = null): array
            {
                $this->calls[] = [$now, $deadline];
                if ($this->failWith !== null) {
                    throw $this->failWith;
                }

                return ['repushed' => 0, 'reset' => 0, 'purged' => 0];
            }
        };
        \Yii::$app->set('notificationDeliveryService', $double);

        return $double;
    }

    // -------------------------------------------------------------------------
    // Stale-sync sweeper
    // -------------------------------------------------------------------------

    public function testStaleSyncSweepFlipsExceededSyncingProjectsToError(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $project->status = Project::STATUS_SYNCING;
        $project->sync_started_at = time() - 1_000; // older than default 900s threshold
        $project->save(false);

        $maintenance = new MaintenanceService();
        $result = $maintenance->runStaleSyncSweep();

        $project->refresh();
        $this->assertSame(1, $result['recovered']);
        $this->assertSame(Project::STATUS_ERROR, $project->status);
        $this->assertNull($project->sync_started_at);
        $this->assertStringContainsString('Sweeper recovered stuck sync', (string)$project->last_sync_error);
    }

    public function testRunIfDueReportsTheStaleSyncSweepWhenItRecoveredAProject(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $project->status = Project::STATUS_SYNCING;
        $project->sync_started_at = time() - 1000;
        $project->save(false);
        \Yii::$app->set('artifactService', $this->makeArtifactService(0));
        $maintenance = new MaintenanceService();
        $maintenance->artifactCleanupIntervalSeconds = 0;

        $report = $maintenance->runIfDue();

        $this->assertSame(['stale-sync-sweep'], $report['ran']);
        $this->assertSame(['recovered' => 1, 'threshold_seconds' => 900], $report['results']['stale-sync-sweep']);
    }

    public function testStaleSyncSweepIgnoresFreshSyncingProject(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $project->status = Project::STATUS_SYNCING;
        $project->sync_started_at = time() - 30; // well within threshold
        $project->save(false);

        $maintenance = new MaintenanceService();
        $result = $maintenance->runStaleSyncSweep();

        $project->refresh();
        $this->assertSame(0, $result['recovered']);
        $this->assertSame(Project::STATUS_SYNCING, $project->status, 'Fresh sync must not be swept.');
    }

    public function testStaleSyncSweepWritesSystemLogLine(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $project->status = Project::STATUS_SYNCING;
        $project->sync_started_at = time() - 1_000;
        $project->save(false);

        $maintenance = new MaintenanceService();
        $maintenance->runStaleSyncSweep();

        $log = ProjectSyncLog::find()
            ->where(['project_id' => $project->id, 'stream' => ProjectSyncLog::STREAM_SYSTEM])
            ->orderBy(['sequence' => SORT_DESC])
            ->one();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Sweeper recovered stuck sync', (string)$log->content);
    }

    public function testStaleSyncSweepDisabledWhenThresholdZero(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $project->status = Project::STATUS_SYNCING;
        $project->sync_started_at = time() - 100_000;
        $project->save(false);

        $maintenance = new MaintenanceService();
        $maintenance->staleSyncThresholdSeconds = 0;
        $result = $maintenance->runStaleSyncSweep();

        $project->refresh();
        $this->assertSame(0, $result['recovered']);
        $this->assertSame(Project::STATUS_SYNCING, $project->status);
    }
}
