<?php

declare(strict_types=1);

namespace app\services;

use yii\base\Component;
use yii\redis\Cache as RedisCache;
use yii\redis\Connection as RedisConnection;

/**
 * Rate-limits TOTP verification attempts per user via cache.
 *
 * When the cache component is Redis-backed, uses atomic INCR+EXPIRE so
 * concurrent verification attempts cannot squeeze past the cap via a
 * classic read-modify-write race. For non-Redis caches (e.g. ArrayCache
 * in tests) it falls back to read-modify-write, which is safe in the
 * single-process test environment.
 */
class TotpRateLimiter extends Component
{
    /** Maximum TOTP verify attempts before lockout. */
    public int $maxAttempts = 5;

    /** Lockout duration in seconds after max attempts. */
    public int $lockoutDuration = 300;

    /**
     * Check if the user is locked out from TOTP attempts.
     */
    public function isLockedOut(int $userId): bool
    {
        return $this->getAttempts($userId) >= $this->maxAttempts;
    }

    /**
     * Record a failed TOTP attempt. Returns remaining attempts.
     */
    public function recordFailedAttempt(int $userId): int
    {
        $key = $this->rateLimitKey($userId);
        $redis = $this->getRedisConnection();
        if ($redis !== null) {
            // Atomic: a concurrent INCR cannot race past maxAttempts.
            // EXPIRE after every INCR resets the TTL, matching the
            // original behaviour of set($key, $data, $lockoutDuration).
            $attempts = (int)$redis->executeCommand('INCR', [$key]);
            $redis->executeCommand('EXPIRE', [$key, $this->lockoutDuration]);
            return max(0, $this->maxAttempts - $attempts);
        }

        /** @var \yii\caching\CacheInterface $cache */
        $cache = \Yii::$app->cache;
        $raw = $cache->get($key);
        $attempts = is_int($raw) ? $raw : 0;
        $attempts++;
        $cache->set($key, $attempts, $this->lockoutDuration);
        return max(0, $this->maxAttempts - $attempts);
    }

    /**
     * Clear the rate limit counter (after successful login).
     */
    public function clearRateLimit(int $userId): void
    {
        $key = $this->rateLimitKey($userId);
        $redis = $this->getRedisConnection();
        if ($redis !== null) {
            $redis->executeCommand('DEL', [$key]);
            return;
        }
        /** @var \yii\caching\CacheInterface $cache */
        $cache = \Yii::$app->cache;
        $cache->delete($key);
    }

    /**
     * Read the current attempt count for a user. Returns 0 when no
     * entry exists or when stored data is of an unexpected shape
     * (e.g. legacy array from a previous version).
     */
    private function getAttempts(int $userId): int
    {
        $key = $this->rateLimitKey($userId);
        $redis = $this->getRedisConnection();
        if ($redis !== null) {
            $value = $redis->executeCommand('GET', [$key]);
            if (is_string($value) || is_int($value)) {
                return (int)$value;
            }
            return 0;
        }

        /** @var \yii\caching\CacheInterface $cache */
        $cache = \Yii::$app->cache;
        $raw = $cache->get($key);
        return is_int($raw) ? $raw : 0;
    }

    /**
     * Return the Redis connection backing the cache component, or null
     * when the cache is not Redis-backed. Protected so tests can
     * override with a fake connection.
     */
    protected function getRedisConnection(): ?RedisConnection
    {
        /** @var \yii\caching\CacheInterface $cache */
        $cache = \Yii::$app->cache;
        if (!$cache instanceof RedisCache) {
            return null;
        }
        $redis = $cache->redis;
        return $redis instanceof RedisConnection ? $redis : null;
    }

    private function rateLimitKey(int $userId): string
    {
        return 'totp_rate_limit_' . $userId;
    }
}
