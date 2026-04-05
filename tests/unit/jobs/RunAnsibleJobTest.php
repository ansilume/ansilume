<?php

declare(strict_types=1);

namespace app\tests\unit\jobs;

use app\components\ArtifactCollector;
use app\components\DockerCommandWrapper;
use app\jobs\RunAnsibleJob;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RunAnsibleJob extracted components — pure logic methods
 * that don't need a database.
 */
class RunAnsibleJobTest extends TestCase
{
    private ArtifactCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new ArtifactCollector();
    }

    // -------------------------------------------------------------------------
    // RunAnsibleJob::buildProcessEnv
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
    // DockerCommandWrapper::wrap
    // -------------------------------------------------------------------------

    public function testWrapInDockerStartsWithDockerRun(): void
    {
        $cmd = DockerCommandWrapper::wrap(
            ['ansible-playbook', '/projects/test/site.yml', '-vv'],
            '/projects/test'
        );
        $this->assertSame('docker', $cmd[0]);
        $this->assertSame('run', $cmd[1]);
        $this->assertSame('--rm', $cmd[2]);
    }

    public function testWrapInDockerRebasesProjectPath(): void
    {
        $cmd = DockerCommandWrapper::wrap(
            ['ansible-playbook', '/var/projects/42/site.yml'],
            '/var/projects/42'
        );
        $this->assertContains('/workspace/site.yml', $cmd);
    }

    public function testWrapInDockerSkipsAnsiblePlaybookArg(): void
    {
        $cmd = DockerCommandWrapper::wrap(
            ['ansible-playbook', '--forks', '5'],
            '/var/projects/1'
        );
        $this->assertNotContains('ansible-playbook', $cmd);
        $this->assertContains('--forks', $cmd);
        $this->assertContains('5', $cmd);
    }

    // -------------------------------------------------------------------------
    // ArtifactCollector::collect
    // -------------------------------------------------------------------------

    public function testCollectNoOpWhenEnvKeyMissing(): void
    {
        $job = $this->makeStubJob();
        // No ANSILUME_ARTIFACT_DIR → early return, no service lookup.
        $this->collector->collect($job, []);
        $this->assertTrue(true);
    }

    public function testCollectNoOpWhenDirectoryDoesNotExist(): void
    {
        $job = $this->makeStubJob();
        $this->collector->collect($job, ['ANSILUME_ARTIFACT_DIR' => '/tmp/nonexistent_' . uniqid('', true)]);
        $this->assertTrue(true);
    }

    public function testCollectHappyPathInvokesServiceAndCleansUp(): void
    {
        $job = $this->makeStubJob();
        $dir = sys_get_temp_dir() . '/ansilume_artifacts_' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/result.json', '{"ok":true}');

        $calls = new \stdClass();
        $calls->count = 0;
        \Yii::$app->set('artifactService', new class ($calls) extends \yii\base\Component {
            public \stdClass $calls;
            public function __construct(\stdClass $c) { parent::__construct(); $this->calls = $c; }
            public function collectFromDirectory($job, string $dir): array
            {
                $this->calls->count++;
                return ['artifact-1'];
            }
        });

        try {
            $this->collector->collect($job, ['ANSILUME_ARTIFACT_DIR' => $dir]);
            $this->assertSame(1, $calls->count);
            $this->assertDirectoryDoesNotExist($dir);
        } finally {
            \Yii::$app->set('artifactService', null);
        }
    }

    public function testCollectSwallowsServiceException(): void
    {
        $job = $this->makeStubJob();
        $dir = sys_get_temp_dir() . '/ansilume_artifacts_' . uniqid('', true);
        mkdir($dir);

        \Yii::$app->set('artifactService', new class extends \yii\base\Component {
            public function collectFromDirectory($job, string $dir): array
            {
                throw new \RuntimeException('service blew up');
            }
        });

        try {
            // Must not throw; finally still cleans the directory.
            $this->collector->collect($job, ['ANSILUME_ARTIFACT_DIR' => $dir]);
            $this->assertDirectoryDoesNotExist($dir);
        } finally {
            \Yii::$app->set('artifactService', null);
        }
    }

    private function makeStubJob(): \app\models\Job
    {
        $stub = $this->getMockBuilder(\app\models\Job::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->getMock();
        $stub->method('save')->willReturn(true);
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($stub, ['id' => 42]);
        return $stub;
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

    // -------------------------------------------------------------------------
    // RunAnsibleJob::parseCallbackFile
    // -------------------------------------------------------------------------

    public function testParseCallbackFileReturnsTaskData(): void
    {
        $file = sys_get_temp_dir() . '/ansilume_test_cb_' . uniqid('', true) . '.ndjson';
        $lines = [
            json_encode(['seq' => 1, 'name' => 'Install package', 'host' => 'web1', 'status' => 'ok', 'changed' => false]),
            json_encode(['seq' => 2, 'name' => 'Start service', 'host' => 'web1', 'status' => 'changed', 'changed' => true]),
        ];
        file_put_contents($file, implode("\n", $lines) . "\n");

        $job = new TestableRunAnsibleJob();
        $tasks = $job->parseCallbackFile($file);
        unlink($file);

        $this->assertCount(2, $tasks);
        $this->assertSame('Install package', $tasks[0]['name']);
        $this->assertTrue($tasks[1]['changed']);
    }

    public function testParseCallbackFileSkipsInvalidJson(): void
    {
        $file = sys_get_temp_dir() . '/ansilume_test_cb_' . uniqid('', true) . '.ndjson';
        file_put_contents($file, "not-json\n" . json_encode(['seq' => 1]) . "\n");

        $job = new TestableRunAnsibleJob();
        $tasks = $job->parseCallbackFile($file);
        unlink($file);

        $this->assertCount(1, $tasks);
        $this->assertSame(1, $tasks[0]['seq']);
    }

    public function testParseCallbackFileReturnsEmptyForEmptyFile(): void
    {
        $file = sys_get_temp_dir() . '/ansilume_test_cb_' . uniqid('', true) . '.ndjson';
        file_put_contents($file, '');

        $job = new TestableRunAnsibleJob();
        $tasks = $job->parseCallbackFile($file);
        unlink($file);

        $this->assertSame([], $tasks);
    }

    // -------------------------------------------------------------------------
    // ArtifactCollector::cleanupDirectory — symlinks
    // -------------------------------------------------------------------------

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

    public function parseCallbackFile(string $callbackFile): array
    {
        return parent::parseCallbackFile($callbackFile);
    }
}
