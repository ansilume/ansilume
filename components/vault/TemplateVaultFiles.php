<?php

declare(strict_types=1);

namespace app\components\vault;

/**
 * Which vault files and values in a checkout a job template probably loads.
 *
 * Ansible loads group_vars/ and host_vars/ next to each inventory source (the
 * source directory itself, or the directory of a source file) and next to the
 * top-level playbook, plus the files a play names in vars_files. The
 * selection covers:
 *
 * - <inventory dir>/group_vars/** and host_vars/**, the inventory source file
 *   itself, or everything under a source directory;
 * - <playbook dir>/group_vars/** and host_vars/**;
 * - the playbook's literal vars_files.
 *
 * Group and host names are not matched, so the selection may include files
 * for hosts a play never targets; checks built on it are informational.
 * Static inventories live in the database and add no inventory directory.
 *
 * The scan lists files under their real path and never follows a symlink.
 * Ansible does, so each of these directories and files also counts under
 * its real path when a symlink inside the checkout leads there (e.g.
 * inventories/staging/group_vars -> ../prod/group_vars). Symlinks further
 * down, inside group_vars/ or host_vars/, are not followed.
 */
final class TemplateVaultFiles
{
    /** Inventory types whose source_path Ansible gets as `-i` (see app\models\Inventory). */
    private const SOURCE_TYPES = ['file', 'dynamic'];

    private const MAX_PLAYBOOK_BYTES = 1000000;

    /**
     * @param list<string> $entryPaths scanned entry paths, relative to the checkout root
     * @param string $playbook the template's playbook, relative to the checkout root
     * @param string|null $sourcePath the inventory's source_path (file and dynamic inventories)
     * @param list<string> $varsFiles relative to the repo root, normalised
     * @return list<string> the subset of $entryPaths the template probably loads,
     *         in their order and with their repetitions
     */
    public static function select(
        string $root,
        array $entryPaths,
        string $playbook,
        string $inventoryType,
        ?string $sourcePath,
        array $varsFiles
    ): array {
        [$exact, $prefixes] = self::inventoryRules($root, $inventoryType, (string)$sourcePath);
        $playbookPath = RepoPath::normalize($playbook);
        if ($playbookPath !== null) {
            $prefixes = array_merge($prefixes, self::varsPrefixes(RepoPath::parent($playbookPath)));
        }
        foreach ($varsFiles as $file) {
            // A path that leaves the root normalises to null, and '' matches no entry.
            $exact[] = (string)RepoPath::normalize($file);
        }
        $exact = array_flip(self::withRealPaths($root, $exact, ''));
        $prefixes = self::withRealPaths($root, $prefixes, '/');

        return array_values(array_filter(
            $entryPaths,
            static fn (string $path): bool => isset($exact[$path]) || self::startsWithAny($path, $prefixes)
        ));
    }

    /**
     * @return list<string> literal top-level vars_files of the playbook, resolved
     *         relative to the playbook dir, normalised; [] when the playbook
     *         is no list of plays (read by PlaybookVarsFiles, never a YAML parser)
     */
    public static function playbookVarsFiles(string $root, string $playbook): array
    {
        $relative = RepoPath::normalize($playbook);
        $content = $relative === null ? null : self::playbookContent($root, $relative);
        $directory = RepoPath::parent((string)$relative);
        $files = [];
        foreach (PlaybookVarsFiles::read((string)$content) as $file) {
            // Templated (Jinja), home-relative and absolute entries are skipped.
            if (preg_match('#^$|^[~/]|\{\{|\{%#', $file) === 1) {
                continue;
            }
            $resolved = RepoPath::normalize($directory === '' ? $file : $directory . '/' . $file);
            if ($resolved !== null && $resolved !== '') {
                $files[] = $resolved;
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @return array{0: list<string>, 1: list<string>} exact paths and path prefixes
     */
    private static function inventoryRules(string $root, string $inventoryType, string $sourcePath): array
    {
        $source = RepoPath::normalize($sourcePath);
        // Without a source path there is no -i, and ansible.cfg decides.
        if ($source === null || trim($sourcePath) === '' || !in_array($inventoryType, self::SOURCE_TYPES, true)) {
            return [[], []];
        }
        if (is_dir($root . '/' . $source)) {
            return [[], [$source === '' ? '' : $source . '/']];
        }

        return [[$source], self::varsPrefixes(RepoPath::parent($source))];
    }

    /**
     * @return list<string>
     */
    private static function varsPrefixes(string $directory): array
    {
        $base = $directory === '' ? '' : $directory . '/';

        return [$base . 'group_vars/', $base . 'host_vars/'];
    }

    /**
     * @param list<string> $prefixes
     */
    private static function startsWithAny(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adds, for each candidate behind a symlink inside the checkout, its real
     * path; the scan lists files only under that one.
     *
     * @param list<string> $paths relative to the root; prefixes end with '/'
     * @param string $suffix '/' for prefixes, '' for files
     * @return list<string>
     */
    private static function withRealPaths(string $root, array $paths, string $suffix): array
    {
        $all = $paths;
        foreach ($paths as $path) {
            $trimmed = rtrim($path, '/');
            $real = $trimmed === '' ? null : RepoPath::realRelative($root, $root . '/' . $trimmed);
            if ($real !== null && $real !== $trimmed) {
                $all[] = $real . $suffix;
            }
        }

        return array_values(array_unique($all));
    }

    /**
     * The text of a playbook inside the checkout; null for a symlink out of
     * it, a missing or unreadable file, or one above the size limit.
     */
    private static function playbookContent(string $root, string $relative): ?string
    {
        $path = $root . '/' . $relative;
        $inside = RepoPath::realRelative($root, $path) !== null;
        if (!$inside || !is_file($path) || !is_readable($path) || filesize($path) > self::MAX_PLAYBOOK_BYTES) {
            return null;
        }
        $content = file_get_contents($path);

        return $content === false ? null : $content;
    }
}
