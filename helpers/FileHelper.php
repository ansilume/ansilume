<?php

declare(strict_types=1);

namespace app\helpers;

class FileHelper
{
    /**
     * Remove a file if it exists. Returns true if removed or did not exist.
     */
    public static function safeUnlink(string $path): bool
    {
        if (!file_exists($path)) {
            return true;
        }
        if (!unlink($path)) {
            \Yii::warning("Failed to unlink: {$path}", __METHOD__);
            return false;
        }
        return true;
    }

    /**
     * Remove an empty directory if it exists. Returns true if removed or did not exist.
     */
    public static function safeRmdir(string $path): bool
    {
        if (!is_dir($path)) {
            return true;
        }
        if (!rmdir($path)) {
            \Yii::warning("Failed to rmdir: {$path}", __METHOD__);
            return false;
        }
        return true;
    }

    /**
     * Read file contents, returning $default on failure.
     */
    public static function safeFileGetContents(string $path, string $default = ''): string
    {
        if (!file_exists($path)) {
            return $default;
        }
        $result = file_get_contents($path);
        if ($result === false) {
            \Yii::warning("Failed to read: {$path}", __METHOD__);
            return $default;
        }
        return $result;
    }

    /**
     * Write contents to a file. Returns true on success.
     */
    public static function safeFilePutContents(string $path, string $content): bool
    {
        $result = file_put_contents($path, $content);
        if ($result === false) {
            \Yii::warning("Failed to write: {$path}", __METHOD__);
            return false;
        }
        return true;
    }

    /**
     * chmod with return-value check.
     */
    public static function safeChmod(string $path, int $mode): bool
    {
        if (!file_exists($path)) {
            return false;
        }
        if (!chmod($path, $mode)) {
            \Yii::warning(sprintf('Failed to chmod %s to %04o', $path, $mode), __METHOD__);
            return false;
        }
        return true;
    }
}
