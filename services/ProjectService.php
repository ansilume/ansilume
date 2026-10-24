<?php

declare(strict_types=1);

namespace app\services;

use app\components\ErrorText;
use app\components\GitSyncDiagnostics;
use app\jobs\SyncProjectJob;
use app\models\Credential;
use app\models\NotificationTemplate;
use app\models\Project;
use app\models\ProjectSyncLog;
use yii\base\Component;

/**
 * Manages project lifecycle: sync queueing and local path resolution.
 *
 * A repository URL can carry credentials in its userinfo
 * (https://user:token@host/...). What a sync writes, logs, stores and sends
 * names it only as GitSyncDiagnostics::redactUrl() shows it: the git command
 * in the log and in the sync output, git's output in the log and in the
 * error, and the error that the project's page shows and project.sync_failed
 * sends.
 */
class ProjectService extends Component
{
    /** Logged (info) before a git command runs; sprintf(): the command, its URL without credentials. */
    public const GIT_COMMAND_LOGGED = 'ProjectService: %s';

    /** The row of the sync output before a git command runs; sprintf() as GIT_COMMAND_LOGGED. */
    public const GIT_COMMAND_ROW = "\$ %s\n";

    /**
     * The error of a git command that exited with another code than 0, which
     * the project stores as its last sync error; sprintf(): the exit code,
     * then git's stderr, or its stdout when stderr is empty, or NO_OUTPUT,
     * without the credentials of URLs.
     */
    public const GIT_FAILED = 'git command failed (exit %d): %s';

    /** What GIT_FAILED says when git wrote nothing. */
    public const NO_OUTPUT = '(no output)';

    /** Logged (info) with what a git command wrote to stdout, without the credentials of URLs. */
    public const GIT_STDOUT_LOGGED = 'ProjectService stdout: %s';

    /** Logged (warning) with what a git command wrote to stderr, without the credentials of URLs. */
    public const GIT_STDERR_LOGGED = 'ProjectService stderr: %s';

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

        // Seed log: without this, the sync-log panel renders empty for
        // however long it takes the worker to BRPOP the job — and stays
        // empty forever if the worker is dead. The seed line gives the
        // operator immediate confirmation that the queue accepted the
        // request, and the worker-heartbeat indicator next to the panel
        // tells them whether anyone is listening.
        $this->seedQueueLog($project);

        /** @var \yii\queue\Queue $queue */
        $queue = \Yii::$app->queue;
        $queue->push(new SyncProjectJob(['projectId' => $project->id]));
    }

    private function seedQueueLog(Project $project): void
    {
        $log = new ProjectSyncLog();
        $log->project_id = $project->id;
        $log->stream = ProjectSyncLog::STREAM_SYSTEM;
        $log->content = sprintf(
            "Sync queued at %s — waiting for queue worker to pick up the job.\n",
            date('Y-m-d H:i:s'),
        );
        $log->sequence = 1;
        $log->created_at = time();
        $log->save(false);
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
            // Fail the sync instead of leaving the project on "syncing" until
            // the stale-sync sweeper notices it.
            $project->status = Project::STATUS_ERROR;
            $project->last_sync_error = 'This git project has no repository URL.';
            $project->sync_started_at = null;
            $project->save(false);
            $this->notifySyncTransition($project);
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
     * @throws \RuntimeException on failure (also when the underlying error
     *         was a \Throwable — wrapped in RuntimeException so callers
     *         can rely on a single exception type).
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
        } catch (\Throwable $e) {
            // Anything thrown while syncing — RuntimeException from
            // proc_open/non-zero exit, Error from a malformed credential, a
            // type juggling slip — must flip the project to STATUS_ERROR.
            // The previous narrower `catch (RuntimeException)` left the row
            // on STATUS_SYNCING for any other exception class, which made
            // the operator's project look "still working" forever.
            // The stored error (shown on the project page and sent in the
            // sync_failed notification) never holds the message of a
            // database error, which carries the SQL statement with its values,
            // nor the credentials of a repository URL.
            $project->status = Project::STATUS_ERROR;
            $project->last_sync_error = GitSyncDiagnostics::redactUrl(ErrorText::of($e));
            $threw = $e;
        } finally {
            $project->sync_started_at = null;
            $project->save(false);
            $this->cleanupKeyFile($keyFile);
        }

        $this->notifySyncTransition($project);

        if ($threw !== null) {
            throw $this->passedOn($threw, (string)$project->last_sync_error);
        }
    }

    /**
     * What sync() throws for the error $e that it stored as $stored: a
     * RuntimeException, so the SyncProjectJob outer try/catch (which already
     * targets RuntimeException, and logs the message) handles every error
     * class uniformly. A RuntimeException is passed on as it is, unless its
     * message names the credentials of a URL; any other error is passed on
     * as $stored, with $e as the previous exception only when nothing in its
     * message must stay out of a log: not for a database error, whose
     * message carries the SQL statement with its values, nor for one that
     * names the credentials of a URL.
     */
    private function passedOn(\Throwable $e, string $stored): \RuntimeException
    {
        $namesCredentials = GitSyncDiagnostics::redactUrl($e->getMessage()) !== $e->getMessage();
        if ($e instanceof \RuntimeException && !$namesCredentials) {
            return $e;
        }

        return new \RuntimeException($stored, 0, $namesCredentials || $e instanceof \yii\db\Exception ? null : $e);
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
    private function cloneOrPull(Project $project, string $dest, #[\SensitiveParameter] array $env): void
    {
        if (is_dir($dest . '/.git')) {
            $this->gitPull($project, (string)$project->scm_url, $dest, $project->scm_branch, $env);
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
                // UserKnownHostsFile=/dev/null skips the known_hosts write
                // entirely — without this the worker tried to update HOME's
                // ~/.ssh/known_hosts and surfaced a "Permission denied"
                // warning that confused operators (and was the symptom of
                // a follow-on hang when SSH refused to proceed).
                $env['GIT_SSH_COMMAND'] = 'ssh -i ' . escapeshellarg($sshKeyFile)
                    . ' -o StrictHostKeyChecking=accept-new'
                    . ' -o UserKnownHostsFile=/dev/null'
                    . ' -o BatchMode=yes';
            }
        } elseif ($project->isHttpsScmUrl()) {
            $this->applyHttpsCredentialEnv($project, $env);
        }

        return $env;
    }

    /**
     * Writable HOME for the git subprocess. The default www-data home in the
     * php-fpm base image is `/var/www`, which is root-owned and not writable
     * by the worker. Anything that probes `~/.gitconfig`, `~/.config/git`,
     * or — crucially — SSH's `~/.ssh/known_hosts` blows up with
     * "Permission denied". The matching directory is created and chowned in
     * docker/php/entrypoint*.sh and docker/runner/entrypoint.sh.
     *
     * Mirrors the equivalent constant for ansible (see
     * PlaybookEnvironment::ANSIBLE_HOME).
     */
    public const GIT_HOME = '/var/www/runtime/git-home';

    /**
     * @return array<string, string>
     */
    private function baseGitEnv(): array
    {
        return [
            'HOME' => self::GIT_HOME,
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
    private function applyHttpsCredentialEnv(Project $project, #[\SensitiveParameter] array &$env): void
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
    private function buildCredentialHelperScript(string $user, #[\SensitiveParameter] string $secret): string
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
     * @param string $url The repository URL, credentials in its userinfo included.
     * @param array<string, string> $env
     */
    protected function gitClone(
        Project $project,
        #[\SensitiveParameter] string $url,
        string $dest,
        string $branch,
        #[\SensitiveParameter] array $env
    ): void {
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
     * Brings the checkout at $dest to the head of $branch at $url, the
     * project's current repository URL.
     *
     * A checkout keeps the URL it was cloned with as its origin, credentials
     * included, in its .git/config. Origin is set to $url first, so that the
     * fetch uses a changed URL (a rotated token, a moved repository) and the
     * credentials of the old URL leave the checkout. "--" ends git's options,
     * so that neither the URL nor the branch is read as one.
     *
     * @param string $url The repository URL, credentials in its userinfo included.
     * @param array<string, string> $env
     */
    protected function gitPull(
        Project $project,
        #[\SensitiveParameter] string $url,
        string $dest,
        string $branch,
        #[\SensitiveParameter] array $env
    ): void {
        $this->runGit($project, ['git', '-C', $dest, 'remote', 'set-url', '--', 'origin', $url], $env);
        $this->runGit($project, ['git', '-C', $dest, 'fetch', '--progress', '--depth', '1', '--', 'origin', $branch], $env);
        $this->runGit($project, ['git', '-C', $dest, 'reset', '--hard', 'FETCH_HEAD'], $env);
    }

    // -------------------------------------------------------------------------
    // Git operations
    // -------------------------------------------------------------------------

    /**
     * Execute a git command via ProjectSyncProcessRunner with the configured
     * timeout. The runner streams git's output into project_sync_log for the
     * live UI panel and throws a clear RuntimeException on timeout.
     * The command and git's output are logged, shown and thrown without the
     * credentials of the repository URL (GitSyncDiagnostics::redactUrl).
     *
     * @param string[] $cmd The command, the repository URL with its credentials included.
     * @param array<string, string> $env
     * @throws \RuntimeException on non-zero exit code or timeout.
     */
    private function runGit(
        Project $project,
        #[\SensitiveParameter] array $cmd,
        #[\SensitiveParameter] array $env = []
    ): void {
        $shown = GitSyncDiagnostics::redactUrl(implode(' ', $cmd));
        \Yii::info(sprintf(self::GIT_COMMAND_LOGGED, $shown), __CLASS__);

        $runner = $this->processRunner();
        $runner->appendSystem($project, sprintf(self::GIT_COMMAND_ROW, $shown));

        [$stdout, $stderr, $exitCode] = $runner->run($project, $cmd, $env, $this->gitTimeoutSeconds);
        $stdout = GitSyncDiagnostics::redactUrl($stdout);
        $stderr = GitSyncDiagnostics::redactUrl($stderr);
        $this->logProcessOutput($stdout, $stderr);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                sprintf(self::GIT_FAILED, $exitCode, trim($stderr ?: $stdout ?: self::NO_OUTPUT))
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
            \Yii::info(sprintf(self::GIT_STDOUT_LOGGED, $stdout), __CLASS__);
        }
        if ($stderr !== '') {
            \Yii::warning(sprintf(self::GIT_STDERR_LOGGED, $stderr), __CLASS__);
        }
    }
}
