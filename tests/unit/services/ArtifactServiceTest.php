<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\Job;
use app\models\JobArtifact;
use app\services\ArtifactArchive;
use app\services\ArtifactFile;
use app\services\ArtifactService;
use app\tests\unit\RunsUnprivileged;
use PHPUnit\Framework\TestCase;
use yii\log\Logger;

/**
 * Tests for ArtifactService collection, limits, and MIME detection.
 *
 * Uses an anonymous subclass to avoid DB writes — overrides the save path
 * and stubs model persistence.
 */
class ArtifactServiceTest extends TestCase
{
    use RunsUnprivileged;

    private string $tempDir;

    /** @var list<string> archives a test got from createZipArchive() */
    private array $archives = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ansilume_artifact_test_' . uniqid('', true);
        mkdir($this->tempDir, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->archives as $archive) {
            if (is_file($archive)) {
                unlink($archive);
            }
        }
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

    // -------------------------------------------------------------------------
    // Tests: isPreviewable
    // -------------------------------------------------------------------------

    public function testIsPreviewableReturnsTrueForTextTypes(): void
    {
        $service = new ArtifactService();
        $this->assertTrue($service->isPreviewable('text/plain'));
        $this->assertTrue($service->isPreviewable('text/csv'));
        $this->assertTrue($service->isPreviewable('text/yaml'));
        $this->assertTrue($service->isPreviewable('text/html'));
        $this->assertTrue($service->isPreviewable('application/json'));
        $this->assertTrue($service->isPreviewable('application/xml'));
        $this->assertTrue($service->isPreviewable('application/yaml'));
    }

    public function testIsPreviewableReturnsFalseForBinaryTypes(): void
    {
        $service = new ArtifactService();
        $this->assertFalse($service->isPreviewable('application/octet-stream'));
        $this->assertFalse($service->isPreviewable('application/zip'));
        $this->assertFalse($service->isPreviewable('application/gzip'));
        $this->assertFalse($service->isPreviewable('application/x-tar'));
        $this->assertFalse($service->isPreviewable('image/png'));
    }

    // -------------------------------------------------------------------------
    // Tests: isImageType
    // -------------------------------------------------------------------------

    public function testIsImageTypeReturnsTrueForRasterImages(): void
    {
        $service = new ArtifactService();
        $this->assertTrue($service->isImageType('image/png'));
        $this->assertTrue($service->isImageType('image/jpeg'));
        $this->assertTrue($service->isImageType('image/gif'));
        $this->assertTrue($service->isImageType('image/webp'));
    }

    public function testIsImageTypeReturnsFalseForUnsupportedAndDangerousTypes(): void
    {
        $service = new ArtifactService();
        // SVG must be rejected — it can carry inline <script> and would be
        // an XSS vector if rendered inline in the same origin.
        $this->assertFalse($service->isImageType('image/svg+xml'));
        $this->assertFalse($service->isImageType('text/plain'));
        $this->assertFalse($service->isImageType('application/json'));
        $this->assertFalse($service->isImageType('text/html'));
        $this->assertFalse($service->isImageType('application/octet-stream'));
    }

    // -------------------------------------------------------------------------
    // Tests: getArtifactContent
    // -------------------------------------------------------------------------

    public function testGetArtifactContentReturnsNullForBinaryType(): void
    {
        $service = new ArtifactService();
        $artifact = $this->makeArtifactStub('application/zip', '/nonexistent');
        $this->assertNull($service->getArtifactContent($artifact));
    }

    public function testGetArtifactContentReturnsNullForMissingFile(): void
    {
        $service = new ArtifactService();
        $artifact = $this->makeArtifactStub('text/plain', '/nonexistent/file.txt');
        $this->assertNull($service->getArtifactContent($artifact));
    }

    public function testGetArtifactContentReturnsContentForTextFile(): void
    {
        $service = new ArtifactService();
        $filePath = $this->tempDir . '/preview.txt';
        file_put_contents($filePath, 'Hello, world!');
        $artifact = $this->makeArtifactStub('text/plain', $filePath);
        $this->assertSame('Hello, world!', $service->getArtifactContent($artifact));
    }

    public function testGetArtifactContentRespectsMaxBytes(): void
    {
        $service = new ArtifactService();
        $filePath = $this->tempDir . '/large.txt';
        file_put_contents($filePath, str_repeat('A', 1000));
        $artifact = $this->makeArtifactStub('text/plain', $filePath);
        $content = $service->getArtifactContent($artifact, 100);
        $this->assertNotNull($content);
        $this->assertSame(100, strlen($content));
    }

    public function testGetArtifactContentReturnsJsonContent(): void
    {
        $service = new ArtifactService();
        $filePath = $this->tempDir . '/data.json';
        file_put_contents($filePath, '{"key": "value"}');
        $artifact = $this->makeArtifactStub('application/json', $filePath);
        $this->assertSame('{"key": "value"}', $service->getArtifactContent($artifact));
    }

    // -------------------------------------------------------------------------
    // Tests: createZipArchive (unit-level, no DB)
    // -------------------------------------------------------------------------

    public function testCreateZipArchiveReturnsNullForEmptyJob(): void
    {
        // Service with stubbed getArtifacts returning empty
        $service = new class () extends ArtifactService {
            /** @return JobArtifact[] */
            public function getArtifacts(Job $job): array
            {
                return [];
            }
        };
        $job = $this->makeJob();
        $this->assertNull($service->createZipArchive($job));
    }

    // -------------------------------------------------------------------------
    // Tests: artifact files that are gone or cannot be read
    // -------------------------------------------------------------------------

    /**
     * Regression: "Download All" of a job whose artifact files were gone
     * answered 500 instead of 404: libzip removed the archive without
     * entries when it closed it, and unlink() of the temporary file warned.
     */
    public function testRegressionCreateZipArchiveOfAJobWhoseFilesAreGoneIsNull(): void
    {
        $service = $this->zipService([
            $this->artifact(7, 'a.txt', $this->tempDir . '/gone-a.txt'),
            $this->artifact(9, 'b.txt', $this->tempDir . '/gone-b.txt'),
        ]);

        [$archive, $logged] = $this->logged(static fn (): ?string => $service->createZipArchive(new Job()));

        $this->assertNull($archive);
        $this->assertSame([], $service->archivePaths, 'no temporary file was created');
        $this->assertSame([sprintf(ArtifactArchive::LEFT_OUT, 0, '7, 9')], $logged);
    }

    public function testCreateZipArchiveLeavesOutAFileThatIsGone(): void
    {
        $kept = $this->tempDir . '/kept.txt';
        file_put_contents($kept, 'kept');
        $service = $this->zipService([
            $this->artifact(7, 'kept.txt', $kept),
            $this->artifact(9, 'gone.txt', $this->tempDir . '/gone.txt'),
        ]);

        [$archive, $logged] = $this->logged(static fn (): ?string => $service->createZipArchive(new Job()));

        $this->assertSame(['kept.txt' => 'kept'], $this->entries($archive));
        $this->assertSame([sprintf(ArtifactArchive::LEFT_OUT, 0, '9')], $logged);
    }

    /**
     * Regression: libzip opened the files only when it closed the archive,
     * and one the web server may not read failed the whole archive with a
     * warning: "Download All" answered 500. Such a file is left out now.
     *
     * @requires extension posix
     */
    public function testRegressionCreateZipArchiveLeavesOutAFileItCannotRead(): void
    {
        chmod($this->tempDir, 0755);
        $readable = $this->tempDir . '/readable.txt';
        $locked = $this->tempDir . '/locked.txt';
        file_put_contents($readable, 'readable');
        file_put_contents($locked, 'locked');
        chmod($locked, 0o000);
        $service = $this->zipService([
            $this->artifact(7, 'locked.txt', $locked),
            $this->artifact(9, 'readable.txt', $readable),
        ]);

        [$archive, $logged] = $this->logged(static fn (): ?string => self::unprivileged(
            static fn (): ?string => $service->createZipArchive(new Job())
        ));

        $this->assertSame(['readable.txt' => 'readable'], $this->entries($archive));
        $this->assertSame([sprintf(ArtifactArchive::LEFT_OUT, 0, '7')], $logged);
    }

    /**
     * @requires extension posix
     */
    public function testCreateZipArchiveOfFilesItCannotReadIsNullAndLeavesNothingBehind(): void
    {
        chmod($this->tempDir, 0755);
        $locked = $this->tempDir . '/locked.txt';
        file_put_contents($locked, 'locked');
        chmod($locked, 0o000);
        $service = $this->zipService([$this->artifact(7, 'locked.txt', $locked)]);

        $archive = self::unprivileged(static fn (): ?string => $service->createZipArchive(new Job()));

        $this->assertNull($archive);
        $this->assertCount(1, $service->archivePaths);
        $this->assertFileDoesNotExist($service->archivePaths[0]);
    }

    /**
     * Regression (race): a file the artifact cleanup removed between the
     * check and the moment libzip closed the archive failed the archive with
     * a warning, and left the temporary file behind. Each file is opened as
     * it is added now, and stays in the archive.
     */
    public function testRegressionAFileRemovedAfterItWasAddedStaysInTheArchive(): void
    {
        $first = $this->tempDir . '/first.txt';
        $second = $this->tempDir . '/second.txt';
        file_put_contents($first, 'first');
        file_put_contents($second, 'second');
        $removesFirst = $this->artifact(9, 'second.txt', $second);
        $removesFirst->onDisplayName = static function () use ($first): void {
            if (is_file($first)) {
                unlink($first);
            }
        };
        $service = $this->zipService([$this->artifact(7, 'first.txt', $first), $removesFirst]);

        $archive = $service->createZipArchive(new Job());

        $this->assertFileDoesNotExist($first);
        $this->assertSame(['first.txt' => 'first', 'second.txt' => 'second'], $this->entries($archive));
    }

    public function testCreateZipArchiveWithoutATemporaryFileIsNull(): void
    {
        $kept = $this->tempDir . '/kept.txt';
        file_put_contents($kept, 'kept');
        $service = $this->zipService([$this->artifact(7, 'kept.txt', $kept)], static fn (): string|false => false);

        $this->assertNull($service->createZipArchive(new Job()));
    }

    /**
     * A zip file that cannot be opened is a server error, not "No artifacts
     * to download.".
     */
    public function testCreateZipArchiveThatCannotBeOpenedThrows(): void
    {
        $kept = $this->tempDir . '/kept.txt';
        file_put_contents($kept, 'kept');
        $directory = $this->tempDir;
        $service = $this->zipService([$this->artifact(7, 'kept.txt', $kept)], static fn (): string => $directory);

        $this->expectException(\RuntimeException::class);
        // Whichever code libzip gives a directory (1.11: ZIP_ER_OPNOTSUPP).
        $message = preg_quote(sprintf(ArtifactArchive::NOT_WRITTEN, sprintf(ArtifactArchive::OPEN_FAILED, 99)), '~');
        $this->expectExceptionMessageMatches('~^' . str_replace('99', '[0-9]+', $message) . '$~');
        $service->createZipArchive(new Job());
    }

    /**
     * When libzip cannot write the archive, here as its directory is gone
     * when it closes it, the caller gets libzip's warning, and nothing stays
     * behind.
     */
    public function testCreateZipArchiveThatCannotBeWrittenThrowsAndLeavesNothingBehind(): void
    {
        $service = $this->vanishingArchiveService();

        try {
            $service->createZipArchive(new Job());
            $this->fail('An archive that cannot be written must not be served.');
        } catch (\RuntimeException $e) {
            $this->assertSame(
                sprintf(ArtifactArchive::NOT_WRITTEN, 'ZipArchive::close(): Failure to create temporary file: No such file or directory'),
                $e->getMessage()
            );
        }
        $this->assertFileDoesNotExist($service->archivePaths[0]);
    }

    /**
     * When libzip cannot write the archive while its temporary file is still
     * there, here as no file descriptor is left, the file is removed: it was
     * left behind, as no download sent it.
     *
     * @requires extension posix
     */
    public function testCreateZipArchiveThatCannotBeWrittenRemovesItsTemporaryFile(): void
    {
        $first = $this->tempDir . '/first.txt';
        $second = $this->tempDir . '/second.txt';
        file_put_contents($first, 'first');
        file_put_contents($second, 'second');
        $limits = posix_getrlimit();
        $this->assertIsArray($limits);
        $soft = $limits['soft openfiles'] === 'unlimited' ? POSIX_RLIMIT_INFINITY : (int)$limits['soft openfiles'];
        $hard = $limits['hard openfiles'] === 'unlimited' ? POSIX_RLIMIT_INFINITY : (int)$limits['hard openfiles'];
        $exhausts = $this->artifact(9, 'second.txt', $second);
        // Only the descriptors 0 to 2 may be opened from here on, and they are open.
        $exhausts->onDisplayName = static fn (): bool => posix_setrlimit(POSIX_RLIMIT_NOFILE, 3, $hard);
        $service = $this->zipService([$this->artifact(7, 'first.txt', $first), $exhausts]);
        // Loaded now: no class file can be opened until the limit is back.
        $this->assertTrue(class_exists(\app\helpers\FileHelper::class));

        $thrown = null;
        try {
            $service->createZipArchive(new Job());
        } catch (\RuntimeException $e) {
            $thrown = $e;
        } finally {
            posix_setrlimit(POSIX_RLIMIT_NOFILE, $soft, $hard);
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown, 'An archive that cannot be written must not be served.');
        $this->assertStringStartsWith(sprintf(ArtifactArchive::NOT_WRITTEN, 'ZipArchive::close(): Failure to create temporary file: '), $thrown->getMessage());
        $this->assertCount(1, $service->archivePaths);
        $this->assertFileDoesNotExist($service->archivePaths[0]);
    }

    /**
     * Regression (race): a file the artifact cleanup removed after
     * create() checked it, and before ZipArchive added it, made addFile()
     * warn, which failed the download, and the archive wrote itself back
     * when it was freed. Such a file is left out now.
     */
    public function testRegressionAFileRemovedBeforeItWasAddedIsLeftOut(): void
    {
        $kept = $this->tempDir . '/kept.txt';
        $removed = $this->tempDir . '/removed.txt';
        file_put_contents($kept, 'kept');
        file_put_contents($removed, 'removed');
        $removedMeanwhile = $this->artifact(9, 'removed.txt', $removed);
        $removedMeanwhile->onDisplayName = static function () use ($removed): void {
            if (is_file($removed)) {
                unlink($removed);
            }
        };
        $service = $this->zipService([$this->artifact(7, 'kept.txt', $kept), $removedMeanwhile]);

        [$archive, $logged] = $this->logged(static fn (): ?string => $service->createZipArchive(new Job()));

        $this->assertSame(['kept.txt' => 'kept'], $this->entries($archive));
        $this->assertSame([sprintf(ArtifactArchive::LEFT_OUT, 0, '9')], $logged);
        $this->assertSame([basename((string)$archive)], array_values(array_filter(
            scandir(dirname((string)$archive)) ?: [],
            static fn (string $name): bool => str_starts_with($name, 'ansilume_artifacts_') && in_array(sys_get_temp_dir() . '/' . $name, $service->archivePaths, true)
        )), 'no other archive was written');
    }

    public function testOpenFileOfAReadableFileOpensIt(): void
    {
        $path = $this->tempDir . '/report.txt';
        file_put_contents($path, 'x');

        $this->assertNull(self::problemOf($this->artifact(7, 'report.txt', $path)));
    }

    public function testOpenFileOfAFileThatIsGoneOrNoFile(): void
    {
        $this->assertSame(ArtifactService::FILE_GONE, self::problemOf($this->artifact(7, 'a.txt', $this->tempDir . '/gone.txt')));
        $this->assertSame(ArtifactService::FILE_GONE, self::problemOf($this->artifact(7, 'a.txt', $this->tempDir)));
        $this->assertSame(ArtifactService::FILE_GONE, self::problemOf($this->artifact(7, 'a.txt', $this->tempDir . '/gone/a.txt')));
        $this->assertSame(ArtifactService::FILE_GONE, self::problemOf($this->artifact(7, 'a.txt', '/')));
    }

    /**
     * Review finding: a storage path with a NUL byte, as only a hand-edited
     * record holds one, made open() throw a ValueError, a server error. Such
     * a file is gone.
     */
    public function testAFileWhosePathHoldsANulByteIsGone(): void
    {
        $this->assertSame(ArtifactService::FILE_GONE, self::problemOf($this->artifact(7, 'a.txt', $this->tempDir . "/job_1/ok\0.txt")));
    }

    /**
     * Review finding: a file that the artifact cleanup removed between the
     * check that it is a file and fopen() was logged as one that cannot be
     * read. It is gone (VanishingFileWrapper removes it as it is opened).
     */
    public function testAFileRemovedBetweenItsCheckAndItsOpeningIsGone(): void
    {
        $artifact = $this->artifact(7, 'report.txt', VanishingFileWrapper::PROTOCOL . '://artifacts/job_42/report.txt');

        [$problem, $logged] = $this->logged(static fn (): ?string => VanishingFileWrapper::with(
            static fn (): ?string => self::problemOf($artifact)
        ));

        $this->assertSame(ArtifactService::FILE_GONE, $problem);
        $this->assertSame([], $logged);
    }

    /**
     * Regression: a download of an artifact whose file the web server may
     * not read answered 500, as fopen() warned in sendFile(). The file is
     * opened once, without the warning, and the reason logged.
     *
     * @requires extension posix
     */
    public function testRegressionFileProblemOfAFileTheWebServerCannotRead(): void
    {
        chmod($this->tempDir, 0755);
        $path = $this->tempDir . '/locked.txt';
        file_put_contents($path, 'x');
        chmod($path, 0o000);
        $artifact = $this->artifact(7, 'locked.txt', $path);
        $artifact->job_id = 42;

        [$problem, $logged] = $this->logged(static fn (): ?string => self::unprivileged(
            static fn (): ?string => self::problemOf($artifact)
        ));

        $this->assertSame(ArtifactService::FILE_UNREADABLE, $problem);
        $this->assertSame(
            [sprintf(ArtifactService::UNREADABLE_LOGGED, 7, 42, "fopen({$path}): Failed to open stream: Permission denied")],
            $logged
        );
    }

    /**
     * Review finding: a file in a job directory the web server may not
     * enter, as a restore by root leaves one, was reported as gone and not
     * logged. It cannot be read, and the log says why.
     *
     * @requires extension posix
     */
    public function testAFileInADirectoryTheWebServerMayNotEnterCannotBeRead(): void
    {
        chmod($this->tempDir, 0755);
        mkdir($this->tempDir . '/job_42', 0o700);
        $path = $this->tempDir . '/job_42/report.txt';
        file_put_contents($path, 'x');
        $artifact = $this->artifact(7, 'report.txt', $path);
        $artifact->job_id = 42;

        [$problem, $logged] = $this->logged(static fn (): ?string => self::unprivileged(
            static fn (): ?string => self::problemOf($artifact)
        ));

        $this->assertSame(ArtifactService::FILE_UNREADABLE, $problem);
        $this->assertSame(
            [sprintf(ArtifactService::UNREADABLE_LOGGED, 7, 42, sprintf(ArtifactFile::DIRECTORY_CLOSED, $this->tempDir . '/job_42'))],
            $logged
        );
    }

    /**
     * Review finding: with a directory above the job directory closed, as
     * runtime/artifacts after a restore by root, the file was reported as
     * gone and nothing was logged. It cannot be read, and the log names the
     * directory; below it, the web server cannot tell a file that is gone
     * from one that is there either.
     *
     * @return array<string, array{0: string}>
     */
    public static function belowAClosedDirectoryProvider(): array
    {
        return ['a file that is there' => ['report.txt'], 'a file that is gone' => ['gone.txt']];
    }

    /**
     * @dataProvider belowAClosedDirectoryProvider
     * @requires extension posix
     */
    public function testAFileBelowADirectoryTheWebServerMayNotEnterCannotBeRead(string $name): void
    {
        chmod($this->tempDir, 0755);
        mkdir($this->tempDir . '/artifacts/job_42', 0o755, true);
        chmod($this->tempDir . '/artifacts', 0o700);
        file_put_contents($this->tempDir . '/artifacts/job_42/report.txt', 'x');
        $artifact = $this->artifact(7, $name, $this->tempDir . '/artifacts/job_42/' . $name);
        $artifact->job_id = 42;

        [$problem, $logged] = $this->logged(static fn (): ?string => self::unprivileged(
            static fn (): ?string => self::problemOf($artifact)
        ));

        $this->assertSame(ArtifactService::FILE_UNREADABLE, $problem);
        $this->assertSame(
            [sprintf(ArtifactService::UNREADABLE_LOGGED, 7, 42, sprintf(ArtifactFile::DIRECTORY_CLOSED, $this->tempDir . '/artifacts'))],
            $logged
        );
    }

    /**
     * Review finding: the download checked the file and then opened it again
     * by its name, which failed with a server error when the artifact
     * cleanup removed it in between. The stream it gets is opened once and
     * still reads after the file is removed.
     */
    public function testRegressionTheOpenedFileStillReadsAfterItIsRemoved(): void
    {
        $path = $this->tempDir . '/report.txt';
        file_put_contents($path, 'the report');

        $file = (new ArtifactService())->openFile($this->artifact(7, 'report.txt', $path));
        $this->assertIsResource($file);
        unlink($path);

        $this->assertSame('the report', stream_get_contents($file));
        fclose($file);
    }

    /**
     * Regression: the preview of an artifact whose file the web server may
     * not read answered 500, as fopen() warned.
     *
     * @requires extension posix
     */
    public function testRegressionGetArtifactContentOfAFileTheWebServerCannotReadIsNull(): void
    {
        chmod($this->tempDir, 0755);
        $path = $this->tempDir . '/locked.txt';
        file_put_contents($path, 'secret');
        chmod($path, 0o000);
        $artifact = $this->makeArtifactStub('text/plain', $path);

        $this->assertNull(self::unprivileged(static fn (): ?string => (new ArtifactService())->getArtifactContent($artifact)));
    }

    public function testGetArtifactContentOfADirectoryIsNull(): void
    {
        $this->assertNull((new ArtifactService())->getArtifactContent($this->makeArtifactStub('text/plain', $this->tempDir)));
    }

    /**
     * Review finding: the preview of a text artifact that is not UTF-8, or
     * that the preview cut inside a character, made the JSON of the answer
     * fail with a server error. The content is valid UTF-8 now.
     *
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function notUtf8Provider(): array
    {
        return [
            'a Latin-1 file' => ["caf\xE9;r\xE9sum\xE9\n", 524288, "caf?;r?sum?\n"],
            'a character cut at the limit' => ["ab\xC3\xA4", 3, 'ab?'],
            'valid UTF-8' => ["caf\xC3\xA9", 524288, "caf\xC3\xA9"],
        ];
    }

    /**
     * @dataProvider notUtf8Provider
     */
    public function testRegressionGetArtifactContentIsValidUtf8(string $bytes, int $maxBytes, string $content): void
    {
        $path = $this->tempDir . '/data.csv';
        file_put_contents($path, $bytes);

        $preview = (new ArtifactService())->getArtifactContent($this->makeArtifactStub('text/csv', $path), $maxBytes);

        $this->assertSame($content, $preview);
        $this->assertNotFalse(json_encode(['content' => $preview]));
    }

    // -------------------------------------------------------------------------
    // Helpers for the artifact file tests
    // -------------------------------------------------------------------------

    /**
     * Why openFile() cannot open the artifact's file, or null when it opens
     * it.
     */
    private static function problemOf(JobArtifact $artifact): ?string
    {
        $file = (new ArtifactService())->openFile($artifact);
        if (is_string($file)) {
            return $file;
        }
        fclose($file);

        return null;
    }

    /**
     * A service whose job has these artifacts and that records the archive
     * paths it hands out, from $archivePath or a temporary file.
     *
     * @param list<JobArtifact> $artifacts
     * @param (callable(): (string|false))|null $archivePath
     */
    private function zipService(array $artifacts, ?callable $archivePath = null): ArtifactService
    {
        $service = new class ($artifacts, $archivePath) extends ArtifactService {
            /** @var list<string> */
            public array $archivePaths = [];

            /**
             * @param list<JobArtifact> $artifacts
             * @param (callable(): (string|false))|null $archivePath
             */
            public function __construct(private array $artifacts, private $archivePath)
            {
                parent::__construct();
            }

            /** @return JobArtifact[] */
            public function getArtifacts(Job $job): array
            {
                return $this->artifacts;
            }

            protected function newArchivePath(): string|false
            {
                $path = $this->archivePath === null ? parent::newArchivePath() : ($this->archivePath)();
                if ($path !== false) {
                    $this->archivePaths[] = $path;
                }

                return $path;
            }
        };
        $this->archives = &$service->archivePaths;

        return $service;
    }

    /**
     * A service whose archive's directory is removed after the archive was
     * opened, before libzip writes it.
     */
    private function vanishingArchiveService(): ArtifactService
    {
        $kept = $this->tempDir . '/kept.txt';
        file_put_contents($kept, 'kept');
        $directory = $this->tempDir . '/archive';
        $artifact = $this->artifact(7, 'kept.txt', $kept);
        $artifact->onDisplayName = static function () use ($directory): void {
            if (is_dir($directory)) {
                unlink($directory . '/job.zip');
                rmdir($directory);
            }
        };

        return $this->zipService([$artifact], static function () use ($directory): string {
            mkdir($directory);
            touch($directory . '/job.zip');

            return $directory . '/job.zip';
        });
    }

    private function artifact(int $id, string $displayName, string $storagePath): JobArtifact
    {
        $artifact = $this->makeArtifactStub('text/plain', $storagePath);
        $artifact->id = $id;
        $artifact->display_name = $displayName;

        return $artifact;
    }

    /**
     * The entries of a zip file with their contents.
     *
     * @return array<string, string>
     */
    private function entries(?string $archive): array
    {
        $this->assertIsString($archive);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($archive));
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            $entries[$name] = (string)$zip->getFromName($name);
        }
        $zip->close();
        ksort($entries);

        return $entries;
    }

    /**
     * What $code returns, and the messages ArtifactService and ArtifactArchive logged meanwhile.
     *
     * @template T
     * @param callable(): T $code
     * @return array{0: T, 1: list<string>}
     */
    private function logged(callable $code): array
    {
        $logger = new Logger();
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $result = $code();
        } finally {
            \Yii::setLogger($previous);
        }
        $own = array_filter(
            $logger->messages,
            static fn (array $message): bool => in_array($message[2], [ArtifactService::class, ArtifactArchive::class, ArtifactFile::class], true)
        );

        return [$result, array_values(array_map(static fn (array $message): string => (string)$message[0], $own))];
    }

    // -------------------------------------------------------------------------
    // Helpers for new tests
    // -------------------------------------------------------------------------

    private function makeArtifactStub(string $mimeType, string $storagePath): JobArtifact
    {
        $artifact = new class () extends JobArtifact {
            /** @var array<string, mixed> */
            private array $_data = [];

            public function init(): void
            {
            }

            public static function getTableSchema(): ?\yii\db\TableSchema
            {
                return null;
            }

            public function __set($name, $value): void
            {
                $this->_data[$name] = $value;
            }

            /** @var (callable(): mixed)|null called when display_name is read */
            public $onDisplayName = null;

            public function __get($name): mixed
            {
                if ($name === 'display_name' && $this->onDisplayName !== null) {
                    ($this->onDisplayName)();
                }
                return $this->_data[$name] ?? null;
            }

            public function __isset($name): bool
            {
                return isset($this->_data[$name]);
            }
        };
        $artifact->mime_type = $mimeType;
        $artifact->storage_path = $storagePath;
        return $artifact;
    }
}
