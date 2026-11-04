<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eLintLockedProjectSeeder;
use app\helpers\FileHelper;
use app\models\Project;
use app\tests\integration\DbTestCase;

/**
 * The fixture of projects/lint-not-run.spec.ts: a manual project whose
 * directory only root may enter, so that the queue-worker, as www-data,
 * cannot start ansible-lint there, while the project page can still list
 * it. The directory is in the runtime directory by default, which the app
 * and the queue-worker both see, and the E2E teardown removes it. The
 * rollback removes the project.
 */
class E2eLintLockedProjectSeederTest extends DbTestCase
{
    private string $dir;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->markTestSkipped('The seeder runs as root.');
        }
        $this->dir = sys_get_temp_dir() . '/ansilume_lint_locked_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            FileHelper::removeDirectory($this->dir);
        }
        parent::tearDown();
    }

    public function testSeedsAManualProjectInAnEmptyDirectoryOnlyRootMayEnter(): void
    {
        $this->seed();

        $project = $this->seededProject();
        $this->assertSame(Project::SCM_TYPE_MANUAL, $project->scm_type);
        $this->assertSame(Project::STATUS_SYNCED, $project->status);
        $this->assertSame($this->dir, $project->local_path);
        $this->assertSame(0o744, fileperms($this->dir) & 0o777);
        $this->assertSame(0, fileowner($this->dir));
        $this->assertSame(['.', '..'], scandir($this->dir));
        $this->assertSame(["  Seeded lint-locked project e2e-lint-locked-project (ID {$project->id}).\n"], $this->log);
    }

    public function testSeedingAgainResetsTheLintResultAndTheDirectory(): void
    {
        $this->seed();
        $first = $this->seededProject();
        $first->lint_exit_code = -1;
        $first->lint_output = 'Lint did not run: earlier run';
        $first->lint_at = time();
        $first->save(false);
        file_put_contents($this->dir . '/site.yml', "- hosts: all\n");
        chmod($this->dir, 0o755);

        $this->seed();

        $this->assertSame(1, (int)Project::find()->where(['name' => E2eLintLockedProjectSeeder::PROJECT])->count());
        $again = $this->seededProject();
        $this->assertSame((int)$first->id, (int)$again->id);
        $this->assertNull($again->lint_exit_code);
        $this->assertNull($again->lint_output);
        $this->assertNull($again->lint_at);
        clearstatcache();
        $this->assertSame(0o744, fileperms($this->dir) & 0o777);
        $this->assertSame(['.', '..'], scandir($this->dir), 'the page could not read a playbook in it');
    }

    /**
     * By default the directory is in the runtime directory: lint runs in the
     * queue-worker, which does not see the temp directory of the app
     * container. teardown() removes it, and says so.
     */
    public function testTheDirectoryIsInTheRuntimeDirectoryAndTheTeardownRemovesIt(): void
    {
        $runtime = sys_get_temp_dir() . '/ansilume_lint_locked_runtime_' . uniqid('', true);
        mkdir($runtime);
        $previous = (string)\Yii::getAlias('@runtime');
        \Yii::setAlias('@runtime', $runtime);
        try {
            $seeder = new E2eLintLockedProjectSeeder(function (string $line): void {
                $this->log[] = $line;
            });
            $seeder->seed($this->createUser()->id);
            $dir = $runtime . '/e2e-lint-locked';

            $this->assertSame('@runtime/e2e-lint-locked', E2eLintLockedProjectSeeder::DIRECTORY);
            $this->assertSame($dir, $this->seededProject()->local_path);
            $this->assertSame(0o744, fileperms($dir) & 0o777);
            $this->assertSame(0, fileowner($dir));

            $seeder->teardown();
            $seeder->teardown();

            $this->assertDirectoryDoesNotExist($dir);
            $this->assertSame("  Deleted lint-locked project directory {$dir}.\n", end($this->log));
            $this->assertCount(2, $this->log, 'the second teardown found nothing to remove');
        } finally {
            \Yii::setAlias('@runtime', $previous);
            FileHelper::removeDirectory($runtime);
        }
    }

    public function testSkipsWhenItDoesNotRunAsRoot(): void
    {
        $seeder = new class (function (string $line): void {
            $this->log[] = $line;
        }, $this->dir) extends E2eLintLockedProjectSeeder {
            protected function runsAsRoot(): bool
            {
                return false;
            }
        };

        $seeder->seed($this->createUser()->id);

        $this->assertSame(["  Lint-locked project skipped: the seeder must run as root.\n"], $this->log);
        $this->assertNull(Project::findOne(['name' => E2eLintLockedProjectSeeder::PROJECT]));
        $this->assertDirectoryDoesNotExist($this->dir);
    }

    private function seed(): void
    {
        (new E2eLintLockedProjectSeeder(function (string $line): void {
            $this->log[] = $line;
        }, $this->dir))->seed($this->createUser()->id);
    }

    private function seededProject(): Project
    {
        $project = Project::findOne(['name' => E2eLintLockedProjectSeeder::PROJECT]);
        $this->assertInstanceOf(Project::class, $project);

        return $project;
    }
}
