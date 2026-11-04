<?php

declare(strict_types=1);

namespace app\commands;

use app\models\Job;
use app\models\JobTask;
use app\models\JobTemplate;

/**
 * Seeds the failures of the E2E specs of recovered failures
 * (tests/e2e/tests/site/dashboard-recovered.spec.ts, site/rbac.spec.ts,
 * jobs/list-view.spec.ts, jobs/rbac.spec.ts), on a project that no team
 * has, so that every role sees them:
 *
 * - e2e-recovery-template: a job that failed two hours ago, and the job
 *   that recovered it, a success that started five minutes after it
 *   finished (RecoveredFailures);
 * - e2e-recovery-open-template: a job that failed three hours ago, which no
 *   later run recovered;
 * - e2e-recovery-old-template: a job that failed three days ago, and the
 *   job that recovered it, five minutes after it finished.
 *
 * The first two failures are within the dashboard's last 24 hours. The
 * open one is the older: the red panel lists it, so it would list the
 * recovered one, which is newer, if that one were not left out. The old one
 * is not: the dashboard's line does not count it, and its link Show them
 * (finished_since) must not list it. The jobs are marked by their execution
 * command. Every seed restores the project and the templates by name and
 * replaces their jobs.
 */
class E2eRecoveredFailureSeeder
{
    public const PROJECT = 'e2e-recovery-project';
    public const TEMPLATE = 'e2e-recovery-template';
    public const OPEN_TEMPLATE = 'e2e-recovery-open-template';
    public const OLD_TEMPLATE = 'e2e-recovery-old-template';

    /** The execution commands that mark the seeded jobs. */
    public const RECOVERED_JOB = 'e2e-recovered-failure';
    public const RECOVERING_JOB = 'e2e-recovering-success';
    public const OPEN_JOB = 'e2e-open-failure';
    public const OLD_RECOVERED_JOB = 'e2e-old-recovered-failure';
    public const OLD_RECOVERING_JOB = 'e2e-old-recovering-success';

    /**
     * What the seed prints; sprintf(): the recovered failure, its recovering
     * job, the open failure, the old recovered failure and its recovering job.
     */
    public const RESTORED = "  Restored " . self::TEMPLATE . ": failed job #%d, recovered by job #%d; "
        . self::OPEN_TEMPLATE . ": failed job #%d, not recovered; "
        . self::OLD_TEMPLATE . ": failed job #%d three days ago, recovered by job #%d.\n";

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(int $userId, int $inventoryId, int $runnerGroupId): void
    {
        $parents = [(int)E2eFixtureHelper::project(self::PROJECT, $userId)->id, $inventoryId, $runnerGroupId];
        $template = E2eFixtureHelper::template(self::TEMPLATE, $parents, $userId, null, []);
        $openTemplate = E2eFixtureHelper::template(self::OPEN_TEMPLATE, $parents, $userId, null, []);
        $oldTemplate = E2eFixtureHelper::template(self::OLD_TEMPLATE, $parents, $userId, null, []);
        self::deleteJobsOf([$template, $openTemplate, $oldTemplate]);

        $failedAt = time() - 7200;
        $failure = self::job($template, $userId, Job::STATUS_FAILED, self::RECOVERED_JOB, $failedAt);
        $success = self::job($template, $userId, Job::STATUS_SUCCEEDED, self::RECOVERING_JOB, $failedAt + 360);
        $open = self::job($openTemplate, $userId, Job::STATUS_FAILED, self::OPEN_JOB, $failedAt - 3600);
        $oldFailedAt = time() - 3 * 86400;
        $oldFailure = self::job($oldTemplate, $userId, Job::STATUS_FAILED, self::OLD_RECOVERED_JOB, $oldFailedAt);
        $oldSuccess = self::job($oldTemplate, $userId, Job::STATUS_SUCCEEDED, self::OLD_RECOVERING_JOB, $oldFailedAt + 360);

        ($this->logger)(sprintf(self::RESTORED, $failure->id, $success->id, $open->id, $oldFailure->id, $oldSuccess->id));
    }

    /**
     * A job of $template with $status, marked by $command, that ran for a
     * minute up to $finishedAt.
     */
    private static function job(JobTemplate $template, int $userId, string $status, string $command, int $finishedAt): Job
    {
        $job = new Job();
        $job->setAttributes([
            'job_template_id' => (int)$template->id,
            'launched_by' => $userId,
            'status' => $status,
            'execution_command' => $command,
            'exit_code' => $status === Job::STATUS_SUCCEEDED ? 0 : 2,
            'check_mode' => 0,
            'timeout_minutes' => 30,
            'has_changes' => 0,
            'queued_at' => $finishedAt - 65,
            'started_at' => $finishedAt - 60,
            'finished_at' => $finishedAt,
            'created_at' => $finishedAt - 65,
        ], false);
        $job->save(false);

        return $job;
    }

    /**
     * Removes the jobs of $templates, those a spec left on them included,
     * with their tasks; their logs, host summaries, artifacts and approval
     * requests go with them (ON DELETE CASCADE).
     *
     * @param list<JobTemplate> $templates
     */
    private static function deleteJobsOf(array $templates): void
    {
        $ids = array_map('intval', Job::find()
            ->select('id')
            ->where(['job_template_id' => array_map(static fn (JobTemplate $t): int => (int)$t->id, $templates)])
            ->column());
        JobTask::deleteAll(['job_id' => $ids]);
        Job::deleteAll(['id' => $ids]);
    }
}
