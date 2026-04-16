<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\models\JobArtifact;
use app\services\ArtifactService;
use app\tests\integration\DbTestCase;

class ArtifactServiceTest extends DbTestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/ansilume_artifact_int_' . uniqid('', true);
        mkdir($this->tempDir, 0750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
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

    private function makeService(): ArtifactService
    {
        $service = new ArtifactService();
        $service->storagePath = $this->tempDir . '/storage';
        return $service;
    }

    public function testCollectAndPersistArtifacts(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $sourceDir = $this->tempDir . '/source';
        mkdir($sourceDir, 0750, true);
        file_put_contents($sourceDir . '/report.json', '{"ok": true}');
        file_put_contents($sourceDir . '/output.txt', 'hello world');

        $service = $this->makeService();
        $artifacts = $service->collectFromDirectory($job, $sourceDir);

        $this->assertCount(2, $artifacts);

        // Verify they're in the DB
        $dbArtifacts = JobArtifact::find()->where(['job_id' => $job->id])->all();
        $this->assertCount(2, $dbArtifacts);
    }

    public function testGetArtifactsReturnsSorted(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $sourceDir = $this->tempDir . '/source';
        mkdir($sourceDir, 0750, true);
        file_put_contents($sourceDir . '/z-file.txt', 'z');
        file_put_contents($sourceDir . '/a-file.txt', 'a');

        $service = $this->makeService();
        $service->collectFromDirectory($job, $sourceDir);

        $artifacts = $service->getArtifacts($job);
        $this->assertSame('a-file.txt', $artifacts[0]->display_name);
        $this->assertSame('z-file.txt', $artifacts[1]->display_name);
    }

    public function testDeleteForJobRemovesFilesAndRecords(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $sourceDir = $this->tempDir . '/source';
        mkdir($sourceDir, 0750, true);
        file_put_contents($sourceDir . '/file.txt', 'content');

        $service = $this->makeService();
        $service->collectFromDirectory($job, $sourceDir);
        $this->assertCount(1, $service->getArtifacts($job));

        $service->deleteForJob($job);

        $this->assertCount(0, $service->getArtifacts($job));
        $this->assertCount(0, JobArtifact::find()->where(['job_id' => $job->id])->all());
    }

    public function testJobArtifactModelValidation(): void
    {
        $model = new JobArtifact();
        $this->assertFalse($model->validate());
        $this->assertArrayHasKey('job_id', $model->getErrors());
        $this->assertArrayHasKey('filename', $model->getErrors());
        $this->assertArrayHasKey('display_name', $model->getErrors());
        $this->assertArrayHasKey('storage_path', $model->getErrors());
    }

    public function testJobArtifactRelation(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $sourceDir = $this->tempDir . '/source';
        mkdir($sourceDir, 0750, true);
        file_put_contents($sourceDir . '/test.txt', 'hello');

        $service = $this->makeService();
        $service->collectFromDirectory($job, $sourceDir);

        // Test Job->artifacts relation
        $job->refresh();
        $artifacts = $job->artifacts;
        $this->assertCount(1, $artifacts);
        $this->assertInstanceOf(JobArtifact::class, $artifacts[0]);

        // Test JobArtifact->job relation
        $this->assertSame($job->id, $artifacts[0]->job->id);
    }

    // -------------------------------------------------------------------------
    // Tests: deleteExpiredArtifacts
    // -------------------------------------------------------------------------

    public function testDeleteExpiredArtifactsRemovesOldRecords(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $sourceDir = $this->tempDir . '/source';
        mkdir($sourceDir, 0750, true);
        file_put_contents($sourceDir . '/old.txt', 'old data');

        $service = $this->makeService();
        $service->retentionDays = 7;
        $service->collectFromDirectory($job, $sourceDir);

        // Backdate the artifact to 10 days ago
        $artifact = JobArtifact::find()->where(['job_id' => $job->id])->one();
        $artifactId = (int)$artifact->id;
        $sizeBytes = (int)$artifact->size_bytes;
        $createdAt = time() - (10 * 86400);
        $artifact->created_at = $createdAt;
        $artifact->save(false);

        $deleted = $service->deleteExpiredArtifacts();
        $this->assertSame(1, $deleted);
        $this->assertCount(0, $service->getArtifacts($job));

        // Audit-Trail: an "artifact.expired" entry must exist for the removed
        // record so operators can later trace what the cleanup sweep deleted.
        $log = AuditLog::find()
            ->where([
                'action' => AuditLog::ACTION_ARTIFACT_EXPIRED,
                'object_type' => 'artifact',
                'object_id' => $artifactId,
            ])
            ->one();
        $this->assertNotNull($log, 'expected an audit entry for the expired artifact');
        $this->assertNull($log->user_id, 'system-triggered cleanup must have no user_id');
        $metadata = json_decode((string)$log->metadata, true);
        $this->assertSame($job->id, $metadata['job_id']);
        $this->assertSame('old.txt', $metadata['display_name']);
        $this->assertSame($sizeBytes, $metadata['size_bytes']);
        $this->assertSame($createdAt, $metadata['created_at']);
        $this->assertSame(7, $metadata['retention_days']);
        $this->assertArrayHasKey('cutoff', $metadata);
    }

    public function testDeleteExpiredArtifactsKeepsRecentOnes(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $sourceDir = $this->tempDir . '/source';
        mkdir($sourceDir, 0750, true);
        file_put_contents($sourceDir . '/recent.txt', 'recent');

        $service = $this->makeService();
        $service->retentionDays = 7;
        $service->collectFromDirectory($job, $sourceDir);

        $deleted = $service->deleteExpiredArtifacts();
        $this->assertSame(0, $deleted);
        $this->assertCount(1, $service->getArtifacts($job));
    }

    public function testDeleteExpiredArtifactsReturnsZeroWhenDisabled(): void
    {
        $service = $this->makeService();
        $service->retentionDays = 0;
        $this->assertSame(0, $service->deleteExpiredArtifacts());
    }

    // -------------------------------------------------------------------------
    // Tests: cleanupOrphans
    // -------------------------------------------------------------------------

    public function testCleanupOrphansRemovesFilesWithoutDbRecords(): void
    {
        $service = $this->makeService();
        $storageDir = $this->tempDir . '/storage/job_999';
        mkdir($storageDir, 0750, true);
        $orphanPath = $storageDir . '/orphan.txt';
        file_put_contents($orphanPath, 'orphaned');

        $removed = $service->cleanupOrphans();
        $this->assertSame(1, $removed);
        $this->assertFalse(file_exists($orphanPath));

        // Audit-Trail: orphan removals must be logged with object_id=null
        // (no DB row by definition) and the file path captured in metadata
        // for forensic traceability.
        $log = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_ARTIFACT_ORPHAN_REMOVED])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertNotNull($log, 'expected an audit entry for the orphan removal');
        $this->assertSame('artifact', $log->object_type);
        $this->assertNull($log->object_id);
        $this->assertNull($log->user_id);
        $metadata = json_decode((string)$log->metadata, true);
        $this->assertSame($orphanPath, $metadata['storage_path']);
        $this->assertSame(8, $metadata['size_bytes']); // strlen("orphaned")
    }

    public function testDeleteExpiredArtifactsKeepsRecentOnesEmitsNoAudit(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $sourceDir = $this->tempDir . '/source';
        mkdir($sourceDir, 0750, true);
        file_put_contents($sourceDir . '/recent.txt', 'recent');

        $service = $this->makeService();
        $service->retentionDays = 7;
        $service->collectFromDirectory($job, $sourceDir);

        $countBefore = (int)AuditLog::find()
            ->where(['action' => AuditLog::ACTION_ARTIFACT_EXPIRED])
            ->count();
        $service->deleteExpiredArtifacts();
        $countAfter = (int)AuditLog::find()
            ->where(['action' => AuditLog::ACTION_ARTIFACT_EXPIRED])
            ->count();

        $this->assertSame($countBefore, $countAfter, 'no audit entries should be created when nothing expired');
    }

    public function testCleanupOrphansEmitsNoAuditWhenNothingRemoved(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $sourceDir = $this->tempDir . '/source';
        mkdir($sourceDir, 0750, true);
        file_put_contents($sourceDir . '/keep.txt', 'keep');

        $service = $this->makeService();
        $service->collectFromDirectory($job, $sourceDir);

        $countBefore = (int)AuditLog::find()
            ->where(['action' => AuditLog::ACTION_ARTIFACT_ORPHAN_REMOVED])
            ->count();
        $service->cleanupOrphans();
        $countAfter = (int)AuditLog::find()
            ->where(['action' => AuditLog::ACTION_ARTIFACT_ORPHAN_REMOVED])
            ->count();

        $this->assertSame($countBefore, $countAfter, 'no audit entries should be created when no orphans existed');
    }

    public function testCleanupOrphansKeepsFilesWithDbRecords(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $sourceDir = $this->tempDir . '/source';
        mkdir($sourceDir, 0750, true);
        file_put_contents($sourceDir . '/keep.txt', 'keep me');

        $service = $this->makeService();
        $service->collectFromDirectory($job, $sourceDir);

        $removed = $service->cleanupOrphans();
        $this->assertSame(0, $removed);
        $this->assertCount(1, $service->getArtifacts($job));
    }

    // -------------------------------------------------------------------------
    // Tests: getStorageStats
    // -------------------------------------------------------------------------

    public function testGetStorageStatsReturnsCorrectCounts(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job1 = $this->createJob($template->id, $user->id);
        $job2 = $this->createJob($template->id, $user->id);

        $dir1 = $this->tempDir . '/source1';
        mkdir($dir1, 0750, true);
        file_put_contents($dir1 . '/a.txt', str_repeat('x', 100));

        $dir2 = $this->tempDir . '/source2';
        mkdir($dir2, 0750, true);
        file_put_contents($dir2 . '/b.txt', str_repeat('y', 200));

        $service = $this->makeService();
        $service->collectFromDirectory($job1, $dir1);
        $service->collectFromDirectory($job2, $dir2);

        $stats = $service->getStorageStats();
        $this->assertSame(2, $stats['artifact_count']);
        $this->assertSame(2, $stats['job_count']);
        $this->assertSame(300, $stats['total_bytes']);
    }

    public function testGetStorageStatsEmptyDatabase(): void
    {
        $service = $this->makeService();
        $stats = $service->getStorageStats();
        $this->assertSame(0, $stats['artifact_count']);
        $this->assertSame(0, $stats['job_count']);
        $this->assertSame(0, $stats['total_bytes']);
    }

    // -------------------------------------------------------------------------
    // Tests: getTopJobsByBytes
    // -------------------------------------------------------------------------

    public function testGetTopJobsByBytesOrdersDescendingByBytes(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $small = $this->createJob($template->id, $user->id);
        $large = $this->createJob($template->id, $user->id);

        $dirSmall = $this->tempDir . '/small';
        mkdir($dirSmall, 0750, true);
        file_put_contents($dirSmall . '/s.txt', str_repeat('s', 50));

        $dirLarge = $this->tempDir . '/large';
        mkdir($dirLarge, 0750, true);
        file_put_contents($dirLarge . '/l1.txt', str_repeat('l', 500));
        file_put_contents($dirLarge . '/l2.txt', str_repeat('m', 300));

        $service = $this->makeService();
        $service->collectFromDirectory($small, $dirSmall);
        $service->collectFromDirectory($large, $dirLarge);

        $top = $service->getTopJobsByBytes(10);
        $this->assertCount(2, $top);
        $this->assertSame((int)$large->id, $top[0]['job_id']);
        $this->assertSame(800, $top[0]['total_bytes']);
        $this->assertSame(2, $top[0]['artifact_count']);
        $this->assertSame((int)$small->id, $top[1]['job_id']);
        $this->assertSame(50, $top[1]['total_bytes']);
        $this->assertSame(1, $top[1]['artifact_count']);
    }

    public function testGetTopJobsByBytesRespectsLimit(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);

        $service = $this->makeService();
        for ($i = 0; $i < 5; $i++) {
            $job = $this->createJob($template->id, $user->id);
            $dir = $this->tempDir . '/j' . $i;
            mkdir($dir, 0750, true);
            file_put_contents($dir . '/x.txt', str_repeat('x', ($i + 1) * 10));
            $service->collectFromDirectory($job, $dir);
        }

        $top = $service->getTopJobsByBytes(3);
        $this->assertCount(3, $top);
        // Largest first: 50, 40, 30 bytes.
        $this->assertSame(50, $top[0]['total_bytes']);
        $this->assertSame(40, $top[1]['total_bytes']);
        $this->assertSame(30, $top[2]['total_bytes']);
    }

    public function testGetTopJobsByBytesEmptyWhenNoArtifacts(): void
    {
        $service = $this->makeService();
        $this->assertSame([], $service->getTopJobsByBytes(10));
    }

    // -------------------------------------------------------------------------
    // Tests: createZipArchive
    // -------------------------------------------------------------------------

    public function testCreateZipArchiveContainsAllArtifacts(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $sourceDir = $this->tempDir . '/source';
        mkdir($sourceDir, 0750, true);
        file_put_contents($sourceDir . '/report.json', '{"ok":true}');
        file_put_contents($sourceDir . '/output.txt', 'hello');

        $service = $this->makeService();
        $service->collectFromDirectory($job, $sourceDir);

        $zipPath = $service->createZipArchive($job);
        $this->assertNotNull($zipPath);
        $this->assertFileExists($zipPath);

        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        unlink($zipPath);

        sort($names);
        $this->assertSame(['output.txt', 'report.json'], $names);
    }

    public function testCreateZipArchiveReturnsNullForJobWithNoArtifacts(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $service = $this->makeService();
        $this->assertNull($service->createZipArchive($job));
    }

    // -------------------------------------------------------------------------
    // Tests: quota enforcement
    // -------------------------------------------------------------------------

    public function testMaxBytesPerJobSkipsFilesOncePerJobCapIsExceeded(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $sourceDir = $this->tempDir . '/source';
        mkdir($sourceDir, 0750, true);
        // Three files of 100 bytes each.
        file_put_contents($sourceDir . '/a.txt', str_repeat('a', 100));
        file_put_contents($sourceDir . '/b.txt', str_repeat('b', 100));
        file_put_contents($sourceDir . '/c.txt', str_repeat('c', 100));

        $service = $this->makeService();
        $service->maxBytesPerJob = 150; // fits one 100-byte file, second would exceed.

        $artifacts = $service->collectFromDirectory($job, $sourceDir);

        // Only one should be persisted; further files skipped without aborting loop.
        $this->assertCount(1, $artifacts);
        $this->assertSame(1, JobArtifact::find()->where(['job_id' => $job->id])->count());
    }

    public function testMaxBytesPerJobZeroMeansUnlimited(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $sourceDir = $this->tempDir . '/source';
        mkdir($sourceDir, 0750, true);
        file_put_contents($sourceDir . '/a.txt', str_repeat('a', 1000));
        file_put_contents($sourceDir . '/b.txt', str_repeat('b', 1000));

        $service = $this->makeService();
        $service->maxBytesPerJob = 0; // explicit unlimited

        $artifacts = $service->collectFromDirectory($job, $sourceDir);
        $this->assertCount(2, $artifacts);
    }

    public function testMaxTotalBytesSkipsNewArtifactsOnceGlobalQuotaIsHit(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);

        $job1 = $this->createJob($template->id, $user->id);
        $job2 = $this->createJob($template->id, $user->id);

        $dir1 = $this->tempDir . '/source1';
        $dir2 = $this->tempDir . '/source2';
        mkdir($dir1, 0750, true);
        mkdir($dir2, 0750, true);
        file_put_contents($dir1 . '/fill.bin', str_repeat('x', 400));
        file_put_contents($dir2 . '/extra.bin', str_repeat('y', 400));

        $service = $this->makeService();
        $service->maxTotalBytes = 500; // room for the first 400-byte file only.

        $a1 = $service->collectFromDirectory($job1, $dir1);
        $a2 = $service->collectFromDirectory($job2, $dir2);

        $this->assertCount(1, $a1, 'first job fits inside global quota');
        $this->assertCount(0, $a2, 'second job is rejected because global quota is exhausted');
    }

    public function testIsInlineFrameTypeReturnsTrueForPdf(): void
    {
        $service = $this->makeService();
        $this->assertTrue($service->isInlineFrameType('application/pdf'));
    }

    public function testIsInlineFrameTypeReturnsFalseForOtherTypes(): void
    {
        $service = $this->makeService();
        $this->assertFalse($service->isInlineFrameType('text/plain'));
        $this->assertFalse($service->isInlineFrameType('application/json'));
        $this->assertFalse($service->isInlineFrameType('image/png'));
        $this->assertFalse($service->isInlineFrameType('image/svg+xml'));
        $this->assertFalse($service->isInlineFrameType('application/octet-stream'));
    }

    // -------------------------------------------------------------------------
    // Tests: deleteByJobCount
    // -------------------------------------------------------------------------

    public function testDeleteByJobCountKeepsNewestN(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);

        $service = $this->makeService();
        $jobIds = [];
        for ($i = 0; $i < 5; $i++) {
            $job = $this->createJob($template->id, $user->id);
            $jobIds[] = $job->id;
            $dir = $this->tempDir . '/src' . $i;
            mkdir($dir, 0750, true);
            file_put_contents($dir . '/a.txt', 'x');
            $service->collectFromDirectory($job, $dir);
            // Space artifact timestamps so the order is deterministic.
            $artifact = JobArtifact::find()->where(['job_id' => $job->id])->one();
            $artifact->created_at = 1_000_000 + $i;
            $artifact->save(false);
        }

        $service->maxJobsWithArtifacts = 2;
        $deleted = $service->deleteByJobCount();

        // 3 jobs should be trimmed (we kept the 2 newest).
        $this->assertSame(3, $deleted);
        $this->assertCount(0, JobArtifact::find()->where(['job_id' => $jobIds[0]])->all());
        $this->assertCount(0, JobArtifact::find()->where(['job_id' => $jobIds[1]])->all());
        $this->assertCount(0, JobArtifact::find()->where(['job_id' => $jobIds[2]])->all());
        $this->assertCount(1, JobArtifact::find()->where(['job_id' => $jobIds[3]])->all());
        $this->assertCount(1, JobArtifact::find()->where(['job_id' => $jobIds[4]])->all());

        // Audit: exactly one entry per trimmed job with reason=job_count.
        $logs = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_ARTIFACT_QUOTA_TRIMMED])
            ->all();
        $this->assertCount(3, $logs);
        foreach ($logs as $log) {
            $meta = json_decode((string)$log->metadata, true);
            $this->assertSame('job_count', $meta['reason']);
            $this->assertArrayHasKey('job_id', $meta);
            $this->assertArrayHasKey('artifact_count', $meta);
            $this->assertArrayHasKey('bytes_freed', $meta);
        }
    }

    public function testDeleteByJobCountReturnsZeroWhenDisabled(): void
    {
        $service = $this->makeService();
        $service->maxJobsWithArtifacts = 0;
        $this->assertSame(0, $service->deleteByJobCount());
    }

    public function testDeleteByJobCountIgnoresJobsWithoutArtifacts(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);

        // One job with artifact, two jobs without.
        $jobWith = $this->createJob($template->id, $user->id);
        $this->createJob($template->id, $user->id);
        $this->createJob($template->id, $user->id);

        $dir = $this->tempDir . '/src';
        mkdir($dir, 0750, true);
        file_put_contents($dir . '/a.txt', 'x');

        $service = $this->makeService();
        $service->collectFromDirectory($jobWith, $dir);
        $service->maxJobsWithArtifacts = 1;

        $this->assertSame(0, $service->deleteByJobCount());
        $this->assertCount(1, JobArtifact::find()->where(['job_id' => $jobWith->id])->all());
    }
}
