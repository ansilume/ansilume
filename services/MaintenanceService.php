<?php

declare(strict_types=1);

namespace app\services;

use yii\base\Component;

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
 * Currently only artifact cleanup is wired up; new tasks should follow the
 * same pattern (config-driven interval, separate cache key, structured result).
 */
class MaintenanceService extends Component
{
    /**
     * Interval in seconds between artifact-cleanup runs. 0 disables the
     * scheduled sweep — in that case operators must run `php yii artifact/cleanup`
     * manually (or via their own cron).
     *
     * @var int
     */
    public int $artifactCleanupIntervalSeconds = 86400; // daily

    /**
     * Run every task whose cooldown has expired and report what happened.
     *
     * The shape is intentionally explicit so the console command and tests
     * can present the results without re-querying anything.
     *
     * @return array{
     *     ran: list<string>,
     *     skipped: list<array{task: string, reason: string}>,
     *     results: array<string, array{expired: int, by_count: int, quota_trimmed: int, orphans: int}>,
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

        return $report;
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
