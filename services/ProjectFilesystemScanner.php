<?php

declare(strict_types=1);

namespace app\services;

use app\components\vault\RepoPath;

/**
 * Scans a project workspace for playbooks and builds directory trees.
 *
 * No database access, no Yii dependencies. Operates purely on the local
 * filesystem given a base path.
 *
 * The web server's user reads the directory, and a project may hold what that
 * user cannot read: a directory it may not list or enter, or a playbook it
 * may not read. Directories are listed and files read with the SPL classes,
 * which throw instead of warning, so such an entry is skipped and remembered
 * (unreadable()) instead of failing the project page; an entry that a sync,
 * or a git pull in a manual project's directory, removes or replaces while
 * the scan runs is read again or left out, never named. Symlinked
 * directories are never followed, so a repository
 * cannot make the page list the server's own directories (a link to /etc) or
 * loop; a symlinked playbook is read only when it resolves inside the
 * project. Use one scanner per project.
 */
class ProjectFilesystemScanner
{
    private const EXCLUDED_FILENAMES = [
        'requirements.yml', 'requirements.yaml',
        'galaxy.yml', 'galaxy.yaml',
        'molecule.yml', 'molecule.yaml',
    ];

    /** How much of a YAML file looksLikePlaybook() reads. */
    private const HEAD_BYTES = 512;

    /** @var array<string, true> the absolute paths the scans could not read */
    private array $unreadable = [];

    /**
     * Detect playbook files in a project directory.
     * Scans root-level YAML files and the playbooks/ subdirectory recursively.
     *
     * @return string[] Relative playbook paths, sorted alphabetically.
     */
    public function detectPlaybooks(string $base): array
    {
        $playbooks = $this->detectRootPlaybooks($base);
        $playbooks = array_merge($playbooks, $this->detectPlaybooksInSubdir($base));

        sort($playbooks);
        return $playbooks;
    }

    /**
     * Check whether a YAML file looks like an Ansible playbook.
     * A playbook is a YAML list at the document root (first content line starts with "- ").
     * Anything but a regular file is none, and a file that cannot be read is
     * remembered (unreadable()).
     */
    public function looksLikePlaybook(string $path): bool
    {
        if ($this->isExcludedFilename($path) || !is_file($path)) {
            return false;
        }

        $head = $this->head($path);
        if ($head === null) {
            return false;
        }

        return $this->firstContentLineIsList($head);
    }

    /**
     * Recursively build a directory tree array, ignoring hidden entries.
     * Each node: ['name' => string, 'rel' => string, 'type' => 'dir'|'file'|'link', 'children' => [...]]
     * A symlink is a 'link' node, never followed.
     *
     * @return array<int, array{name: string, rel: string, type: string, children: array<int, mixed>}>
     */
    public function buildTree(string $base, string $dir, int $depth = 0, int $maxDepth = 5): array
    {
        if ($depth >= $maxDepth) {
            return [];
        }

        $nodes = $this->scanDirectoryEntries($base, $dir, $depth, $maxDepth);

        usort($nodes, static fn (array $a, array $b): int =>
            (($b['type'] === 'dir') <=> ($a['type'] === 'dir')) ?: strcmp($a['name'], $b['name']));

        return $nodes;
    }

    /**
     * The paths the scans so far could not read, relative to $base and
     * sorted: directories that could not be listed, files that could not be
     * read, and entries that were listed but could not be examined, as in a
     * directory the web server may list but not enter. '.' is $base itself.
     *
     * @return list<string>
     */
    public function unreadable(string $base): array
    {
        $paths = [];
        foreach (array_keys($this->unreadable) as $path) {
            $paths[] = $path === $base ? '.' : ltrim(substr($path, strlen($base)), '/');
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    // -------------------------------------------------------------------------
    // Playbook detection internals
    // -------------------------------------------------------------------------

    /**
     * @return string[]
     */
    private function detectRootPlaybooks(string $base): array
    {
        $playbooks = [];
        foreach (glob($base . '/*.{yml,yaml}', GLOB_BRACE) ?: [] as $file) {
            if ($this->isPlaybookInside($base, $file)) {
                $playbooks[] = basename($file);
            }
        }
        return $playbooks;
    }

    /**
     * @return string[]
     */
    private function detectPlaybooksInSubdir(string $base): array
    {
        $dir = $base . '/playbooks';
        if (is_link($dir) || !is_dir($dir)) {
            return [];
        }

        return $this->playbooksBelow($base, $dir, 'playbooks');
    }

    /**
     * The playbooks in $dir and its subdirectories, relative to the project
     * directory; symlinked directories are not entered.
     *
     * @return string[]
     */
    private function playbooksBelow(string $base, string $dir, string $relative): array
    {
        $playbooks = [];
        foreach ($this->entries($dir) ?? [] as $name) {
            $path = $dir . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                $playbooks = array_merge($playbooks, $this->playbooksBelow($base, $path, $relative . '/' . $name));
            } elseif ($this->isYamlFile($name) && $this->isPlaybookInside($base, $path)) {
                $playbooks[] = $relative . '/' . $name;
            }
        }
        return $playbooks;
    }

    /**
     * Whether $path is a playbook the project holds: a symlink that leads
     * outside the project directory is not read. An entry that was listed
     * but cannot be examined is remembered.
     */
    private function isPlaybookInside(string $base, string $path): bool
    {
        if (is_link($path)) {
            return RepoPath::realRelative($base, $path) !== null && $this->looksLikePlaybook($path);
        }

        return $this->examined($path) && $this->looksLikePlaybook($path);
    }

    private function isYamlFile(string $name): bool
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return $ext === 'yml' || $ext === 'yaml';
    }

    private function isExcludedFilename(string $path): bool
    {
        $name = strtolower(basename($path));

        if (str_starts_with($name, '.')) {
            return true;
        }

        return in_array($name, self::EXCLUDED_FILENAMES, true);
    }

    private function firstContentLineIsList(string $head): bool
    {
        foreach (explode("\n", $head) as $line) {
            $trimmed = rtrim($line);
            if ($trimmed === '' || $trimmed === '---' || str_starts_with($trimmed, '#')) {
                continue;
            }
            return str_starts_with($trimmed, '- ') || $trimmed === '-';
        }
        return false;
    }

    /**
     * The first bytes of a regular file, or null when it cannot be opened,
     * which is remembered, or is gone. SplFileObject throws where
     * file_get_contents() warns: a RuntimeException for a file it cannot
     * open, a LogicException for one that became a directory since it was
     * checked. One that is there is opened once more before it is
     * remembered, as git writes a changed file anew.
     */
    private function head(string $path): ?string
    {
        for ($tries = 0; $tries < 2; $tries++) {
            try {
                $head = (new \SplFileObject($path, 'rb'))->fread(self::HEAD_BYTES);

                return $head === false ? null : $head;
            } catch (\RuntimeException | \LogicException $e) {
                if (!self::canExamine($path)) {
                    return null;
                }
            }
        }
        $this->unreadable[$path] = true;

        return null;
    }

    // -------------------------------------------------------------------------
    // Directory tree internals
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array{name: string, rel: string, type: string, children: array<int, mixed>}>
     */
    private function scanDirectoryEntries(string $base, string $dir, int $depth, int $maxDepth): array
    {
        $nodes = [];

        foreach ($this->entries($dir) ?? [] as $item) {
            if (str_starts_with($item, '.')) {
                continue;
            }

            $node = $this->buildNode($base, $dir . '/' . $item, $item, $depth, $maxDepth);
            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * The node of a listed entry, or null for one that is gone since.
     *
     * @return array{name: string, rel: string, type: string, children: array<int, mixed>}|null
     */
    private function buildNode(string $base, string $path, string $name, int $depth, int $maxDepth): ?array
    {
        $rel = ltrim(substr($path, strlen($base)), '/');

        if (is_link($path)) {
            return ['name' => $name, 'rel' => $rel, 'type' => 'link', 'children' => []];
        }

        if (is_dir($path)) {
            return [
                'name' => $name,
                'rel' => $rel,
                'type' => 'dir',
                'children' => $this->buildTree($base, $path, $depth + 1, $maxDepth),
            ];
        }

        // Not examined: gone, or remembered in a directory it may not enter.
        if (!$this->examined($path) && !isset($this->unreadable[$path])) {
            return null;
        }

        return ['name' => $name, 'rel' => $rel, 'type' => 'file', 'children' => []];
    }

    /**
     * Whether a listed entry can be examined: lstat() succeeds. In a
     * directory that can be entered, lstat() fails only for an entry that is
     * absent at that moment, as git, which removes a changed file before it
     * writes it anew, or a sync leaves it: it is examined once more, and is
     * gone, not named, when that fails too. In a directory the web server may
     * list but not enter, no entry can be examined, and each one is
     * remembered (unreadable()).
     */
    private function examined(string $path): bool
    {
        if (self::canExamine($path)) {
            return true;
        }
        if (!is_dir(dirname($path) . '/.')) {
            $this->unreadable[$path] = true;

            return false;
        }

        return self::canExamine($path);
    }



    /**
     * Whether a listed entry can be examined: lstat() succeeds, which it
     * does not in a directory the web server may list but not enter.
     * SplFileInfo::getType() throws where filetype() warns, and asks lstat()
     * as is_link() and is_dir() do; file_exists() asks access(), which checks
     * the real user instead of the effective one.
     */
    private static function canExamine(string $path): bool
    {
        // What it found a moment ago may be gone or back since.
        clearstatcache();
        try {
            (new \SplFileInfo($path))->getType();
        } catch (\RuntimeException $e) {
            return false;
        }

        return true;
    }

    /**
     * The names in a directory, '.' and '..' left out, sorted; null when it
     * cannot be listed, which is remembered, or is gone. FilesystemIterator
     * throws where scandir() warns; a directory that is there is listed once
     * more before it is remembered.
     *
     * @return list<string>|null
     */
    protected function entries(string $dir): ?array
    {
        for ($tries = 0; $tries < 2; $tries++) {
            $names = [];
            try {
                foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $entry) {
                    /** @var \SplFileInfo $entry */
                    $names[] = $entry->getFilename();
                }
                sort($names, SORT_STRING);

                return $names;
            } catch (\UnexpectedValueException $e) {
                if (!self::canExamine($dir)) {
                    return null;
                }
            }
        }
        $this->unreadable[$dir] = true;

        return null;
    }
}
