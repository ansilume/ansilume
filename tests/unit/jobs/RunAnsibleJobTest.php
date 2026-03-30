<?php

declare(strict_types=1);

namespace app\tests\unit\jobs;

use app\components\AnsibleJobCommandBuilder;
use app\components\ArtifactCollector;
use app\jobs\RunAnsibleJob;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RunAnsibleJob extracted components — pure logic methods
 * that don't need a database.
 */
class RunAnsibleJobTest extends TestCase
{
    private AnsibleJobCommandBuilder $builder;
    private ArtifactCollector $collector;

    protected function setUp(): void
    {
        $this->builder = new AnsibleJobCommandBuilder();
        $this->collector = new ArtifactCollector();
    }

    // -------------------------------------------------------------------------
    // AnsibleJobCommandBuilder::addPlaybookOptions
    // -------------------------------------------------------------------------

    public function testAddPlaybookOptionsEmpty(): void
    {
        $cmd = ['ansible-playbook', 'site.yml'];
        $this->builder->addPlaybookOptions($cmd, []);
        $this->assertSame(['ansible-playbook', 'site.yml'], $cmd);
    }

    public function testAddPlaybookOptionsVerbosity(): void
    {
        $cmd = ['ansible-playbook'];
        $this->builder->addPlaybookOptions($cmd, ['verbosity' => 3]);
        $this->assertContains('-vvv', $cmd);
    }

    public function testAddPlaybookOptionsVerbosityCappedAt5(): void
    {
        $cmd = ['ansible-playbook'];
        $this->builder->addPlaybookOptions($cmd, ['verbosity' => 10]);
        $this->assertContains('-vvvvv', $cmd);
    }

    public function testAddPlaybookOptionsVerbosityZeroOmitted(): void
    {
        $cmd = ['ansible-playbook'];
        $this->builder->addPlaybookOptions($cmd, ['verbosity' => 0]);
        $this->assertSame(['ansible-playbook'], $cmd);
    }

    public function testAddPlaybookOptionsForks(): void
    {
        $cmd = [];
        $this->builder->addPlaybookOptions($cmd, ['forks' => 10]);
        $this->assertSame(['--forks', '10'], $cmd);
    }

    public function testAddPlaybookOptionsBecome(): void
    {
        $cmd = [];
        $this->builder->addPlaybookOptions($cmd, ['become' => true]);
        $this->assertContains('--become', $cmd);
        $this->assertContains('--become-method', $cmd);
        $this->assertContains('sudo', $cmd);
        $this->assertContains('--become-user', $cmd);
        $this->assertContains('root', $cmd);
    }

    public function testAddPlaybookOptionsBecomeCustom(): void
    {
        $cmd = [];
        $this->builder->addPlaybookOptions($cmd, [
            'become' => true,
            'become_method' => 'su',
            'become_user' => 'deploy',
        ]);
        $this->assertContains('su', $cmd);
        $this->assertContains('deploy', $cmd);
    }

    public function testAddPlaybookOptionsLimit(): void
    {
        $cmd = [];
        $this->builder->addPlaybookOptions($cmd, ['limit' => 'web*']);
        $this->assertSame(['--limit', 'web*'], $cmd);
    }

    public function testAddPlaybookOptionsTags(): void
    {
        $cmd = [];
        $this->builder->addPlaybookOptions($cmd, ['tags' => 'deploy,config']);
        $this->assertSame(['--tags', 'deploy,config'], $cmd);
    }

    public function testAddPlaybookOptionsSkipTags(): void
    {
        $cmd = [];
        $this->builder->addPlaybookOptions($cmd, ['skip_tags' => 'slow']);
        $this->assertSame(['--skip-tags', 'slow'], $cmd);
    }

    public function testAddPlaybookOptionsExtraVars(): void
    {
        $cmd = [];
        $this->builder->addPlaybookOptions($cmd, ['extra_vars' => '{"env":"prod"}']);
        $this->assertSame(['--extra-vars', '{"env":"prod"}'], $cmd);
    }

    public function testAddPlaybookOptionsAllCombined(): void
    {
        $cmd = [];
        $this->builder->addPlaybookOptions($cmd, [
            'verbosity' => 2,
            'forks' => 20,
            'become' => true,
            'limit' => 'db*',
            'tags' => 'schema',
            'skip_tags' => 'backup',
            'extra_vars' => '{"x":1}',
        ]);
        $this->assertContains('-vv', $cmd);
        $this->assertContains('--forks', $cmd);
        $this->assertContains('20', $cmd);
        $this->assertContains('--become', $cmd);
        $this->assertContains('--limit', $cmd);
        $this->assertContains('db*', $cmd);
        $this->assertContains('--tags', $cmd);
        $this->assertContains('schema', $cmd);
        $this->assertContains('--skip-tags', $cmd);
        $this->assertContains('backup', $cmd);
        $this->assertContains('--extra-vars', $cmd);
        $this->assertContains('{"x":1}', $cmd);
    }

    // -------------------------------------------------------------------------
    // AnsibleJobCommandBuilder::buildProcessEnv (via RunAnsibleJob)
    // -------------------------------------------------------------------------

    public function testBuildProcessEnvContainsCallbackKeys(): void
    {
        $job = new TestableRunAnsibleJob();
        $env = $job->buildProcessEnv('/tmp/cb.ndjson', '/tmp/artifacts');

        $this->assertSame('/tmp/cb.ndjson', $env['ANSILUME_CALLBACK_FILE']);
        $this->assertSame('/tmp/artifacts', $env['ANSILUME_ARTIFACT_DIR']);
        $this->assertSame('ansilume_callback', $env['ANSIBLE_CALLBACKS_ENABLED']);
        $this->assertSame('1', $env['ANSIBLE_FORCE_COLOR']);
        $this->assertSame('1', $env['PYTHONUNBUFFERED']);
    }

    public function testBuildProcessEnvIncludesPluginDir(): void
    {
        $job = new TestableRunAnsibleJob();
        $env = $job->buildProcessEnv('/tmp/cb', '/tmp/art');
        $this->assertStringEndsWith('ansible/callback_plugins', $env['ANSIBLE_CALLBACK_PLUGINS']);
    }

    // -------------------------------------------------------------------------
    // AnsibleJobCommandBuilder::wrapInDocker
    // -------------------------------------------------------------------------

    public function testWrapInDockerStartsWithDockerRun(): void
    {
        $cmd = $this->builder->wrapInDocker(
            ['ansible-playbook', '/projects/test/site.yml', '-vv'],
            ['project_id' => 0]
        );
        $this->assertSame('docker', $cmd[0]);
        $this->assertSame('run', $cmd[1]);
        $this->assertSame('--rm', $cmd[2]);
    }

    public function testWrapInDockerRebasesProjectPath(): void
    {
        // resolveProjectPath returns /tmp/ansilume/projects for unknown project_id
        $cmd = $this->builder->wrapInDocker(
            ['ansible-playbook', '/tmp/ansilume/projects/site.yml'],
            ['project_id' => 0]
        );
        $this->assertContains('/workspace/site.yml', $cmd);
    }

    public function testWrapInDockerSkipsAnsiblePlaybookArg(): void
    {
        $cmd = $this->builder->wrapInDocker(
            ['ansible-playbook', '--forks', '5'],
            ['project_id' => 0]
        );
        $this->assertNotContains('ansible-playbook', $cmd);
        $this->assertContains('--forks', $cmd);
        $this->assertContains('5', $cmd);
    }

    // -------------------------------------------------------------------------
    // ArtifactCollector::cleanupDirectory
    // -------------------------------------------------------------------------

    public function testCleanupDirectoryRemovesFiles(): void
    {
        $dir = sys_get_temp_dir() . '/ansilume_test_cleanup_' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/file1.txt', 'test');
        file_put_contents($dir . '/file2.txt', 'test');

        $this->collector->cleanupDirectory($dir);

        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testCleanupDirectoryRemovesNestedStructure(): void
    {
        $dir = sys_get_temp_dir() . '/ansilume_test_cleanup_' . uniqid('', true);
        mkdir($dir . '/sub/deep', 0755, true);
        file_put_contents($dir . '/sub/deep/file.txt', 'test');
        file_put_contents($dir . '/sub/file2.txt', 'test');

        $this->collector->cleanupDirectory($dir);

        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testCleanupDirectoryHandlesNonExistentDir(): void
    {
        // Should not throw
        $this->collector->cleanupDirectory('/tmp/nonexistent_' . uniqid('', true));
        $this->assertTrue(true);
    }

    public function testCleanupDirectoryHandlesSymlinks(): void
    {
        $dir = sys_get_temp_dir() . '/ansilume_test_cleanup_' . uniqid('', true);
        mkdir($dir);
        $target = sys_get_temp_dir() . '/ansilume_test_target_' . uniqid('', true);
        file_put_contents($target, 'do not delete');
        symlink($target, $dir . '/link');

        $this->collector->cleanupDirectory($dir);

        // Symlink target should still exist (not followed)
        $this->assertFileExists($target);
        $this->assertDirectoryDoesNotExist($dir);

        unlink($target);
    }
}

/**
 * Testable subclass exposing protected methods.
 */
class TestableRunAnsibleJob extends RunAnsibleJob
{
    public function buildProcessEnv(string $callbackFile, string $artifactDir): array
    {
        return parent::buildProcessEnv($callbackFile, $artifactDir);
    }
}
