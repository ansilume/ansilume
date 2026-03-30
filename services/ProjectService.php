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

    /**
     * @param array<string, string> $env
     */
    private function cloneOrPull(Project $project, string $dest, array $env): void
    {
        if (is_dir($dest . '/.git')) {
            $this->gitPull($dest, $project->scm_branch, $env);
        } else {
            $this->gitClone((string)$project->scm_url, $dest, $project->scm_branch, $env);
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
        $base = (string)\Yii::getAlias(rtrim($this->workspacePath, '/'));
        return $base . '/' . $project->id;
    }

    /**
     * Build the environment for git subprocesses.
     * If the project has an SSH key credential, writes it to a temp file and sets
     * GIT_SSH_COMMAND so git uses it — no passwords ever appear in the process table.
     *
     * @param string|null $keyFile Set to the temp key path if one was written (caller must delete it).
     * @return array<string, string>
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

    /**
     * @return array<string, string>
     */
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

    /**
     * @param array<string, string> $env
     */
    protected function gitClone(string $url, string $dest, string $branch, array $env): void
    {
        $this->runGit(['git', 'clone', '--branch', $branch, '--depth', '1', '--', $url, $dest], $env);
    }

    /**
     * @param array<string, string> $env
     */
    protected function gitPull(string $dest, string $branch, array $env): void
    {
        $this->runGit(['git', '-C', $dest, 'fetch', '--depth', '1', 'origin', $branch], $env);
        $this->runGit(['git', '-C', $dest, 'reset', '--hard', 'FETCH_HEAD'], $env);
    }

    // -------------------------------------------------------------------------
    // Git operations
    // -------------------------------------------------------------------------

    /**
     * Execute a git command as a subprocess.
     * All arguments are passed as an array — never shell-interpolated.
     *
     * @param string[] $cmd
     * @param array<string, string> $env
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
     * @param string[] $cmd
     * @param array<string, string> $env
     * @return array{0: string, 1: string, 2: int}
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
