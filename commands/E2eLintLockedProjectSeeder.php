<?php

declare(strict_types=1);

namespace app\commands;

use app\helpers\FileHelper;
use app\models\Project;

/**
 * Seeds a manual project whose directory the web server's user may list
 * but not enter, for projects/lint-not-run.spec.ts: Run Lint cannot start
 * ansible-lint there (ProcessStarter), and the lint card says "not run"
 * and why, not "issues found".
 *
 * The seeder runs as root and php-fpm as www-data, so the directory is
 * root's, mode 0744, and stays empty: the project page reads the playbooks
 * in a project's directory, which it could not open here. Run as another
 * user, the seeder skips, and so does the spec. Idempotent: every seed
 * creates the directory again and resets the lint result.
 */
class E2eLintLockedProjectSeeder
{
    public const PROJECT = 'e2e-lint-locked-project';

    /** @var callable(string): void */
    private $logger;

    /** Where the project's directory goes. */
    private string $dir;

    /**
     * @param callable(string): void $logger
     * @param string|null $dir where the project's directory goes, by default ansilume-e2e-lint-locked in the temp
     *        directory
     */
    public function __construct(callable $logger, ?string $dir = null)
    {
        $this->logger = $logger;
        $this->dir = $dir ?? sys_get_temp_dir() . '/ansilume-e2e-lint-locked';
    }

    public function seed(int $userId): void
    {
        if (!$this->runsAsRoot()) {
            ($this->logger)("  Lint-locked project skipped: the seeder must run as root.\n");
            return;
        }
        FileHelper::removeDirectory($this->dir);
        mkdir($this->dir, 0o744);
        chmod($this->dir, 0o744);

        $project = E2eFixtureHelper::project(self::PROJECT, $userId);
        $project->description = 'E2E project whose directory the web server may not enter';
        $project->local_path = $this->dir;
        $project->status = Project::STATUS_SYNCED;
        $project->lint_output = null;
        $project->lint_exit_code = null;
        $project->lint_at = null;
        $project->save(false);
        ($this->logger)("  Seeded lint-locked project {$project->name} (ID {$project->id}).\n");
    }

    /** Whether the seeder runs as root: only root makes a directory that php-fpm's user may not enter. */
    protected function runsAsRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }
}
