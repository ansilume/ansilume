<?php

declare(strict_types=1);

namespace app\services;

use app\components\WorkerHeartbeat;
use app\models\Job;
use app\models\JobLog;
use app\models\JobTemplate;
use app\models\Runner;
use app\models\RunnerGroup;
use yii\base\Component;

/**
 * Detects and reclaims jobs that were left in STATUS_RUNNING by a runner that
 * died, crashed, lost network, or otherwise stopped reporting progress.
 *
 * A job is considered stale when:
 *   1. status = running, AND
 *   2. last_progress_at is older than `progressTimeoutSeconds`, AND
 *   3. either the runner is offline (last_seen_at older than RunnerGroup::STALE_AFTER)
 *      or the runner record is gone entirely.
 *
 * The runner-online check matters: a slow-but-alive playbook (e.g. a long
 * `wait_for` task) should not be killed off just because it produces no log
 * output. Only when the runner itself is silent do we declare the job dead.
 *
 * Reclaim outcome is controlled by `mode`:
 *   - MODE_FAIL (default): transition to STATUS_FAILED with a clear stderr line.
 *   - MODE_REQUEUE: if attempt_count < max_attempts, push the job back to
 *     STATUS_QUEUED, clear runner_id and timing, increment attempt_count, and
 *     emit a stderr breadcrumb. Once max_attempts is reached, fall back to
 *     FAIL so an operator notices instead of a job looping forever.
 *
 * Both paths write an audit entry so operators can trace exactly why the
 * status moved.
 */
class JobReclaimService extends Component
{
    public const MODE_FAIL = 'fail';
    public const MODE_REQUEUE = 'requeue';

    /**
     * Seconds without progress before a job becomes a reclaim candidate.
     * Default 600s (10 min) — long enough to outlast normal Ansible work,
     * short enough that a hung runner is detected within a useful window.
     */
    public int $progressTimeoutSeconds = 600;

    /**
     * Reclaim policy. MODE_FAIL preserves historical behavior (one-shot jobs
     * that go straight to failed). MODE_REQUEUE retries up to job.max_attempts
     * times before falling back to fail.
     */
    public string $mode = self::MODE_FAIL;

    /**
     * Seconds a job may sit in STATUS_QUEUED before it becomes a starvation
     * candidate. A queued job is only reclaimed by reclaimOrphanedQueuedJobs()
     * if NO live Yii-queue worker exists AND its target runner group has zero
     * online runners — i.e. nothing on the system can ever pick it up.
     *
     * Default 1800s (30 min). Operators wanting a tighter SLA can lower it,
     * but the floor should comfortably exceed worst-case worker restart time.
     */
    public int $queueTimeoutSeconds = 1800;

    /**
     * Optional override for the live-worker probe. When null, the sweep calls
     * {@see WorkerHeartbeat::all()} directly. Tests inject a closure to bypass
     * the real Redis state, since dev/CI environments may host a real
     * queue-worker container that keeps refreshing its heartbeat.
     *
     * @var (callable(): bool)|null
     */
    public $liveWorkerProbe = null;

    /**
     * Find and reclaim all currently-stale running jobs.
     *
     * @return int Number of jobs reclaimed.
     */
    public function reclaimStaleJobs(): int
    {
        $cutoff = time() - $this->progressTimeoutSeconds;
        $reclaimed = 0;

        /** @var Job[] $candidates */
        $candidates = Job::find()
            ->where(['status' => Job::STATUS_RUNNING])
            ->andWhere(['<', 'last_progress_at', $cutoff])
            ->all();

        foreach ($candidates as $job) {
            if (!$this->shouldReclaim($job)) {
                continue;
            }

            if ($this->reclaim($job)) {
                $reclaimed++;
            }
        }

        return $reclaimed;
    }

    /**
     * Detect jobs stuck in STATUS_QUEUED that no executor can ever pick up:
     * the Yii-queue heartbeat set is empty AND the job's target runner group
     * has zero online runners. These would otherwise sit forever, hidden
     * from the existing stale-RUNNING sweep.
     *
     * Conservative: a queued job with at least one live worker OR one online
     * runner is left alone, since the wait may be legitimate (busy fleet,
     * RBAC-restricted runner, etc.).
     *
     * @return int Number of orphaned queued jobs failed.
     */
    public function reclaimOrphanedQueuedJobs(): int
    {
        $cutoff = time() - $this->queueTimeoutSeconds;
        $reclaimed = 0;

        $hasLiveWorker = $this->liveWorkerProbe !== null
            ? (bool)($this->liveWorkerProbe)()
            : !empty(WorkerHeartbeat::all());

        /** @var Job[] $candidates */
        $candidates = Job::find()
            ->where(['status' => Job::STATUS_QUEUED])
            ->andWhere(['<', 'queued_at', $cutoff])
            ->all();

        foreach ($candidates as $job) {
            if (!$this->isQueuedJobOrphaned($job, $hasLiveWorker)) {
                continue;
            }

            if ($this->failOrphanedQueuedJob($job, $hasLiveWorker)) {
                $reclaimed++;
            }
        }

        return $reclaimed;
    }

    /**
     * Decide if a stuck QUEUED job can never be picked up.
     * If a live worker exists OR the target runner group has any online
     * runner, leave it alone — the wait is legitimate.
     */
    private function isQueuedJobOrphaned(Job $job, bool $hasLiveWorker): bool
    {
        if ($hasLiveWorker) {
            return false;
        }

        if ($job->job_template_id === null) {
            // No template means no runner group binding — only the Yii queue
            // could ever execute this. With no live worker, it's orphaned.
            return true;
        }

        /** @var JobTemplate|null $template */
        $template = JobTemplate::findOne($job->job_template_id);
        if ($template === null || $template->runner_group_id === null) {
            return true;
        }

        /** @var RunnerGroup|null $group */
        $group = RunnerGroup::findOne($template->runner_group_id);
        if ($group === null) {
            return true;
        }

        return $group->countOnline() === 0;
    }

    /**
     * Atomic FAIL transition for an orphaned queued job. Optimistic on
     * (id, status=queued) so a worker that just woke up and claimed the job
     * does not get overwritten.
     */
    private function failOrphanedQueuedJob(Job $job, bool $hasLiveWorker): bool
    {
        $now = time();
        $reason = $hasLiveWorker
            ? 'Queued for too long with no online runner in target group; failed by queue-starvation sweep.'
            : 'Queued for too long with no live Yii-queue worker and no online runner; failed by queue-starvation sweep.';

        $rows = Job::updateAll(
            [
                'status' => Job::STATUS_FAILED,
                'finished_at' => $now,
                'exit_code' => -1,
                'updated_at' => $now,
            ],
            ['id' => $job->id, 'status' => Job::STATUS_QUEUED]
        );

        if ($rows !== 1) {
            return false;
        }

        $this->appendStderr($job, $reason);

        \Yii::$app->get('auditService')->log(
            AuditService::ACTION_JOB_RECLAIMED,
            'job',
            $job->id,
            null,
            [
                'reason' => $reason,
                'queued_at' => $job->queued_at,
                'queue_timeout_seconds' => $this->queueTimeoutSeconds,
                'starvation' => true,
            ]
        );

        \Yii::warning(
            "Failed orphaned queued job #{$job->id}: {$reason}",
            __CLASS__
        );

        return true;
    }

    /**
     * A job is reclaimable when its assigned runner is offline.
     * If runner_id is null — either set so by the FK cascade after the runner
     * was deleted, or by data corruption — there is no producer that could
     * ever finish the job, so reclaim it unconditionally.
     */
    private function shouldReclaim(Job $job): bool
    {
        if ($job->runner_id === null) {
            return true;
        }

        // FK is ON DELETE SET NULL, so a non-null runner_id always resolves
        // to an existing runner — no defensive null check needed here.
        /** @var Runner $runner */
        $runner = Runner::findOne($job->runner_id);
        return !$runner->isOnline();
    }

    /**
     * Decide between requeue and fail for a single stale job, then perform
     * the corresponding atomic transition. Returns true if any state change
     * happened (so the sweep counter is accurate).
     */
    private function reclaim(Job $job): bool
    {
        if ($this->mode === self::MODE_REQUEUE && $job->attempt_count < $job->max_attempts) {
            return $this->requeue($job);
        }
        return $this->fail($job);
    }

    /**
     * Atomically transition the job to FAILED and emit the reason.
     *
     * Uses an optimistic UPDATE on (id, status=running) so that if the runner
     * came back to life and completed the job in the gap between SELECT and
     * UPDATE, the reclaim becomes a no-op instead of overwriting a real result.
     */
    private function fail(Job $job): bool
    {
        $now = time();
        $reason = $this->buildFailReason($job);

        $rows = Job::updateAll(
            [
                'status' => Job::STATUS_FAILED,
                'finished_at' => $now,
                'exit_code' => -1,
                'updated_at' => $now,
            ],
            ['id' => $job->id, 'status' => Job::STATUS_RUNNING]
        );

        if ($rows !== 1) {
            return false;
        }

        $this->appendStderr($job, $reason);

        \Yii::$app->get('auditService')->log(
            AuditService::ACTION_JOB_RECLAIMED,
            'job',
            $job->id,
            null,
            [
                'reason' => $reason,
                'last_progress_at' => $job->last_progress_at,
                'runner_id' => $job->runner_id,
                'attempt_count' => $job->attempt_count,
                'max_attempts' => $job->max_attempts,
                'progress_timeout_seconds' => $this->progressTimeoutSeconds,
            ]
        );

        \Yii::warning(
            "Reclaimed stuck job #{$job->id}: {$reason}",
            __CLASS__
        );

        return true;
    }

    /**
     * Atomically push the job back to STATUS_QUEUED so a healthy runner can
     * pick it up again. attempt_count is incremented, runner_id and timing
     * fields are cleared so the next run starts cleanly.
     *
     * Same optimistic UPDATE as fail(): if the original runner reported
     * completion between SELECT and UPDATE the requeue becomes a no-op.
     */
    private function requeue(Job $job): bool
    {
        $now = time();
        $newAttempt = $job->attempt_count + 1;
        $reason = $this->buildRequeueReason($job, $newAttempt);

        $rows = Job::updateAll(
            [
                'status' => Job::STATUS_QUEUED,
                'runner_id' => null,
                'started_at' => null,
                'finished_at' => null,
                'last_progress_at' => null,
                'pid' => null,
                'worker_id' => null,
                'exit_code' => null,
                'attempt_count' => $newAttempt,
                'queued_at' => $now,
                'updated_at' => $now,
            ],
            ['id' => $job->id, 'status' => Job::STATUS_RUNNING]
        );

        if ($rows !== 1) {
            return false;
        }

        $this->appendStderr($job, $reason);

        \Yii::$app->get('auditService')->log(
            AuditService::ACTION_JOB_REQUEUED,
            'job',
            $job->id,
            null,
            [
                'reason' => $reason,
                'previous_runner_id' => $job->runner_id,
                'previous_attempt' => $job->attempt_count,
                'new_attempt' => $newAttempt,
                'max_attempts' => $job->max_attempts,
                'progress_timeout_seconds' => $this->progressTimeoutSeconds,
            ]
        );

        \Yii::warning(
            "Re-queued stuck job #{$job->id} (attempt {$newAttempt}/{$job->max_attempts}): {$reason}",
            __CLASS__
        );

        return true;
    }

    private function buildFailReason(Job $job): string
    {
        if ($job->runner_id === null) {
            return 'Job had no assigned runner; reclaimed by stale-job sweep.';
        }

        /** @var Runner $runner */
        $runner = Runner::findOne($job->runner_id);
        $silentFor = $job->last_progress_at !== null
            ? (time() - $job->last_progress_at) . 's'
            : 'unknown';
        $lastSeen = $runner->last_seen_at !== null
            ? (time() - $runner->last_seen_at) . 's ago'
            : 'never';

        return sprintf(
            'Runner "%s" stopped responding (last seen %s, last job progress %s ago); reclaimed by stale-job sweep.',
            $runner->name,
            $lastSeen,
            $silentFor
        );
    }

    private function buildRequeueReason(Job $job, int $newAttempt): string
    {
        $runnerName = '(none)';
        if ($job->runner_id !== null) {
            /** @var Runner $runner */
            $runner = Runner::findOne($job->runner_id);
            $runnerName = $runner->name;
        }

        return sprintf(
            'Runner "%s" stopped responding; re-queued for attempt %d of %d.',
            $runnerName,
            $newAttempt,
            $job->max_attempts
        );
    }

    /**
     * Append a stderr line so operators see the reason in the log viewer
     * without needing to dig through the audit table.
     */
    private function appendStderr(Job $job, string $reason): void
    {
        $maxSeq = (int)JobLog::find()
            ->where(['job_id' => $job->id])
            ->max('sequence');

        $log = new JobLog();
        $log->job_id = $job->id;
        $log->stream = JobLog::STREAM_STDERR;
        $log->content = "\n[ansilume] {$reason}\n";
        $log->sequence = $maxSeq + 1;
        $log->created_at = time();
        $log->save(false);
    }
}
