<?php

declare(strict_types=1);

namespace app\commands;

use app\models\Job;
use app\models\JobArtifact;

/**
 * Seeds a finished job with artifact files for E2E tests.
 *
 * Creates a SUCCEEDED job plus a text and JSON artifact on disk so the
 * artifacts UI (download + preview) can be exercised end-to-end without
 * needing a real Ansible run.
 */
class E2eArtifactSeeder
{
    /**
     * @var callable(string): void
     */
    private $logger;

    /**
     * @param callable(string): void $logger
     */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(int $userId, int $templateId): void
    {
        $existing = Job::find()
            ->where(['job_template_id' => $templateId, 'status' => Job::STATUS_SUCCEEDED])
            ->andWhere(['like', 'execution_command', 'e2e-artifact'])
            ->one();
        if ($existing !== null) {
            ($this->logger)("  Job with artifacts already exists (ID {$existing->id}).\n");
            return;
        }

        $job = $this->createJob($userId, $templateId);
        $storagePath = $this->ensureStoragePath($job->id);

        $this->createArtifact(
            $job->id,
            $storagePath . '/report.txt',
            'report.txt',
            'text/plain',
            "E2E Artifact Report\nStatus: OK\nTimestamp: " . date('c'),
        );
        $this->createArtifact(
            $job->id,
            $storagePath . '/results.json',
            'results.json',
            'application/json',
            '{"status":"ok","tests_passed":42,"tests_failed":0}',
        );
        // 1x1 transparent PNG so the image preview path is exercised end-to-end.
        $this->createArtifact(
            $job->id,
            $storagePath . '/screenshot.png',
            'screenshot.png',
            'image/png',
            base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
            ) ?: '',
        );

        ($this->logger)("  Created job #{$job->id} with 3 artifacts.\n");
    }

    private function createJob(int $userId, int $templateId): Job
    {
        $job = new Job();
        $job->job_template_id = $templateId;
        $job->launched_by = $userId;
        $job->status = Job::STATUS_SUCCEEDED;
        $job->exit_code = 0;
        $job->execution_command = 'e2e-artifact-job';
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

    private function ensureStoragePath(int $jobId): string
    {
        $storagePath = \Yii::getAlias('@runtime/artifacts') . '/job_' . $jobId;
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }
        return $storagePath;
    }

    private function createArtifact(
        int $jobId,
        string $path,
        string $filename,
        string $mime,
        string $contents
    ): void {
        file_put_contents($path, $contents);
        $artifact = new JobArtifact();
        $artifact->job_id = $jobId;
        $artifact->filename = $filename;
        $artifact->display_name = $filename;
        $artifact->mime_type = $mime;
        $artifact->size_bytes = (int)filesize($path);
        $artifact->storage_path = $path;
        $artifact->created_at = time();
        $artifact->save(false);
    }
}
