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
        $service->maxJobsWithArtifacts = 5;
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
        $this->assertSame(5, $ctrl->capturedParams['maxJobsWithArtifacts']);
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

    /**
     * The Global byte quota row shows how much of the quota the artifacts
     * use, in whole percent and at most 100: 575 MB of 1000 MB is 57.5 %,
     * shown as 58 %.
     *
     * Regression: since PHP 8.4 round() has no pre-rounding, and the share,
     * computed as used / quota * 100 (57.49999999999999), showed 57 %
     * (app\components\Percent).
     *
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    public static function quotaUseProvider(): array
    {
        return [
            '575 MB of 1000 MB, an exact half' => [575000000, 1000000000, 58],
            '145 MB of 1000 MB, an exact half' => [145000000, 1000000000, 15],
            'a third' => [1, 3, 33],
            'over the quota' => [1500, 1000, 100],
        ];
    }

    /**
     * @dataProvider quotaUseProvider
     */
    public function testRegressionTheQuotaUseRoundedAnExactHalfDown(int $used, int $quota, int $percent): void
    {
        $html = $this->renderedStats($used, $quota);

        $this->assertStringContainsString('<div class="progress-bar" style="width:' . $percent . '%"></div>', $html);
        $this->assertStringContainsString('<small class="text-muted">' . $percent . '% used</small>', $html);
    }

    public function testWithoutAQuotaTheRowShowsNoUse(): void
    {
        $html = $this->renderedStats(575000000, 0);

        $this->assertStringNotContainsString('progress-bar', $html);
        $this->assertStringNotContainsString('% used', $html);
    }

    /**
     * The artifact storage page for $used bytes of artifacts and a global
     * byte quota of $quota bytes (0: none).
     */
    private function renderedStats(int $used, int $quota): string
    {
        return \Yii::$app->view->renderFile('@app/views/system/artifact-stats.php', [
            'stats' => ['total_bytes' => $used, 'artifact_count' => 3, 'job_count' => 1],
            'topJobs' => [],
            'retentionDays' => 0,
            'maxFileSize' => 100000,
            'maxArtifactsPerJob' => 7,
            'maxJobsWithArtifacts' => 0,
            'maxBytesPerJob' => 0,
            'maxTotalBytes' => $quota,
        ]);
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
