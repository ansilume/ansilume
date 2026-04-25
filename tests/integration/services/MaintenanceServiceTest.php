<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\models\JobArtifact;
use app\models\Project;
use app\models\ProjectSyncLog;
use app\services\ArtifactService;
use app\services\MaintenanceService;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for MaintenanceService.
 *
 * The Yii test app uses ArrayCache, so the cooldown is per-process and
 * naturally reset between tests by the framework. Each test deliberately
 * flushes the cache in setUp to keep the cooldown state predictable.
 */
class MaintenanceServiceTest extends DbTestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/ansilume_maintenance_int_' . uniqid('', true);
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
        // stale-sync-sweep skips on every tick when there's nothing to recover.
        $this->assertSame(['stale-sync-sweep'], array_column($report['skipped'], 'task'));
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
