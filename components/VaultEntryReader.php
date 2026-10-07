<?php

declare(strict_types=1);

namespace app\components;

use app\components\vault\InlineVaultExtractor;
use app\components\vault\RepoPath;
use app\components\vault\VaultContentScanner;
use app\components\vault\VaultEnvelope;
use app\models\ProjectVaultEntry;

/**
 * Reads the vault envelopes of scanned entries from the checkout as it is
 * now. A file is read, and its inline values extracted, once for all of its
 * entries (one extract per entry made checks of files with many inline
 * values quadratic). An envelope is null when the file is gone, lies behind
 * a symlink, grew past the size limit, no longer has the value at that line,
 * no longer parses, or its encrypted content differs from what the scan saw
 * (fingerprint), so a check never trusts a stale scan.
 */
final class VaultEntryReader
{
    /**
     * @param list<ProjectVaultEntry> $entries entries of the one file $path
     * @return list<VaultEnvelope|null> in the order of $entries
     */
    public static function envelopes(string $root, string $path, array $entries): array
    {
        $content = self::read($root, $path);
        $inline = null;
        $envelopes = [];
        foreach ($entries as $entry) {
            if ($content !== null && $entry->kind === ProjectVaultEntry::KIND_INLINE) {
                $inline ??= self::inlineByLine($content);
                $text = $inline[(int)$entry->line] ?? null;
            } else {
                $text = $content;
            }
            $envelopes[] = $text === null ? null : self::matching($text, $entry);
        }

        return $envelopes;
    }

    /**
     * The file's content; null when it is no longer what the scan read: the
     * real path must be the scanned path (no symlink on the way, nothing
     * outside the checkout), and a file that grew past the limit is not read.
     */
    private static function read(string $root, string $path): ?string
    {
        $file = rtrim($root, '/') . '/' . $path;
        // The file may have changed since PHP last looked at it in this process.
        clearstatcache(true, $file);
        if (RepoPath::realRelative($root, $file) !== $path || !is_file($file) || !is_readable($file)) {
            return null;
        }
        if ((int)filesize($file) > VaultContentScanner::MAX_FILE_BYTES) {
            return null;
        }
        $content = file_get_contents($file);

        return $content === false ? null : $content;
    }

    /**
     * @return array<int, string> line of the tag => envelope text
     */
    private static function inlineByLine(string $content): array
    {
        $byLine = [];
        foreach (InlineVaultExtractor::extract($content) as $value) {
            $byLine[$value['line']] = $value['envelope'];
        }

        return $byLine;
    }

    private static function matching(string $text, ProjectVaultEntry $entry): ?VaultEnvelope
    {
        try {
            $envelope = VaultEnvelope::parse($text);
        } catch (\UnexpectedValueException) {
            return null;
        }

        return $envelope->fingerprint() === $entry->fingerprint ? $envelope : null;
    }
}
