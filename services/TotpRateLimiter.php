<?php

declare(strict_types=1);

namespace app\services;

use yii\base\Component;

/**
 * Rate-limits TOTP verification attempts per user via cache.
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
        /** @var \yii\caching\CacheInterface $cache */
        $cache = \Yii::$app->cache;
        $data = $cache->get($this->rateLimitKey($userId));
        if ($data === false || !is_array($data)) {
            return false;
        }
        return (int)($data['attempts'] ?? 0) >= $this->maxAttempts;
    }

    /**
     * Record a failed TOTP attempt. Returns remaining attempts.
     */
    public function recordFailedAttempt(int $userId): int
    {
        /** @var \yii\caching\CacheInterface $cache */
        $cache = \Yii::$app->cache;
        $key = $this->rateLimitKey($userId);
        $raw = $cache->get($key);
        /** @var array{attempts: int} $data */
        $data = is_array($raw) ? $raw : ['attempts' => 0];
        $data['attempts'] = (int)($data['attempts'] ?? 0) + 1;
        $cache->set($key, $data, $this->lockoutDuration);
        return max(0, $this->maxAttempts - $data['attempts']);
    }

    /**
     * Clear the rate limit counter (after successful login).
     */
    public function clearRateLimit(int $userId): void
    {
        /** @var \yii\caching\CacheInterface $cache */
        $cache = \Yii::$app->cache;
        $cache->delete($this->rateLimitKey($userId));
    }

    private function rateLimitKey(int $userId): string
    {
        return 'totp_rate_limit_' . $userId;
    }
}
