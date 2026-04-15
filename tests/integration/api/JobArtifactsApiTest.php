<?php

declare(strict_types=1);

namespace app\tests\integration\api;

use app\controllers\api\v1\JobsController;
use app\models\Job;
use app\models\JobArtifact;
use app\services\ArtifactService;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * Integration tests for artifact API endpoints.
 *
 * Tests artifact listing, download, content preview, batch download,
 * and artifact_count in job serialization.
 */
class JobArtifactsApiTest extends WebControllerTestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/ansilume_artifact_api_' . uniqid('', true);
        mkdir($this->tempDir, 0750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    /**
     * @return array{0: \app\models\User, 1: \app\models\Job}
     */
    private function scaffold(): array
    {
        $user = $this->createUser('api');
        $group = $this->createRunnerGroup($user->id);
        $proj = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $tpl = $this->createJobTemplate($proj->id, $inv->id, $group->id, $user->id);
        $job = $this->createJob($tpl->id, $user->id);
        return [$user, $job];
    }

    private function collectArtifacts(Job $job, string $filename, string $content): void
    {
        $sourceDir = $this->tempDir . '/source_' . uniqid('', true);
        mkdir($sourceDir, 0750, true);
        file_put_contents($sourceDir . '/' . $filename, $content);

        $service = $this->makeArtifactService();
        $service->collectFromDirectory($job, $sourceDir);
    }

    private function makeArtifactService(): ArtifactService
    {
        $service = new ArtifactService();
        $service->storagePath = $this->tempDir . '/storage';
        return $service;
    }

    private function makeController(): JobsController
    {
        return new JobsController('api/v1/jobs', \Yii::$app);
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

    // ─── Artifact list ──────────────────────────────────────────────

    public function testListArtifactsReturnsCorrectStructure(): void
    {
        [$user, $job] = $this->scaffold();
        $this->loginAs($user);
        $this->collectArtifacts($job, 'report.json', '{"ok":true}');
        $this->collectArtifacts($job, 'output.txt', 'hello');

        \Yii::$app->set('artifactService', $this->makeArtifactService());
        $ctrl = $this->makeController();
        $result = $ctrl->actionArtifacts((int)$job->id);

        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']);

        $first = $result['data'][0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('display_name', $first);
        $this->assertArrayHasKey('mime_type', $first);
        $this->assertArrayHasKey('size_bytes', $first);
        $this->assertArrayHasKey('previewable', $first);
        $this->assertArrayHasKey('created_at', $first);
    }

    public function testListArtifactsReturnsEmptyForJobWithoutArtifacts(): void
    {
        [$user, $job] = $this->scaffold();
        $this->loginAs($user);

        \Yii::$app->set('artifactService', $this->makeArtifactService());
        $ctrl = $this->makeController();
        $result = $ctrl->actionArtifacts((int)$job->id);

        $this->assertArrayHasKey('data', $result);
        $this->assertCount(0, $result['data']);
    }

    public function testListArtifactsReturns404ForMissingJob(): void
    {
        [$user] = $this->scaffold();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $this->expectException(\yii\web\NotFoundHttpException::class);
        $ctrl->actionArtifacts(999999);
    }

    // ─── Artifact content ───────────────────────────────────────────

    public function testArtifactContentReturnsTextContent(): void
    {
        [$user, $job] = $this->scaffold();
        $this->loginAs($user);
        $this->collectArtifacts($job, 'output.txt', 'hello world');

        $artifact = JobArtifact::find()->where(['job_id' => $job->id])->one();
        \Yii::$app->set('artifactService', $this->makeArtifactService());
        $ctrl = $this->makeController();
        $result = $ctrl->actionArtifactContent((int)$job->id, (int)$artifact->id);

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('hello world', $result['data']['content']);
        $this->assertSame('text/plain', $result['data']['mime_type']);
    }

    public function testArtifactContentReturns415ForBinaryFile(): void
    {
        [$user, $job] = $this->scaffold();
        $this->loginAs($user);

        // Create a binary artifact manually
        $storageDir = $this->tempDir . '/storage/job_' . $job->id;
        mkdir($storageDir, 0750, true);
        $storedPath = $storageDir . '/binary.bin';
        file_put_contents($storedPath, "\x00\x01\x02");

        $artifact = new JobArtifact();
        $artifact->job_id = $job->id;
        $artifact->filename = 'binary.bin';
        $artifact->display_name = 'archive.zip';
        $artifact->mime_type = 'application/zip';
        $artifact->size_bytes = 3;
        $artifact->storage_path = $storedPath;
        $artifact->created_at = time();
        $artifact->save(false);

        \Yii::$app->set('artifactService', $this->makeArtifactService());
        $ctrl = $this->makeController();
        $result = $ctrl->actionArtifactContent((int)$job->id, (int)$artifact->id);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(415, \Yii::$app->response->statusCode);
    }

    public function testArtifactContentReturns404ForMissingArtifact(): void
    {
        [$user, $job] = $this->scaffold();
        $this->loginAs($user);

        \Yii::$app->set('artifactService', $this->makeArtifactService());
        $ctrl = $this->makeController();
        $result = $ctrl->actionArtifactContent((int)$job->id, 999999);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(404, \Yii::$app->response->statusCode);
    }

    // ─── Previewable flag in artifact list ───────────────────────────

    public function testArtifactListIncludesPreviewableFlag(): void
    {
        [$user, $job] = $this->scaffold();
        $this->loginAs($user);
        $this->collectArtifacts($job, 'data.json', '{}');

        \Yii::$app->set('artifactService', $this->makeArtifactService());
        $ctrl = $this->makeController();
        $result = $ctrl->actionArtifacts((int)$job->id);

        $artifact = $result['data'][0];
        $this->assertTrue($artifact['previewable']);
    }

    // ─── artifact_count in job serialization ────────────────────────

    public function testJobViewIncludesArtifactCount(): void
    {
        [$user, $job] = $this->scaffold();
        $this->loginAs($user);
        $this->collectArtifacts($job, 'a.txt', 'a');
        $this->collectArtifacts($job, 'b.txt', 'b');

        $ctrl = $this->makeController();
        $result = $ctrl->actionView((int)$job->id);

        $this->assertArrayHasKey('data', $result);
        $this->assertSame(2, $result['data']['artifact_count']);
    }

    public function testJobViewShowsZeroArtifactCountWhenNone(): void
    {
        [$user, $job] = $this->scaffold();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $result = $ctrl->actionView((int)$job->id);

        $this->assertArrayHasKey('data', $result);
        $this->assertSame(0, $result['data']['artifact_count']);
    }
}
