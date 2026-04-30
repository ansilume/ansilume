<?php

declare(strict_types=1);

namespace app\components;

use app\helpers\AppVersion;

/**
 * Manages worker heartbeat records in Redis.
 *
 * Each worker process registers itself with a unique key and refreshes
 * the timestamp every HEARTBEAT_INTERVAL seconds. The web health endpoint
 * reads these keys to report which workers are alive.
 *
 * Key schema:  ansilume:worker:{workerId}  →  JSON {pid, hostname, started_at, seen_at, app_version}
 * TTL:         STALE_AFTER seconds (auto-expiry if worker dies without deregistering)
 *
 * `app_version` is captured once at construct time so a worker that
 * started against an older code revision keeps the OLD version stamp
 * even after the on-disk VERSION file is bumped — which is exactly how
 * the "stale code" banner detects a worker that didn't restart after a
 * deploy.
 */
class WorkerHeartbeat
{
    public const HEARTBEAT_INTERVAL = 30; // seconds between refreshes
    public const STALE_AFTER = 120; // Redis TTL — worker considered dead after this

    private string $workerId;
    private int $startedAt;
    private string $appVersion;
    /** Time the worker last finished processing a real job. Null until the
     *  first job completes. Updated by WorkerController on EVENT_AFTER_EXEC. */
    private ?int $lastJobProcessedAt = null;
    private \Redis $redis;

    public function __construct()
    {
        $this->workerId = gethostname() . ':' . getmypid();
        $this->startedAt = time();
        $this->appVersion = AppVersion::current();
        $this->redis = $this->connectRedis();
    }

    /**
     * Stamp that a real job just finished. Snapshots use the gap between
     * this and `now` to detect "process is alive but BRPOP loop has gone
     * stale and isn't returning new jobs" — heartbeat alone can't see that
     * because it runs on a separate Redis connection (via SIGALRM).
     */
    public function markJobProcessed(): void
    {
        $this->lastJobProcessedAt = time();
        // Mirror to the persisted blob so a subsequent snapshot reflects
        // the freshly stamped value even before the next 30s heartbeat tick.
        $this->write();
    }

    /**
     * Register this worker and write first heartbeat.
     */
    public function register(): void
    {
        $this->write();
    }

    /**
     * Update the heartbeat timestamp.
     */
    public function refresh(): void
    {
        $this->write();
    }

    /**
     * Remove this worker's key on clean shutdown.
     */
    public function deregister(): void
    {
        try {
            $this->redis->del($this->key());
        } catch (\Throwable) {
            // Best-effort
        }
    }

    /**
     * Fetch all live worker records from Redis.
     *
     * @return array<int, array{worker_id: string, pid: int, hostname: string, started_at: int, seen_at: int, app_version?: string, last_job_processed_at?: int|null}>
     */
    public static function all(): array
    {
        try {
            $redis = static::connectRedisStatic();
            $keys = $redis->keys('ansilume:worker:*');
            $cutoff = time() - 2 * self::HEARTBEAT_INTERVAL;

            $workers = [];
            foreach ($keys as $key) {
                $raw = $redis->get($key);
                $data = ($raw !== false && is_string($raw)) ? json_decode($raw, true) : null;
                if (is_array($data) && (int)($data['seen_at'] ?? 0) >= $cutoff) {
                    /** @var array{worker_id: string, pid: int, hostname: string, started_at: int, seen_at: int, app_version?: string, last_job_processed_at?: int|null} $data */
                    $workers[] = $data;
                }
            }
            return $workers;
        } catch (\Throwable) {
            return [];
        }
    }

    private function write(): void
    {
        $data = json_encode([
            'worker_id' => $this->workerId,
            'pid' => getmypid(),
            'hostname' => gethostname(),
            'started_at' => $this->startedAt,
            'seen_at' => time(),
            'app_version' => $this->appVersion,
            'last_job_processed_at' => $this->lastJobProcessedAt,
        ]);

        try {
            $this->redis->setex($this->key(), self::STALE_AFTER, $data);
        } catch (\Throwable $e) {
            \Yii::warning('WorkerHeartbeat: failed to write: ' . $e->getMessage(), __CLASS__);
        }
    }

    private function key(): string
    {
        return 'ansilume:worker:' . $this->workerId;
    }

    private function connectRedis(): \Redis
    {
        return static::connectRedisStatic();
    }

    protected static function connectRedisStatic(): \Redis
    {
        $r = new \Redis();
        $r->connect(
            $_ENV['REDIS_HOST'] ?? 'redis',
            (int)($_ENV['REDIS_PORT'] ?? 6379)
        );
        $db = (int)($_ENV['REDIS_DB'] ?? 0);
        if ($db !== 0) {
            $r->select($db);
        }
        return $r;
    }
}
