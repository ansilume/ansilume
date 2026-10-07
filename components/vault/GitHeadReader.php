<?php

declare(strict_types=1);

namespace app\components\vault;

/**
 * Reads the commit a checkout is at from its .git directory, without running
 * git: .git/HEAD holds a commit id (detached HEAD) or `ref: refs/...`, and the
 * ref is a loose file under .git or a line in .git/packed-refs.
 *
 * Not supported, the result is null: .git files (worktrees, submodules), the
 * reftable ref format, symbolic refs that point at other symbolic refs.
 */
final class GitHeadReader
{
    private const MAX_REF_BYTES = 4096;

    private const MAX_PACKED_REFS_BYTES = 50000000;

    /**
     * @return string|null 40 (SHA-1) or 64 (SHA-256) lower-case hex digits
     */
    public static function sha(string $root): ?string
    {
        $gitDir = $root . '/.git';
        $head = is_dir($gitDir) ? self::read($gitDir . '/HEAD', self::MAX_REF_BYTES) : null;
        if ($head === null || !str_starts_with($head, 'ref:')) {
            return self::commitId((string)$head);
        }
        $ref = trim(substr($head, strlen('ref:')));
        if (!self::isSafeRef($ref)) {
            return null;
        }
        $loose = self::read($gitDir . '/' . $ref, self::MAX_REF_BYTES);

        return $loose === null ? self::packedRef($gitDir, $ref) : self::commitId($loose);
    }

    /**
     * Below refs/ and without '..', so the ref cannot name a file outside
     * .git; git's ref name rules forbid the excluded characters anyway.
     */
    private static function isSafeRef(string $ref): bool
    {
        return preg_match('#^refs/[^\x00-\x20\x7F~^:?*\[\\\\]+$#', $ref) === 1 && !str_contains($ref, '..');
    }

    /**
     * `<commit id> <ref>` lines; '#' lines are comments, '^' lines peeled tags.
     */
    private static function packedRef(string $gitDir, string $ref): ?string
    {
        foreach (explode("\n", (string)self::read($gitDir . '/packed-refs', self::MAX_PACKED_REFS_BYTES)) as $line) {
            $fields = explode(' ', trim($line), 2);
            if (($fields[1] ?? null) === $ref) {
                return self::commitId($fields[0]);
            }
        }

        return null;
    }

    private static function read(string $path, int $maxBytes): ?string
    {
        if (!is_file($path) || !is_readable($path) || filesize($path) > $maxBytes) {
            return null;
        }
        $content = file_get_contents($path);

        return $content === false ? null : trim($content);
    }

    private static function commitId(string $text): ?string
    {
        $id = strtolower(trim($text));

        return in_array(strlen($id), [40, 64], true) && ctype_xdigit($id) ? $id : null;
    }
}
