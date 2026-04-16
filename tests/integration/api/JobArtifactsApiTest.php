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

    // ─── image flag and inline download ─────────────────────────────

    public function testArtifactListIncludesImageFlagForRasterImage(): void
    {
        [$user, $job] = $this->scaffold();
        $this->loginAs($user);
        $this->collectArtifacts($job, 'screenshot.png', "\x89PNG\r\n\x1a\nfake-png-bytes");
        $this->collectArtifacts($job, 'log.txt', 'plain text');

        \Yii::$app->set('artifactService', $this->makeArtifactService());
        $ctrl = $this->makeController();
        $result = $ctrl->actionArtifacts((int)$job->id);

        $byName = [];
        foreach ($result['data'] as $row) {
            $byName[$row['display_name']] = $row;
        }

        $this->assertArrayHasKey('image', $byName['screenshot.png']);
        $this->assertTrue($byName['screenshot.png']['image']);
        $this->assertFalse($byName['log.txt']['image']);
    }

    public function testDownloadArtifactInlineForImageSetsInlineDisposition(): void
    {
        [$user, $job] = $this->scaffold();
        $this->loginAs($user);
        $this->collectArtifacts($job, 'screenshot.png', "\x89PNG\r\n\x1a\nfake-png-bytes");

        $artifact = JobArtifact::find()->where(['job_id' => $job->id])->one();

        \Yii::$app->set('artifactService', $this->makeArtifactService());
        \Yii::$app->request->setQueryParams(['inline' => '1']);

        $ctrl = $this->makeController();
        $response = $ctrl->actionDownloadArtifact((int)$job->id, (int)$artifact->id);

        $this->assertInstanceOf(\yii\web\Response::class, $response);
        $disposition = (string)$response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('inline;', $disposition);
        $this->assertStringContainsString('image/png', (string)$response->headers->get('Content-Type'));
    }

    public function testDownloadArtifactInlineIgnoredForNonImage(): void
    {
        [$user, $job] = $this->scaffold();
        $this->loginAs($user);
        $this->collectArtifacts($job, 'log.txt', 'plain text');

        $artifact = JobArtifact::find()->where(['job_id' => $job->id])->one();

        \Yii::$app->set('artifactService', $this->makeArtifactService());
        \Yii::$app->request->setQueryParams(['inline' => '1']);

        $ctrl = $this->makeController();
        $response = $ctrl->actionDownloadArtifact((int)$job->id, (int)$artifact->id);

        $this->assertInstanceOf(\yii\web\Response::class, $response);
        $disposition = (string)$response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
    }

    public function testDownloadArtifactWithoutInlineParamReturnsAttachment(): void
    {
        [$user, $job] = $this->scaffold();
        $this->loginAs($user);
        $this->collectArtifacts($job, 'screenshot.png', "\x89PNG\r\n\x1a\nfake-png-bytes");

        $artifact = JobArtifact::find()->where(['job_id' => $job->id])->one();

        \Yii::$app->set('artifactService', $this->makeArtifactService());
        \Yii::$app->request->setQueryParams([]);

        $ctrl = $this->makeController();
        $response = $ctrl->actionDownloadArtifact((int)$job->id, (int)$artifact->id);

        $this->assertInstanceOf(\yii\web\Response::class, $response);
        $disposition = (string)$response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
    }

    // ─── PDF inline download with security headers ───────────────────

    public function testApiPdfDownloadInlineSetsSandboxCsp(): void
    {
        [$user, $job] = $this->scaffold();
        $this->loginAs($user);

        $storageDir = $this->tempDir . '/storage/job_' . $job->id;
        mkdir($storageDir, 0750, true);
        $storedPath = $storageDir . '/report.pdf';
        file_put_contents($storedPath, '%PDF-1.4 fake-pdf-content');

        $artifact = new JobArtifact();
        $artifact->job_id = $job->id;
        $artifact->filename = 'report.pdf';
        $artifact->display_name = 'report.pdf';
        $artifact->mime_type = 'application/pdf';
        $artifact->size_bytes = 24;
        $artifact->storage_path = $storedPath;
        $artifact->created_at = time();
        $artifact->save(false);

        \Yii::$app->set('artifactService', $this->makeArtifactService());
        \Yii::$app->request->setQueryParams(['inline' => '1']);

        $ctrl = $this->makeController();
        $response = $ctrl->actionDownloadArtifact((int)$job->id, (int)$artifact->id);

        $this->assertInstanceOf(\yii\web\Response::class, $response);

        $disposition = (string)$response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('inline;', $disposition);

        $this->assertStringContainsString('application/pdf', (string)$response->headers->get('Content-Type'));

        $csp = (string)$response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('sandbox', $csp);

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    public function testApiPdfDownloadWithoutInlineIsAttachment(): void
    {
        [$user, $job] = $this->scaffold();
        $this->loginAs($user);

        $storageDir = $this->tempDir . '/storage/job_' . $job->id;
        mkdir($storageDir, 0750, true);
        $storedPath = $storageDir . '/report.pdf';
        file_put_contents($storedPath, '%PDF-1.4 fake-pdf-content');

        $artifact = new JobArtifact();
        $artifact->job_id = $job->id;
        $artifact->filename = 'report.pdf';
        $artifact->display_name = 'report.pdf';
        $artifact->mime_type = 'application/pdf';
        $artifact->size_bytes = 24;
        $artifact->storage_path = $storedPath;
        $artifact->created_at = time();
        $artifact->save(false);

        \Yii::$app->set('artifactService', $this->makeArtifactService());
        \Yii::$app->request->setQueryParams([]);

        $ctrl = $this->makeController();
        $response = $ctrl->actionDownloadArtifact((int)$job->id, (int)$artifact->id);

        $this->assertInstanceOf(\yii\web\Response::class, $response);
        $disposition = (string)$response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
    }

    // ─── inline_frame flag in artifact list ─────────────────────────

    public function testApiArtifactListIncludesInlineFrameFlag(): void
    {
        [$user, $job] = $this->scaffold();
        $this->loginAs($user);

        $storageDir = $this->tempDir . '/storage/job_' . $job->id;
        mkdir($storageDir, 0750, true);

        // Seed PDF artifact
        $pdfPath = $storageDir . '/doc.pdf';
        file_put_contents($pdfPath, '%PDF-1.4');
        $pdfArtifact = new JobArtifact();
        $pdfArtifact->job_id = $job->id;
        $pdfArtifact->filename = 'doc.pdf';
        $pdfArtifact->display_name = 'doc.pdf';
        $pdfArtifact->mime_type = 'application/pdf';
        $pdfArtifact->size_bytes = 8;
        $pdfArtifact->storage_path = $pdfPath;
        $pdfArtifact->created_at = time();
        $pdfArtifact->save(false);

        // Seed PNG artifact
        $pngPath = $storageDir . '/pic.png';
        file_put_contents($pngPath, "\x89PNG\r\n\x1a\n");
        $pngArtifact = new JobArtifact();
        $pngArtifact->job_id = $job->id;
        $pngArtifact->filename = 'pic.png';
        $pngArtifact->display_name = 'pic.png';
        $pngArtifact->mime_type = 'image/png';
        $pngArtifact->size_bytes = 8;
        $pngArtifact->storage_path = $pngPath;
        $pngArtifact->created_at = time();
        $pngArtifact->save(false);

        // Seed TXT artifact
        $txtPath = $storageDir . '/notes.txt';
        file_put_contents($txtPath, 'hello');
        $txtArtifact = new JobArtifact();
        $txtArtifact->job_id = $job->id;
        $txtArtifact->filename = 'notes.txt';
        $txtArtifact->display_name = 'notes.txt';
        $txtArtifact->mime_type = 'text/plain';
        $txtArtifact->size_bytes = 5;
        $txtArtifact->storage_path = $txtPath;
        $txtArtifact->created_at = time();
        $txtArtifact->save(false);

        \Yii::$app->set('artifactService', $this->makeArtifactService());
        $ctrl = $this->makeController();
        $result = $ctrl->actionArtifacts((int)$job->id);

        $this->assertArrayHasKey('data', $result);

        $byName = [];
        foreach ($result['data'] as $row) {
            $byName[$row['display_name']] = $row;
        }

        // PDF: inline_frame=true, image=false, previewable=false
        $this->assertTrue($byName['doc.pdf']['inline_frame']);
        $this->assertFalse($byName['doc.pdf']['image']);
        $this->assertFalse($byName['doc.pdf']['previewable']);

        // PNG: inline_frame=false, image=true
        $this->assertFalse($byName['pic.png']['inline_frame']);
        $this->assertTrue($byName['pic.png']['image']);

        // TXT: inline_frame=false, previewable=true
        $this->assertFalse($byName['notes.txt']['inline_frame']);
        $this->assertTrue($byName['notes.txt']['previewable']);
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
