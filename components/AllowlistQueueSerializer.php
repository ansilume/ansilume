<?php

declare(strict_types=1);

namespace app\components;

use app\jobs\SyncProjectJob;
use yii\queue\serializers\PhpSerializer;

/**
 * Queue serializer that only ever instantiates Ansilume's own job classes.
 *
 * The stock PhpSerializer calls unserialize() without any class restriction.
 * Anyone who can write to Redis could then make the queue-worker instantiate
 * arbitrary classes (PHP object injection, gadget chains) or run any loadable
 * job class with chosen properties. This serializer keeps the wire format, so
 * old and new app containers stay compatible during an update, but restricts
 * unserialize() to an allowlist and turns every malformed message into an
 * exception the queue reports as an invalid job instead of crashing.
 *
 * This is defense in depth, not the boundary: the cache and the session store
 * unserialize data from the same Redis. Keeping untrusted code away from Redis
 * (REDIS_PASSWORD, runners on their own network) is what protects it.
 */
final class AllowlistQueueSerializer extends PhpSerializer
{
    /**
     * Every class implementing yii\queue\JobInterface under jobs/ must be listed
     * here, otherwise the worker drops its messages.
     *
     * @var list<class-string>
     */
    public const ALLOWED_CLASSES = [SyncProjectJob::class];

    private const MAX_DEPTH = 8;

    /** @var list<class-string> */
    public array $allowedClasses = self::ALLOWED_CLASSES;

    /**
     * @param mixed $serialized
     * @return mixed
     */
    public function unserialize($serialized)
    {
        if (!is_string($serialized)) {
            throw new \UnexpectedValueException('Queue message must be a string.');
        }

        set_error_handler(static function (int $severity, string $message): never {
            throw new \UnexpectedValueException('Malformed queue message: ' . $message);
        });
        try {
            $job = unserialize($serialized, [
                'allowed_classes' => $this->allowedClasses,
                'max_depth' => self::MAX_DEPTH,
            ]);
        } catch (\Error $e) {
            throw new \UnexpectedValueException('Invalid queue message: ' . $e->getMessage(), 0, $e);
        } finally {
            restore_error_handler();
        }

        if ($job instanceof \__PHP_Incomplete_Class) {
            $class = (string)(((array)$job)['__PHP_Incomplete_Class_Name'] ?? 'unknown');
            \Yii::warning("Rejected queue message for class not on the allowlist: {$class}", __METHOD__);
            throw new \UnexpectedValueException("Queue message class is not allowed: {$class}");
        }

        return $job;
    }
}
