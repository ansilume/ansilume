<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\Project;
use app\services\ProjectService;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

/**
 * Tests for ProjectService — path construction, playbook detection, directory tree.
 */
class ProjectServiceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ansilume_project_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ── localPath ────────────────────────────────────────────────────────────

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

    // ── looksLikePlaybook ────────────────────────────────────────────────────

    public function testLooksLikePlaybookReturnsTrueForPlaybook(): void
    {
        $file = $this->tmpDir . '/site.yml';
        file_put_contents($file, "---\n- hosts: all\n  tasks:\n    - debug: msg=hello\n");

        $svc = new ProjectService();
        $this->assertTrue($svc->looksLikePlaybook($file));
    }

    public function testLooksLikePlaybookReturnsFalseForRoleFile(): void
    {
        $file = $this->tmpDir . '/main.yml';
        file_put_contents($file, "---\nsome_var: true\nanother: false\n");

        $svc = new ProjectService();
        $this->assertFalse($svc->looksLikePlaybook($file));
    }

    public function testLooksLikePlaybookReturnsFalseForHiddenFiles(): void
    {
        $file = $this->tmpDir . '/.hidden.yml';
        file_put_contents($file, "---\n- hosts: all\n");

        $svc = new ProjectService();
        $this->assertFalse($svc->looksLikePlaybook($file));
    }

    public function testLooksLikePlaybookExcludesRequirementsYml(): void
    {
        $file = $this->tmpDir . '/requirements.yml';
        file_put_contents($file, "---\n- src: some.role\n");

        $svc = new ProjectService();
        $this->assertFalse($svc->looksLikePlaybook($file));
    }

    public function testLooksLikePlaybookExcludesGalaxyYml(): void
    {
        $file = $this->tmpDir . '/galaxy.yml';
        file_put_contents($file, "---\n- name: collection\n");

        $svc = new ProjectService();
        $this->assertFalse($svc->looksLikePlaybook($file));
    }

    public function testLooksLikePlaybookHandlesCommentsBeforeList(): void
    {
        $file = $this->tmpDir . '/playbook.yml';
        file_put_contents($file, "---\n# This is a comment\n\n- hosts: all\n");

        $svc = new ProjectService();
        $this->assertTrue($svc->looksLikePlaybook($file));
    }

    public function testLooksLikePlaybookReturnsFalseForEmptyFile(): void
    {
        $file = $this->tmpDir . '/empty.yml';
        file_put_contents($file, '');

        $svc = new ProjectService();
        $this->assertFalse($svc->looksLikePlaybook($file));
    }

    // ── detectPlaybooks ──────────────────────────────────────────────────────

    public function testDetectPlaybooksFindsRootLevelPlaybooks(): void
    {
        file_put_contents($this->tmpDir . '/site.yml', "---\n- hosts: all\n");
        file_put_contents($this->tmpDir . '/vars.yml', "---\nsome_var: true\n");

        $svc = new ProjectService();
        $result = $svc->detectPlaybooks($this->tmpDir);

        $this->assertContains('site.yml', $result);
        $this->assertNotContains('vars.yml', $result);
    }

    public function testDetectPlaybooksFindsPlaybooksSubdir(): void
    {
        mkdir($this->tmpDir . '/playbooks', 0755, true);
        file_put_contents($this->tmpDir . '/playbooks/deploy.yaml', "---\n- hosts: web\n");
        file_put_contents($this->tmpDir . '/playbooks/rollback.yml', "---\n- hosts: web\n");

        $svc = new ProjectService();
        $result = $svc->detectPlaybooks($this->tmpDir);

        $this->assertContains('playbooks/deploy.yaml', $result);
        $this->assertContains('playbooks/rollback.yml', $result);
    }

    public function testDetectPlaybooksFindsNestedPlaybooks(): void
    {
        mkdir($this->tmpDir . '/playbooks/infra', 0755, true);
        file_put_contents($this->tmpDir . '/playbooks/infra/setup.yml', "---\n- hosts: all\n");

        $svc = new ProjectService();
        $result = $svc->detectPlaybooks($this->tmpDir);

        $this->assertContains('playbooks/infra/setup.yml', $result);
    }

    public function testDetectPlaybooksExcludesRequirements(): void
    {
        file_put_contents($this->tmpDir . '/requirements.yml', "---\n- src: some.role\n");

        $svc = new ProjectService();
        $result = $svc->detectPlaybooks($this->tmpDir);

        $this->assertEmpty($result);
    }

    public function testDetectPlaybooksReturnsSorted(): void
    {
        file_put_contents($this->tmpDir . '/z_playbook.yml', "---\n- hosts: all\n");
        file_put_contents($this->tmpDir . '/a_playbook.yml', "---\n- hosts: all\n");

        $svc = new ProjectService();
        $result = $svc->detectPlaybooks($this->tmpDir);

        $this->assertSame(['a_playbook.yml', 'z_playbook.yml'], $result);
    }

    public function testDetectPlaybooksReturnsEmptyForEmptyDir(): void
    {
        $svc = new ProjectService();
        $this->assertSame([], $svc->detectPlaybooks($this->tmpDir));
    }

    // ── buildTree ────────────────────────────────────────────────────────────

    public function testBuildTreeReturnsFilesAndDirs(): void
    {
        file_put_contents($this->tmpDir . '/file1.txt', 'x');
        mkdir($this->tmpDir . '/subdir');
        file_put_contents($this->tmpDir . '/subdir/file2.txt', 'y');

        $svc = new ProjectService();
        $tree = $svc->buildTree($this->tmpDir, $this->tmpDir);

        // Dirs come first
        $this->assertSame('subdir', $tree[0]['name']);
        $this->assertSame('dir', $tree[0]['type']);
        $this->assertSame('file1.txt', $tree[1]['name']);
        $this->assertSame('file', $tree[1]['type']);

        // Nested file
        $this->assertSame('file2.txt', $tree[0]['children'][0]['name']);
        $this->assertSame('subdir/file2.txt', $tree[0]['children'][0]['rel']);
    }

    public function testBuildTreeHidesHiddenEntries(): void
    {
        file_put_contents($this->tmpDir . '/.hidden', 'x');
        mkdir($this->tmpDir . '/.git');
        file_put_contents($this->tmpDir . '/visible.txt', 'y');

        $svc = new ProjectService();
        $tree = $svc->buildTree($this->tmpDir, $this->tmpDir);

        $names = array_column($tree, 'name');
        $this->assertNotContains('.hidden', $names);
        $this->assertNotContains('.git', $names);
        $this->assertContains('visible.txt', $names);
    }

    public function testBuildTreeRespectsMaxDepth(): void
    {
        mkdir($this->tmpDir . '/a/b/c', 0755, true);
        file_put_contents($this->tmpDir . '/a/b/c/deep.txt', 'x');

        $svc = new ProjectService();
        $tree = $svc->buildTree($this->tmpDir, $this->tmpDir, 0, 2);

        // Depth 0: a/, Depth 1: b/, Depth 2: truncated (maxDepth reached)
        $this->assertSame('a', $tree[0]['name']);
        $this->assertSame('b', $tree[0]['children'][0]['name']);
        $this->assertSame([], $tree[0]['children'][0]['children']);
    }

    public function testBuildTreeSortsDirsBeforeFiles(): void
    {
        file_put_contents($this->tmpDir . '/z_file.txt', 'x');
        mkdir($this->tmpDir . '/a_dir');
        file_put_contents($this->tmpDir . '/a_file.txt', 'x');

        $svc = new ProjectService();
        $tree = $svc->buildTree($this->tmpDir, $this->tmpDir);

        $this->assertSame('dir', $tree[0]['type']);
        $this->assertSame('a_dir', $tree[0]['name']);
        $this->assertSame('file', $tree[1]['type']);
        $this->assertSame('a_file.txt', $tree[1]['name']);
        $this->assertSame('file', $tree[2]['type']);
        $this->assertSame('z_file.txt', $tree[2]['name']);
    }

    public function testBuildTreeReturnsEmptyForEmptyDir(): void
    {
        $svc = new ProjectService();
        $this->assertSame([], $svc->buildTree($this->tmpDir, $this->tmpDir));
    }
}
