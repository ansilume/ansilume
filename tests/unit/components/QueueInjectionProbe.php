<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use yii\queue\JobInterface;

/**
 * Test double for a class an attacker would like the queue-worker to
 * instantiate from a crafted Redis message. Records whether PHP ever woke it
 * up, destroyed it, or executed it.
 */
class QueueInjectionProbe implements JobInterface
{
    public static bool $woke = false;
    public static bool $destroyed = false;
    public static bool $executed = false;

    public string $payload = '';

    public static function reset(): void
    {
        self::$woke = false;
        self::$destroyed = false;
        self::$executed = false;
    }

    public function __wakeup(): void
    {
        self::$woke = true;
    }

    public function __destruct()
    {
        if ($this->payload === 'armed') {
            self::$destroyed = true;
        }
    }

    public function execute($queue): void
    {
        self::$executed = true;
    }
}
