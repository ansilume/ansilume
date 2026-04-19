<?php

declare(strict_types=1);

namespace app\services;

use app\models\JobTemplate;
use app\models\Project;
use yii\base\Component;

/**
 * Runs ansible-lint against a job template's playbook and stores the result.
 *
 * Uses --profile production (strictest built-in profile).
 * Safe to call from both web (after save) and worker (after project sync).
 * If ansible-lint is not installed the result is stored as an informational message.
 */
class LintService extends Component
{
    /**
     * Run ansible-lint on an entire project directory (no specific playbook —
     * ansible-lint auto-discovers all playbooks, roles, tasks, and collections).
     * Stores the result on the Project record.
     */
    public function runForProject(Project $project): void
    {
        $projectPath = $this->resolveProjectPath($project);
        if ($projectPath === null) {
            return;
        }

        if (!is_dir($projectPath)) {
            $message = $project->scm_type === Project::SCM_TYPE_MANUAL
                ? 'Project path not found: ' . $projectPath
                : 'Project workspace not found — sync the project first.';
            $this->storeProject($project, null, $message);
            return;
        }

        if (!$this->isAvailable()) {
            return;
        }

        [$output, $exitCode] = $this->execute(null, $projectPath);
        $this->storeProject($project, $exitCode, $output ?: '(no output)');
    }

    public function runForTemplate(JobTemplate $template): void
    {
        $project = $template->project;
        if ($project === null) {
            $this->store($template, null, 'No project assigned to this template.');
            return;
        }

        $projectPath = $this->resolveProjectPath($project);

        if ($projectPath === null) {
            $this->store($template, null, 'No local path configured for this project.');
            return;
        }

        if (!is_dir($projectPath)) {
            $message = $project->scm_type === Project::SCM_TYPE_MANUAL
                ? "Project path not found: {$projectPath}"
                : 'Project workspace not found — sync the project first.';
            $this->store($template, null, $message);
            return;
        }

        $playbookPath = $projectPath . '/' . ltrim($template->playbook, '/');
        if (!file_exists($playbookPath)) {
            $this->store($template, null, "Playbook not found: {$template->playbook}\nSync the project to fetch the latest files.");
            return;
        }

        if (!$this->isAvailable()) {
            return;
        }

        [$output, $exitCode] = $this->execute($template->playbook, $projectPath);
        $this->store($template, $exitCode, $output ?: '(no output)');
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

    protected function storeProject(Project $project, ?int $exitCode, string $output): void
    {
        $project->lint_output = $output;
        $project->lint_at = time();
        $project->lint_exit_code = $exitCode;
        $project->save(false, ['lint_output', 'lint_at', 'lint_exit_code']);
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

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = array_merge(getenv() ?: [], [
            'HOME' => sys_get_temp_dir(),
            'ANSIBLE_HOME' => $this->ensureCacheDir($cwd),
        ]);

        $process = proc_open($cmd, $descriptors, $pipes, $cwd, $env);

        if (!is_resource($process)) {
            return ['Failed to start ansible-lint process.', -1];
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
     */
    protected function ensureCacheDir(string $cwd): string
    {
        $cacheDir = $cwd . '/.ansible';

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

        // Fallback: per-CWD temp dir. Deterministic (hashed) so repeat runs
        // hit the same cache instead of leaking new dirs under /tmp.
        $fallback = sys_get_temp_dir() . '/ansilume-ansible-' . substr(hash('sha256', $cwd), 0, 16);
        if (!is_dir($fallback)) {
            mkdir($fallback, 0o755, true);
        }
        return $fallback;
    }

    protected function store(JobTemplate $template, ?int $exitCode, string $output): void
    {
        $template->lint_output = $output;
        $template->lint_at = time();
        $template->lint_exit_code = $exitCode;
        $template->save(false, ['lint_output', 'lint_at', 'lint_exit_code']);
    }
}
