<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\WorkerController;
use app\components\AllowlistQueueSerializer;
use app\components\ExceptionLogText;
use app\components\WorkerHeartbeat;
use app\jobs\SyncProjectJob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use yii\base\Event;
use yii\db\Connection;
use yii\queue\cli\SignalLoop;
use yii\queue\ExecEvent;
use yii\queue\JobInterface;
use yii\queue\Queue;
use yii\queue\redis\Queue as RedisQueue;

/**
 * The queue worker (worker/start) runs each queue job on a database
 * connection of its own: its events (WorkerController::attachWorkerEvents())
 * close the application's connection before and after each job, so that it
 * holds none while the queue sits idle.
 *
 * Regression: the worker kept the connection that its first job opened for
 * its whole life. Once the queue had sat idle longer than the database's
 * wait_timeout (28800 seconds by default on MariaDB and MySQL: a queue idle
 * overnight), the database had closed that connection, and every job after
 * it failed with driver error 2006, "MySQL server has gone away" (MariaDB,
 * and MySQL before PHP 8.4), or 4031 (MySQL 8.0.24 or later on PHP 8.4 or
 * later), until the worker restarted: every project sync of the server
 * failed, and the project showed "syncing".
 *
 * The jobs run through yii2-queue's worker loop (run()), as worker/start
 * runs them, on a Redis channel of their own, with the worker's events. The
 * database closes a connection as after a night: a probe job gives its
 * connection a session wait_timeout of one second, and the test waits until
 * the server no longer lists it. The jobs run on a connection of the test's
 * own, as the other tests keep a transaction open on the application's.
 *
 * tearDown() puts back the application's connection as the same object. Put
 * back as its definition (RestoresComponents), it was a new connection, while
 * the authManager kept writing through the old one: the roles and assignments
 * of every later test stayed in the test database (RestoresComponentsTest).
 */
final class WorkerJobDbConnectionTest extends TestCase
{
    /** Seconds a probe's connection may sit idle before the server closes it. */
    private const WAIT_TIMEOUT = 1;

    /** Seconds the server may take to close an idle connection after its wait_timeout. */
    private const CLOSE_DEADLINE = 15;

    /** Seconds the server may take to drop a connection that the worker closed. */
    private const QUIT_DEADLINE = 5;

    private ?RedisQueue $queue = null;

    /** The application's database connection before the test, which tearDown() puts back. */
    private ?Connection $applicationDb = null;

    /** The application's database connection while the test runs. */
    private ?Connection $db = null;

    /** A connection of its own that asks the server which connections it has. */
    private ?Connection $observer = null;

    /** @var list<\Throwable> the errors of the jobs that failed */
    private array $errors = [];

    /** How many jobs succeeded. */
    private int $succeeded = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Event::offAll();
        $this->applicationDb = \Yii::$app->getDb();
        $this->db = self::connection();
        \Yii::$app->set('db', $this->db);
        $this->observer = self::connection();
        ConnectionProbeJob::$connectionIds = [];

        $this->queue = new RedisQueue([
            'redis' => \Yii::$app->get('redis'),
            'channel' => 'ansilume-test-worker-db-' . bin2hex(random_bytes(6)),
            'serializer' => [
                'class' => AllowlistQueueSerializer::class,
                'allowedClasses' => [...AllowlistQueueSerializer::ALLOWED_CLASSES, ConnectionProbeJob::class],
            ],
            // SignalLoop without exit signals: no signal handlers in the test process.
            'loopConfig' => ['class' => SignalLoop::class, 'exitSignals' => []],
        ]);
        $this->queue->on(Queue::EVENT_AFTER_EXEC, function (): void {
            $this->succeeded++;
        });
        $this->queue->on(Queue::EVENT_AFTER_ERROR, function (ExecEvent $event): void {
            $this->errors[] = $event->error ?? new \LogicException('An error event without its error.');
        });

        $app = \Yii::$app;
        $this->assertNotNull($app);
        (new WorkerController('worker', $app))->attachWorkerEvents(self::silentHeartbeat());
    }

    protected function tearDown(): void
    {
        if ($this->applicationDb !== null) {
            \Yii::$app->set('db', $this->applicationDb);
        }
        $this->db?->close();
        $this->observer?->close();
        $this->queue?->clear();
        Event::offAll();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: JobInterface}>
     */
    public static function jobAfterTheIdleTimeProvider(): array
    {
        return [
            'a job that queries the database' => [new ConnectionProbeJob()],
            // A project that does not exist: the job reads it, logs that it is not there and ends.
            'a project sync, the job of the queue' => [new SyncProjectJob(['projectId' => 0])],
        ];
    }

    /**
     * Regression: after the database closed the connection that the
     * worker's first job opened, because it sat idle longer than its
     * wait_timeout, the next job failed with 2006 (here, on MariaDB) or
     * 4031. It runs on a new connection now and succeeds.
     */
    #[DataProvider('jobAfterTheIdleTimeProvider')]
    public function testRegressionAJobSucceedsAfterTheDatabaseClosedTheIdleConnection(JobInterface $job): void
    {
        $this->runJob(new ConnectionProbeJob(['sessionWaitTimeout' => self::WAIT_TIMEOUT]));
        $this->assertCount(1, ConnectionProbeJob::$connectionIds);
        $first = ConnectionProbeJob::$connectionIds[0];
        $this->waitUntilTheServerDropped($first, self::CLOSE_DEADLINE);

        $this->runJob($job);

        $this->assertSame([], $this->errorTexts(), 'The job after the idle time failed.');
        $this->assertSame(2, $this->succeeded);
        $this->assertNotContains($first, array_slice(ConnectionProbeJob::$connectionIds, 1), 'The job ran on the closed connection.');
    }

    /**
     * Before each job too: a connection that was opened outside a job and
     * that the database closed since is not the one the next job runs on.
     */
    public function testAJobRunsOnANewConnectionWhenTheOneOpenedBeforeItWasClosed(): void
    {
        $db = \Yii::$app->getDb();
        $db->createCommand('SET SESSION wait_timeout = ' . self::WAIT_TIMEOUT)->execute();
        $before = (int)$db->createCommand('SELECT CONNECTION_ID()')->queryScalar();
        $this->waitUntilTheServerDropped($before, self::CLOSE_DEADLINE);

        $this->runJob(new ConnectionProbeJob());

        $this->assertSame([], $this->errorTexts(), 'The job failed on the connection that was opened before it.');
        $this->assertSame(1, $this->succeeded);
        $this->assertCount(1, ConnectionProbeJob::$connectionIds);
        $this->assertNotSame($before, ConnectionProbeJob::$connectionIds[0]);
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function outcomeProvider(): array
    {
        return [
            'after a job that succeeded' => [false],
            'after a job that failed' => [true],
        ];
    }

    /**
     * The worker holds no database connection between jobs: after a job,
     * whether it succeeded or failed, the application has none open, and
     * the server no longer has the job's connection, which no wait_timeout
     * of its own would have closed yet.
     */
    #[DataProvider('outcomeProvider')]
    public function testTheWorkerHoldsNoConnectionAfterAJob(bool $fails): void
    {
        $this->runJob(new ConnectionProbeJob(['fail' => $fails]));

        $this->assertSame($fails ? [ConnectionProbeJob::FAILED] : [], array_map(static fn (\Throwable $e): string => $e->getMessage(), $this->errors));
        $this->assertSame($fails ? 0 : 1, $this->succeeded);
        $this->assertFalse(\Yii::$app->getDb()->getIsActive(), 'The worker still has a database connection open after the job.');
        $this->assertCount(1, ConnectionProbeJob::$connectionIds);
        $this->waitUntilTheServerDropped(ConnectionProbeJob::$connectionIds[0], self::QUIT_DEADLINE);
    }

    /**
     * Pushes $job and runs the worker loop until the queue is empty again.
     */
    private function runJob(JobInterface $job): void
    {
        $queue = $this->queue;
        $this->assertNotNull($queue);
        $queue->push($job);
        $queue->run(false);
    }

    /**
     * Waits until the server no longer lists connection $id, at most $deadline seconds.
     */
    private function waitUntilTheServerDropped(int $id, int $deadline): void
    {
        $observer = $this->observer;
        $this->assertNotNull($observer);
        $until = microtime(true) + $deadline;
        $command = $observer->createCommand('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = :id', [':id' => $id]);
        while ((int)$command->queryScalar() > 0) {
            $this->assertLessThan($until, microtime(true), "The server still has connection {$id} after {$deadline} seconds.");
            usleep(100000);
        }
    }

    /**
     * The errors of the jobs that failed, as the queue's log names them.
     *
     * @return list<string>
     */
    private function errorTexts(): array
    {
        return array_map(static fn (\Throwable $e): string => ExceptionLogText::brief($e), $this->errors);
    }

    /**
     * A new connection to the test database, as config/test.php configures it.
     */
    private static function connection(): Connection
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 3) . '/config/db-test.php';
        self::assertSame(Connection::class, ltrim((string)$config['class'], '\\'));
        unset($config['class']);

        return new Connection($config);
    }

    /**
     * The worker's heartbeat, but one that writes nothing: the test process
     * is no worker the stack should list.
     */
    private static function silentHeartbeat(): WorkerHeartbeat
    {
        return new class () extends WorkerHeartbeat {
            public function markJobProcessed(): void
            {
            }

            public function deregister(): void
            {
            }
        };
    }
}
