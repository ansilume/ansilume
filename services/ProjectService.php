<?php

declare(strict_types=1);

namespace app\services;

use app\jobs\SyncProjectJob;
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
            // Manual projects have no SCM — nothing to sync.
            $project->status        = Project::STATUS_SYNCED;
            $project->last_synced_at = time();
            $project->save(false);
            return;
        }

        if (empty($project->scm_url)) {
            throw new \RuntimeException("Project #{$project->id} has no SCM URL.");
        }

        $dest = $this->localPath($project);
        $project->local_path = $dest;
        $project->save(false);

        try {
            if (is_dir($dest . '/.git')) {
                $this->gitPull($dest, $project->scm_branch);
            } else {
                $this->gitClone($project->scm_url, $dest, $project->scm_branch);
            }

            $project->status         = Project::STATUS_SYNCED;
            $project->last_synced_at = time();
        } catch (\RuntimeException $e) {
            $project->status = Project::STATUS_ERROR;
            throw $e;
        } finally {
            $project->save(false);
        }
    }

    /**
     * Resolve the local filesystem path for a project workspace.
     */
    public function localPath(Project $project): string
    {
        return rtrim($this->workspacePath, '/') . '/' . $project->id;
    }

    private function gitClone(string $url, string $dest, string $branch): void
    {
        $this->runGit(['git', 'clone', '--branch', $branch, '--depth', '1', '--', $url, $dest]);
    }

    private function gitPull(string $dest, string $branch): void
    {
        $this->runGit(['git', '-C', $dest, 'fetch', '--depth', '1', 'origin', $branch]);
        $this->runGit(['git', '-C', $dest, 'reset', '--hard', 'FETCH_HEAD']);
    }

    /**
     * Execute a git command as a subprocess.
     * All arguments are passed as an array — never shell-interpolated.
     *
     * @throws \RuntimeException on non-zero exit code.
     */
    private function runGit(array $cmd): void
    {
        \Yii::info('ProjectService: ' . implode(' ', $cmd), __CLASS__);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('proc_open failed for git command.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($stdout) {
            \Yii::info('ProjectService stdout: ' . $stdout, __CLASS__);
        }
        if ($stderr) {
            \Yii::warning('ProjectService stderr: ' . $stderr, __CLASS__);
        }

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                sprintf('git command failed (exit %d): %s', $exitCode, trim($stderr ?: $stdout ?: '(no output)'))
            );
        }
    }
}
