<?php

declare(strict_types=1);

namespace app\services;

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
 * Artifact cleanup follows that pattern; new periodic tasks should too
 * (config-driven interval, separate cache key, structured result). Two tasks
 * run on every tick instead: the stale-sync sweep, and the encryption of
 * survey password answers that finished jobs stored in plaintext before
 * 2.9, which works through the job history a few seconds per tick until it
 * is done ({@see SurveySecretBackfill}).
 */
class MaintenanceService extends Component
{
    /** The cache key that marks the survey password scrub as done. */
    public const SURVEY_SECRET_SCRUB_DONE_KEY = 'maintenance:survey-secret-scrub:done';

    /** The cache key of the survey password scrub's position. */
    public const SURVEY_SECRET_SCRUB_CURSOR_KEY = 'maintenance:survey-secret-scrub:cursor';

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
