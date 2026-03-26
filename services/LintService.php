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
        $project->lint_output    = $output;
        $project->lint_at        = time();
        $project->lint_exit_code = $exitCode;
        $project->save(false, ['lint_output', 'lint_at', 'lint_exit_code']);
    }

    protected function isAvailable(): bool
    {
        exec('which ansible-lint 2>/dev/null', $out, $code);
        return $code === 0;
    }

    /**
     * @return array{string, int}  [combined output, exit code]
     */
    protected function execute(?string $playbook, string $cwd): array
    {
        $cmd = ['ansible-lint', '--profile', 'production', '--nocolor'];
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

        $process = proc_open($cmd, $descriptors, $pipes, $cwd);

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

    protected function store(JobTemplate $template, ?int $exitCode, string $output): void
    {
        $template->lint_output    = $output;
        $template->lint_at        = time();
        $template->lint_exit_code = $exitCode;
        $template->save(false, ['lint_output', 'lint_at', 'lint_exit_code']);
    }
}
