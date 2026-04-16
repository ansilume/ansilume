<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\SystemController;
use app\services\ArtifactService;

/**
 * Exercises SystemController::actionArtifactStats() directly.
 */
class SystemControllerActionTest extends WebControllerTestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/ansilume_system_web_' . uniqid('', true);
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

    private function makeArtifactService(int $retention = 0): ArtifactService
    {
        $service = new ArtifactService();
        $service->storagePath = $this->tempDir . '/storage';
        $service->retentionDays = $retention;
        $service->maxFileSize = 100000;
        $service->maxArtifactsPerJob = 7;
        $service->maxBytesPerJob = 200000;
        $service->maxTotalBytes = 1000000;
        return $service;
    }

    public function testArtifactStatsRendersWithStats(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $svc = $this->makeArtifactService(retention: 30);
        $sourceDir = $this->tempDir . '/source';
        mkdir($sourceDir, 0750, true);
        file_put_contents($sourceDir . '/data.txt', str_repeat('a', 250));
        $svc->collectFromDirectory($job, $sourceDir);

        \Yii::$app->set('artifactService', $svc);

        $ctrl = $this->makeController();
        $result = $ctrl->actionArtifactStats();

        $this->assertSame('rendered:artifact-stats', $result);
        $this->assertSame(1, $ctrl->capturedParams['stats']['artifact_count']);
        $this->assertSame(1, $ctrl->capturedParams['stats']['job_count']);
        $this->assertSame(250, $ctrl->capturedParams['stats']['total_bytes']);
        $this->assertCount(1, $ctrl->capturedParams['topJobs']);
        $this->assertSame((int)$job->id, $ctrl->capturedParams['topJobs'][0]['job_id']);
        $this->assertSame(30, $ctrl->capturedParams['retentionDays']);
        $this->assertSame(100000, $ctrl->capturedParams['maxFileSize']);
        $this->assertSame(7, $ctrl->capturedParams['maxArtifactsPerJob']);
        $this->assertSame(200000, $ctrl->capturedParams['maxBytesPerJob']);
        $this->assertSame(1000000, $ctrl->capturedParams['maxTotalBytes']);
    }

    public function testArtifactStatsWithEmptyDb(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        \Yii::$app->set('artifactService', $this->makeArtifactService());

        $ctrl = $this->makeController();
        $ctrl->actionArtifactStats();

        $this->assertSame(0, $ctrl->capturedParams['stats']['artifact_count']);
        $this->assertSame([], $ctrl->capturedParams['topJobs']);
    }

    private function makeController(): SystemController
    {
        return new class ('system', \Yii::$app) extends SystemController {
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
        };
    }
}
