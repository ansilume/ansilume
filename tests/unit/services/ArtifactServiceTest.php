<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\Job;
use app\models\JobArtifact;
use app\services\ArtifactService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ArtifactService collection, limits, and MIME detection.
 *
 * Uses an anonymous subclass to avoid DB writes — overrides the save path
 * and stubs model persistence.
 */
class ArtifactServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ansilume_artifact_test_' . uniqid('', true);
        mkdir($this->tempDir, 0750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeService(?string $storagePath = null): ArtifactService
    {
        $service = new class () extends ArtifactService {
            /** @var JobArtifact[] Artifacts "saved" during collection */
            public array $savedArtifacts = [];

            public function __construct()
            {
            }

            protected function resolveStoragePath(Job $job): string
            {
                return $this->storagePath . '/job_' . $job->id;
            }

            protected function saveArtifactRecord(
                Job $job,
                string $storedName,
                string $displayName,
                string $sourcePath,
                int $fileSize,
                string $destPath,
            ): ?JobArtifact {
                // Use a mock to avoid ActiveRecord table schema lookup
                $artifact = new class () extends JobArtifact {
                    private array $_data = [];

                    public function init(): void
                    {
                    }

                    public static function getTableSchema(): ?\yii\db\TableSchema
                    {
                        return null;
                    }

                    public function __set($name, $value)
                    {
                        $this->_data[$name] = $value;
                    }

                    public function __get($name)
                    {
                        return $this->_data[$name] ?? null;
                    }

                    public function __isset($name)
                    {
                        return isset($this->_data[$name]);
                    }
                };
                $artifact->job_id = $job->id;
                $artifact->filename = $storedName;
                $artifact->display_name = $displayName;
                $artifact->size_bytes = $fileSize;
                $artifact->storage_path = $destPath;
                $artifact->created_at = time();
                $this->savedArtifacts[] = $artifact;
                return $artifact;
            }
        };

        $service->storagePath = $storagePath ?? $this->tempDir . '/storage';

        return $service;
    }

    private function makeJob(int $id = 1): Job
    {
        $job = new class () extends Job {
            private int $_stubId = 0;

            public function init(): void
            {
            }

            public static function getTableSchema(): ?\yii\db\TableSchema
            {
                return null;
            }

            public function setStubId(int $id): void
            {
                $this->_stubId = $id;
            }

            public function __get($name)
            {
                if ($name === 'id') {
                    return $this->_stubId;
                }
                return parent::__get($name);
            }
        };
        $job->setStubId($id);
        return $job;
    }

    private function createSourceDir(array $files): string
    {
        $dir = $this->tempDir . '/source_' . uniqid('', true);
        mkdir($dir, 0750, true);

        foreach ($files as $path => $content) {
            $fullPath = $dir . '/' . $path;
            $parentDir = dirname($fullPath);
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0750, true);
            }
            file_put_contents($fullPath, $content);
        }

        return $dir;
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
            $path = $item->getPathname();
            if ($item->isLink()) {
                unlink($path);
            } elseif ($item->isDir()) {
                rmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // Tests: collectFromDirectory
    // -------------------------------------------------------------------------

    public function testCollectFromNonExistentDirectory(): void
    {
        $service = $this->makeService();
        $job = $this->makeJob();

        $result = $service->collectFromDirectory($job, '/nonexistent/dir');

        $this->assertSame([], $result);
    }

    public function testCollectFromEmptyDirectory(): void
    {
        $service = $this->makeService();
        $job = $this->makeJob();
        $sourceDir = $this->createSourceDir([]);

        $result = $service->collectFromDirectory($job, $sourceDir);

        $this->assertSame([], $result);
    }

    public function testCollectCopiesFilesAndCreatesRecords(): void
    {
        $service = $this->makeService();
        $job = $this->makeJob(42);

        $sourceDir = $this->createSourceDir([
            'report.json' => '{"status": "ok"}',
            'subdir/config.yaml' => 'key: value',
        ]);

        $artifacts = $service->collectFromDirectory($job, $sourceDir);

        $this->assertCount(2, $artifacts);

        $storagePath = $this->tempDir . '/storage/job_42';
        $this->assertTrue(is_dir($storagePath));

        $storedFiles = glob($storagePath . '/*');
        $this->assertCount(2, $storedFiles);

        $displayNames = array_map(fn($a) => $a->display_name, $artifacts);
        sort($displayNames);
        $this->assertSame(['report.json', 'subdir/config.yaml'], $displayNames);
    }

    public function testMaxFileSizeEnforced(): void
    {
        $service = $this->makeService();
        $service->maxFileSize = 10; // 10 bytes
        $job = $this->makeJob();

        $sourceDir = $this->createSourceDir([
            'small.txt' => 'hi',
            'large.txt' => str_repeat('x', 100),
        ]);

        $artifacts = $service->collectFromDirectory($job, $sourceDir);

        // Only small.txt should be collected
        $this->assertCount(1, $artifacts);
        $this->assertSame('small.txt', $artifacts[0]->display_name);
    }

    public function testMaxArtifactsPerJobEnforced(): void
    {
        $service = $this->makeService();
        $service->maxArtifactsPerJob = 2;
        $job = $this->makeJob();

        $sourceDir = $this->createSourceDir([
            'a.txt' => 'a',
            'b.txt' => 'b',
            'c.txt' => 'c',
            'd.txt' => 'd',
        ]);

        $artifacts = $service->collectFromDirectory($job, $sourceDir);

        $this->assertCount(2, $artifacts);
    }

    // -------------------------------------------------------------------------
    // Tests: MIME detection (via reflection)
    // -------------------------------------------------------------------------

    public function testMimeDetectionByExtension(): void
    {
        $service = new ArtifactService();
        $method = new \ReflectionMethod($service, 'detectMimeType');

        // Create a real file for mime_content_type
        $tmpFile = $this->tempDir . '/test.json';
        file_put_contents($tmpFile, '{}');

        $this->assertSame('application/json', $method->invoke($service, $tmpFile, 'report.json'));
        $this->assertSame('text/yaml', $method->invoke($service, $tmpFile, 'config.yaml'));
        $this->assertSame('text/yaml', $method->invoke($service, $tmpFile, 'config.yml'));
        $this->assertSame('text/csv', $method->invoke($service, $tmpFile, 'data.csv'));
        $this->assertSame('application/gzip', $method->invoke($service, $tmpFile, 'archive.gz'));
    }

    // -------------------------------------------------------------------------
    // Tests: generateStoredName
    // -------------------------------------------------------------------------

    public function testGeneratedNameHasExtension(): void
    {
        $service = new ArtifactService();
        $method = new \ReflectionMethod($service, 'generateStoredName');

        $name = $method->invoke($service, 'report.json');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.json$/', $name);
    }

    public function testGeneratedNameNoExtension(): void
    {
        $service = new ArtifactService();
        $method = new \ReflectionMethod($service, 'generateStoredName');

        $name = $method->invoke($service, 'Makefile');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $name);
    }

    // -------------------------------------------------------------------------
    // Tests: MIME detection edge cases
    // -------------------------------------------------------------------------

    public function testMimeDetectionMultipleDots(): void
    {
        $service = new ArtifactService();
        $method = new \ReflectionMethod($service, 'detectMimeType');

        $tmpFile = $this->tempDir . '/test.txt';
        file_put_contents($tmpFile, 'hello');

        // "data.backup.json" should detect as json via last extension
        $this->assertSame('application/json', $method->invoke($service, $tmpFile, 'data.backup.json'));
    }

    public function testMimeDetectionUnknownExtensionFallsBack(): void
    {
        $service = new ArtifactService();
        $method = new \ReflectionMethod($service, 'detectMimeType');

        $tmpFile = $this->tempDir . '/test.xyz';
        file_put_contents($tmpFile, 'some data');

        $mime = $method->invoke($service, $tmpFile, 'file.xyz');
        // Should be either from mime_content_type or the fallback
        $this->assertIsString($mime);
        $this->assertNotEmpty($mime);
    }

    public function testMimeDetectionPlainTextExtensions(): void
    {
        $service = new ArtifactService();
        $method = new \ReflectionMethod($service, 'detectMimeType');

        $tmpFile = $this->tempDir . '/test.txt';
        file_put_contents($tmpFile, 'hello');

        $this->assertSame('text/plain', $method->invoke($service, $tmpFile, 'output.txt'));
        $this->assertSame('text/plain', $method->invoke($service, $tmpFile, 'run.log'));
        $this->assertSame('text/plain', $method->invoke($service, $tmpFile, 'settings.ini'));
        $this->assertSame('text/plain', $method->invoke($service, $tmpFile, 'app.cfg'));
        $this->assertSame('text/plain', $method->invoke($service, $tmpFile, 'nginx.conf'));
    }

    public function testMimeDetectionArchiveTypes(): void
    {
        $service = new ArtifactService();
        $method = new \ReflectionMethod($service, 'detectMimeType');

        $tmpFile = $this->tempDir . '/test.bin';
        file_put_contents($tmpFile, "\x00\x01\x02");

        $this->assertSame('application/x-tar', $method->invoke($service, $tmpFile, 'backup.tar'));
        $this->assertSame('application/zip', $method->invoke($service, $tmpFile, 'archive.zip'));
    }

    // -------------------------------------------------------------------------
    // Tests: generated names are unique
    // -------------------------------------------------------------------------

    public function testGeneratedNamesAreUnique(): void
    {
        $service = new ArtifactService();
        $method = new \ReflectionMethod($service, 'generateStoredName');

        $names = [];
        for ($i = 0; $i < 100; $i++) {
            $names[] = $method->invoke($service, 'file.txt');
        }

        $this->assertCount(100, array_unique($names));
    }

    // -------------------------------------------------------------------------
    // Tests: nested directory artifact collection
    // -------------------------------------------------------------------------

    public function testCollectPreservesRelativePaths(): void
    {
        $service = $this->makeService();
        $job = $this->makeJob();

        $sourceDir = $this->createSourceDir([
            'top.txt' => 'top',
            'a/nested.txt' => 'nested',
            'a/b/deep.txt' => 'deep',
        ]);

        $artifacts = $service->collectFromDirectory($job, $sourceDir);

        $displayNames = array_map(fn($a) => $a->display_name, $artifacts);
        sort($displayNames);
        $this->assertSame(['a/b/deep.txt', 'a/nested.txt', 'top.txt'], $displayNames);
    }

    // -------------------------------------------------------------------------
    // Tests: file size tracking
    // -------------------------------------------------------------------------

    public function testCollectTracksSizeBytes(): void
    {
        $service = $this->makeService();
        $job = $this->makeJob();

        $content = str_repeat('x', 42);
        $sourceDir = $this->createSourceDir([
            'file.txt' => $content,
        ]);

        $artifacts = $service->collectFromDirectory($job, $sourceDir);

        $this->assertCount(1, $artifacts);
        $this->assertSame(42, $artifacts[0]->size_bytes);
    }

    // -------------------------------------------------------------------------
    // Tests: directories only (no files)
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Tests: symlink protection (security)
    // -------------------------------------------------------------------------

    public function testCollectSkipsSymlinks(): void
    {
        $service = $this->makeService();
        $job = $this->makeJob();

        // Create a file outside the artifact directory
        $secretFile = $this->tempDir . '/secret.txt';
        file_put_contents($secretFile, 'password=hunter2');

        // Create source dir with a symlink pointing to the secret
        $sourceDir = $this->createSourceDir([
            'legit.txt' => 'ok',
        ]);
        symlink($secretFile, $sourceDir . '/exfiltrated.txt');

        $artifacts = $service->collectFromDirectory($job, $sourceDir);

        // Only the legitimate file should be collected
        $this->assertCount(1, $artifacts);
        $this->assertSame('legit.txt', $artifacts[0]->display_name);
    }

    public function testCollectSkipsSymlinkDirectories(): void
    {
        $service = $this->makeService();
        $job = $this->makeJob();

        // Create a directory outside the source with sensitive files
        $sensitiveDir = $this->tempDir . '/sensitive';
        mkdir($sensitiveDir, 0750, true);
        file_put_contents($sensitiveDir . '/credentials.json', '{"key": "secret"}');

        // Create source dir with a directory symlink
        $sourceDir = $this->createSourceDir([
            'legit.txt' => 'ok',
        ]);
        symlink($sensitiveDir, $sourceDir . '/linked_dir');

        $artifacts = $service->collectFromDirectory($job, $sourceDir);

        // Verify no files from the symlinked directory were collected
        $displayNames = array_map(fn($a) => $a->display_name, $artifacts);
        $this->assertNotContains('linked_dir/credentials.json', $displayNames);
        $this->assertContains('legit.txt', $displayNames);
    }

    public function testCollectSkipsDirectoriesOnly(): void
    {
        $service = $this->makeService();
        $job = $this->makeJob();

        $dir = $this->tempDir . '/source_empty_dirs';
        mkdir($dir . '/subdir1/subdir2', 0750, true);

        $artifacts = $service->collectFromDirectory($job, $dir);

        $this->assertSame([], $artifacts);
    }
}
