<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\JobController;
use app\models\JobArtifact;
use app\services\ArtifactService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Integration tests for JobController artifact actions.
 */
class JobControllerActionTest extends WebControllerTestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/ansilume_jobctrl_' . uniqid('', true);
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

    private function makeArtifactService(): ArtifactService
    {
        $service = new ArtifactService();
        $service->storagePath = $this->tempDir . '/storage';
        return $service;
    }

    private function makeController(): JobController
    {
        return new class ('job', \Yii::$app) extends JobController {
            public string $capturedView = '';
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                $this->capturedView = $view;
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): \yii\web\Response
            {
                $r = new \yii\web\Response();
                $r->content = 'redirected';
                return $r;
            }
        };
    }

    // ─── artifact-content ───────────────────────────────────────────

    public function testArtifactContentReturnsJsonForTextFile(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $service = $this->makeArtifactService();
        $sourceDir = $this->tempDir . '/source';
        mkdir($sourceDir, 0750, true);
        file_put_contents($sourceDir . '/output.txt', 'hello world');
        $service->collectFromDirectory($job, $sourceDir);

        $artifact = JobArtifact::find()->where(['job_id' => $job->id])->one();

        \Yii::$app->set('artifactService', $service);
        $ctrl = $this->makeController();
        $response = $ctrl->actionArtifactContent((int)$job->id, (int)$artifact->id);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertIsArray($response->data);
        $this->assertSame('hello world', $response->data['content']);
    }

    public function testArtifactContentReturns415ForBinaryType(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        // Create binary artifact
        $storageDir = $this->tempDir . '/storage/job_' . $job->id;
        mkdir($storageDir, 0750, true);
        $storedPath = $storageDir . '/bin.dat';
        file_put_contents($storedPath, "\x00\x01");

        $artifact = new JobArtifact();
        $artifact->job_id = $job->id;
        $artifact->filename = 'bin.dat';
        $artifact->display_name = 'archive.zip';
        $artifact->mime_type = 'application/zip';
        $artifact->size_bytes = 2;
        $artifact->storage_path = $storedPath;
        $artifact->created_at = time();
        $artifact->save(false);

        \Yii::$app->set('artifactService', $this->makeArtifactService());
        $ctrl = $this->makeController();
        $response = $ctrl->actionArtifactContent((int)$job->id, (int)$artifact->id);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(415, \Yii::$app->response->statusCode);
    }

    public function testArtifactContentThrows404ForMissingArtifact(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        \Yii::$app->set('artifactService', $this->makeArtifactService());
        $ctrl = $this->makeController();

        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionArtifactContent((int)$job->id, 999999);
    }

    // ─── download-artifact (inline image preview) ──────────────────

    public function testDownloadArtifactInlineForImageSetsInlineDisposition(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $service = $this->makeArtifactService();
        $sourceDir = $this->tempDir . '/source';
        mkdir($sourceDir, 0750, true);
        file_put_contents($sourceDir . '/screenshot.png', "\x89PNG\r\n\x1a\nfake-png-bytes");
        $service->collectFromDirectory($job, $sourceDir);

        $artifact = JobArtifact::find()->where(['job_id' => $job->id])->one();

        \Yii::$app->set('artifactService', $service);
        \Yii::$app->request->setQueryParams(['inline' => '1']);

        $ctrl = $this->makeController();
        $response = $ctrl->actionDownloadArtifact((int)$job->id, (int)$artifact->id);

        $this->assertInstanceOf(Response::class, $response);
        $disposition = (string)$response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('inline;', $disposition);
        $this->assertStringContainsString('image/png', (string)$response->headers->get('Content-Type'));
    }

    public function testDownloadArtifactInlineIgnoredForNonImage(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $service = $this->makeArtifactService();
        $sourceDir = $this->tempDir . '/source';
        mkdir($sourceDir, 0750, true);
        file_put_contents($sourceDir . '/log.txt', 'plain text');
        $service->collectFromDirectory($job, $sourceDir);

        $artifact = JobArtifact::find()->where(['job_id' => $job->id])->one();

        \Yii::$app->set('artifactService', $service);
        \Yii::$app->request->setQueryParams(['inline' => '1']);

        $ctrl = $this->makeController();
        $response = $ctrl->actionDownloadArtifact((int)$job->id, (int)$artifact->id);

        $this->assertInstanceOf(Response::class, $response);
        $disposition = (string)$response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
    }

    public function testDownloadArtifactWithoutInlineReturnsAttachment(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $service = $this->makeArtifactService();
        $sourceDir = $this->tempDir . '/source';
        mkdir($sourceDir, 0750, true);
        file_put_contents($sourceDir . '/screenshot.png', "\x89PNG\r\n\x1a\nfake-png-bytes");
        $service->collectFromDirectory($job, $sourceDir);

        $artifact = JobArtifact::find()->where(['job_id' => $job->id])->one();

        \Yii::$app->set('artifactService', $service);
        \Yii::$app->request->setQueryParams([]);

        $ctrl = $this->makeController();
        $response = $ctrl->actionDownloadArtifact((int)$job->id, (int)$artifact->id);

        $this->assertInstanceOf(Response::class, $response);
        $disposition = (string)$response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
    }

    // ─── download-all-artifacts ─────────────────────────────────────

    public function testDownloadAllArtifactsReturnsZip(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $service = $this->makeArtifactService();
        $sourceDir = $this->tempDir . '/source';
        mkdir($sourceDir, 0750, true);
        file_put_contents($sourceDir . '/a.txt', 'aaa');
        file_put_contents($sourceDir . '/b.txt', 'bbb');
        $service->collectFromDirectory($job, $sourceDir);

        \Yii::$app->set('artifactService', $service);
        $ctrl = $this->makeController();
        $response = $ctrl->actionDownloadAllArtifacts((int)$job->id);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testDownloadAllArtifactsThrows404WhenEmpty(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        \Yii::$app->set('artifactService', $this->makeArtifactService());
        $ctrl = $this->makeController();

        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionDownloadAllArtifacts((int)$job->id);
    }
}
