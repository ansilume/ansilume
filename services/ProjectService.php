<?php

declare(strict_types=1);

namespace app\services;

use app\jobs\SyncProjectJob;
use app\models\Credential;
use app\models\Project;
use yii\base\Component;

/**
 * Manages project lifecycle: sync queueing and local path resolution.
 */
class ProjectService extends Component
{
    /**
     * Base directory where project workspaces are checked out.
     * Override via Yii component config or params.
     */
    public string $workspacePath = '/var/www/runtime/projects';

    /**
     * Queue a sync job for the given project.
     * Transitions status to 'syncing' immediately.
     */
    public function queueSync(Project $project): void
    {
        $project->status = Project::STATUS_SYNCING;
        $project->save(false);

        \Yii::$app->queue->push(new SyncProjectJob(['projectId' => $project->id]));
    }

    /**
     * Perform the actual sync (called from the worker, not the web request).
     * Clones or pulls the repository and updates the project record.
     *
     * @throws \RuntimeException on failure.
     */
    public function sync(Project $project): void
    {
        if ($project->scm_type !== Project::SCM_TYPE_GIT) {
            $this->markSynced($project);
            return;
        }

        if (empty($project->scm_url)) {
            throw new \RuntimeException("Project #{$project->id} has no SCM URL.");
        }

        $dest = $this->localPath($project);
        $project->local_path = $dest;
        $project->save(false);

        $this->executeGitSync($project, $dest);
    }

    /**
     * Mark a non-git project as synced (nothing to pull).
     */
    private function markSynced(Project $project): void
    {
        $project->status = Project::STATUS_SYNCED;
        $project->last_synced_at = time();
        $project->save(false);
    }

    /**
     * Run git clone or pull with SSH key handling and error recovery.
     *
     * @throws \RuntimeException on failure.
     */
    private function executeGitSync(Project $project, string $dest): void
    {
        $keyFile = null;
        try {
            $env = $this->buildGitEnv($project, $keyFile);
            $this->cloneOrPull($project, $dest, $env);

            $project->status = Project::STATUS_SYNCED;
            $project->last_synced_at = time();
            $project->last_sync_error = null;
        } catch (\RuntimeException $e) {
            $project->status = Project::STATUS_ERROR;
            $project->last_sync_error = $e->getMessage();
            throw $e;
        } finally {
            $project->save(false);
            $this->cleanupKeyFile($keyFile);
        }
    }

    private function cloneOrPull(Project $project, string $dest, array $env): void
    {
        if (is_dir($dest . '/.git')) {
            $this->gitPull($dest, $project->scm_branch, $env);
        } else {
            $this->gitClone($project->scm_url, $dest, $project->scm_branch, $env);
        }
    }

    private function cleanupKeyFile(?string $keyFile): void
    {
        if ($keyFile !== null) {
            \app\helpers\FileHelper::safeUnlink($keyFile);
        }
    }

    /**
     * Resolve the local filesystem path for a project workspace.
     */
    public function localPath(Project $project): string
    {
        $base = \Yii::getAlias(rtrim($this->workspacePath, '/'));
        return $base . '/' . $project->id;
    }

    /**
     * Build the environment for git subprocesses.
     * If the project has an SSH key credential, writes it to a temp file and sets
     * GIT_SSH_COMMAND so git uses it — no passwords ever appear in the process table.
     *
     * @param string|null $keyFile  Set to the temp key path if one was written (caller must delete it).
     */
    protected function buildGitEnv(Project $project, ?string &$keyFile): array
    {
        $env = $this->baseGitEnv();

        $sshKeyFile = $this->writeSshKeyFile($project);
        if ($sshKeyFile !== null) {
            $keyFile = $sshKeyFile;
            $env['GIT_SSH_COMMAND'] = 'ssh -i ' . escapeshellarg($sshKeyFile)
                . ' -o StrictHostKeyChecking=no'
                . ' -o BatchMode=yes';
        }

        return $env;
    }

    private function baseGitEnv(): array
    {
        return [
            'HOME' => getenv('HOME') ?: '/root',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'safe.directory',
            'GIT_CONFIG_VALUE_0' => '*',
        ];
    }

    /**
     * If the project has an SSH key credential, write it to a temp file.
     * Returns the temp file path, or null if no key is available.
     */
    private function writeSshKeyFile(Project $project): ?string
    {
        if ($project->scm_credential_id === null) {
            return null;
        }

        $credential = $project->scmCredential;
        if ($credential === null || $credential->credential_type !== Credential::TYPE_SSH_KEY) {
            return null;
        }

        /** @var CredentialService $cs */
        $cs = \Yii::$app->get('credentialService');
        $key = $cs->getSecrets($credential)['private_key'] ?? '';

        if ($key === '') {
            return null;
        }

        $keyFile = tempnam(sys_get_temp_dir(), 'ansilume_ssh_');
        file_put_contents($keyFile, $key);
        chmod($keyFile, 0600);

        return $keyFile;
    }

    protected function gitClone(string $url, string $dest, string $branch, array $env): void
    {
        $this->runGit(['git', 'clone', '--branch', $branch, '--depth', '1', '--', $url, $dest], $env);
    }

    protected function gitPull(string $dest, string $branch, array $env): void
    {
        $this->runGit(['git', '-C', $dest, 'fetch', '--depth', '1', 'origin', $branch], $env);
        $this->runGit(['git', '-C', $dest, 'reset', '--hard', 'FETCH_HEAD'], $env);
    }

    // -------------------------------------------------------------------------
    // Filesystem scanning — playbook detection and directory tree
    // -------------------------------------------------------------------------

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

    private function isExcludedFilename(string $path): bool
    {
        $name = strtolower(basename($path));

        if (str_starts_with($name, '.')) {
            return true;
        }

        $excluded = [
            'requirements.yml', 'requirements.yaml',
            'galaxy.yml', 'galaxy.yaml',
            'molecule.yml', 'molecule.yaml',
        ];

        return in_array($name, $excluded, true);
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
     * Recursively build a directory tree array, ignoring .git and hidden dirs.
     * Each node: ['name' => string, 'rel' => string, 'type' => 'dir'|'file', 'children' => [...]]
     *
     * @return array<array{name: string, rel: string, type: string, children: array}>
     */
    public function buildTree(string $base, string $dir, int $depth = 0, int $maxDepth = 5): array
    {
        if ($depth >= $maxDepth) {
            return [];
        }

        $nodes = $this->scanDirectoryEntries($base, $dir, $depth, $maxDepth);

        usort($nodes, fn($a, $b) =>
            ($a['type'] === $b['type'])
                ? strcmp($a['name'], $b['name'])
                : ($a['type'] === 'dir' ? -1 : 1));

        return $nodes;
    }

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

    // -------------------------------------------------------------------------
    // Git operations
    // -------------------------------------------------------------------------

    /**
     * Execute a git command as a subprocess.
     * All arguments are passed as an array — never shell-interpolated.
     *
     * @throws \RuntimeException on non-zero exit code.
     */
    private function runGit(array $cmd, array $env = []): void
    {
        \Yii::info('ProjectService: ' . implode(' ', $cmd), __CLASS__);

        [$stdout, $stderr, $exitCode] = $this->execProcess($cmd, $env);
        $this->logProcessOutput($stdout, $stderr);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                sprintf('git command failed (exit %d): %s', $exitCode, trim($stderr ?: $stdout ?: '(no output)'))
            );
        }
    }

    /**
     * Run a command and return [stdout, stderr, exitCode].
     *
     * @return array{string, string, int}
     */
    private function execProcess(array $cmd, array $env): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, null, $env ?: null);
        if (!is_resource($process)) {
            throw new \RuntimeException('proc_open failed for git command.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [$stdout ?: '', $stderr ?: '', proc_close($process)];
    }

    private function logProcessOutput(string $stdout, string $stderr): void
    {
        if ($stdout !== '') {
            \Yii::info('ProjectService stdout: ' . $stdout, __CLASS__);
        }
        if ($stderr !== '') {
            \Yii::warning('ProjectService stderr: ' . $stderr, __CLASS__);
        }
    }
}
