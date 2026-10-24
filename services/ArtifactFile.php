<?php

declare(strict_types=1);

namespace app\services;

use app\models\JobArtifact;

/**
 * Opens and reads the file of a job artifact for a download or a preview.
 *
 * The file may be gone (a lost runtime/artifacts, a restore, the artifact
 * cleanup), or one the web server's user may not read (a restore by root, a
 * job directory, or runtime/artifacts, it may not enter). fopen() warns for
 * such a file, and the error handler turned the warning into an exception:
 * the download and the preview answered 500. The file is opened once, its
 * warning kept from the error handler, and a download sends that stream, so
 * the cleanup cannot remove the file between a check and the send.
 */
final class ArtifactFile
{
    /**
     * Why a file below a directory the web server may not enter cannot be
     * read (UNREADABLE_LOGGED); sprintf(): the directory.
     */
    public const DIRECTORY_CLOSED = 'its directory %s cannot be entered';

    /**
     * The artifact's file opened for reading, or why it cannot be:
     * ArtifactService::FILE_GONE, or FILE_UNREADABLE, which is logged. A
     * file that is gone below a directory the web server may not enter may
     * still be there, so it cannot be read; one the cleanup removes between
     * the check and fopen() is gone.
     *
     * @return resource|string
     */
    public static function open(JobArtifact $artifact): mixed
    {
        $path = $artifact->storage_path;
        $handle = false;
        $warning = '';
        if (is_file($path)) {
            set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
                $warning = $message;

                return true;
            });
            try {
                $handle = fopen($path, 'rb');
            } finally {
                restore_error_handler();
            }
        }
        if ($handle !== false) {
            return $handle;
        }
        // Not with $path: clearstatcache() throws for a NUL byte in it, which is_file() takes.
        clearstatcache();
        if (is_file($path)) {
            return self::unreadable($artifact, $warning);
        }
        $closed = self::closedAbove($path);

        return $closed === null ? ArtifactService::FILE_GONE : self::unreadable($artifact, sprintf(self::DIRECTORY_CLOSED, $closed));
    }

    /**
     * Up to $maxBytes of the file, as valid UTF-8 for the JSON of a preview:
     * a file in another encoding, or one cut inside a character, made the
     * JSON encoding fail with a server error. Null when it cannot be read.
     */
    public static function content(JobArtifact $artifact, int $maxBytes): ?string
    {
        $opened = self::open($artifact);
        if (is_string($opened)) {
            return null;
        }
        $content = fread($opened, max(1, $maxBytes));
        fclose($opened);

        return $content === false ? null : mb_scrub($content, 'UTF-8');
    }

    /**
     * The nearest directory above $path that the web server sees, when it
     * may not enter it; null when it may, and $path is gone. Below a
     * directory it may not enter it sees nothing, and stat() of "<dir>/."
     * needs leave to enter <dir>, as reading a file in it does.
     */
    private static function closedAbove(string $path): ?string
    {
        $directory = dirname($path);
        while (!is_dir($directory) && dirname($directory) !== $directory) {
            $directory = dirname($directory);
        }

        return is_dir($directory . '/.') ? null : $directory;
    }

    private static function unreadable(JobArtifact $artifact, string $reason): string
    {
        \Yii::warning(sprintf(ArtifactService::UNREADABLE_LOGGED, (int)$artifact->id, (int)$artifact->job_id, $reason), __CLASS__);

        return ArtifactService::FILE_UNREADABLE;
    }
}
