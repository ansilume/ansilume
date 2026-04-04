<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\Project;
use app\services\ProjectService;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

/**
 * Tests for ProjectService — path construction and sync logic.
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

    public function testBaseGitEnvContainsRequiredKeys(): void
    {
        $service = new ProjectService();
        $ref = new \ReflectionMethod(ProjectService::class, 'baseGitEnv');
        $ref->setAccessible(true);

        /** @var array<string, string> $env */
        $env = $ref->invoke($service);

        $this->assertArrayHasKey('HOME', $env);
        $this->assertSame('0', $env['GIT_TERMINAL_PROMPT']);
        $this->assertSame('1', $env['GIT_CONFIG_COUNT']);
        $this->assertSame('safe.directory', $env['GIT_CONFIG_KEY_0']);
        $this->assertSame('*', $env['GIT_CONFIG_VALUE_0']);
    }

    public function testBuildCredentialHelperScriptContainsUsernameAndPassword(): void
    {
        $service = new ProjectService();
        $ref = new \ReflectionMethod(ProjectService::class, 'buildCredentialHelperScript');
        $ref->setAccessible(true);

        $script = $ref->invoke($service, 'myuser', 'mypass');

        $this->assertStringContainsString('username=myuser', $script);
        $this->assertStringContainsString('password=mypass', $script);
        $this->assertStringStartsWith('!f() {', $script);
        $this->assertStringEndsWith('; }; f', $script);
    }

    public function testBuildCredentialHelperScriptWrapsInShellFunction(): void
    {
        $service = new ProjectService();
        $ref = new \ReflectionMethod(ProjectService::class, 'buildCredentialHelperScript');
        $ref->setAccessible(true);

        $script = $ref->invoke($service, 'bot', 'tok3n');

        // Must be a credential helper shell function
        $this->assertMatchesRegularExpression('/^!f\(\) \{.*\}; f$/', $script);
        // Must contain echo for each credential line
        $this->assertStringContainsString('echo "username=bot"', $script);
        $this->assertStringContainsString('echo "password=tok3n"', $script);
    }

    public function testCleanupKeyFileDeletesTempFile(): void
    {
        $service = new ProjectService();
        $ref = new \ReflectionMethod(ProjectService::class, 'cleanupKeyFile');
        $ref->setAccessible(true);

        // null does nothing
        $ref->invoke($service, null);
        $this->assertTrue(true);

        // Real temp file gets removed
        $tmp = tempnam(sys_get_temp_dir(), 'test_');
        $this->assertFileExists($tmp);
        $ref->invoke($service, $tmp);
        $this->assertFileDoesNotExist($tmp);
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
            'id' => $id,
            'name' => 'Test Project',
            'scm_type' => Project::SCM_TYPE_GIT,
            'scm_url' => 'https://example.com/repo.git',
            'scm_branch' => 'main',
            'local_path' => null,
            'status' => Project::STATUS_NEW,
            'created_by' => 1,
            'created_at' => null,
            'updated_at' => null,
        ]);
        return $project;
    }
}
