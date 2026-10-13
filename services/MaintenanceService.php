<?php

declare(strict_types=1);

namespace app\services;

use app\components\PendingMigrations;
use app\components\SurveySecretBackfill;
use app\models\Project;
use app\models\ProjectSyncLog;
use yii\base\Component;
use yii\caching\CacheInterface;

/**
 * Throttled, idempotent maintenance dispatcher.
 *
 * The schedule-runner container invokes `php yii maintenance/run` once a
 * minute. That cadence is right for cheap things like checking whether the
 * artifact retention sweep is due, but the sweeps themselves should run far
 * less frequently (hourly or daily).
 *
 * To bridge that gap, every task here is gated behind a Redis-backed cooldown
 * key. A task only runs when its cooldown has expired. After a successful run
 * the cooldown is re-armed using {@see \yii\caching\CacheInterface::add()},
 * which has SETNX semantics — so two schedule-runner instances launched
 * accidentally in parallel cannot double-trigger a sweep.
 *
 * Artifact cleanup follows that pattern, and so does the sweep of deleted
 * templates: the active runs of deleted workflow templates are canceled
 * ({@see WorkflowTemplateDeletionService::sweep()}), then the stranded jobs
 * (waiting jobs whose workflow run has ended, jobs that wait for an approval
 * request that does not exist, and waiting jobs whose job template was purged
 * with its project) and the steps still running in runs that ended
 * ({@see StrandedJobSweep::sweep()}), then the waiting jobs of
 * deleted job templates, whose schedules are disabled
 * ({@see JobTemplateDeletionService::sweep()}); new periodic tasks should
 * follow it too (config-driven interval, separate cache key, structured
 * result). Three tasks run on every tick instead: the stale-sync sweep; the
 * approval timeouts, which apply each expired approval request's timeout
 * action ({@see ApprovalService::processTimeouts()}); and the encryption of
 * survey password answers that finished jobs stored in plaintext before
 * 2.9, which works through the job history a few seconds per tick until it
 * is done ({@see SurveySecretBackfill}).
 *
 * The approval timeouts and the deleted-template sweep change jobs, approval
 * requests and workflow runs, so they wait while database migrations are
 * pending: in the prebuilt setup the schedule runner starts while the app
 * container still migrates, and a bind-mounted checkout runs new code right
 * after `git pull`. They share one time budget per tick
 * ({@see $jobTaskBudgetSeconds}): what they change sends notifications,
 * synchronously, and a timeout can move a workflow run on to its next
 * steps. An error in either is logged (its class, never its message, which
 * can carry SQL with values). The approval timeouts are retried on the next
 * tick; the sweep is retried when its interval
 * ({@see $deletedTemplateSweepIntervalSeconds}) has passed, because its
 * cooldown is taken before it starts and is kept after an error: the
 * cooldown is the backoff for an error that repeats.
 */
class MaintenanceService extends Component
{
    /** The cache key that marks the survey password scrub as done. */
    public const SURVEY_SECRET_SCRUB_DONE_KEY = 'maintenance:survey-secret-scrub:done';

    /** The cache key of the survey password scrub's position. */
    public const SURVEY_SECRET_SCRUB_CURSOR_KEY = 'maintenance:survey-secret-scrub:cursor';

    /** The cooldown key of the sweep of deleted job and workflow templates. */
    public const DELETED_TEMPLATE_SWEEP_KEY = 'maintenance:deleted-template-sweep';

    public const TASK_APPROVAL_TIMEOUTS = 'approval-timeouts';
    public const TASK_DELETED_TEMPLATE_SWEEP = 'deleted-template-sweep';

    /** Skip reasons of the two tasks above (besides 'disabled' and 'cooldown'). */
    public const SKIP_MIGRATIONS_PENDING = 'migrations-pending';
    public const SKIP_ERROR = 'error';
    public const SKIP_NONE_EXPIRED = 'none-expired';
    public const SKIP_NONE_FOUND = 'none-found';

    /**
     * Expired approval requests one tick times out at most, oldest expiry
     * first; the rest follow on the next ticks. The batch bounds the memory
     * and the work of a tick; {@see $jobTaskBudgetSeconds} bounds its time.
     */
    public const APPROVAL_TIMEOUT_BATCH_SIZE = 100;

    /** Logged when a task fails; sprintf(): what failed, the exception class. */
    public const TASK_FAILED = 'MaintenanceService: %s stopped after an error (%s); the next run retries.';

    /**
     * Interval in seconds between artifact-cleanup runs. 0 disables the
     * scheduled sweep — in that case operators must run `php yii artifact/cleanup`
     * manually (or via their own cron).
     *
     * @var int
     */
    public int $artifactCleanupIntervalSeconds = 86400; // daily

    /**
     * Maximum time a project may stay in STATUS_SYNCING before the sweeper
     * declares it dead and flips it to STATUS_ERROR. Five minutes of git
     * timeout + a comfortable buffer.
     */
    public int $staleSyncThresholdSeconds = 900; // 15 minutes

    /**
     * Seconds per tick the encryption of stored survey password answers of
     * finished jobs may take (a page of rows that started is finished); 0
     * disables it. The schedule-runner's health check allows a loop of up to
     * 150 seconds.
     */
    public float $surveySecretScrubBudgetSeconds = 5.0;

    /**
     * Seconds between two sweeps of deleted job and workflow templates and
     * stranded jobs; 0 disables it. A sweep that leaves waiting jobs or
     * active runs behind (more than one batch, its time budget used up, or
     * cancels that failed) is followed on the next tick.
     */
    public int $deletedTemplateSweepIntervalSeconds = 600;

    /**
     * Seconds per tick for the tasks that change jobs: the approval timeouts
     * and the sweep share one deadline, taken before the approval timeouts.
     * Each of their loops starts no new item (request, run, job or step) once
     * it has passed, finishes the item it started, and leaves the rest to the
     * next ticks: the timeouts run on every tick, and a sweep with work left
     * lets its cooldown go. 0 (or less) removes the bound.
     *
     * The batch sizes bound the memory and the work of a tick; this bounds
     * its time, which notification endpoints stretch: notifications are sent
     * synchronously, and an HTTP channel waits up to 10 seconds for an
     * endpoint that does not answer. The schedule-runner loop sleeps 60
     * seconds between ticks, and its health check fails once the loop has
     * not come round for 150 seconds: the default leaves 45 seconds of that
     * window for the rest of the loop, such as schedule/run, the survey
     * password scrub (5 seconds) and the item that was running when the
     * budget ran out.
     */
    public float $jobTaskBudgetSeconds = 45.0;

    /**
     * Run every task whose cooldown has expired and report what happened.
     *
     * The shape is intentionally explicit so the console command and tests
     * can present the results without re-querying anything.
     *
     * @return array{
     *     ran: list<string>,
     *     skipped: list<array{task: string, reason: string}>,
     *     results: array<string, array<string, int>>,
     * }
     */
    public function runIfDue(): array
    {
        $report = ['ran' => [], 'skipped' => [], 'results' => []];

        $artifact = $this->maybeRunArtifactCleanup();
        if ($artifact['ran']) {
            $report['ran'][] = 'artifact-cleanup';
            $report['results']['artifact-cleanup'] = $artifact['result'];
        } else {
            $report['skipped'][] = ['task' => 'artifact-cleanup', 'reason' => $artifact['reason']];
        }

        // Stale-sync sweeping runs on every tick (no cooldown): the query is
        // a single indexed SELECT and the typical result set is empty, so
        // gating it would only delay recovery without saving real work.
        $stale = $this->runStaleSyncSweep();
        if ($stale['recovered'] > 0) {
            $report['ran'][] = 'stale-sync-sweep';
            $report['results']['stale-sync-sweep'] = $stale;
        } else {
            $report['skipped'][] = ['task' => 'stale-sync-sweep', 'reason' => 'none-stale'];
        }

        $blocked = $this->migrationBlock();
        // One deadline for both tasks that change jobs, taken before the first.
        $deadline = $this->jobTaskBudgetSeconds > 0 ? microtime(true) + $this->jobTaskBudgetSeconds : null;
        $report = self::withTask($report, self::TASK_APPROVAL_TIMEOUTS, $this->runApprovalTimeouts($blocked, $deadline));
        $report = self::withTask($report, self::TASK_DELETED_TEMPLATE_SWEEP, $this->maybeRunDeletedTemplateSweep($blocked, $deadline));

        $scrub = $this->maybeRunSurveySecretScrub();
        if ($scrub['ran']) {
            $report['ran'][] = 'survey-secret-scrub';
            $report['results']['survey-secret-scrub'] = $scrub['result'];
        } else {
            $report['skipped'][] = ['task' => 'survey-secret-scrub', 'reason' => $scrub['reason']];
        }

        return $report;
    }

    /**
     * $report with the outcome of $task: ran, with its result, or skipped,
     * with the reason.
     *
     * @param array{ran: list<string>, skipped: list<array{task: string, reason: string}>, results: array<string, array<string, int>>} $report
     * @param array{ran: bool, reason: string, result: array<string, int>} $outcome
     * @return array{ran: list<string>, skipped: list<array{task: string, reason: string}>, results: array<string, array<string, int>>}
     */
    private static function withTask(array $report, string $task, array $outcome): array
    {
        if ($outcome['ran']) {
            $report['ran'][] = $task;
            $report['results'][$task] = $outcome['result'];
        } else {
            $report['skipped'][] = ['task' => $task, 'reason' => $outcome['reason']];
        }

        return $report;
    }

    /**
     * Why the tasks that change jobs and approval requests must wait this
     * tick: SKIP_MIGRATIONS_PENDING while the database lags behind the
     * code, SKIP_ERROR when the migration state cannot be read; null when
     * they may run.
     */
    private function migrationBlock(): ?string
    {
        try {
            return $this->pendingMigrations()->count() > 0 ? self::SKIP_MIGRATIONS_PENDING : null;
        } catch (\Throwable $e) {
            self::logFailure('reading the migration state', $e);

            return self::SKIP_ERROR;
        }
    }

    /**
     * Apply the timeout action of expired approval requests, at most
     * APPROVAL_TIMEOUT_BATCH_SIZE per tick and none started after $deadline;
     * the result counts the requests resolved (timed out, or closed because
     * their job no longer waited). Runs on every tick: the query is an
     * indexed SELECT that usually finds nothing, and a delay holds jobs and
     * workflow runs back.
     *
     * @param string|null $blocked migrationBlock()
     * @param float|null $deadline the tick's deadline (microtime(true)); null: no bound
     * @return array{ran: bool, reason: string, result: array<string, int>}
     */
    private function runApprovalTimeouts(?string $blocked, ?float $deadline): array
    {
        if ($blocked !== null) {
            return ['ran' => false, 'reason' => $blocked, 'result' => []];
        }
        try {
            /** @var ApprovalService $approvals */
            $approvals = \Yii::$app->get('approvalService');
            $resolved = $approvals->processTimeouts(self::APPROVAL_TIMEOUT_BATCH_SIZE, $deadline);
        } catch (\Throwable $e) {
            self::logFailure('applying approval timeouts', $e);

            return ['ran' => false, 'reason' => self::SKIP_ERROR, 'result' => []];
        }

        return $resolved > 0
            ? ['ran' => true, 'reason' => 'due', 'result' => ['resolved' => $resolved]]
            : ['ran' => false, 'reason' => self::SKIP_NONE_EXPIRED, 'result' => []];
    }

    /**
     * Once per cooldown, until $deadline: cancel the active runs of deleted
     * workflow templates (WorkflowTemplateDeletionService::sweep()), the
     * stranded jobs (StrandedJobSweep::sweep()), and the waiting jobs of
     * deleted job templates, whose enabled schedules are disabled
     * (JobTemplateDeletionService::sweep()). When jobs or runs are left for
     * the next batch, the cooldown is let go so the next tick goes on.
     *
     * The result line keeps the keys of the job templates, then of the runs,
     * then of the stranded jobs.
     *
     * @param string|null $blocked migrationBlock()
     * @param float|null $deadline the tick's deadline (microtime(true)); null: no bound
     * @return array{ran: bool, reason: string, result: array<string, int>}
     */
    private function maybeRunDeletedTemplateSweep(?string $blocked, ?float $deadline): array
    {
        if ($this->deletedTemplateSweepIntervalSeconds <= 0) {
            return ['ran' => false, 'reason' => 'disabled', 'result' => []];
        }
        if ($blocked !== null) {
            return ['ran' => false, 'reason' => $blocked, 'result' => []];
        }
        if (!$this->acquireCooldown(self::DELETED_TEMPLATE_SWEEP_KEY, $this->deletedTemplateSweepIntervalSeconds)) {
            return ['ran' => false, 'reason' => 'cooldown', 'result' => []];
        }
        try {
            // The runs first, so that none of them goes on to its next step
            // when a later sweep cancels the job it waits for. The stranded
            // jobs next: the jobs of runs that have ended are canceled with
            // their run's reason and no notification of their own, before
            // the job sweep would cancel those of deleted job templates as
            // ordinary jobs.
            /** @var WorkflowTemplateDeletionService $workflows */
            $workflows = \Yii::$app->get('workflowTemplateDeletionService');
            $runs = $workflows->sweep(WorkflowTemplateDeletionService::SWEEP_BATCH_SIZE, $deadline);
            /** @var StrandedJobSweep $strandedJobs */
            $strandedJobs = \Yii::$app->get('strandedJobSweep');
            $stranded = $strandedJobs->sweep(StrandedJobSweep::BATCH_SIZE, $deadline);
            /** @var JobTemplateDeletionService $templates */
            $templates = \Yii::$app->get('jobTemplateDeletionService');
            $result = $templates->sweep(JobTemplateDeletionService::SWEEP_BATCH_SIZE, $deadline) + $runs + $stranded;
        } catch (\Throwable $e) {
            self::logFailure('the sweep of deleted job and workflow templates', $e);

            return ['ran' => false, 'reason' => self::SKIP_ERROR, 'result' => []];
        }
        if ($result['remaining_jobs'] + $result['remaining_runs'] + $result['stranded_jobs_remaining'] > 0) {
            /** @var CacheInterface $cache */
            $cache = \Yii::$app->cache;
            $cache->delete(self::DELETED_TEMPLATE_SWEEP_KEY);
        }

        // Jobs and runs left over (a full batch, the deadline, or cancels that failed) are reported too.
        return array_sum($result) > 0
            ? ['ran' => true, 'reason' => 'due', 'result' => $result]
            : ['ran' => false, 'reason' => self::SKIP_NONE_FOUND, 'result' => []];
    }

    private static function logFailure(string $what, \Throwable $e): void
    {
        \Yii::warning(sprintf(self::TASK_FAILED, $what, get_class($e)), __CLASS__);
    }

    /**
     * The migration state checked before the tasks that change jobs and
     * approval requests.
     */
    protected function pendingMigrations(): PendingMigrations
    {
        return new PendingMigrations();
    }

    /**
     * Forget the survey password scrub's position and done mark, so the
     * next ticks check the whole job history again. Migration m000081 calls
     * this: when it runs, the data may be from before 2.9 (an update, or a
     * restored backup), whatever the cache says. Operators run it through
     * `php yii maintenance/reset-survey-secret-scrub` after restoring a
     * backup taken after the update started, for which m000081 does not run
     * again. Only these two cache keys are deleted.
     */
    public function resetSurveySecretScrub(): void
    {
        /** @var CacheInterface $cache */
        $cache = \Yii::$app->cache;
        $cache->delete(self::SURVEY_SECRET_SCRUB_DONE_KEY);
        $cache->delete(self::SURVEY_SECRET_SCRUB_CURSOR_KEY);
    }

    /**
     * Encrypt the stored survey password answers of finished jobs, from
     * where the previous tick stopped until the budget is used up. The
     * position and the done mark live in the cache: when they are lost the
     * scrub starts over, which is safe because it skips rows that are done.
     *
     * It reports as run only when it checked rows; a tick that only found it
     * done, blocked (pending migrations, no usable APP_SECRET_KEY) or failed
     * before the first row is skipped, with that reason.
     *
     * @return array{ran: bool, reason: string, result: array<string, int>}
     */
    private function maybeRunSurveySecretScrub(): array
    {
        if ($this->surveySecretScrubBudgetSeconds <= 0) {
            return ['ran' => false, 'reason' => 'disabled', 'result' => []];
        }
        /** @var CacheInterface $cache */
        $cache = \Yii::$app->cache;
        if ($cache->get(self::SURVEY_SECRET_SCRUB_DONE_KEY) !== false) {
            return ['ran' => false, 'reason' => SurveySecretBackfill::STATUS_DONE, 'result' => []];
        }

        $cursor = SurveySecretBackfill::cursorFrom($cache->get(self::SURVEY_SECRET_SCRUB_CURSOR_KEY));
        $slice = $this->surveySecretBackfill()->scrubFinishedJobs($cursor, $this->surveySecretScrubBudgetSeconds);
        $this->storeSurveySecretScrubState($cache, $slice['status'], $slice['cursor']);

        $result = $slice['counts'] + ['done' => $slice['status'] === SurveySecretBackfill::STATUS_DONE ? 1 : 0];

        return ['ran' => $slice['counts']['checked'] > 0, 'reason' => $slice['status'], 'result' => $result];
    }

    /**
     * @param array{template: int, job: int} $cursor
     */
    private function storeSurveySecretScrubState(CacheInterface $cache, string $status, array $cursor): void
    {
        if ($status === SurveySecretBackfill::STATUS_DONE) {
            $cache->set(self::SURVEY_SECRET_SCRUB_DONE_KEY, time(), 0);
            $cache->delete(self::SURVEY_SECRET_SCRUB_CURSOR_KEY);
            return;
        }
        if ($status === SurveySecretBackfill::STATUS_PARTIAL || $status === SurveySecretBackfill::STATUS_ERROR) {
            $cache->set(self::SURVEY_SECRET_SCRUB_CURSOR_KEY, $cursor, 0);
        }
        if ($status === SurveySecretBackfill::STATUS_ERROR) {
            \Yii::warning('MaintenanceService: the encryption of stored survey password answers stopped after an error; the next run continues.', __CLASS__);
        }
    }

    /**
     * The backfill the scrub runs; its report lines go to the application
     * log (counts and IDs only).
     */
    protected function surveySecretBackfill(): SurveySecretBackfill
    {
        return new SurveySecretBackfill(\Yii::$app->db, static function (string $line): void {
            \Yii::info('survey-secret-scrub: ' . $line, MaintenanceService::class);
        });
    }

    /**
     * Find Projects in STATUS_SYNCING that haven't progressed within the
     * threshold and mark them as ERROR with a clear last_sync_error and a
     * `system`-stream entry in project_sync_log.
     *
     * Triggered on every maintenance tick; the threshold lives in
     * {@see $staleSyncThresholdSeconds}. Returns the number of projects
     * recovered so the dispatcher can surface it in the report.
     *
     * @return array{recovered: int, threshold_seconds: int}
     */
    public function runStaleSyncSweep(): array
    {
        $threshold = $this->staleSyncThresholdSeconds;
        if ($threshold <= 0) {
            return ['recovered' => 0, 'threshold_seconds' => 0];
        }

        $cutoff = time() - $threshold;
        $stale = Project::find()
            ->where(['status' => Project::STATUS_SYNCING])
            ->andWhere(['<', 'sync_started_at', $cutoff])
            ->all();

        $recovered = 0;
        foreach ($stale as $project) {
            /** @var Project $project */
            $startedAt = (int)$project->sync_started_at;
            $message = sprintf(
                'Sweeper recovered stuck sync after %d seconds (threshold=%d).',
                time() - $startedAt,
                $threshold,
            );

            $project->status = Project::STATUS_ERROR;
            $project->last_sync_error = $message;
            $project->sync_started_at = null;
            $project->save(false);

            $this->appendSystemLog($project, $message . "\n");
            \Yii::warning(
                "MaintenanceService: stale sync swept for project #{$project->id} ({$project->name}): {$message}",
                __CLASS__,
            );
            $recovered++;
        }

        return ['recovered' => $recovered, 'threshold_seconds' => $threshold];
    }

    private function appendSystemLog(Project $project, string $content): void
    {
        $log = new ProjectSyncLog();
        $log->project_id = $project->id;
        $log->stream = ProjectSyncLog::STREAM_SYSTEM;
        $log->content = $content;
        $log->sequence = (int)ProjectSyncLog::find()->where(['project_id' => $project->id])->max('sequence') + 1;
        $log->created_at = time();
        $log->save(false);
    }

    /**
     * @return array{
     *     ran: bool,
     *     reason: string,
     *     result: array{expired: int, by_count: int, quota_trimmed: int, orphans: int},
     * }
     */
    private function maybeRunArtifactCleanup(): array
    {
        $empty = ['expired' => 0, 'by_count' => 0, 'quota_trimmed' => 0, 'orphans' => 0];

        if ($this->artifactCleanupIntervalSeconds <= 0) {
            return ['ran' => false, 'reason' => 'disabled', 'result' => $empty];
        }

        if (!$this->acquireCooldown('maintenance:artifact-cleanup', $this->artifactCleanupIntervalSeconds)) {
            return ['ran' => false, 'reason' => 'cooldown', 'result' => $empty];
        }

        /** @var ArtifactService $svc */
        $svc = \Yii::$app->get('artifactService');
        $expired = $svc->retentionDays > 0 ? $svc->deleteExpiredArtifacts() : 0;
        $byCount = $svc->maxJobsWithArtifacts > 0 ? $svc->deleteByJobCount() : 0;
        $quotaTrimmed = $svc->maxTotalBytes > 0 ? $svc->trimToTotalBytes() : 0;
        $orphans = $svc->cleanupOrphans();

        \Yii::info(
            "MaintenanceService: artifact cleanup ran (expired={$expired}, by_count={$byCount}, quota_trimmed={$quotaTrimmed}, orphans={$orphans})",
            __CLASS__
        );

        return [
            'ran' => true,
            'reason' => 'due',
            'result' => [
                'expired' => $expired,
                'by_count' => $byCount,
                'quota_trimmed' => $quotaTrimmed,
                'orphans' => $orphans,
            ],
        ];
    }

    /**
     * Try to claim a cooldown slot. Returns true if the caller now owns it
     * for the given TTL, false if another caller already holds it.
     *
     * Uses {@see \yii\caching\CacheInterface::add()}, which is atomic SETNX
     * semantics on Redis and a no-op overwrite on ArrayCache (used by tests).
     */
    protected function acquireCooldown(string $key, int $ttlSeconds): bool
    {
        /** @var \yii\caching\CacheInterface $cache */
        $cache = \Yii::$app->cache;
        return $cache->add($key, time(), $ttlSeconds);
    }
}
