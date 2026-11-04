<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\WorkerController;
use app\components\WorkerHeartbeat;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use yii\base\Event;
use yii\console\Exception as ConsoleException;
use yii\queue\cli\Queue as CliQueue;
use yii\queue\sync\Queue as SyncQueue;

/**
 * Integration tests for the console WorkerController.
 *
 * Signal-driven graceful shutdown is exercised indirectly by triggering the
 * yii2-queue worker lifecycle events directly — this avoids spawning an actual
 * worker process and sending real POSIX signals while still proving that our
 * cleanup hook (heartbeat deregistration) fires when the loop exits.
 *
 * worker/start runs the queue component that --queue names: the queue of
 * project syncs by default (the queue-worker), the notification queue with
 * --queue=notificationQueue (the notification-worker). What a worker started
 * so does is in NotificationWorkerProcessTest and WorkerJobDbConnectionTest.
 */
class WorkerControllerTest extends DbTestCase
{
    use RestoresComponents;

    protected function setUp(): void
    {
        parent::setUp();
        // Detach any handlers a previous test may have left behind on the
        // global event hub so each scenario starts from a clean slate.
        Event::offAll();
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        Event::offAll();
        parent::tearDown();
    }

    /**
     * --queue is an option of worker/start, and names the queue of project
     * syncs unless it is given.
     */
    public function testTheStartCommandTakesTheQueueOption(): void
    {
        $controller = $this->makeController();

        $this->assertContains('queue', $controller->options('start'));
        $this->assertNotContains('queue', $controller->options('status'));
        $this->assertSame('queue', $controller->queue);
        $this->assertSame(\Yii::$app->get('queue'), $controller->queueComponent());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function workerQueueProvider(): array
    {
        return [
            'the queue-worker' => ['queue', WorkerHeartbeat::KEY_PREFIX],
            'the notification-worker' => ['notificationQueue', WorkerHeartbeat::NOTIFICATION_KEY_PREFIX],
        ];
    }

    /**
     * Each queue a worker runs is a component of the application, and the
     * worker beats under the key prefix of its queue.
     *
     * @dataProvider workerQueueProvider
     */
    public function testEachQueueAWorkerRunsIsAComponentWithAHeartbeatOfItsOwn(string $queue, string $keyPrefix): void
    {
        $controller = $this->makeController();
        $controller->queue = $queue;

        $this->assertSame(\Yii::$app->get($queue), $controller->queueComponent());
        $this->assertSame($keyPrefix, WorkerController::QUEUES[$queue]);
    }

    /**
     * A --queue that names no queue a worker runs, such as another
     * component, stops the command with the reason before the worker
     * starts: no heartbeat, nothing run.
     */
    public function testAnUnknownQueueIsRefusedBeforeTheWorkerStarts(): void
    {
        $controller = $this->makeController();

        try {
            $controller->runAction('start', ['queue' => 'cache']);
            $this->fail('worker/start --queue=cache was not refused.');
        } catch (ConsoleException $e) {
            $this->assertSame(sprintf(WorkerController::UNKNOWN_QUEUE, 'cache', 'queue, notificationQueue'), $e->getMessage());
        }
        $this->assertEmpty($this->findOwnHeartbeat());
        $this->assertEmpty($this->findOwnHeartbeat(WorkerHeartbeat::NOTIFICATION_KEY_PREFIX));
    }

    /**
     * The notification queue of a configuration that made it no Redis queue
     * is refused with the reason, as the worker can run only a Redis queue.
     */
    public function testAQueueComponentThatIsNoRedisQueueIsRefused(): void
    {
        $this->swapComponent('notificationQueue', new SyncQueue());
        $controller = $this->makeController();

        try {
            $controller->runAction('start', ['queue' => 'notificationQueue']);
            $this->fail('worker/start ran a queue that is no Redis queue.');
        } catch (ConsoleException $e) {
            $this->assertSame(sprintf(WorkerController::NOT_A_REDIS_QUEUE, 'notificationQueue'), $e->getMessage());
        }
        $this->assertEmpty($this->findOwnHeartbeat(WorkerHeartbeat::NOTIFICATION_KEY_PREFIX));
    }

    public function testAttachWorkerEventsDeregistersHeartbeatOnStopSignal(): void
    {
        $heartbeat = new WorkerHeartbeat();
        $heartbeat->register();

        // Sanity: heartbeat is visible to the global probe before stop.
        $this->assertNotEmpty(
            $this->findOwnHeartbeat(),
            'WorkerHeartbeat should be registered before stop signal.'
        );

        $controller = $this->makeController();
        $controller->attachWorkerEvents($heartbeat);

        // Simulate the queue loop exiting (which is exactly what SignalLoop
        // does once SIGTERM/INT/QUIT/HUP arrives between iterations).
        Event::trigger(CliQueue::class, CliQueue::EVENT_WORKER_STOP);

        $this->assertEmpty(
            $this->findOwnHeartbeat(),
            'WorkerHeartbeat must be deregistered when the worker stops gracefully.'
        );
    }

    public function testAttachWorkerEventsIsIdempotentAcrossMultipleCalls(): void
    {
        $heartbeat = new WorkerHeartbeat();
        $heartbeat->register();

        $controller = $this->makeController();
        // Two calls = listeners attached twice, but deregister() is best-effort
        // and tolerates double-execution. The end state is what matters.
        $controller->attachWorkerEvents($heartbeat);
        $controller->attachWorkerEvents($heartbeat);

        Event::trigger(CliQueue::class, CliQueue::EVENT_WORKER_STOP);

        $this->assertEmpty(
            $this->findOwnHeartbeat(),
            'Heartbeat should be gone regardless of how many times the hook was attached.'
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findOwnHeartbeat(string $keyPrefix = WorkerHeartbeat::KEY_PREFIX): array
    {
        $expected = gethostname() . ':' . getmypid();
        return array_values(array_filter(
            WorkerHeartbeat::all($keyPrefix),
            static fn (array $w): bool => ($w['worker_id'] ?? '') === $expected
        ));
    }

    private function makeController(): WorkerController
    {
        return new class ('worker', \Yii::$app) extends WorkerController {
            public function stdout($string): int
            {
                return 0;
            }

            public function stderr($string): int
            {
                return 0;
            }
        };
    }
}
