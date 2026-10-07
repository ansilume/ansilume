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

    /** @var array<string, mixed> application components replaced by a test */
    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/ansilume_jobctrl_' . uniqid('', true);
        mkdir($this->tempDir, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $id => $component) {
            \Yii::$app->set($id, $component);
        }
        $this->original = [];
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

    // ─── relaunch ───────────────────────────────────────────────────

    /**
     * Regression: a relaunch skips the launch page and showed none of the
     * template's warnings. The job still starts; the warning follows.
     */
    public function testARelaunchFlashesTheTemplateWarnings(): void
    {
        [$job, $template] = $this->finishedJob();
        $check = new \app\models\JobTemplateVaultCheck([
            'job_template_id' => $template->id,
            'status' => \app\models\JobTemplateVaultCheck::STATUS_MISSING_PASSWORD,
            'relevant_count' => 2,
            'unopened_count' => 0,
            'checked_at' => time(),
            'scanned_at' => $this->vaultScanTimeOf((int)$template->project_id),
        ]);
        $check->save(false);
        $this->stubJobLaunchService();
        \Yii::$app->session->removeAllFlashes();

        $this->makeController()->actionRelaunch((int)$job->id);

        $flashes = \Yii::$app->session->getAllFlashes();
        $this->assertStringStartsWith('Re-launched as Job #', (string)$flashes['success']);
        $this->assertSame(
            'This template has no vault password, but it probably loads 2 encrypted files or values. '
            . 'Jobs fail when Ansible needs one of them. Attach the vault password of this environment.',
            $flashes['warning']
        );
    }

    public function testARelaunchWithoutWarningsFlashesNoWarning(): void
    {
        [$job] = $this->finishedJob();
        $this->stubJobLaunchService();
        \Yii::$app->session->removeAllFlashes();

        $this->makeController()->actionRelaunch((int)$job->id);

        $this->assertArrayNotHasKey('warning', \Yii::$app->session->getAllFlashes());
        $this->assertArrayHasKey('success', \Yii::$app->session->getAllFlashes());
    }

    /**
     * @return array{0: \app\models\Job, 1: \app\models\JobTemplate}
     */
    private function finishedJob(): array
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $template = $this->createJobTemplate(
            $this->createProject($user->id)->id,
            $this->createInventory($user->id)->id,
            $this->createRunnerGroup($user->id)->id,
            $user->id
        );

        return [$this->createJob($template->id, $user->id, \app\models\Job::STATUS_SUCCEEDED), $template];
    }

    private function stubJobLaunchService(): void
    {
        $this->original['jobLaunchService'] = \Yii::$app->get('jobLaunchService');
        \Yii::$app->set('jobLaunchService', new class extends \app\services\JobLaunchService {
            public function launch(\app\models\JobTemplate $template, int $userId, array $overrides = []): \app\models\Job
            {
                $job = new \app\models\Job();
                $job->job_template_id = $template->id;
                $job->launched_by = $userId;
                $job->status = \app\models\Job::STATUS_QUEUED;
                $job->timeout_minutes = 120;
                $job->has_changes = 0;
                $job->queued_at = time();
                $job->created_at = time();
                $job->updated_at = time();
                $job->save(false);

                return $job;
            }
        });
    }

    // ─── view ───────────────────────────────────────────────────────
    // ─── view ───────────────────────────────────────────────────────

    /**
     * The job page lists the credentials as launched: names from the launch
     * snapshot, and a credential deleted since then is flagged, not dropped.
     */
    public function testViewListsTheCredentialsAsLaunched(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($this->createProject($user->id)->id, $this->createInventory($user->id)->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id, \app\models\Job::STATUS_SUCCEEDED);
        $live = $this->createCredential($user->id, \app\models\Credential::TYPE_TOKEN);
        $job->runner_payload = (string)json_encode([
            'credential_id' => $live->id,
            'credential_ids' => [$live->id, 999999],
            \app\models\Job::PAYLOAD_CREDENTIAL_SNAPSHOT => [
                ['id' => $live->id, 'name' => 'deploy-token', 'credential_type' => 'token', 'role' => 'primary'],
                ['id' => 999999, 'name' => 'removed-key', 'credential_type' => 'ssh_key', 'role' => 'additional'],
            ],
        ]);
        $job->save(false);

        $ctrl = $this->makeController();
        $this->assertSame('rendered:view', $ctrl->actionView((int)$job->id));

        $this->assertSame([
            ['id' => (int)$live->id, 'name' => 'deploy-token', 'credential_type' => 'token', 'role' => 'primary', 'deleted' => false],
            ['id' => 999999, 'name' => 'removed-key', 'credential_type' => 'ssh_key', 'role' => 'additional', 'deleted' => true],
        ], $ctrl->capturedParams['jobCredentials']);
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

    // ─── PDF inline with sandbox CSP ───────────────────────────────────

    /**
     * PDF + ?inline=1 must emit Content-Disposition: inline, sandbox CSP,
     * X-Content-Type-Options, and X-Frame-Options headers.
     */
    public function testPdfDownloadWithInlineSetsSandboxCsp(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $storageDir = $this->tempDir . '/storage/job_' . $job->id;
        mkdir($storageDir, 0750, true);
        $storedPath = $storageDir . '/report.pdf';
        file_put_contents($storedPath, '%PDF-1.4 fake content');

        $artifact = new JobArtifact();
        $artifact->job_id = $job->id;
        $artifact->filename = 'report.pdf';
        $artifact->display_name = 'report.pdf';
        $artifact->mime_type = 'application/pdf';
        $artifact->size_bytes = 20;
        $artifact->storage_path = $storedPath;
        $artifact->created_at = time();
        $artifact->save(false);

        \Yii::$app->set('artifactService', $this->makeArtifactService());
        \Yii::$app->request->setQueryParams(['inline' => '1']);

        $ctrl = $this->makeController();
        $response = $ctrl->actionDownloadArtifact((int)$job->id, (int)$artifact->id);

        $this->assertInstanceOf(Response::class, $response);
        $disposition = (string)$response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('inline;', $disposition);
        $csp = (string)$response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('sandbox', $csp);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    /**
     * PDF without ?inline=1 must use attachment disposition and no CSP headers.
     */
    public function testPdfDownloadWithoutInlineIsAttachment(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $storageDir = $this->tempDir . '/storage/job_' . $job->id;
        mkdir($storageDir, 0750, true);
        $storedPath = $storageDir . '/report.pdf';
        file_put_contents($storedPath, '%PDF-1.4 fake content');

        $artifact = new JobArtifact();
        $artifact->job_id = $job->id;
        $artifact->filename = 'report.pdf';
        $artifact->display_name = 'report.pdf';
        $artifact->mime_type = 'application/pdf';
        $artifact->size_bytes = 20;
        $artifact->storage_path = $storedPath;
        $artifact->created_at = time();
        $artifact->save(false);

        \Yii::$app->set('artifactService', $this->makeArtifactService());
        \Yii::$app->request->setQueryParams([]);

        $ctrl = $this->makeController();
        $response = $ctrl->actionDownloadArtifact((int)$job->id, (int)$artifact->id);

        $this->assertInstanceOf(Response::class, $response);
        $disposition = (string)$response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    /**
     * text/plain + ?inline=1 must still use attachment disposition.
     * Text preview goes through /artifact-content, not inline download.
     */
    public function testTextArtifactIgnoresInlineFlag(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $storageDir = $this->tempDir . '/storage/job_' . $job->id;
        mkdir($storageDir, 0750, true);
        $storedPath = $storageDir . '/notes.txt';
        file_put_contents($storedPath, 'plain text content');

        $artifact = new JobArtifact();
        $artifact->job_id = $job->id;
        $artifact->filename = 'notes.txt';
        $artifact->display_name = 'notes.txt';
        $artifact->mime_type = 'text/plain';
        $artifact->size_bytes = 18;
        $artifact->storage_path = $storedPath;
        $artifact->created_at = time();
        $artifact->save(false);

        \Yii::$app->set('artifactService', $this->makeArtifactService());
        \Yii::$app->request->setQueryParams(['inline' => '1']);

        $ctrl = $this->makeController();
        $response = $ctrl->actionDownloadArtifact((int)$job->id, (int)$artifact->id);

        $this->assertInstanceOf(Response::class, $response);
        $disposition = (string)$response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
    }
}
