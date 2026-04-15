<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\ArtifactController;
use app\models\JobArtifact;
use app\services\ArtifactService;
use app\tests\integration\DbTestCase;
use yii\console\ExitCode;

/**
 * Integration tests for the console ArtifactController.
 */
class ArtifactControllerTest extends DbTestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/ansilume_artifact_cmd_' . uniqid('', true);
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

    /**
     * @return ArtifactController&object{captured: string}
     */
    private function makeController(): ArtifactController
    {
        return new class ('artifact', \Yii::$app) extends ArtifactController {
            public string $captured = '';

            public function stdout($string): int
            {
                $this->captured .= $string;
                return 0;
            }

            public function stderr($string): int
            {
                $this->captured .= $string;
                return 0;
            }
        };
    }

    private function makeArtifactService(int $retentionDays = 0): ArtifactService
    {
        $service = new ArtifactService();
        $service->storagePath = $this->tempDir . '/storage';
        $service->retentionDays = $retentionDays;
        return $service;
    }

    // ─── cleanup ────────────────────────────────────────────────────

    public function testCleanupSkipsExpiryWhenRetentionDisabled(): void
    {
        \Yii::$app->set('artifactService', $this->makeArtifactService(0));
        $ctrl = $this->makeController();
        $result = $ctrl->actionCleanup();

        $this->assertSame(ExitCode::OK, $result);
        $this->assertStringContainsString('Retention disabled', $ctrl->captured);
    }

    public function testCleanupDeletesExpiredArtifacts(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $service = $this->makeArtifactService(7);

        $sourceDir = $this->tempDir . '/source';
        mkdir($sourceDir, 0750, true);
        file_put_contents($sourceDir . '/old.txt', 'old');
        $service->collectFromDirectory($job, $sourceDir);

        // Backdate
        $artifact = JobArtifact::find()->where(['job_id' => $job->id])->one();
        $artifact->created_at = time() - (10 * 86400);
        $artifact->save(false);

        \Yii::$app->set('artifactService', $service);
        $ctrl = $this->makeController();
        $result = $ctrl->actionCleanup();

        $this->assertSame(ExitCode::OK, $result);
        $this->assertStringContainsString('Deleted 1 expired', $ctrl->captured);
    }

    public function testCleanupRemovesOrphanFiles(): void
    {
        $service = $this->makeArtifactService(0);

        // Create orphan file
        $orphanDir = $this->tempDir . '/storage/job_999';
        mkdir($orphanDir, 0750, true);
        file_put_contents($orphanDir . '/orphan.txt', 'orphan');

        \Yii::$app->set('artifactService', $service);
        $ctrl = $this->makeController();
        $result = $ctrl->actionCleanup();

        $this->assertSame(ExitCode::OK, $result);
        $this->assertStringContainsString('Removed 1 orphan', $ctrl->captured);
    }

    // ─── stats ──────────────────────────────────────────────────────

    public function testStatsOutputsCorrectData(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $service = $this->makeArtifactService(0);

        $sourceDir = $this->tempDir . '/source';
        mkdir($sourceDir, 0750, true);
        file_put_contents($sourceDir . '/data.txt', str_repeat('x', 1024));
        $service->collectFromDirectory($job, $sourceDir);

        \Yii::$app->set('artifactService', $service);
        $ctrl = $this->makeController();
        $result = $ctrl->actionStats();

        $this->assertSame(ExitCode::OK, $result);
        $this->assertStringContainsString('1 KB', $ctrl->captured);
        $this->assertStringContainsString('Artifacts:   1', $ctrl->captured);
        $this->assertStringContainsString('Jobs:        1', $ctrl->captured);
        $this->assertStringContainsString('Retention:   forever', $ctrl->captured);
    }

    public function testStatsShowsRetentionDays(): void
    {
        \Yii::$app->set('artifactService', $this->makeArtifactService(30));
        $ctrl = $this->makeController();
        $result = $ctrl->actionStats();

        $this->assertSame(ExitCode::OK, $result);
        $this->assertStringContainsString('30 days', $ctrl->captured);
    }
}
