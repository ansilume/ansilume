<?php

declare(strict_types=1);

namespace app\tests\integration;

use app\components\AllowlistQueueSerializer;
use yii\queue\JobInterface;
use yii\queue\sync\Queue as SyncQueue;

/**
 * Test double of the queue of the queue-worker (the application component
 * queue, which LintService pushes to): it records every job pushed, read
 * back through the queue's allowlist (AllowlistQueueSerializer) as the
 * worker reads it, and runs the jobs when the test calls run(), as the
 * queue-worker would. failWith makes every push throw, as a Redis that
 * cannot be reached does; beforePush runs first on every push, as another
 * request that comes meanwhile.
 */
final class RecordingQueue extends SyncQueue
{
    /** @var list<JobInterface> the jobs pushed, in order */
    public array $jobs = [];

    /** An error each push throws; null: every push is taken. */
    public ?\Throwable $failWith = null;

    /** Called on every push before anything else; null: nothing. */
    public ?\Closure $beforePush = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config + ['serializer' => AllowlistQueueSerializer::class]);
    }

    /**
     * @param string $message
     * @param int $ttr
     * @param int $delay
     * @param mixed $priority
     */
    protected function pushMessage($message, $ttr, $delay, $priority): string
    {
        if ($this->beforePush !== null) {
            ($this->beforePush)();
        }
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $job = $this->serializer->unserialize($message);
        if ($job instanceof JobInterface) {
            $this->jobs[] = $job;
        }

        return (string)parent::pushMessage($message, $ttr, $delay, $priority);
    }
}
