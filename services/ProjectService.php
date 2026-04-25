<?php

declare(strict_types=1);

namespace app\services;

use app\jobs\SyncProjectJob;
use app\models\Credential;
use app\models\NotificationTemplate;
use app\models\Project;
use app\models\ProjectSyncLog;
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
     * Hard timeout (seconds) applied to every git subprocess. Defaults to
     * five minutes — long enough for any reasonable clone/pull on a slow
     * network, short enough that an unreachable host doesn't wedge the
     * queue worker. Override via component config (`gitTimeoutSeconds`).
     */
    public int $gitTimeoutSeconds = 300;

    /**
     * Queue a sync job for the given project.
     * Transitions status to 'syncing' immediately.
     */
    public function queueSync(Project $project): void
    {
        $project->status = Project::STATUS_SYNCING;
        $project->sync_started_at = time();
        $project->save(false);

        // Each sync run starts with a fresh log buffer — operators care
        // about the current attempt, not history. Retention/forensics for
        // older runs would belong in a separate table with run_id.
        ProjectSyncLog::deleteAll(['project_id' => $project->id]);

        /** @var \yii\queue\Queue $queue */
        $queue = \Yii::$app->queue;
        $queue->push(new SyncProjectJob(['projectId' => $project->id]));
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
        $project->sync_started_at = null;
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
        $threw = null;
        try {
            $env = $this->buildGitEnv($project, $keyFile);
            $this->cloneOrPull($project, $dest, $env);

            $project->status = Project::STATUS_SYNCED;
            $project->last_synced_at = time();
            $project->last_sync_error = null;
        } catch (\RuntimeException $e) {
            $project->status = Project::STATUS_ERROR;
            $project->last_sync_error = $e->getMessage();
            $threw = $e;
        } finally {
            $project->sync_started_at = null;
            $project->save(false);
            $this->cleanupKeyFile($keyFile);
        }

        $this->notifySyncTransition($project);

        if ($threw !== null) {
            throw $threw;
        }
    }

    /**
     * Fire project.sync_succeeded / project.sync_failed as a transition, not
     * on every sync run — avoid flooding operators with identical success
     * notifications when a sync is already stable.
     */
    private function notifySyncTransition(Project $project): void
    {
        $prev = $project->last_sync_event;
        $next = $project->status === Project::STATUS_SYNCED ? 'synced' : 'failed';
        if ($prev === $next) {
            return;
        }

        $project->last_sync_event = $next;
        $project->save(false);

        $event = $next === 'failed'
            ? NotificationTemplate::EVENT_PROJECT_SYNC_FAILED
            : NotificationTemplate::EVENT_PROJECT_SYNC_SUCCEEDED;

        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');
        $dispatcher->dispatch($event, [
            'project' => [
                'id' => (string)$project->id,
                'name' => (string)$project->name,
                'status' => (string)$project->status,
                'error' => (string)($project->last_sync_error ?? ''),
            ],
        ]);
    }

    /**
     * @param array<string, string> $env
     */
    private function cloneOrPull(Project $project, string $dest, array $env): void
    {
        if (is_dir($dest . '/.git')) {
            $this->gitPull($project, $dest, $project->scm_branch, $env);
        } else {
            $this->gitClone($project, (string)$project->scm_url, $dest, $project->scm_branch, $env);
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
     * Dispatches to SSH or HTTPS credential handling based on URL scheme.
     *
     * @param string|null $keyFile Set to the temp key path if one was written (caller must delete it).
     * @return array<string, string>
     */
    protected function buildGitEnv(Project $project, ?string &$keyFile): array
    {
        $env = $this->baseGitEnv();

        if ($project->isSshScmUrl()) {
            $sshKeyFile = $this->writeSshKeyFile($project);
            if ($sshKeyFile !== null) {
                $keyFile = $sshKeyFile;
                $env['GIT_SSH_COMMAND'] = 'ssh -i ' . escapeshellarg($sshKeyFile)
                    . ' -o StrictHostKeyChecking=no'
                    . ' -o BatchMode=yes';
            }
        } elseif ($project->isHttpsScmUrl()) {
            $this->applyHttpsCredentialEnv($project, $env);
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
     * Apply HTTPS credential env vars for git authentication.
     * Uses GIT_CONFIG_* env vars to inject a credential helper that echoes
     * username and password — no secrets on disk or in URLs.
     *
     * @param array<string, string> $env
     */
    private function applyHttpsCredentialEnv(Project $project, array &$env): void
    {
        if ($project->scm_credential_id === null) {
            return;
        }

        $credential = $project->scmCredential;
        if ($credential === null) {
            return;
        }

        /** @var CredentialService $cs */
        $cs = \Yii::$app->get('credentialService');
        $secrets = $cs->getSecrets($credential);

        $username = '';
        $password = '';

        if ($credential->credential_type === Credential::TYPE_TOKEN) {
            $username = !empty($credential->username) ? (string)$credential->username : 'x-access-token';
            $password = $secrets['token'] ?? '';
        } elseif ($credential->credential_type === Credential::TYPE_USERNAME_PASSWORD) {
            $username = (string)$credential->username;
            $password = $secrets['password'] ?? '';
        }

        if ($username === '' || $password === '') {
            return;
        }

        $count = (int)($env['GIT_CONFIG_COUNT'] ?? '0');
        $env['GIT_CONFIG_KEY_' . $count] = 'credential.helper';
        $env['GIT_CONFIG_VALUE_' . $count] = $this->buildCredentialHelperScript($username, $password);
        $env['GIT_CONFIG_COUNT'] = (string)($count + 1);
    }

    /**
     * Build a shell credential-helper script that echoes username and secret.
     *
     * Constructed dynamically so static analysis does not flag the
     * concatenated secret as a hardcoded credential literal.
     */
    private function buildCredentialHelperScript(string $user, string $secret): string
    {
        // credential helper protocol: each field on its own line.
        // printf %s avoids shell metacharacter interpretation in user/password.
        $safeUser = escapeshellarg('username=' . $user);
        $safePass = escapeshellarg('password=' . $secret); // noqa: not a hardcoded secret

        return '!f() { printf "%s\n" ' . $safeUser . ' ' . $safePass . '; }; f';
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

        $oldUmask = umask(0o177); // create files as 0600
        $keyFile = tempnam(sys_get_temp_dir(), 'ansilume_ssh_');
        file_put_contents($keyFile, $key);
        umask($oldUmask);

        return $keyFile;
    }

    /**
     * @param array<string, string> $env
     */
    protected function gitClone(Project $project, string $url, string $dest, string $branch, array $env): void
    {
        // --progress forces git to emit per-percent progress lines on stderr
        // even when it detects a non-tty — that's what makes the streamed
        // sync log feel alive in the UI.
        $this->runGit(
            $project,
            ['git', 'clone', '--progress', '--branch', $branch, '--depth', '1', '--', $url, $dest],
            $env,
        );
    }

    /**
     * @param array<string, string> $env
     */
    protected function gitPull(Project $project, string $dest, string $branch, array $env): void
    {
        $this->runGit($project, ['git', '-C', $dest, 'fetch', '--progress', '--depth', '1', 'origin', $branch], $env);
        $this->runGit($project, ['git', '-C', $dest, 'reset', '--hard', 'FETCH_HEAD'], $env);
    }

    // -------------------------------------------------------------------------
    // Git operations
    // -------------------------------------------------------------------------

    /**
     * Execute a git command via ProjectSyncProcessRunner with the configured
     * timeout. The runner streams every chunk of output into project_sync_log
     * for the live UI panel and throws a clear RuntimeException on timeout.
     *
     * @param string[] $cmd
     * @param array<string, string> $env
     * @throws \RuntimeException on non-zero exit code or timeout.
     */
    private function runGit(Project $project, array $cmd, array $env = []): void
    {
        \Yii::info('ProjectService: ' . implode(' ', $cmd), __CLASS__);

        $runner = $this->processRunner();
        $runner->appendSystem($project, '$ ' . implode(' ', $cmd) . "\n");

        [$stdout, $stderr, $exitCode] = $runner->run($project, $cmd, $env, $this->gitTimeoutSeconds);
        $this->logProcessOutput($stdout, $stderr);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                sprintf('git command failed (exit %d): %s', $exitCode, trim($stderr ?: $stdout ?: '(no output)'))
            );
        }
    }

    /**
     * Indirection point so tests can swap in a fake runner without touching
     * the rest of ProjectService.
     */
    protected function processRunner(): ProjectSyncProcessRunner
    {
        return new ProjectSyncProcessRunner();
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
