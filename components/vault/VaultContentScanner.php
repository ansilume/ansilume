<?php

declare(strict_types=1);

namespace app\components\vault;

/**
 * Walks a project checkout and lists its vault content, whole encrypted files
 * and inline `!vault` values, without decrypting anything.
 *
 * Symlinks are never followed, files or directories: a checkout must not make
 * the scan read outside itself. .git and .ansible directories are skipped at
 * any depth; binary files (a NUL byte in the first 8 KiB) and files larger
 * than the byte limit are not read. At the file, entry or time limit the scan
 * stops and says so in VaultScanResult::$truncated. Directory entries are
 * visited in sorted order, so the same checkout always gives the same result.
 */
final class VaultContentScanner
{
    public const MAX_FILES = 20000;
    public const MAX_FILE_BYTES = 5000000;
    public const MAX_ENTRIES = 2000;

    private const SKIPPED_DIRECTORIES = ['.git', '.ansible'];

    private const BINARY_PROBE_BYTES = 8192;

    /** @var list<VaultScanEntry> */
    private array $entries = [];

    private int $filesScanned = 0;

    private bool $truncated = false;

    /** hrtime() nanoseconds at which the scan stops. */
    private int $deadline = 0;

    /**
     * The file, entry and byte limits are parameters for tests; production
     * code passes at most the time limit.
     */
    public function __construct(
        private readonly float $maxSeconds = 10.0,
        private readonly int $maxFiles = self::MAX_FILES,
        private readonly int $maxEntries = self::MAX_ENTRIES,
        private readonly int $maxFileBytes = self::MAX_FILE_BYTES,
    ) {
    }

    /**
     * A root that is not a directory gives an empty result.
     */
    public function scan(string $root): VaultScanResult
    {
        $this->entries = [];
        $this->filesScanned = 0;
        $this->truncated = false;
        $this->deadline = self::now() + (int)($this->maxSeconds * 1000000000);
        if (is_dir($root)) {
            $this->walk($root, '');
        }
        $entries = $this->entries;
        usort($entries, static function (VaultScanEntry $a, VaultScanEntry $b): int {
            return strcmp($a->path, $b->path) ?: ($a->line ?? 0) <=> ($b->line ?? 0);
        });

        return new VaultScanResult($entries, $this->truncated, $this->filesScanned);
    }

    private function walk(string $directory, string $prefix): void
    {
        foreach (self::children($directory) as $name) {
            if (self::now() >= $this->deadline) {
                $this->truncated = true;
            }
            if ($this->truncated) {
                return;
            }
            $this->visit($directory . '/' . $name, $name, $prefix === '' ? $name : $prefix . '/' . $name);
        }
    }

    private function visit(string $path, string $name, string $relative): void
    {
        if (is_link($path)) {
            return;
        }
        if (is_dir($path)) {
            if (!in_array($name, self::SKIPPED_DIRECTORIES, true)) {
                $this->walk($path, $relative);
            }

            return;
        }
        // Sockets, FIFOs and devices are neither; reading a FIFO would block.
        if (is_file($path)) {
            $this->scanFile($path, $relative);
        }
    }

    private function scanFile(string $path, string $relative): void
    {
        if ($this->filesScanned >= $this->maxFiles) {
            $this->truncated = true;

            return;
        }
        $this->filesScanned++;
        $content = $this->read($path);
        if ($content === null) {
            return;
        }
        foreach (VaultFileInspector::inspect($relative, $content) as $entry) {
            if (count($this->entries) >= $this->maxEntries) {
                $this->truncated = true;

                return;
            }
            $this->entries[] = $entry;
        }
    }

    /**
     * The file's content, or null when it is unreadable, too large or binary.
     */
    private function read(string $path): ?string
    {
        $size = is_readable($path) ? filesize($path) : false;
        if ($size === false || $size > $this->maxFileBytes) {
            return null;
        }
        $content = file_get_contents($path);
        if ($content === false || str_contains(substr($content, 0, self::BINARY_PROBE_BYTES), "\0")) {
            return null;
        }

        return $content;
    }

    /**
     * @return list<string> the directory's entries without '.' and '..', sorted
     */
    private static function children(string $directory): array
    {
        $names = [];
        $handle = is_readable($directory) ? opendir($directory) : false;
        if ($handle !== false) {
            while (($name = readdir($handle)) !== false) {
                $names[] = $name;
            }
            closedir($handle);
        }
        $names = array_values(array_diff($names, ['.', '..']));
        sort($names, SORT_STRING);

        return $names;
    }

    private static function now(): int
    {
        return (int)hrtime(true);
    }
}
