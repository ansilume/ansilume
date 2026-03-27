<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\Project;
use app\services\ProjectService;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

/**
 * Tests for ProjectService::localPath() — pure path construction, no DB.
 */
class ProjectServiceTest extends TestCase
{
    public function testLocalPathCombinesWorkspaceAndProjectId(): void
    {
        $service = new ProjectService();
        $service->workspacePath = '/var/projects';

        $project = $this->makeProject(42);
        $this->assertSame('/var/projects/42', $service->localPath($project));
    }

    public function testLocalPathStripsTrailingSlashFromWorkspace(): void
    {
        $service = new ProjectService();
        $service->workspacePath = '/var/projects/';

        $project = $this->makeProject(7);
        $this->assertSame('/var/projects/7', $service->localPath($project));
    }

    public function testLocalPathUsesProjectId(): void
    {
        $service = new ProjectService();
        $service->workspacePath = '/workspace';

        $this->assertSame('/workspace/1', $service->localPath($this->makeProject(1)));
        $this->assertSame('/workspace/100', $service->localPath($this->makeProject(100)));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeProject(int $id): Project
    {
        $project = $this->getMockBuilder(Project::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $project->method('attributes')->willReturn(
            ['id', 'name', 'scm_type', 'scm_url', 'scm_branch', 'local_path',
             'status', 'created_by', 'created_at', 'updated_at']
        );
        $project->method('save')->willReturn(true);
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($project, [
            'id'         => $id,
            'name'       => 'Test Project',
            'scm_type'   => Project::SCM_TYPE_GIT,
            'scm_url'    => 'https://example.com/repo.git',
            'scm_branch' => 'main',
            'local_path' => null,
            'status'     => Project::STATUS_NEW,
            'created_by' => 1,
            'created_at' => null,
            'updated_at' => null,
        ]);
        return $project;
    }
}
