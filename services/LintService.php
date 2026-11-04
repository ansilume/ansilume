<?php

declare(strict_types=1);

namespace app\services;

use app\components\ErrorText;
use app\components\ExceptionLogText;
use app\components\ProcessStarter;
use app\components\StoredLintOutput;
use app\components\SubprocessEnvironment;
use app\components\VaultIsolation;
use app\jobs\LintProjectJob;
use app\jobs\LintTemplateJob;
use app\models\JobTemplate;
use app\models\Project;
use yii\base\Component;
use yii\db\Expression;
use yii\di\Instance;
use yii\queue\JobInterface;
use yii\queue\Queue;

/**
 * Runs ansible-lint against a job template's playbook, or a whole project,
 * and stores the result.
 *
 * Lint never runs in a web request. A save of a job template (web UI and
 * API) and Run Lint (project page and API) queue a run: queueForTemplate()
 * and queueForProject() mark the record as queued (lint_queued_at) and push
 * a job to the queue of the queue-worker (LintTemplateJob, LintProjectJob),
 * which calls runForTemplate() or runForProject(). The sync of a project
 * lints in its own job (SyncProjectJob). One run took about two seconds for
 * a small playbook, a failed check of ansible-lint for a newer release ten
 * more (SKIP_VERSION_CHECK), and ansible-lint waits up to 180 seconds for
 * the lock of a checkout that another run holds.
 *
 * A run serves every request up to the time it is given ($requestedAt, the
 * mark the request set) or, without one, up to the time it started, read
 * before it reads anything. Storing its result and clearing the mark is one
 * UPDATE, which clears the mark only when it is no newer than that time: the
 * mark of a newer request stays, and the run of that request stores its
 * result. With seconds as the unit, a request in the same second as a run's
 * start can see its mark cleared by that run, which may have read the
 * template before the request's save; its own run still comes and stores the
 * result of what was saved. Every path of a run ends the requests it serves,
 * the early ones too (no project, no path, a path or playbook that is
 * missing, ansible-lint not installed, a process that cannot start), and
 * one whose result the database refuses. An output longer than the column
 * holds is stored as its start and its end (StoredLintOutput): whole, it
 * failed the UPDATE (a TEXT column, a database in strict mode), and the mark
 * stayed for good. A result refused all the same, as an output that is no
 * valid UTF-8, is stored as why (NOT_STORED), in an UPDATE of its own that
 * ends the requests by the same rule.
 *
 * Uses --profile production (strictest built-in profile). If ansible-lint is
 * not installed nothing new is stored: the mark is cleared, and the stored
 * result stays.
 */
class LintService extends Component
{
    /**
     * The stored output, with the exit code -1, when ansible-lint did not
     * run; sprintf(): why, such as ProcessStarter::NOT_STARTED_IN when
     * ansible-lint did not start.
     */
    public const NOT_RUN = 'Lint did not run: %s';

    /**
     * The warning of a save or of Run Lint whose lint run could not be
     * queued, as of a Redis that cannot be reached: the save stays (it was
     * committed before), and the record is not marked as queued.
     */
    public const NOT_QUEUED = 'Lint was not queued: the queue could not be reached. Try again once it is back.';

    /**
     * Logged (warning) when a lint run could not be queued. sprintf(): what
     * was to be linted ('job template' or 'project'), its ID, and the error
     * as ExceptionLogText::brief() names it: for a database error, which
     * yii2-redis throws too, its class, SQLSTATE and driver error code, never
     * its message, which can carry the Redis command with its values.
     */
    public const NOT_QUEUED_LOGGED = 'LintService: the lint run of %s #%d could not be queued (its save stays): %s';

    /**
     * The stored output, with ansible-lint's exit code, when the database
     * refused the result of a run, as an output that is no valid UTF-8
     * (1366); sprintf(): the error as ErrorText gives it: for a database
     * error its class, SQLSTATE and driver error code, never its message,
     * which carries the statement with the output.
     */
    public const NOT_STORED = 'The lint result could not be stored: %s';

    /**
     * Logged (warning) when the database refused the result of a run, which
     * NOT_STORED then replaces. sprintf(): what was linted ('job template' or
     * 'project'), its ID, and the error as for NOT_STORED.
     */
    public const NOT_STORED_LOGGED = 'LintService: the lint result of %s #%d could not be stored, so why is stored instead: %s';

    /** The stored output of a run of a job template that has no project. */
    public const NO_PROJECT = 'No project assigned to this template.';

    /**
     * The stored output of a run of a job template whose project has no
     * path: a manual project without a local path, or a git project whose
     * checkout has none yet.
     */
    public const NO_LOCAL_PATH = 'No local path configured for this project.';

    /**
     * The stored output when the directory of a manual project is missing
     * where lint runs, in the queue-worker; sprintf(): the path.
     */
    public const PATH_NOT_FOUND = 'Project path not found: %s';

    /** The stored output when the checkout of a git project is missing. */
    public const WORKSPACE_NOT_FOUND = 'Project workspace not found — sync the project first.';

    /** The stored output when the checkout lacks the template's playbook; sprintf(): the playbook. */
    public const PLAYBOOK_NOT_FOUND = "Playbook not found: %s\nSync the project to fetch the latest files.";

    /**
     * Set for ansible-lint: it skips its check for a newer release of
     * itself. ansible-lint asks api.github.com for the latest release at the
     * end of every run, caches only an answer that came, and without
     * internet access every run waited for the check's timeout of 10 seconds
     * (12.1 seconds per run instead of 2.0 with outbound traffic dropped).
     * In ansible-lint 26.10 PRE_COMMIT=1 has exactly that one effect (its
     * only use in ansiblelint). Never --offline instead: it would also skip
     * installing the roles and collections of a project's requirements.yml
     * and drop the lock of the checkout's cache, and so change the results.
     * Not taken from the server's environment: SubprocessEnvironment does
     * not forward PRE_COMMIT.
     */
    public const SKIP_VERSION_CHECK = ['PRE_COMMIT' => '1'];

    /**
     * Clears lint_queued_at in the UPDATE of a run when it is no newer than
     * the time the run serves up to (:lintServedUpTo); a newer mark stays.
     */
    private const CLEAR_SERVED = 'CASE WHEN [[lint_queued_at]] <= :lintServedUpTo THEN NULL ELSE [[lint_queued_at]] END';

    /**
     * The queue lint runs are pushed to: the ID of an application component,
     * by default the queue of the queue-worker ('queue', not the notification
     * queue), or the queue itself.
     */
    public Queue|string $queue = 'queue';

    /**
     * Queues a lint run of $template, after a save: marks it as queued
     * (lint_queued_at, one UPDATE) and pushes a LintTemplateJob that carries
     * the mark. False when the push failed, as with a Redis that cannot be
     * reached: the mark this call set is cleared again, a newer one stays,
     * and a warning is logged (NOT_QUEUED_LOGGED); the save stays.
     */
    public function queueForTemplate(JobTemplate $template): bool
    {
        $requestedAt = $this->markQueued($template);

        return $this->push($template, new LintTemplateJob(['templateId' => (int)$template->id, 'requestedAt' => $requestedAt]), $requestedAt);
    }

    /**
     * Queues a lint run of the whole project $project (Run Lint), as
     * queueForTemplate() does for a template, with a LintProjectJob.
     */
    public function queueForProject(Project $project): bool
    {
        $requestedAt = $this->markQueued($project);

        return $this->push($project, new LintProjectJob(['projectId' => (int)$project->id, 'requestedAt' => $requestedAt]), $requestedAt);
    }

    /**
     * Run ansible-lint on an entire project directory (no specific playbook —
     * ansible-lint auto-discovers all playbooks, roles, tasks, and collections).
     * Stores the result on the Project record, and ends the requests up to
     * $requestedAt (see the class). A project without a path stores nothing.
     */
    public function runForProject(Project $project, ?int $requestedAt = null): void
    {
        $servedUpTo = $requestedAt ?? time();
        $result = $this->lintProject($project);
        if ($result === null) {
            $this->clearQueued($project, $servedUpTo);
            return;
        }

        $this->storeProject($project, $result[0], $result[1], $servedUpTo);
    }

    /**
     * Run ansible-lint on the playbook of $template. Stores the result on
     * the template, and ends the requests up to $requestedAt (see the class).
     */
    public function runForTemplate(JobTemplate $template, ?int $requestedAt = null): void
    {
        $servedUpTo = $requestedAt ?? time();
        $result = $this->lintTemplate($template);
        if ($result === null) {
            $this->clearQueued($template, $servedUpTo);
            return;
        }

        $this->store($template, $result[0], $result[1], $servedUpTo);
    }

    /**
     * What a run of $project stores: [exit code, output]; null for nothing
     * new (no path configured, ansible-lint not installed).
     *
     * @return array{0: int|null, 1: string}|null
     */
    private function lintProject(Project $project): ?array
    {
        $projectPath = $this->resolveProjectPath($project);
        if ($projectPath === null) {
            return null;
        }

        if (!is_dir($projectPath)) {
            return [null, $this->missingPathMessage($project, $projectPath)];
        }

        if (!$this->isAvailable()) {
            return null;
        }

        [$output, $exitCode] = $this->execute(null, $projectPath);

        return [$exitCode, $output ?: '(no output)'];
    }

    /**
     * What a run of $template stores: [exit code, output]; null for nothing
     * new (ansible-lint not installed).
     *
     * @return array{0: int|null, 1: string}|null
     */
    private function lintTemplate(JobTemplate $template): ?array
    {
        $project = $template->project;
        if ($project === null) {
            return [null, self::NO_PROJECT];
        }

        $projectPath = $this->resolveProjectPath($project);
        if ($projectPath === null) {
            return [null, self::NO_LOCAL_PATH];
        }

        if (!is_dir($projectPath)) {
            return [null, $this->missingPathMessage($project, $projectPath)];
        }

        if (!file_exists($projectPath . '/' . ltrim($template->playbook, '/'))) {
            return [null, sprintf(self::PLAYBOOK_NOT_FOUND, $template->playbook)];
        }

        if (!$this->isAvailable()) {
            return null;
        }

        [$output, $exitCode] = $this->execute($template->playbook, $projectPath);

        return [$exitCode, $output ?: '(no output)'];
    }

    private function missingPathMessage(Project $project, string $projectPath): string
    {
        return $project->scm_type === Project::SCM_TYPE_MANUAL
            ? sprintf(self::PATH_NOT_FOUND, $projectPath)
            : self::WORKSPACE_NOT_FOUND;
    }

    /**
     * Resolve the filesystem path for a project, regardless of SCM type.
     * Returns null if no path is configured (e.g. new git project not yet synced).
     */
    protected function resolveProjectPath(Project $project): ?string
    {
        if ($project->scm_type === Project::SCM_TYPE_MANUAL) {
            return !empty($project->local_path) ? $project->local_path : null;
        }

        /** @var ProjectService $projectService */
        $projectService = \Yii::$app->get('projectService');
        return $projectService->localPath($project);
    }

    /**
     * Stores the result of a run on $project and ends the requests up to
     * $servedUpTo (persistResult()).
     */
    protected function storeProject(Project $project, ?int $exitCode, string $output, int $servedUpTo): void
    {
        $this->persistResult($project, $exitCode, $output, $servedUpTo);
    }

    /**
     * Ends the requests up to $servedUpTo of a run that stores nothing new
     * (persist()).
     */
    protected function clearQueued(JobTemplate|Project $record, int $servedUpTo): void
    {
        $this->persist($record, [], $servedUpTo);
    }

    protected function isAvailable(): bool
    {
        exec('which ansible-lint 2>/dev/null', $lines, $code);
        return $code === 0 && !empty($lines);
    }

    /**
     * @return array{0: string, 1: int}  [combined output, exit code]
     */
    protected function execute(?string $playbook, string $cwd): array
    {
        $cmd = ['ansible-lint', '--profile', 'production', '--force-color'];
        // For project-level runs, omit the path argument so ansible-lint uses
        // full auto-discovery from the CWD (picks up playbooks/ and roles/).
        // For template-level runs, pass the specific playbook as entry point.
        if ($playbook !== null) {
            $cmd[] = $playbook;
        }

        $vault = $this->vaultIsolation();
        try {
            $env = $this->buildProcessEnv($cwd, $vault->overrides());
            return $this->runProcess($cmd, $cwd, $env);
        } catch (\RuntimeException | \ErrorException $e) {
            // ErrorException: a warning, as of a cache directory that cannot be created.
            return [sprintf(self::NOT_RUN, $e->getMessage()), -1];
        } finally {
            $vault->cleanup();
        }
    }

    protected function vaultIsolation(): VaultIsolation
    {
        return new VaultIsolation();
    }

    /**
     * @param string[] $cmd
     * @param array<string, string> $env
     * @return array{0: string, 1: int}  [combined output, exit code]
     */
    private function runProcess(array $cmd, string $cwd, #[\SensitiveParameter] array $env): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = ProcessStarter::open($cmd, $descriptors, $pipes, $cwd, $env, $failure);

        if ($process === null) {
            return [sprintf(self::NOT_RUN, $failure), -1];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $output = trim(($stdout ?: '') . ($stderr ? "\n" . $stderr : ''));
        return [$output, $exitCode];
    }

    /**
     * Environment for ansible-lint. Lint runs ansible-playbook --syntax-check
     * and loads repository-controlled code (plugins, rule directories) inside
     * the queue-worker container, so it must never inherit the server's
     * environment with APP_SECRET_KEY, DB_PASSWORD and friends. See
     * {@see SubprocessEnvironment}. On top of the allowlist come HOME,
     * ANSIBLE_HOME and PRE_COMMIT (SKIP_VERSION_CHECK); the vault overrides
     * ({@see VaultIsolation}) come last: a repository's vault password script
     * never runs, and vault content is never decrypted on the server.
     *
     * @param array<string, string> $vaultOverrides
     * @return array<string, string>
     */
    protected function buildProcessEnv(string $cwd, array $vaultOverrides = []): array
    {
        return SubprocessEnvironment::build(getenv() ?: [], array_merge([
            'HOME' => sys_get_temp_dir(),
            'ANSIBLE_HOME' => $this->ensureCacheDir($cwd),
        ], self::SKIP_VERSION_CHECK, $vaultOverrides));
    }

    /**
     * Ensure a writable .ansible cache dir exists inside the project CWD.
     *
     * ansible-compat's get_cache_dir() (isolated mode) tries $cwd/.ansible
     * for caching. If that dir is not writable it emits noisy warnings
     * before falling back to /tmp. Creating it upfront silences them.
     *
     * If the CWD-local cache dir exists but is not writable by the current
     * user (e.g. it was created by a different user such as root in a prior
     * container run, before worker processes dropped to www-data), fall
     * back to a per-CWD temp dir. Otherwise ansible-lint fails with
     * "[Errno 13] Permission denied" when it tries to mkdir $cache/tmp/...
     *
     * A .ansible that is a symlink is never used, as the checkout's content
     * decides where it leads: one that led nowhere made mkdir() warn, and
     * Run Lint answered 500; one that led out of the checkout made
     * ansible-lint write its cache there.
     */
    protected function ensureCacheDir(string $cwd): string
    {
        $cacheDir = $cwd . '/.ansible';

        if (is_link($cacheDir)) {
            return $this->fallbackCacheDir($cwd);
        }
        if (is_dir($cacheDir) && is_writable($cacheDir)) {
            return $cacheDir;
        }

        // Only attempt mkdir when:
        //   - the path is free (no file blocking it), AND
        //   - the parent dir is writable by the current user.
        // Calling mkdir() on a path we can't create raises a PHP warning
        // that Yii's ErrorHandler promotes to an exception, bypassing the
        // fallback below. The parent-writable check keeps us out of that
        // code path for the exact scenario that caused the original bug:
        // a www-data web process trying to create .ansible inside a
        // root-owned project directory.
        if (
            !file_exists($cacheDir)
            && is_writable($cwd)
            && mkdir($cacheDir, 0o755, true)
            && is_writable($cacheDir)
        ) {
            return $cacheDir;
        }

        return $this->fallbackCacheDir($cwd);
    }

    /**
     * Per-CWD temp dir. Deterministic (hashed) so repeat runs hit the same
     * cache instead of leaking new dirs under /tmp.
     */
    private function fallbackCacheDir(string $cwd): string
    {
        $fallback = sys_get_temp_dir() . '/ansilume-ansible-' . substr(hash('sha256', $cwd), 0, 16);
        if (!is_dir($fallback)) {
            mkdir($fallback, 0o755, true);
        }
        return $fallback;
    }

    /**
     * Stores the result of a run on $template and ends the requests up to
     * $servedUpTo (persistResult()).
     */
    protected function store(JobTemplate $template, ?int $exitCode, string $output, int $servedUpTo): void
    {
        $this->persistResult($template, $exitCode, $output, $servedUpTo);
    }

    /**
     * Stores the result of a run on $record and ends the requests up to
     * $servedUpTo, in one UPDATE (persist()): the output as StoredLintOutput
     * fits it into the column. A result that the database refuses all the
     * same is logged (NOT_STORED_LOGGED) and replaced by why (NOT_STORED),
     * in an UPDATE of its own that ends the requests too; should that fail
     * as well, as with a database that cannot be reached, its error goes to
     * the queue job.
     */
    private function persistResult(JobTemplate|Project $record, ?int $exitCode, string $output, int $servedUpTo): void
    {
        try {
            $this->persist($record, self::result($exitCode, StoredLintOutput::of($output)), $servedUpTo);
        } catch (\yii\db\Exception $e) {
            $error = ErrorText::of($e);
            \Yii::warning(sprintf(self::NOT_STORED_LOGGED, self::kindOf($record), (int)$record->id, $error), __METHOD__);
            $this->persist($record, self::result($exitCode, sprintf(self::NOT_STORED, $error)), $servedUpTo);
        }
    }

    /**
     * The columns of a stored result: the output, when it was linted, and
     * ansible-lint's exit code.
     *
     * @return array{lint_output: string, lint_at: int, lint_exit_code: int|null}
     */
    private static function result(?int $exitCode, string $output): array
    {
        return ['lint_output' => $output, 'lint_at' => time(), 'lint_exit_code' => $exitCode];
    }

    /** What $record is, as the log lines name it. */
    private static function kindOf(JobTemplate|Project $record): string
    {
        return $record instanceof JobTemplate ? 'job template' : 'project';
    }

    /**
     * One UPDATE of $record's row: $columns, and lint_queued_at cleared when
     * it is no newer than $servedUpTo (CLEAR_SERVED), so that a newer
     * request's mark stays. $record holds what was written; its
     * lint_queued_at is cleared by the same rule.
     *
     * @param array<string, mixed> $columns
     */
    private function persist(JobTemplate|Project $record, array $columns, int $servedUpTo): void
    {
        $clear = new Expression(self::CLEAR_SERVED, [':lintServedUpTo' => $servedUpTo]);
        $record::updateAll($columns + ['lint_queued_at' => $clear], ['id' => $record->id]);
        $queuedAt = $record->lint_queued_at;
        $this->remember($record, $columns + ['lint_queued_at' => $queuedAt !== null && (int)$queuedAt <= $servedUpTo ? null : $queuedAt]);
    }

    /**
     * Marks $record as queued (lint_queued_at, one UPDATE, not a save);
     * returns the mark, the current time.
     */
    private function markQueued(JobTemplate|Project $record): int
    {
        $now = time();
        $record::updateAll(['lint_queued_at' => $now], ['id' => $record->id]);
        $this->remember($record, ['lint_queued_at' => $now]);

        return $now;
    }

    /**
     * Pushes $job to the queue; on failure clears the mark $requestedAt of
     * $record and logs a warning (NOT_QUEUED_LOGGED).
     */
    private function push(JobTemplate|Project $record, JobInterface $job, int $requestedAt): bool
    {
        try {
            Instance::ensure($this->queue, Queue::class)->push($job);

            return true;
        } catch (\Exception $e) {
            \Yii::warning(sprintf(self::NOT_QUEUED_LOGGED, self::kindOf($record), (int)$record->id, ExceptionLogText::brief($e)), __METHOD__);
            $this->clearQueued($record, $requestedAt);

            return false;
        }
    }

    /**
     * $record holds $values as stored: they are no unsaved changes.
     *
     * @param array<string, mixed> $values
     */
    private function remember(JobTemplate|Project $record, array $values): void
    {
        foreach ($values as $name => $value) {
            $record->setAttribute($name, $value);
            $record->setOldAttribute($name, $value);
        }
    }
}
