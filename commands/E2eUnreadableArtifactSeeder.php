<?php

declare(strict_types=1);

namespace app\commands;

use app\models\Job;
use app\models\JobArtifact;

/**
 * Seeds a finished job whose artifact files are gone or may not be read by
 * the web server, for jobs/artifacts-unreadable.spec.ts: "Download All", the
 * download and the preview answered 500 for them, and answer 404 now
 * (ArtifactService::openFile(), createZipArchive()).
 *
 * gone.txt and gone.json have records but no files. locked.txt is root's
 * file, mode 0600, which php-fpm, as www-data, may not read; run as another
 * user, the seeder leaves it out. The job's storage directory belongs to
 * www-data, so the artifact cleanup, which runs as www-data, can still
 * remove the file. Idempotent: every seed removes the files of the gone
 * artifacts and creates the locked file again, as root's: the entrypoints
 * give runtime/ to www-data on every start.
 */
class E2eUnreadableArtifactSeeder
{
    /** The job's execution command, by which the spec finds it. */
    public const JOB = 'e2e-unreadable-artifacts-job';

    public const GONE = ['gone.json', 'gone.txt'];

    public const LOCKED = 'locked.txt';

    /** @var callable(string): void */
    private $logger;

    /** Where the jobs' artifact directories are. */
    private string $storageRoot;

    /**
     * @param callable(string): void $logger
     * @param string|null $storageRoot where the jobs' artifact directories are, by default the artifact
     *        service's storage path, @runtime/artifacts
     */
    public function __construct(callable $logger, ?string $storageRoot = null)
    {
        $this->logger = $logger;
        $this->storageRoot = $storageRoot ?? (string)\Yii::getAlias('@runtime/artifacts');
    }

    public function seed(int $userId, int $templateId): void
    {
        $job = Job::findOne(['job_template_id' => $templateId, 'execution_command' => self::JOB]) ?? $this->createJob($userId, $templateId);
        $storage = $this->storageRoot . '/job_' . $job->id;
        if (!is_dir($storage)) {
            mkdir($storage, 0o755, true);
        }
        foreach (self::GONE as $name) {
            $this->artifact($job, $storage . '/' . $name, $name);
            if (is_file($storage . '/' . $name)) {
                unlink($storage . '/' . $name);
            }
        }
        if ($this->runsAsRoot()) {
            if (posix_getpwnam('www-data') !== false) {
                chown($storage, 'www-data');
            }
            if (is_file($storage . '/' . self::LOCKED)) {
                unlink($storage . '/' . self::LOCKED);
            }
            file_put_contents($storage . '/' . self::LOCKED, "locked\n");
            chmod($storage . '/' . self::LOCKED, 0o600);
            $this->artifact($job, $storage . '/' . self::LOCKED, self::LOCKED);
        }
        ($this->logger)("  Seeded job #{$job->id} with artifacts whose files are gone or locked.\n");
    }

    /** Whether the seeder runs as root: only root makes a file that php-fpm's user may not read. */
    protected function runsAsRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    private function createJob(int $userId, int $templateId): Job
    {
        $job = new Job();
        $job->job_template_id = $templateId;
        $job->launched_by = $userId;
        $job->status = Job::STATUS_SUCCEEDED;
        $job->exit_code = 0;
        $job->execution_command = self::JOB;
        $job->timeout_minutes = 120;
        $job->has_changes = 0;
        $job->queued_at = time() - 60;
        $job->started_at = time() - 30;
        $job->finished_at = time();
        $job->created_at = time();
        $job->updated_at = time();
        $job->save(false);

        return $job;
    }

    private function artifact(Job $job, string $path, string $name): void
    {
        if (JobArtifact::find()->where(['job_id' => $job->id, 'display_name' => $name])->exists()) {
            return;
        }
        $artifact = new JobArtifact();
        $artifact->job_id = $job->id;
        $artifact->filename = $name;
        $artifact->display_name = $name;
        $artifact->mime_type = str_ends_with($name, '.json') ? 'application/json' : 'text/plain';
        $artifact->size_bytes = 7;
        $artifact->storage_path = $path;
        $artifact->created_at = time();
        $artifact->save(false);
    }
}
