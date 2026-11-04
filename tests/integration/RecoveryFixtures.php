<?php

declare(strict_types=1);

namespace app\tests\integration;

use app\models\Job;
use app\models\JobTemplate;

/**
 * Jobs placed in time, for the tests of failures that a later run of the
 * same job template recovered (RecoveredFailures). Use in DbTestCase
 * subclasses.
 */
trait RecoveryFixtures
{
    /**
     * A job template in a project of its own, which no team has: every
     * signed-in user may see it.
     */
    protected function recoveryTemplate(int $userId): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    /**
     * A job of $template with $status that started at $startedAt and
     * finished at $finishedAt (null: not yet), created and queued five
     * seconds before it started.
     */
    protected function jobAt(JobTemplate $template, int $userId, string $status, int $startedAt, ?int $finishedAt, bool $dryRun = false): Job
    {
        $job = $this->createJob((int)$template->id, $userId, $status);
        $job->check_mode = $dryRun ? 1 : 0;
        $job->created_at = $startedAt - 5;
        $job->queued_at = $startedAt - 5;
        $job->started_at = $startedAt;
        $job->finished_at = $finishedAt;
        $job->save(false);

        return $job;
    }

    /**
     * A failure of $template ($status: failed or timed out) that finished at
     * $finishedAt, and the success that recovered it: started ten seconds
     * later, finished twenty seconds after that.
     *
     * @return array{0: Job, 1: Job} the failure and the job that recovered it
     */
    protected function recoveredFailureAt(JobTemplate $template, int $userId, int $finishedAt, string $status = Job::STATUS_FAILED): array
    {
        $failure = $this->jobAt($template, $userId, $status, $finishedAt - 30, $finishedAt);
        $success = $this->jobAt($template, $userId, Job::STATUS_SUCCEEDED, $finishedAt + 10, $finishedAt + 30);

        return [$failure, $success];
    }
}
