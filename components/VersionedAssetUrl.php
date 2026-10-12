<?php

declare(strict_types=1);

namespace app\components;

/**
 * The URL of a static file under web/ with a version query taken from its
 * content, such as /js/extra-vars-editor.js?v=3f2a9c0d1e4b.
 *
 * nginx serves these files with Last-Modified and an ETag but without
 * Cache-Control, so a browser may reuse a cached copy for days (heuristic
 * freshness) and run an older script after an update. The version changes
 * with the content, so an updated file reaches the browser at once in every
 * deployment path: the dev checkout and source installs, where nginx and
 * PHP read the same file, and the prebuilt images, where the app image
 * renders the page and the nginx image serves the copy built with it. The
 * query string does not change which file nginx serves.
 */
final class VersionedAssetUrl
{
    /** How many hex characters of the content's SHA-256 the version uses. */
    public const VERSION_LENGTH = 12;

    /**
     * $path with ?v=<version of the file's content>; $path unchanged when the
     * file is missing or unreadable.
     *
     * @param string $path URL path of a file under the web root, such as /js/extra-vars-editor.js
     * @param string|null $webRoot the web root; null for the application's web/
     */
    public static function of(string $path, ?string $webRoot = null): string
    {
        $file = ($webRoot ?? dirname(__DIR__) . '/web') . $path;
        if (!is_file($file) || !is_readable($file)) {
            return $path;
        }
        $hash = hash_file('sha256', $file);

        return $hash === false ? $path : $path . '?v=' . substr($hash, 0, self::VERSION_LENGTH);
    }
}
