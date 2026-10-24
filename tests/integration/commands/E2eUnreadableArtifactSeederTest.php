<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eUnreadableArtifactSeeder;
use app\helpers\FileHelper;
use app\models\Job;
use app\models\JobArtifact;
use app\services\ArtifactService;
use app\tests\integration\DbTestCase;
use app\tests\unit\RunsUnprivileged;

/**
 * The fixture of jobs/artifacts-unreadable.spec.ts: a finished job whose
 * artifact files are gone, and one only root may read. The rollback removes
 * the job; the seeds write into a storage root of the test's own, never into
 * the stack's runtime/artifacts, which the test removes.
 */
class E2eUnreadableArtifactSeederTest extends DbTestCase
{
    use RunsUnprivileged;

    /** @var list<string> */
    private array $log = [];

    /** The storage root the seeds write into. */
    private string $root;

    private ?int $userId = null;

    private ?int $templateId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/ansilume_unreadable_artifacts_' . uniqid('', true);
        mkdir($this->root, 0o755);
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->root);
        parent::tearDown();
    }

    public function testSeedsAJobWhoseArtifactFilesAreGoneOrLocked(): void
    {
        $job = $this->seed(true);

        $artifacts = $this->artifactsOf($job);
        $this->assertSame([...E2eUnreadableArtifactSeeder::GONE, E2eUnreadableArtifactSeeder::LOCKED], array_keys($artifacts));
        foreach (E2eUnreadableArtifactSeeder::GONE as $name) {
            $this->assertFileDoesNotExist($artifacts[$name]->storage_path);
        }
        $locked = $artifacts[E2eUnreadableArtifactSeeder::LOCKED]->storage_path;
        $this->assertSame(0o600, fileperms($locked) & 0o777);
        $webServer = posix_getpwnam('www-data');
        if ($webServer !== false && posix_geteuid() === 0) {
            $this->assertSame(0, fileowner($locked));
            $this->assertSame($webServer['uid'], fileowner(dirname($locked)), 'the artifact cleanup may remove the file');
        }
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);
        $this->assertSame(["  Seeded job #{$job->id} with artifacts whose files are gone or locked.\n"], $this->log);
    }

    /**
     * What the spec expects: the web server finds the gone files gone, the
     * locked one unreadable, and nothing to download.
     *
     * @requires extension posix
     */
    public function testTheWebServerFindsNothingItCanServe(): void
    {
        if (posix_geteuid() !== 0) {
            $this->markTestSkipped('Only root writes a file the web server may not read.');
        }
        $job = $this->seed(true);
        $artifacts = $this->artifactsOf($job);
        $service = new ArtifactService();

        [$gone, $locked, $zip] = self::unprivileged(static fn (): array => [
            $service->openFile($artifacts['gone.txt']),
            $service->openFile($artifacts[E2eUnreadableArtifactSeeder::LOCKED]),
            $service->createZipArchive($job),
        ]);

        $this->assertSame(ArtifactService::FILE_GONE, $gone);
        $this->assertSame(ArtifactService::FILE_UNREADABLE, $locked);
        $this->assertNull($zip);
    }

    public function testSeedingAgainKeepsTheJobAndItsArtifacts(): void
    {
        $job = $this->seed(true);
        $gone = $this->artifactsOf($job)['gone.txt']->storage_path;
        file_put_contents($gone, 'back');

        $again = $this->seed(true);

        $this->assertSame((int)$job->id, (int)$again->id);
        $this->assertCount(3, $this->artifactsOf($again));
        $this->assertFileDoesNotExist($gone);
        $this->assertStringStartsWith($this->root . '/', $this->artifactsOf($again)['gone.txt']->storage_path);
    }

    /**
     * Review finding: the entrypoints give runtime/ to www-data on every
     * start, and seeding again kept that owner, so the web server could read
     * the locked file, and the spec failed. Every seed creates it as root's.
     *
     * @requires extension posix
     */
    public function testSeedingAgainMakesTheLockedFileRootsAgain(): void
    {
        if (posix_geteuid() !== 0) {
            $this->markTestSkipped('Only root writes a file the web server may not read.');
        }
        $job = $this->seed(true);
        $locked = $this->artifactsOf($job)[E2eUnreadableArtifactSeeder::LOCKED]->storage_path;
        chown($locked, 65534);
        chmod($locked, 0o644);

        $this->seed(true);

        clearstatcache();
        $this->assertSame(0, fileowner($locked));
        $this->assertSame(0o600, fileperms($locked) & 0o777);
    }

    public function testTheDefaultStorageRootIsTheArtifactServicesOne(): void
    {
        $seeder = new E2eUnreadableArtifactSeeder(static function (string $line): void {
        });
        $root = (new \ReflectionProperty($seeder, 'storageRoot'))->getValue($seeder);

        $this->assertSame(\Yii::getAlias('@runtime/artifacts'), $root);
    }

    public function testLeavesTheLockedFileOutWhenItDoesNotRunAsRoot(): void
    {
        $job = $this->seed(false);

        $this->assertSame(E2eUnreadableArtifactSeeder::GONE, array_keys($this->artifactsOf($job)));
    }

    /**
     * Seeds the job, as root when $asRoot; every seed of a test uses the
     * same user and job template.
     */
    private function seed(bool $asRoot): Job
    {
        if ($this->templateId === null) {
            $user = $this->createUser();
            $project = $this->createProject($user->id);
            $inventory = $this->createInventory($user->id);
            $group = $this->createRunnerGroup($user->id);
            $this->userId = (int)$user->id;
            $this->templateId = (int)$this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id)->id;
        }
        $templateId = $this->templateId;
        $seeder = new class (function (string $line): void {
            $this->log[] = $line;
        }, $this->root, $asRoot) extends E2eUnreadableArtifactSeeder {
            /** @param callable(string): void $logger */
            public function __construct(callable $logger, string $root, private bool $asRoot)
            {
                parent::__construct($logger, $root);
            }

            protected function runsAsRoot(): bool
            {
                return $this->asRoot;
            }
        };
        $seeder->seed((int)$this->userId, $templateId);

        $job = Job::findOne(['job_template_id' => $templateId, 'execution_command' => E2eUnreadableArtifactSeeder::JOB]);
        $this->assertInstanceOf(Job::class, $job);

        return $job;
    }

    /**
     * @return array<string, JobArtifact> by display name, sorted
     */
    private function artifactsOf(Job $job): array
    {
        $artifacts = [];
        foreach (JobArtifact::find()->where(['job_id' => $job->id])->orderBy(['display_name' => SORT_ASC])->all() as $artifact) {
            $artifacts[$artifact->display_name] = $artifact;
        }

        return $artifacts;
    }
}
