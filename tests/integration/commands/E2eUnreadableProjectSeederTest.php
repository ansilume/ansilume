<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eUnreadableProjectSeeder;
use app\helpers\FileHelper;
use app\models\Project;
use app\services\ProjectFilesystemScanner;
use app\tests\integration\DbTestCase;
use app\tests\unit\RunsUnprivileged;

/**
 * The fixture of projects/unreadable-files.spec.ts: a manual project whose
 * directory holds paths that php-fpm, as www-data, may not read, beside
 * playbooks it may, a directory named like a playbook and a link to /etc.
 * The rollback removes the project.
 */
class E2eUnreadableProjectSeederTest extends DbTestCase
{
    use RunsUnprivileged;

    private string $dir;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->markTestSkipped('The seeder runs as root.');
        }
        $this->dir = sys_get_temp_dir() . '/ansilume_unreadable_project_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            foreach ([$this->dir, $this->dir . '-unlistable', $this->dir . '-closed'] as $directory) {
                FileHelper::removeDirectory($directory);
            }
        }
        parent::tearDown();
    }

    public function testSeedsAManualProjectWithPathsOnlyRootMayRead(): void
    {
        $this->seed();

        $project = $this->seededProject();
        $this->assertSame(Project::SCM_TYPE_MANUAL, $project->scm_type);
        $this->assertSame(Project::STATUS_SYNCED, $project->status);
        $this->assertSame($this->dir, $project->local_path);
        $this->assertSame(0o600, fileperms($this->dir . '/secret.yml') & 0o777);
        $this->assertSame(0o700, fileperms($this->dir . '/private') & 0o777);
        $this->assertSame(0o700, fileperms($this->dir . '/playbooks/locked') & 0o777);
        $this->assertSame(0, fileowner($this->dir . '/secret.yml'));
        $this->assertDirectoryExists($this->dir . '/group.yml');
        $this->assertSame('/etc', readlink($this->dir . '/etc'));
        $unlistable = $this->seededProject(E2eUnreadableProjectSeeder::UNLISTABLE_PROJECT);
        $unreachable = $this->seededProject(E2eUnreadableProjectSeeder::UNREACHABLE_PROJECT);
        $this->assertSame($this->dir . '-unlistable', $unlistable->local_path);
        $this->assertSame(0o700, fileperms($this->dir . '-unlistable') & 0o777);
        $this->assertSame($this->dir . '-closed/project', $unreachable->local_path);
        $this->assertSame(0o700, fileperms($this->dir . '-closed') & 0o777);
        $this->assertSame([
            "  Seeded unreadable project e2e-unreadable-project (ID {$project->id}).\n",
            "  Seeded unreadable project e2e-unlistable-project (ID {$unlistable->id}).\n",
            "  Seeded unreadable project e2e-unreachable-project (ID {$unreachable->id}).\n",
        ], $this->log);
    }

    /**
     * What the spec expects of the other two pages: as a user who may not
     * read what only root may, the page cannot list one directory and
     * cannot reach the other.
     *
     * @requires extension posix
     */
    public function testTheWebServerCannotListOneDirectoryAndCannotReachTheOther(): void
    {
        $this->seed();

        [$unlisted, $reached] = self::unprivileged(function (): array {
            $scanner = new ProjectFilesystemScanner();
            $scanner->buildTree($this->dir . '-unlistable', $this->dir . '-unlistable');

            return [$scanner->unreadable($this->dir . '-unlistable'), is_dir($this->dir . '-closed/project')];
        });

        $this->assertSame(['.'], $unlisted);
        $this->assertFalse($reached);
    }

    /**
     * What the spec expects of the page: the scanner, as a user who may not
     * read what only root may, finds the playbooks and names the rest.
     *
     * @requires extension posix
     */
    public function testTheWebServerFindsThePlaybooksAndNamesWhatItCannotRead(): void
    {
        $this->seed();

        [$playbooks, $unreadable] = self::unprivileged(function (): array {
            $scanner = new ProjectFilesystemScanner();
            $playbooks = $scanner->detectPlaybooks($this->dir);
            $scanner->buildTree($this->dir, $this->dir);

            return [$playbooks, $scanner->unreadable($this->dir)];
        });

        $this->assertSame(E2eUnreadableProjectSeeder::PLAYBOOKS, $playbooks);
        $this->assertSame(E2eUnreadableProjectSeeder::UNREADABLE, $unreadable);
    }

    /**
     * Seeding again removes the link to /etc, not what /etc holds.
     */
    public function testSeedingAgainCreatesTheDirectoryAgainAndLeavesEtcAlone(): void
    {
        $this->seed();
        $first = $this->seededProject();
        file_put_contents($this->dir . '/extra.yml', "- hosts: all\n");

        $this->seed();

        $this->assertSame(1, (int)Project::find()->where(['name' => E2eUnreadableProjectSeeder::PROJECT])->count());
        $this->assertSame((int)$first->id, (int)$this->seededProject()->id);
        $this->assertFileDoesNotExist($this->dir . '/extra.yml');
        $this->assertFileExists('/etc/passwd');
    }

    public function testSkipsWhenItDoesNotRunAsRoot(): void
    {
        $seeder = new class (function (string $line): void {
            $this->log[] = $line;
        }, $this->dir) extends E2eUnreadableProjectSeeder {
            protected function runsAsRoot(): bool
            {
                return false;
            }
        };

        $seeder->seed($this->createUser()->id);

        $this->assertSame(["  Unreadable project skipped: the seeder must run as root.\n"], $this->log);
        $this->assertNull(Project::findOne(['name' => E2eUnreadableProjectSeeder::PROJECT]));
        $this->assertNull(Project::findOne(['name' => E2eUnreadableProjectSeeder::UNLISTABLE_PROJECT]));
        $this->assertDirectoryDoesNotExist($this->dir);
    }

    private function seed(): void
    {
        (new E2eUnreadableProjectSeeder(function (string $line): void {
            $this->log[] = $line;
        }, $this->dir))->seed($this->createUser()->id);
    }

    private function seededProject(string $name = E2eUnreadableProjectSeeder::PROJECT): Project
    {
        $project = Project::findOne(['name' => $name]);
        $this->assertInstanceOf(Project::class, $project);

        return $project;
    }
}
