<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\SystemController;
use app\models\ApiToken;
use app\models\User;
use app\services\ArtifactService;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * Integration tests for the System API controller.
 *
 * Covers artifact-stats endpoint: structure, permission gating,
 * and integration with ArtifactService.
 */
class SystemControllerTest extends WebControllerTestCase
{
    private SystemController $ctrl;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new SystemController('api/v1/system', \Yii::$app);
        $this->tempDir = sys_get_temp_dir() . '/ansilume_system_api_' . uniqid('', true);
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
        $service->retentionDays = 7;
        $service->maxFileSize = 12345;
        $service->maxArtifactsPerJob = 9;
        $service->maxJobsWithArtifacts = 3;
        $service->maxBytesPerJob = 67890;
        $service->maxTotalBytes = 0;
        return $service;
    }

    public function testArtifactStatsRejects403WithoutPermission(): void
    {
        $this->authenticateAs('no-perm');
        $result = $this->ctrl->actionArtifactStats();
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertArrayHasKey('error', $result);
    }

    public function testArtifactStatsReturnsAggregateData(): void
    {
        $admin = $this->authenticateWithAdmin();
        $project = $this->createProject($admin->id);
        $inventory = $this->createInventory($admin->id);
        $group = $this->createRunnerGroup($admin->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $admin->id);
        $job = $this->createJob($template->id, $admin->id);

        $svc = $this->makeArtifactService();
        $sourceDir = $this->tempDir . '/source';
        mkdir($sourceDir, 0750, true);
        file_put_contents($sourceDir . '/a.txt', str_repeat('x', 100));
        file_put_contents($sourceDir . '/b.txt', str_repeat('y', 50));
        $svc->collectFromDirectory($job, $sourceDir);

        \Yii::$app->set('artifactService', $svc);

        $result = $this->ctrl->actionArtifactStats();
        $this->assertArrayHasKey('data', $result);
        /** @var array<string, mixed> $data */
        $data = $result['data'];

        $this->assertArrayHasKey('stats', $data);
        $this->assertSame(2, $data['stats']['artifact_count']);
        $this->assertSame(1, $data['stats']['job_count']);
        $this->assertSame(150, $data['stats']['total_bytes']);

        $this->assertArrayHasKey('top_jobs', $data);
        $this->assertCount(1, $data['top_jobs']);
        $this->assertSame((int)$job->id, $data['top_jobs'][0]['job_id']);
        $this->assertSame(150, $data['top_jobs'][0]['total_bytes']);

        $this->assertArrayHasKey('config', $data);
        $this->assertSame(7, $data['config']['retention_days']);
        $this->assertSame(12345, $data['config']['max_file_size']);
        $this->assertSame(9, $data['config']['max_artifacts_per_job']);
        $this->assertSame(3, $data['config']['max_jobs_with_artifacts']);
        $this->assertSame(67890, $data['config']['max_bytes_per_job']);
        $this->assertSame(0, $data['config']['max_total_bytes']);
    }

    public function testArtifactStatsReturnsZeroesWhenEmpty(): void
    {
        $this->authenticateWithAdmin();
        \Yii::$app->set('artifactService', $this->makeArtifactService());

        $result = $this->ctrl->actionArtifactStats();
        $this->assertArrayHasKey('data', $result);
        /** @var array<string, mixed> $data */
        $data = $result['data'];
        $this->assertSame(0, $data['stats']['artifact_count']);
        $this->assertSame(0, $data['stats']['job_count']);
        $this->assertSame(0, $data['stats']['total_bytes']);
        $this->assertSame([], $data['top_jobs']);
    }

    /**
     * Create a user with no RBAC role — will fail all permission checks.
     */
    private function authenticateAs(string $label): void
    {
        $user = $this->createUser($label);
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }

    /**
     * Create an admin user with full permissions and authenticate.
     */
    private function authenticateWithAdmin(): User
    {
        $user = $this->createUser('api-admin');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $adminRole = $auth->getRole('admin');
        $this->assertNotNull($adminRole);
        $auth->assign($adminRole, (string)$user->id);

        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'admin-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);

        return $user;
    }
}
