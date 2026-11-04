<?php

declare(strict_types=1);

namespace app\tests\integration\components;

use app\components\RedisSettings;
use app\components\WorkerHeartbeat;
use PHPUnit\Framework\TestCase;

/**
 * The heartbeat of a worker (worker/start) in Redis, under the key prefix of
 * the queue it runs. The queue-worker beats under ansilume:worker:, which
 * WorkerHeartbeat::all() reads by default: the sync worker of the project
 * page (ProjectController's worker snapshot) and the live queue worker of
 * the queue-starvation sweep (JobReclaimService). The notification-worker
 * beats under ansilume:notification-worker:, which neither reads: counted as
 * a sync worker, it would hide a queue-worker that is gone ("no worker
 * running") or stuck, and keep the sweep from failing a job that no worker
 * can run.
 */
final class WorkerHeartbeatTest extends TestCase
{
    /** The key of a queue-worker, before its worker ID (host:pid). */
    private const SYNC_KEY = 'ansilume:worker:';

    /** The key of a notification-worker, before its worker ID (host:pid). */
    private const NOTIFICATION_KEY = 'ansilume:notification-worker:';

    private ?\Redis $redis = null;

    /** @var list<WorkerHeartbeat> the heartbeats the test registered */
    private array $registered = [];

    /** @var list<string> the keys the test wrote itself */
    private array $planted = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = RedisSettings::fromEnvironment($_ENV)->connectPhpRedis(new \Redis());
    }

    protected function tearDown(): void
    {
        foreach ($this->registered as $heartbeat) {
            $heartbeat->deregister();
        }
        foreach ($this->planted as $key) {
            $this->redis?->del($key);
        }
        $this->redis?->close();
        parent::tearDown();
    }

    public function testAQueueWorkerBeatsUnderTheKeyThatTheProjectPageReads(): void
    {
        $this->register(new WorkerHeartbeat());

        $this->assertSame(1, $this->exists(self::SYNC_KEY . self::ownId()));
        $this->assertSame(0, $this->exists(self::NOTIFICATION_KEY . self::ownId()));
        $this->assertContains(self::ownId(), self::ids(WorkerHeartbeat::all()));
        $this->assertNotContains(self::ownId(), self::ids(WorkerHeartbeat::all(WorkerHeartbeat::NOTIFICATION_KEY_PREFIX)));
    }

    public function testANotificationWorkerBeatsUnderAKeyOfItsOwn(): void
    {
        $this->register(new WorkerHeartbeat(WorkerHeartbeat::NOTIFICATION_KEY_PREFIX));

        $key = self::NOTIFICATION_KEY . self::ownId();
        $this->assertSame(1, $this->exists($key));
        $ttl = (int)$this->redis()->ttl($key);
        $this->assertGreaterThan(0, $ttl, 'it expires when the worker dies without deregistering');
        $this->assertLessThanOrEqual(WorkerHeartbeat::STALE_AFTER, $ttl);
        $this->assertSame(0, $this->exists(self::SYNC_KEY . self::ownId()));
        $this->assertContains(self::ownId(), self::ids(WorkerHeartbeat::all(WorkerHeartbeat::NOTIFICATION_KEY_PREFIX)));
        $this->assertNotContains(self::ownId(), self::ids(WorkerHeartbeat::all()), 'no sync worker for the project page and the sweep');
    }

    /**
     * A finished job and a refresh stamp the key of the worker's prefix,
     * and deregister() removes it.
     */
    public function testANotificationWorkerKeepsAndRemovesTheKeyOfItsPrefix(): void
    {
        $heartbeat = $this->register(new WorkerHeartbeat(WorkerHeartbeat::NOTIFICATION_KEY_PREFIX));
        $key = self::NOTIFICATION_KEY . self::ownId();

        $heartbeat->markJobProcessed();
        $heartbeat->refresh();
        $beat = json_decode((string)$this->redis()->get($key), true);
        $this->assertIsArray($beat);
        $this->assertSame(self::ownId(), $beat['worker_id'] ?? null);
        $this->assertIsInt($beat['last_job_processed_at'] ?? null);
        $this->assertSame(0, $this->exists(self::SYNC_KEY . self::ownId()));

        $heartbeat->deregister();

        $this->assertSame(0, $this->exists($key));
    }

    /**
     * all() lists the live workers of the prefix it is given, the queue's by
     * default, and no worker whose beat is older than two intervals.
     */
    public function testAllListsTheLiveWorkersOfItsPrefixOnly(): void
    {
        $suffix = 'phpunit-heartbeat-' . bin2hex(random_bytes(6));
        $this->plant(self::SYNC_KEY . $suffix, 'sync-' . $suffix, time());
        $this->plant(self::NOTIFICATION_KEY . $suffix, 'notification-' . $suffix, time());
        $this->plant(self::NOTIFICATION_KEY . $suffix . '-gone', 'gone-' . $suffix, time() - 3 * WorkerHeartbeat::HEARTBEAT_INTERVAL);

        $sync = self::ids(WorkerHeartbeat::all());
        $notification = self::ids(WorkerHeartbeat::all(WorkerHeartbeat::NOTIFICATION_KEY_PREFIX));

        $this->assertContains('sync-' . $suffix, $sync);
        $this->assertNotContains('notification-' . $suffix, $sync);
        $this->assertContains('notification-' . $suffix, $notification);
        $this->assertNotContains('sync-' . $suffix, $notification);
        $this->assertNotContains('gone-' . $suffix, $notification);
    }

    private function register(WorkerHeartbeat $heartbeat): WorkerHeartbeat
    {
        $heartbeat->register();
        $this->registered[] = $heartbeat;

        return $heartbeat;
    }

    /**
     * Writes a heartbeat as a worker writes it, last seen at $seenAt.
     */
    private function plant(string $key, string $workerId, int $seenAt): void
    {
        $this->planted[] = $key;
        $this->redis()->setex($key, WorkerHeartbeat::STALE_AFTER, (string)json_encode([
            'worker_id' => $workerId,
            'pid' => 1,
            'hostname' => 'phpunit',
            'started_at' => $seenAt - 60,
            'seen_at' => $seenAt,
            'app_version' => 'phpunit',
            'last_job_processed_at' => null,
        ]));
    }

    private function exists(string $key): int
    {
        return (int)$this->redis()->exists($key);
    }

    private function redis(): \Redis
    {
        $this->assertNotNull($this->redis);

        return $this->redis;
    }

    /** The worker ID of a heartbeat of this process. */
    private static function ownId(): string
    {
        return gethostname() . ':' . getmypid();
    }

    /**
     * @param array<int, array<string, mixed>> $workers
     * @return list<string>
     */
    private static function ids(array $workers): array
    {
        return array_values(array_map(static fn (array $worker): string => (string)($worker['worker_id'] ?? ''), $workers));
    }
}
