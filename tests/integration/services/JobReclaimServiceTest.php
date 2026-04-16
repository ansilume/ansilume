<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\models\Job;
use app\models\JobLog;
use app\models\Runner;
use app\models\RunnerGroup;
use app\services\JobReclaimService;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for JobReclaimService.
 *
 * Each test wires up a runner, a running job, and adjusts last_progress_at
 * + runner.last_seen_at to land in the desired stale/healthy combination,
 * then asserts the sweep takes (or doesn't take) the expected action.
 */
class JobReclaimServiceTest extends DbTestCase
{
    private JobReclaimService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new JobReclaimService();
        $this->service->progressTimeoutSeconds = 600;
    }

    // -------------------------------------------------------------------------
    // Reclaim happens when both progress timeout AND runner-offline match
    // -------------------------------------------------------------------------

    public function testReclaimsRunningJobWhenProgressTimedOutAndRunnerOffline(): void
    {
        [, $runner, $job] = $this->makeRunningJob();
        $this->markJobStale($job, 700);
        $this->markRunnerOffline($runner);

        $count = $this->service->reclaimStaleJobs();

        $this->assertSame(1, $count);
        $job->refresh();
        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $this->assertSame(-1, $job->exit_code);
        $this->assertNotNull($job->finished_at);
    }

    public function testReclaimReturnsZeroWhenNothingStale(): void
    {
        [, $runner, $job] = $this->makeRunningJob();
        // Recent progress, runner online — healthy.
        $job->last_progress_at = time();
        $job->save(false);
        $runner->last_seen_at = time();
        $runner->save(false);

        $this->assertSame(0, $this->service->reclaimStaleJobs());
    }

    // -------------------------------------------------------------------------
    // Negative cases — only one of the two conditions met
    // -------------------------------------------------------------------------

    public function testDoesNotReclaimWhenRunnerStillOnlineEvenIfProgressTimedOut(): void
    {
        // Long-running playbook with silent task — runner is still alive,
        // we must not kill the job just because no logs flowed.
        [, $runner, $job] = $this->makeRunningJob();
        $this->markJobStale($job, 700);
        $runner->last_seen_at = time();
        $runner->save(false);

        $this->assertSame(0, $this->service->reclaimStaleJobs());
        $job->refresh();
        $this->assertSame(Job::STATUS_RUNNING, $job->status);
    }

    public function testDoesNotReclaimWhenProgressRecentEvenIfRunnerOffline(): void
    {
        // Edge case: job recently bumped progress but runner has gone offline
        // since. The progress timestamp is the canonical liveness signal —
        // if it's fresh, leave the job alone (next sweep will catch it).
        [, $runner, $job] = $this->makeRunningJob();
        $job->last_progress_at = time() - 10;
        $job->save(false);
        $this->markRunnerOffline($runner);

        $this->assertSame(0, $this->service->reclaimStaleJobs());
        $job->refresh();
        $this->assertSame(Job::STATUS_RUNNING, $job->status);
    }

    public function testDoesNotReclaimNonRunningJobs(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        // Queued job — never started, no runner.
        $queued = $this->createJob($template->id, $user->id, Job::STATUS_QUEUED);
        $queued->last_progress_at = time() - 10000;
        $queued->save(false);

        $this->assertSame(0, $this->service->reclaimStaleJobs());
        $queued->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $queued->status);
    }

    // -------------------------------------------------------------------------
    // Edge cases on the runner side
    // -------------------------------------------------------------------------

    public function testReclaimsWhenRunnerIsDeleted(): void
    {
        // Runner deleted while a job was running. The FK on job.runner_id is
        // ON DELETE SET NULL, so job.runner_id becomes null after the cascade
        // — the sweep then reclaims via the "no runner_id" path.
        [, $runner, $job] = $this->makeRunningJob();
        $this->markJobStale($job, 700);
        $runner->delete();

        $this->assertSame(1, $this->service->reclaimStaleJobs());
        $job->refresh();
        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $this->assertNull($job->runner_id);

        $audit = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_JOB_RECLAIMED, 'object_id' => $job->id])
            ->one();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('no assigned runner', (string)$audit->metadata);
    }

    public function testReclaimsWhenRunnerIdIsNull(): void
    {
        // Pathological data: status=running but runner_id=null.
        // Nothing will ever finish this job, so reclaim it.
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $job = $this->createJob($template->id, $user->id, Job::STATUS_RUNNING);
        $job->runner_id = null;
        $job->started_at = time() - 1000;
        $job->last_progress_at = time() - 1000;
        $job->save(false);

        $this->assertSame(1, $this->service->reclaimStaleJobs());
        $job->refresh();
        $this->assertSame(Job::STATUS_FAILED, $job->status);
    }

    // -------------------------------------------------------------------------
    // Side effects: log line, audit entry
    // -------------------------------------------------------------------------

    public function testReclaimAppendsStderrLogLineWithReason(): void
    {
        [, $runner, $job] = $this->makeRunningJob();
        $this->markJobStale($job, 700);
        $this->markRunnerOffline($runner);

        $this->service->reclaimStaleJobs();

        /** @var JobLog|null $log */
        $log = JobLog::find()
            ->where(['job_id' => $job->id, 'stream' => JobLog::STREAM_STDERR])
            ->orderBy(['sequence' => SORT_DESC])
            ->one();
        $this->assertNotNull($log);
        $this->assertStringContainsString('reclaimed', $log->content);
        $this->assertStringContainsString($runner->name, $log->content);
    }

    public function testReclaimLogLineSequenceFollowsExistingLogs(): void
    {
        [, $runner, $job] = $this->makeRunningJob();

        // Pre-existing log lines from the runner before it died.
        for ($i = 0; $i < 3; $i++) {
            $log = new JobLog();
            $log->job_id = $job->id;
            $log->stream = JobLog::STREAM_STDOUT;
            $log->content = "line {$i}";
            $log->sequence = $i;
            $log->created_at = time();
            $log->save(false);
        }

        $this->markJobStale($job, 700);
        $this->markRunnerOffline($runner);
        $this->service->reclaimStaleJobs();

        /** @var JobLog|null $log */
        $log = JobLog::find()
            ->where(['job_id' => $job->id, 'stream' => JobLog::STREAM_STDERR])
            ->one();
        $this->assertNotNull($log);
        $this->assertSame(3, $log->sequence);
    }

    public function testReclaimWritesAuditLog(): void
    {
        [, $runner, $job] = $this->makeRunningJob();
        $this->markJobStale($job, 700);
        $this->markRunnerOffline($runner);

        $this->service->reclaimStaleJobs();

        /** @var AuditLog|null $audit */
        $audit = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_JOB_RECLAIMED, 'object_id' => $job->id])
            ->one();
        $this->assertNotNull($audit);
        $this->assertSame('job', $audit->object_type);
        $this->assertStringContainsString('runner_id', (string)$audit->metadata);
        $this->assertStringContainsString('progress_timeout_seconds', (string)$audit->metadata);
    }

    public function testReclaimReasonMentionsRunnerLastSeen(): void
    {
        [, $runner, $job] = $this->makeRunningJob();
        $this->markJobStale($job, 700);
        $this->markRunnerOffline($runner);

        $this->service->reclaimStaleJobs();

        /** @var JobLog|null $log */
        $log = JobLog::find()
            ->where(['job_id' => $job->id, 'stream' => JobLog::STREAM_STDERR])
            ->one();
        $this->assertNotNull($log);
        $this->assertStringContainsString('last seen', $log->content);
    }

    public function testReclaimReasonHandlesRunnerNeverSeen(): void
    {
        [, $runner, $job] = $this->makeRunningJob();
        $this->markJobStale($job, 700);
        $runner->last_seen_at = null; // never reported in
        $runner->save(false);

        $this->service->reclaimStaleJobs();

        /** @var JobLog|null $log */
        $log = JobLog::find()
            ->where(['job_id' => $job->id, 'stream' => JobLog::STREAM_STDERR])
            ->one();
        $this->assertNotNull($log);
        $this->assertStringContainsString('never', $log->content);
    }

    // -------------------------------------------------------------------------
    // Concurrency-safety: the optimistic UPDATE WHERE status=running must
    // not double-write if the runner finishes the job between SELECT and
    // UPDATE.
    // -------------------------------------------------------------------------

    public function testDoesNotOverwriteJobThatWasJustCompleted(): void
    {
        [, $runner, $job] = $this->makeRunningJob();
        $this->markJobStale($job, 700);
        $this->markRunnerOffline($runner);

        // Simulate the runner reporting completion between our find and update.
        // We can't truly race in a test, but we can verify the optimistic
        // UPDATE only fires on status=running by mutating the row first.
        Job::updateAll(
            ['status' => Job::STATUS_SUCCEEDED, 'finished_at' => time(), 'exit_code' => 0],
            ['id' => $job->id]
        );

        $count = $this->service->reclaimStaleJobs();

        $this->assertSame(0, $count);
        $job->refresh();
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);
        $this->assertSame(0, $job->exit_code);
    }

    // -------------------------------------------------------------------------
    // Threshold tuning
    // -------------------------------------------------------------------------

    public function testCustomProgressTimeoutSeconds(): void
    {
        [, $runner, $job] = $this->makeRunningJob();

        $this->service->progressTimeoutSeconds = 60;

        // 90s without progress is enough at 60s threshold but not at default 600.
        $job->last_progress_at = time() - 90;
        $job->save(false);
        $this->markRunnerOffline($runner);

        $this->assertSame(1, $this->service->reclaimStaleJobs());
    }

    public function testReclaimsMultipleStaleJobsInOneSweep(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        for ($i = 0; $i < 3; $i++) {
            $runner = $this->createRunner($group->id, $user->id);
            $this->markRunnerOffline($runner);
            $job = $this->createJob($template->id, $user->id, Job::STATUS_RUNNING);
            $job->runner_id = $runner->id;
            $job->started_at = time() - 1000;
            $job->last_progress_at = time() - 1000;
            $job->save(false);
        }

        $this->assertSame(3, $this->service->reclaimStaleJobs());
    }

    // -------------------------------------------------------------------------
    // Requeue mode: stuck jobs are pushed back to QUEUED instead of FAILED
    // -------------------------------------------------------------------------

    public function testRequeueModeMovesJobBackToQueuedAndIncrementsAttempt(): void
    {
        $this->service->mode = JobReclaimService::MODE_REQUEUE;

        [, $runner, $job] = $this->makeRunningJob();
        $job->max_attempts = 3;
        $job->save(false);
        $this->markJobStale($job, 700);
        $this->markRunnerOffline($runner);

        $this->assertSame(1, $this->service->reclaimStaleJobs());

        $job->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
        $this->assertSame(2, $job->attempt_count);
        $this->assertNull($job->runner_id);
        $this->assertNull($job->started_at);
        $this->assertNull($job->finished_at);
        $this->assertNull($job->last_progress_at);
        $this->assertNull($job->exit_code);
    }

    public function testRequeueModeFailsOnceMaxAttemptsReached(): void
    {
        $this->service->mode = JobReclaimService::MODE_REQUEUE;

        [, $runner, $job] = $this->makeRunningJob();
        $job->max_attempts = 3;
        $job->attempt_count = 3; // already at the ceiling
        $job->save(false);
        $this->markJobStale($job, 700);
        $this->markRunnerOffline($runner);

        $this->assertSame(1, $this->service->reclaimStaleJobs());

        $job->refresh();
        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $this->assertSame(-1, $job->exit_code);
        $this->assertSame(3, $job->attempt_count); // not incremented past ceiling
    }

    public function testRequeueModeWritesRequeueAuditLogNotReclaimed(): void
    {
        $this->service->mode = JobReclaimService::MODE_REQUEUE;

        [, $runner, $job] = $this->makeRunningJob();
        $job->max_attempts = 3;
        $job->save(false);
        $this->markJobStale($job, 700);
        $this->markRunnerOffline($runner);

        $this->service->reclaimStaleJobs();

        $requeue = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_JOB_REQUEUED, 'object_id' => $job->id])
            ->one();
        $this->assertNotNull($requeue);
        $this->assertStringContainsString('new_attempt', (string)$requeue->metadata);
        $this->assertStringContainsString('max_attempts', (string)$requeue->metadata);

        $reclaim = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_JOB_RECLAIMED, 'object_id' => $job->id])
            ->one();
        $this->assertNull($reclaim);
    }

    public function testRequeueAppendsStderrLineMentioningAttempt(): void
    {
        $this->service->mode = JobReclaimService::MODE_REQUEUE;

        [, $runner, $job] = $this->makeRunningJob();
        $job->max_attempts = 3;
        $job->save(false);
        $this->markJobStale($job, 700);
        $this->markRunnerOffline($runner);

        $this->service->reclaimStaleJobs();

        /** @var JobLog|null $log */
        $log = JobLog::find()
            ->where(['job_id' => $job->id, 'stream' => JobLog::STREAM_STDERR])
            ->orderBy(['sequence' => SORT_DESC])
            ->one();
        $this->assertNotNull($log);
        $this->assertStringContainsString('re-queued', $log->content);
        $this->assertStringContainsString('attempt 2 of 3', $log->content);
    }

    public function testRequeueResetsQueuedAtSoClaimSeesItAsFresh(): void
    {
        $this->service->mode = JobReclaimService::MODE_REQUEUE;

        [, $runner, $job] = $this->makeRunningJob();
        $job->max_attempts = 3;
        $originalQueuedAt = time() - 5000;
        $job->queued_at = $originalQueuedAt;
        $job->save(false);
        $this->markJobStale($job, 700);
        $this->markRunnerOffline($runner);

        $this->service->reclaimStaleJobs();

        $job->refresh();
        $this->assertGreaterThan($originalQueuedAt, (int)$job->queued_at);
    }

    public function testRequeueDoesNotOverwriteCompletedJob(): void
    {
        // Same concurrency-safety guarantee as the fail() path.
        $this->service->mode = JobReclaimService::MODE_REQUEUE;

        [, $runner, $job] = $this->makeRunningJob();
        $job->max_attempts = 3;
        $job->save(false);
        $this->markJobStale($job, 700);
        $this->markRunnerOffline($runner);

        Job::updateAll(
            ['status' => Job::STATUS_SUCCEEDED, 'finished_at' => time(), 'exit_code' => 0],
            ['id' => $job->id]
        );

        $this->assertSame(0, $this->service->reclaimStaleJobs());
        $job->refresh();
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);
        $this->assertSame(1, $job->attempt_count);
    }

    public function testRequeueMultipleJobsInOneSweep(): void
    {
        $this->service->mode = JobReclaimService::MODE_REQUEUE;

        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        for ($i = 0; $i < 3; $i++) {
            $runner = $this->createRunner($group->id, $user->id);
            $this->markRunnerOffline($runner);
            $job = $this->createJob($template->id, $user->id, Job::STATUS_RUNNING);
            $job->runner_id = $runner->id;
            $job->started_at = time() - 1000;
            $job->last_progress_at = time() - 1000;
            $job->max_attempts = 3;
            $job->save(false);
        }

        $this->assertSame(3, $this->service->reclaimStaleJobs());
        $this->assertSame(3, (int)Job::find()->where(['status' => Job::STATUS_QUEUED])->count());
    }

    public function testFailModeIgnoresAttemptCount(): void
    {
        // Default mode is fail — even with max_attempts headroom, jobs still
        // go to FAILED. Locks in the historical behavior as the safe default.
        $this->service->mode = JobReclaimService::MODE_FAIL;

        [, $runner, $job] = $this->makeRunningJob();
        $job->max_attempts = 99;
        $job->save(false);
        $this->markJobStale($job, 700);
        $this->markRunnerOffline($runner);

        $this->service->reclaimStaleJobs();

        $job->refresh();
        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $this->assertSame(1, $job->attempt_count);
    }

    // -------------------------------------------------------------------------
    // Queue-starvation: orphaned QUEUED jobs with no executor at all
    // -------------------------------------------------------------------------

    /**
     * Force the service to behave as if no live Yii-queue worker exists.
     * Avoids touching the real Redis state, which the dev/CI queue-worker
     * container would immediately re-populate.
     */
    private function clearWorkerHeartbeats(): void
    {
        $this->service->liveWorkerProbe = static fn (): bool => false;
    }

    /**
     * Force the service to behave as if a live worker exists.
     */
    private function seedLiveWorker(): void
    {
        $this->service->liveWorkerProbe = static fn (): bool => true;
    }

    public function testOrphanedQueuedJobIsFailedWhenNoWorkerAndNoOnlineRunner(): void
    {
        $this->clearWorkerHeartbeats();
        $this->service->queueTimeoutSeconds = 60;

        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        // No runners in group → group->countOnline() === 0
        $job = $this->createJob($template->id, $user->id, Job::STATUS_QUEUED);
        $job->queued_at = time() - 200;
        $job->save(false);

        $this->assertSame(1, $this->service->reclaimOrphanedQueuedJobs());
        $job->refresh();
        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $this->assertSame(-1, $job->exit_code);
        $this->assertNotNull($job->finished_at);
    }

    public function testOrphanedQueuedSweepLeavesJobAloneWhenLiveWorkerExists(): void
    {
        $this->clearWorkerHeartbeats();
        $this->seedLiveWorker();
        $this->service->queueTimeoutSeconds = 60;

        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $job = $this->createJob($template->id, $user->id, Job::STATUS_QUEUED);
        $job->queued_at = time() - 200;
        $job->save(false);

        $this->assertSame(0, $this->service->reclaimOrphanedQueuedJobs());
        $job->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $job->status);

        $this->clearWorkerHeartbeats();
    }

    public function testOrphanedQueuedSweepLeavesJobAloneWhenAnyRunnerOnline(): void
    {
        $this->clearWorkerHeartbeats();
        $this->service->queueTimeoutSeconds = 60;

        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        // Online runner makes pickup possible.
        $runner = $this->createRunner($group->id, $user->id);
        $runner->last_seen_at = time();
        $runner->save(false);

        $job = $this->createJob($template->id, $user->id, Job::STATUS_QUEUED);
        $job->queued_at = time() - 200;
        $job->save(false);

        $this->assertSame(0, $this->service->reclaimOrphanedQueuedJobs());
        $job->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
    }

    public function testOrphanedQueuedSweepLeavesYoungJobAlone(): void
    {
        $this->clearWorkerHeartbeats();
        $this->service->queueTimeoutSeconds = 600;

        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        // Just queued — under the timeout.
        $job = $this->createJob($template->id, $user->id, Job::STATUS_QUEUED);
        $job->queued_at = time() - 30;
        $job->save(false);

        $this->assertSame(0, $this->service->reclaimOrphanedQueuedJobs());
        $job->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
    }

    public function testOrphanedQueuedSweepWritesAuditWithStarvationFlag(): void
    {
        $this->clearWorkerHeartbeats();
        $this->service->queueTimeoutSeconds = 60;

        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $job = $this->createJob($template->id, $user->id, Job::STATUS_QUEUED);
        $job->queued_at = time() - 200;
        $job->save(false);

        $this->service->reclaimOrphanedQueuedJobs();

        $audit = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_JOB_RECLAIMED, 'object_id' => $job->id])
            ->one();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('starvation', (string)$audit->metadata);
        $this->assertStringContainsString('queue_timeout_seconds', (string)$audit->metadata);
    }

    public function testOrphanedQueuedSweepAppendsStderrLogLine(): void
    {
        $this->clearWorkerHeartbeats();
        $this->service->queueTimeoutSeconds = 60;

        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $job = $this->createJob($template->id, $user->id, Job::STATUS_QUEUED);
        $job->queued_at = time() - 200;
        $job->save(false);

        $this->service->reclaimOrphanedQueuedJobs();

        /** @var JobLog|null $log */
        $log = JobLog::find()
            ->where(['job_id' => $job->id, 'stream' => JobLog::STREAM_STDERR])
            ->one();
        $this->assertNotNull($log);
        $this->assertStringContainsString('queue-starvation', $log->content);
    }

    public function testOrphanedQueuedSweepDoesNotOverwriteJobThatJustGotPickedUp(): void
    {
        $this->clearWorkerHeartbeats();
        $this->service->queueTimeoutSeconds = 60;

        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $job = $this->createJob($template->id, $user->id, Job::STATUS_QUEUED);
        $job->queued_at = time() - 200;
        $job->save(false);

        // Race: a runner claimed the job between our SELECT and UPDATE.
        Job::updateAll(
            ['status' => Job::STATUS_RUNNING, 'started_at' => time()],
            ['id' => $job->id]
        );

        $this->assertSame(0, $this->service->reclaimOrphanedQueuedJobs());
        $job->refresh();
        $this->assertSame(Job::STATUS_RUNNING, $job->status);
    }

    public function testOrphanedQueuedSweepFailsJobWithMissingTemplate(): void
    {
        // Job whose template was deleted (template_id nulled by FK cascade).
        // Cannot resolve a runner group at all → orphaned by definition.
        $this->clearWorkerHeartbeats();
        $this->service->queueTimeoutSeconds = 60;

        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $job = $this->createJob($template->id, $user->id, Job::STATUS_QUEUED);
        $job->queued_at = time() - 200;
        $job->job_template_id = null;
        $job->save(false);

        $this->assertSame(1, $this->service->reclaimOrphanedQueuedJobs());
        $job->refresh();
        $this->assertSame(Job::STATUS_FAILED, $job->status);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array{0: RunnerGroup, 1: Runner, 2: Job}
     */
    private function makeRunningJob(): array
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $runner = $this->createRunner($group->id, $user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $job = $this->createJob($template->id, $user->id, Job::STATUS_RUNNING);
        $job->runner_id = $runner->id;
        $job->started_at = time();
        $job->last_progress_at = time();
        $job->save(false);

        return [$group, $runner, $job];
    }

    private function markJobStale(Job $job, int $secondsAgo): void
    {
        $job->last_progress_at = time() - $secondsAgo;
        $job->save(false);
    }

    private function markRunnerOffline(Runner $runner): void
    {
        $runner->last_seen_at = time() - (RunnerGroup::STALE_AFTER + 60);
        $runner->save(false);
    }
}
