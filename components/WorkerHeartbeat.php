<?php

declare(strict_types=1);

namespace app\components;

/**
 * Manages worker heartbeat records in Redis.
 *
 * Each worker process registers itself with a unique key and refreshes
 * the timestamp every HEARTBEAT_INTERVAL seconds. The web health endpoint
 * reads these keys to report which workers are alive.
 *
 * Key schema:  ansilume:worker:{workerId}  →  JSON {pid, hostname, started_at, seen_at}
 * TTL:         STALE_AFTER seconds (auto-expiry if worker dies without deregistering)
 */
class WorkerHeartbeat
{
    public const HEARTBEAT_INTERVAL = 30;  // seconds between refreshes
    public const STALE_AFTER        = 120; // Redis TTL — worker considered dead after this

    private string $workerId;
    private int    $startedAt;
    private \Redis $redis;

    public function __construct()
    {
        $this->workerId  = gethostname() . ':' . getmypid();
        $this->startedAt = time();
        $this->redis     = $this->connectRedis();
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
     * @return array[] List of worker info arrays.
     */
    public static function all(): array
    {
        try {
            $redis  = static::connectRedisStatic();
            $keys   = $redis->keys('ansilume:worker:*');
            $cutoff = time() - 2 * self::HEARTBEAT_INTERVAL;

            $workers = [];
            foreach ($keys as $key) {
                $raw  = $redis->get($key);
                $data = $raw !== false ? json_decode($raw, true) : null;
                if ($data && ($data['seen_at'] ?? 0) >= $cutoff) {
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
            'worker_id'  => $this->workerId,
            'pid'        => getmypid(),
            'hostname'   => gethostname(),
            'started_at' => $this->startedAt,
            'seen_at'    => time(),
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

    private static function connectRedisStatic(): \Redis
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
