<?php

declare(strict_types=1);

namespace app\services;

/**
 * Scans a project workspace for playbooks and builds directory trees.
 *
 * Stateless — no database access, no Yii dependencies. Operates purely
 * on the local filesystem given a base path.
 */
class ProjectFilesystemScanner
{
    private const EXCLUDED_FILENAMES = [
        'requirements.yml', 'requirements.yaml',
        'galaxy.yml', 'galaxy.yaml',
        'molecule.yml', 'molecule.yaml',
    ];

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
     */
    public function looksLikePlaybook(string $path): bool
    {
        if ($this->isExcludedFilename($path)) {
            return false;
        }

        $head = file_get_contents($path, false, null, 0, 512);
        if ($head === false) {
            return false;
        }

        return $this->firstContentLineIsList($head);
    }

    /**
     * Recursively build a directory tree array, ignoring hidden entries.
     * Each node: ['name' => string, 'rel' => string, 'type' => 'dir'|'file', 'children' => [...]]
     *
     * @return array<int, array{name: string, rel: string, type: string, children: array<int, mixed>}>
     */
    public function buildTree(string $base, string $dir, int $depth = 0, int $maxDepth = 5): array
    {
        if ($depth >= $maxDepth) {
            return [];
        }

        $nodes = $this->scanDirectoryEntries($base, $dir, $depth, $maxDepth);

        usort($nodes, fn ($a, $b) =>
            ($a['type'] === $b['type'])
                ? strcmp($a['name'], $b['name'])
                : ($a['type'] === 'dir' ? -1 : 1));

        return $nodes;
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
            if ($this->looksLikePlaybook($file)) {
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
        if (!is_dir($dir)) {
            return [];
        }

        $playbooks = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || !$this->isYamlFile($file)) {
                continue;
            }
            if ($this->looksLikePlaybook($file->getPathname())) {
                $playbooks[] = 'playbooks/' . ltrim(
                    substr($file->getPathname(), strlen($dir)),
                    '/'
                );
            }
        }
        return $playbooks;
    }

    private function isYamlFile(\SplFileInfo $file): bool
    {
        $ext = strtolower($file->getExtension());
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

    // -------------------------------------------------------------------------
    // Directory tree internals
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array{name: string, rel: string, type: string, children: array<int, mixed>}>
     */
    private function scanDirectoryEntries(string $base, string $dir, int $depth, int $maxDepth): array
    {
        $nodes = [];

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..' || str_starts_with($item, '.')) {
                continue;
            }

            $nodes[] = $this->buildNode($base, $dir . '/' . $item, $item, $depth, $maxDepth);
        }

        return $nodes;
    }

    /**
     * @return array{name: string, rel: string, type: string, children: array<int, mixed>}
     */
    private function buildNode(string $base, string $path, string $name, int $depth, int $maxDepth): array
    {
        $rel = ltrim(substr($path, strlen($base)), '/');

        if (is_dir($path)) {
            return [
                'name' => $name,
                'rel' => $rel,
                'type' => 'dir',
                'children' => $this->buildTree($base, $path, $depth + 1, $maxDepth),
            ];
        }

        return ['name' => $name, 'rel' => $rel, 'type' => 'file', 'children' => []];
    }
}
