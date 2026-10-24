<?php

declare(strict_types=1);

namespace app\services;

use app\helpers\FileHelper;
use app\models\Job;
use app\models\JobArtifact;

/**
 * The zip archive of a job's artifacts, for "Download All"
 * (ArtifactService::createZipArchive()).
 *
 * An artifact whose file is gone or cannot be read is left out and logged
 * (LEFT_OUT), and there is no archive when that leaves none. libzip removes
 * an archive without entries when it closes it, which made unlink() of the
 * temporary file warn, and it opened the files it was given only then, so a
 * file it could not open failed the whole archive with a warning: each file
 * is opened as it is added (FL_OPEN_FILE_NOW), one per artifact, at most
 * ArtifactService::$maxArtifactsPerJob, so one the artifact cleanup removes
 * after that stays in the archive. ZipArchive warns for a file the cleanup
 * removed after create() checked it, and when it cannot write the archive;
 * those warnings are kept from the error handler (quietly()), which turned
 * them into an exception while the archive was open, and the archive then
 * wrote itself back when it was freed.
 */
final class ArtifactArchive
{
    /**
     * Logged when the archive leaves out artifacts whose files are gone or
     * cannot be read; sprintf(): the job's ID, then the artifacts' IDs.
     */
    public const LEFT_OUT = 'Download All of job #%d leaves out artifacts %s: their files are gone or cannot be read.';

    /**
     * Thrown when the zip file could not be written, as on a full disk;
     * sprintf(): ZipArchive's warning, or OPEN_FAILED.
     */
    public const NOT_WRITTEN = 'The zip archive of the artifacts could not be written: %s';

    /** Why NOT_WRITTEN when the zip file did not open; sprintf(): ZipArchive's error code (ZipArchive::ER_*). */
    public const OPEN_FAILED = 'ZipArchive::open() failed with error %d';

    /** @var callable(): (string|false) */
    private $newPath;

    /**
     * @param callable(): (string|false) $newPath a new, empty temporary file for the archive, or false
     */
    public function __construct(callable $newPath)
    {
        $this->newPath = $newPath;
    }

    /**
     * The path of the archive of the artifacts, or null when none of their
     * files can be read.
     *
     * @param JobArtifact[] $artifacts
     * @throws \RuntimeException NOT_WRITTEN when the zip file cannot be opened or written
     */
    public function create(Job $job, array $artifacts): ?string
    {
        $files = [];
        $leftOut = [];
        foreach ($artifacts as $artifact) {
            if (is_file($artifact->storage_path)) {
                $files[(int)$artifact->id] = $artifact;
            } else {
                $leftOut[] = (int)$artifact->id;
            }
        }
        $path = $files === [] ? false : ($this->newPath)();
        if ($path === false) {
            $this->logLeftOut($job, $leftOut);
            return null;
        }

        $notAdded = $this->write($path, $files);
        $this->logLeftOut($job, array_merge($leftOut, $notAdded));
        if (count($notAdded) === count($files)) {
            FileHelper::safeUnlink($path);
            return null;
        }

        return $path;
    }

    /**
     * Writes the artifacts' files into the zip file $path; the IDs of the
     * artifacts whose files did not open. The zip file is removed when
     * writing it fails.
     *
     * @param array<int, JobArtifact> $artifacts by ID
     * @return list<int>
     */
    private function write(string $path, array $artifacts): array
    {
        $zip = new \ZipArchive();
        $opened = $zip->open($path, \ZipArchive::OVERWRITE);
        if ($opened !== true) {
            self::discard($path);
            throw new \RuntimeException(sprintf(self::NOT_WRITTEN, sprintf(self::OPEN_FAILED, (int)$opened)));
        }
        $notAdded = [];
        foreach ($artifacts as $id => $artifact) {
            $added = self::quietly(static fn (): bool => $zip->addFile(
                $artifact->storage_path,
                $artifact->display_name,
                flags: \ZipArchive::FL_OVERWRITE | \ZipArchive::FL_OPEN_FILE_NOW
            ));
            if (!$added) {
                $notAdded[] = $id;
            }
        }
        if (!self::quietly(static fn (): bool => $zip->close(), $warning)) {
            self::discard($path);
            throw new \RuntimeException(sprintf(self::NOT_WRITTEN, (string)$warning));
        }

        return $notAdded;
    }

    /**
     * Runs $call with its warnings kept from the error handler; $warning
     * gets the last one.
     *
     * @param callable(): bool $call
     */
    private static function quietly(callable $call, ?string &$warning = null): bool
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    private static function discard(string $path): void
    {
        if (is_file($path)) {
            FileHelper::safeUnlink($path);
        }
    }

    /**
     * @param list<int> $leftOut
     */
    private function logLeftOut(Job $job, array $leftOut): void
    {
        if ($leftOut !== []) {
            sort($leftOut);
            \Yii::warning(sprintf(self::LEFT_OUT, (int)$job->id, implode(', ', $leftOut)), __CLASS__);
        }
    }
}
