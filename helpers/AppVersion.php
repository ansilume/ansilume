<?php

declare(strict_types=1);

namespace app\helpers;

/**
 * Resolves the running application version from the `/var/www/VERSION`
 * file that {@see ./bin/release} stamps on every release cut.
 *
 * Used by the worker heartbeat to detect "worker process started against
 * an older code revision than is currently on disk" — without that
 * comparison we can't tell stale workers apart from healthy long-runners.
 */
final class AppVersion
{
    /** Sentinel returned when the VERSION file is missing or empty. */
    public const UNKNOWN = 'unknown';

    /**
     * Path of the VERSION file. Overridable for tests via {@see withPath()}.
     */
    private static ?string $overridePath = null;

    public static function current(): string
    {
        $path = self::$overridePath ?? \Yii::getAlias('@app/VERSION');
        if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) {
            return self::UNKNOWN;
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            return self::UNKNOWN;
        }
        $trimmed = trim($contents);
        return $trimmed === '' ? self::UNKNOWN : $trimmed;
    }

    /**
     * Test helper: point current() at an arbitrary file. Pass null to reset.
     */
    public static function withPath(?string $path): void
    {
        self::$overridePath = $path;
    }
}
