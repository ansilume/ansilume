<?php

declare(strict_types=1);

namespace app\commands;

use app\helpers\FileHelper;
use app\models\Project;

/**
 * Seeds a manual project whose directory holds what the web server's user
 * may not read, for projects/unreadable-files.spec.ts: the project page
 * answered 500 for each of them, and names them now
 * (ProjectFilesystemScanner).
 *
 * The seeder runs as root and php-fpm as www-data, so root owns what the
 * page may not read: the playbook secret.yml (0600), the directories
 * private/ and playbooks/locked/ (0700). Beside them are what the page shows:
 * the playbooks site.yml and playbooks/deploy.yml, a directory named like a
 * playbook (group.yml/), and a symlink to /etc, which the page shows and
 * does not follow. Two more manual projects get the other notes: one whose
 * directory the web server may not list (0700), and one below a directory
 * it may not enter. Run as another user, the seeder skips, and so does the
 * spec. Idempotent: every seed creates the directories again.
 */
class E2eUnreadableProjectSeeder
{
    public const PROJECT = 'e2e-unreadable-project';

    /** The project whose directory the web server may not list (ProjectFilesNote::UNREADABLE_DIRECTORY). */
    public const UNLISTABLE_PROJECT = 'e2e-unlistable-project';

    /** The project below a directory the web server may not enter (ProjectFilesNote::UNREACHABLE_DIRECTORY). */
    public const UNREACHABLE_PROJECT = 'e2e-unreachable-project';

    /** The paths the page cannot read, as ProjectFilesystemScanner::unreadable() names them. */
    public const UNREADABLE = ['playbooks/locked', 'private', 'secret.yml'];

    /** The playbooks the page still finds. */
    public const PLAYBOOKS = ['playbooks/deploy.yml', 'site.yml'];

    private const PLAYBOOK = "- hosts: all\n  tasks: []\n";

    /** @var callable(string): void */
    private $logger;

    /** Where the project's directory goes. */
    private string $dir;

    /**
     * @param callable(string): void $logger
     * @param string|null $dir where the project's directory goes, by default ansilume-e2e-unreadable in the temp
     *        directory
     */
    public function __construct(callable $logger, ?string $dir = null)
    {
        $this->logger = $logger;
        $this->dir = $dir ?? sys_get_temp_dir() . '/ansilume-e2e-unreadable';
    }

    public function seed(int $userId): void
    {
        if (!$this->runsAsRoot()) {
            ($this->logger)("  Unreadable project skipped: the seeder must run as root.\n");
            return;
        }
        foreach ([$this->dir, $this->unlistable(), dirname($this->unreachable())] as $directory) {
            FileHelper::removeDirectory($directory);
        }
        $this->createDirectory();
        mkdir($this->unlistable(), 0o700);
        file_put_contents($this->unlistable() . '/site.yml', self::PLAYBOOK);
        mkdir($this->unreachable(), 0o755, true);
        file_put_contents($this->unreachable() . '/site.yml', self::PLAYBOOK);
        chmod(dirname($this->unreachable()), 0o700);

        $this->project($userId, self::PROJECT, $this->dir, 'E2E project with paths the web server may not read');
        $this->project($userId, self::UNLISTABLE_PROJECT, $this->unlistable(), 'E2E project whose directory the web server may not list');
        $this->project($userId, self::UNREACHABLE_PROJECT, $this->unreachable(), 'E2E project below a directory the web server may not enter');
    }

    /** The directory of UNLISTABLE_PROJECT. */
    public function unlistable(): string
    {
        return $this->dir . '-unlistable';
    }

    /** The directory of UNREACHABLE_PROJECT, in a directory only root may enter. */
    public function unreachable(): string
    {
        return $this->dir . '-closed/project';
    }

    private function project(int $userId, string $name, string $directory, string $description): void
    {
        $project = E2eFixtureHelper::project($name, $userId);
        $project->description = $description;
        $project->local_path = $directory;
        $project->status = Project::STATUS_SYNCED;
        $project->save(false);
        ($this->logger)("  Seeded unreadable project {$project->name} (ID {$project->id}).\n");
    }

    /** Whether the seeder runs as root: only root makes paths that php-fpm's user may not read. */
    protected function runsAsRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    private function createDirectory(): void
    {
        foreach (['', '/private', '/playbooks/locked', '/group.yml'] as $directory) {
            mkdir($this->dir . $directory, 0o755, true);
        }
        $this->write('/site.yml', 0o644);
        $this->write('/secret.yml', 0o600);
        $this->write('/private/hosts', 0o644);
        $this->write('/playbooks/deploy.yml', 0o644);
        $this->write('/playbooks/locked/maintenance.yml', 0o644);
        chmod($this->dir . '/private', 0o700);
        chmod($this->dir . '/playbooks/locked', 0o700);
        symlink('/etc', $this->dir . '/etc');
    }

    private function write(string $relative, int $mode): void
    {
        file_put_contents($this->dir . $relative, self::PLAYBOOK);
        chmod($this->dir . $relative, $mode);
    }
}
