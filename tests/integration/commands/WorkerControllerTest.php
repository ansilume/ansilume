<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\WorkerController;
use app\components\WorkerHeartbeat;
use app\tests\integration\DbTestCase;
use yii\base\Event;
use yii\queue\cli\Queue as CliQueue;

/**
 * Integration tests for the console WorkerController.
 *
 * Signal-driven graceful shutdown is exercised indirectly by triggering the
 * yii2-queue worker lifecycle events directly — this avoids spawning an actual
 * worker process and sending real POSIX signals while still proving that our
 * cleanup hook (heartbeat deregistration) fires when the loop exits.
 */
class WorkerControllerTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Detach any handlers a previous test may have left behind on the
        // global event hub so each scenario starts from a clean slate.
        Event::offAll();
    }

    protected function tearDown(): void
    {
        Event::offAll();
        parent::tearDown();
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
    private function findOwnHeartbeat(): array
    {
        $expected = gethostname() . ':' . getmypid();
        return array_values(array_filter(
            WorkerHeartbeat::all(),
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
