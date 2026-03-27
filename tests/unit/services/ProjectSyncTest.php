<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\Project;
use app\services\ProjectService;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

/**
 * Tests for ProjectService::sync() — git command construction and error handling.
 *
 * Uses anonymous subclasses to stub gitClone/gitPull so no real git operations
 * are executed. Verifies status transitions, error state, and clone-vs-pull logic.
 */
class ProjectSyncTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/project_sync_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp dirs created by tests
        $this->rmDir($this->tmpDir);
    }

    private function rmDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmDir($path) : \app\helpers\FileHelper::safeUnlink($path);
        }
        \app\helpers\FileHelper::safeRmdir($dir);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeProject(array $attrs): Project
    {
        $defaults = [
            'id'                => 1,
            'name'              => 'Test',
            'scm_type'          => Project::SCM_TYPE_GIT,
            'scm_url'           => 'https://example.com/repo.git',
            'scm_branch'        => 'main',
            'scm_credential_id' => null,
            'local_path'        => null,
            'status'            => Project::STATUS_NEW,
            'last_synced_at'    => null,
            'last_sync_error'   => null,
            'created_by'        => 1,
            'created_at'        => time(),
            'updated_at'        => time(),
        ];

        $project = $this->getMockBuilder(Project::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $project->method('attributes')->willReturn(array_keys($defaults));
        $project->method('save')->willReturn(true);

        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($project, array_merge($defaults, $attrs));

        return $project;
    }

    /**
     * Build a testable ProjectService that records git operations instead of running them.
     */
    private function makeService(bool $simulateExistingClone = false, bool $failGit = false): ProjectService
    {
        $tmpDir = $this->tmpDir;

        $svc = new class($tmpDir, $simulateExistingClone, $failGit) extends ProjectService {
            public array  $cloneCalls = [];
            public array  $pullCalls  = [];
            private string $tmpDir;
            private bool   $simulateExistingClone;
            private bool   $failGit;

            public function __construct(string $tmpDir, bool $simulateExistingClone, bool $failGit)
            {
                $this->tmpDir                = $tmpDir;
                $this->simulateExistingClone = $simulateExistingClone;
                $this->failGit               = $failGit;
                $this->workspacePath         = $tmpDir;
            }

            public function localPath(\app\models\Project $project): string
            {
                $dest = $this->tmpDir . '/' . $project->id;
                if ($this->simulateExistingClone) {
                    if (!is_dir($dest . '/.git')) {
                        mkdir($dest . '/.git', 0755, true);
                    }
                }
                return $dest;
            }

            protected function gitClone(string $url, string $dest, string $branch, array $env): void
            {
                $this->cloneCalls[] = ['url' => $url, 'dest' => $dest, 'branch' => $branch];
                if ($this->failGit) {
                    throw new \RuntimeException('git clone failed (exit 128): fatal: repository not found');
                }
            }

            protected function gitPull(string $dest, string $branch, array $env): void
            {
                $this->pullCalls[] = ['dest' => $dest, 'branch' => $branch];
                if ($this->failGit) {
                    throw new \RuntimeException('git fetch failed (exit 1): network error');
                }
            }

            protected function buildGitEnv(\app\models\Project $project, ?string &$keyFile): array
            {
                return ['HOME' => '/root', 'GIT_TERMINAL_PROMPT' => '0'];
            }
        };

        return $svc;
    }

    // -------------------------------------------------------------------------
    // Manual project sync (no git)
    // -------------------------------------------------------------------------

    public function testSyncManualProjectSetsStatusWithoutGit(): void
    {
        $project = $this->makeProject(['scm_type' => Project::SCM_TYPE_MANUAL]);
        $service = $this->makeService();

        $service->sync($project);

        $this->assertSame(Project::STATUS_SYNCED, $project->status);
        $this->assertNotNull($project->last_synced_at);
        $this->assertEmpty($service->cloneCalls);
        $this->assertEmpty($service->pullCalls);
    }

    // -------------------------------------------------------------------------
    // Git clone (fresh checkout)
    // -------------------------------------------------------------------------

    public function testSyncGitProjectClonesWhenNoLocalCopyExists(): void
    {
        $project = $this->makeProject(['scm_url' => 'git@example.com:repo.git', 'scm_branch' => 'develop']);
        $service = $this->makeService(simulateExistingClone: false);

        $service->sync($project);

        $this->assertCount(1, $service->cloneCalls);
        $this->assertSame('git@example.com:repo.git', $service->cloneCalls[0]['url']);
        $this->assertSame('develop', $service->cloneCalls[0]['branch']);
        $this->assertEmpty($service->pullCalls);
        $this->assertSame(Project::STATUS_SYNCED, $project->status);
    }

    // -------------------------------------------------------------------------
    // Git pull (existing checkout)
    // -------------------------------------------------------------------------

    public function testSyncGitProjectPullsWhenLocalCopyExists(): void
    {
        $project = $this->makeProject(['scm_branch' => 'main']);
        $service = $this->makeService(simulateExistingClone: true);

        $service->sync($project);

        $this->assertCount(1, $service->pullCalls);
        $this->assertSame('main', $service->pullCalls[0]['branch']);
        $this->assertEmpty($service->cloneCalls);
        $this->assertSame(Project::STATUS_SYNCED, $project->status);
    }

    // -------------------------------------------------------------------------
    // Error handling
    // -------------------------------------------------------------------------

    public function testSyncSetsErrorStatusOnGitFailure(): void
    {
        $project = $this->makeProject([]);
        $service = $this->makeService(simulateExistingClone: false, failGit: true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/repository not found/');

        try {
            $service->sync($project);
        } finally {
            $this->assertSame(Project::STATUS_ERROR, $project->status);
            $this->assertNotNull($project->last_sync_error);
            $this->assertStringContainsString('repository not found', $project->last_sync_error);
        }
    }

    public function testSyncThrowsWhenScmUrlIsEmpty(): void
    {
        $project = $this->makeProject(['scm_url' => '']);
        $service = $this->makeService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no SCM URL/');

        $service->sync($project);
    }

    // -------------------------------------------------------------------------
    // Status transitions
    // -------------------------------------------------------------------------

    public function testSyncSetsLastSyncedAt(): void
    {
        $project = $this->makeProject([]);
        $service = $this->makeService();

        $before = time();
        $service->sync($project);

        $this->assertGreaterThanOrEqual($before, (int)$project->last_synced_at);
    }

    public function testSyncClearsLastSyncErrorOnSuccess(): void
    {
        $project = $this->makeProject(['last_sync_error' => 'previous failure']);
        $service = $this->makeService();

        $service->sync($project);

        $this->assertNull($project->last_sync_error);
    }

    public function testSyncSetsLocalPath(): void
    {
        $project = $this->makeProject(['id' => 42]);
        $service = $this->makeService();

        $service->sync($project);

        $this->assertStringContainsString('42', $project->local_path);
    }
}
