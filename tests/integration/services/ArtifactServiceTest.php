<?php

declare(strict_types=1);

namespace app\tests\integration\services;

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
}
