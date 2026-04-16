<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\models\JobArtifact;
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
        $this->assertSame([], $report['skipped']);
        $this->assertSame(1, $report['results']['artifact-cleanup']['expired']);
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
        $this->assertSame([['task' => 'artifact-cleanup', 'reason' => 'cooldown']], $second['skipped']);
    }

    public function testRunIfDueReportsDisabledWhenIntervalZero(): void
    {
        \Yii::$app->set('artifactService', $this->makeArtifactService(0));

        $maintenance = new MaintenanceService();
        $maintenance->artifactCleanupIntervalSeconds = 0;

        $report = $maintenance->runIfDue();

        $this->assertSame([], $report['ran']);
        $this->assertSame([['task' => 'artifact-cleanup', 'reason' => 'disabled']], $report['skipped']);
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
        $this->assertSame(1, $report['results']['artifact-cleanup']['orphans']);
        $this->assertFalse(file_exists($orphanDir . '/orphan.txt'));
    }
}
