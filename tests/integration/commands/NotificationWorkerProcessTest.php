<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\WorkerController;
use app\components\AllowlistQueueSerializer;
use app\components\RedisSettings;
use app\components\WorkerHeartbeat;
use PHPUnit\Framework\TestCase;
use yii\db\Connection;
use yii\queue\redis\Queue as RedisQueue;

/**
 * The notification-worker as its container runs it,
 * `php yii worker/start --queue=notificationQueue`: in a child PHP process
 * that builds the console application from config/console.php as the yii
 * script does, with the test database, its log in a temporary file, and
 * channels of the test's own for both queues. The worker:
 * - says that it runs the notification queue, and runs a job of it;
 * - closes the job's database connection after the job, so that it waits
 *   for the next job without one (CLAUDE.md: a process that keeps running
 *   holds no database connection while it waits);
 * - beats under the key prefix of the notification-worker, never as a sync
 *   worker of the project page;
 * - leaves the jobs of the queue of project syncs to the queue-worker;
 * - logs its jobs with the queue's log behaviour;
 * - ends cleanly on SIGTERM, as `docker stop` sends it, with exit code 0,
 *   and removes its heartbeat.
 *
 * Before the notification-worker, worker/start had no --queue: the command
 * of the service stopped with "Unknown option: --queue", and Docker would
 * have restarted it without end.
 */
final class NotificationWorkerProcessTest extends TestCase
{
    /** Seconds the child may take to start and to run the job. */
    private const RUN_DEADLINE = 30;

    /** Seconds the server may take to drop a connection that the worker closed. */
    private const QUIT_DEADLINE = 5;

    /** Seconds the child may take to end after SIGTERM: one wait of its loop for a job takes 3. */
    private const STOP_DEADLINE = 15;

    /**
     * The child: the yii script, with the test database, its log file and the
     * channels of the test. Arguments: the repository root, the log file, the
     * channel of the notification queue, the channel of the queue of project
     * syncs, then the command line after the yii script.
     */
    private const WORKER = <<<'PHP'
        <?php

        declare(strict_types=1);

        [, $root, $logFile, $notificationChannel, $syncChannel] = $argv;
        define('YII_DEBUG', false);
        define('YII_ENV', 'prod');
        require $root . '/vendor/autoload.php';
        \Dotenv\Dotenv::createUnsafeImmutable($root)->safeLoad();
        require $root . '/vendor/yiisoft/yii2/Yii.php';

        $config = require $root . '/config/console.php';
        $config['components']['db'] = require $root . '/config/db-test.php';
        $config['components']['log']['targets'][0]['logFile'] = $logFile;
        $serializer = [
            'class' => \app\components\AllowlistQueueSerializer::class,
            'allowedClasses' => [
                ...\app\components\AllowlistQueueSerializer::ALLOWED_CLASSES,
                \app\tests\integration\commands\ConnectionProbeJob::class,
            ],
        ];
        foreach (['notificationQueue' => $notificationChannel, 'queue' => $syncChannel] as $id => $channel) {
            $config['components'][$id]['channel'] = $channel;
            $config['components'][$id]['serializer'] = $serializer;
        }
        $_SERVER['argv'] = ['yii', ...array_slice($argv, 5)];
        $exitCode = (new \yii\console\Application($config))->run();
        exit($exitCode);

        PHP;

    /** @var resource|null the child */
    private $process = null;

    /** @var array<int, resource> the child's stdout (1) and stderr (2) */
    private array $pipes = [];

    /** @var array<int, string> what the child wrote to stdout (1) and stderr (2) */
    private array $output = [1 => '', 2 => ''];

    /** @var list<RedisQueue> */
    private array $queues = [];

    /** @var list<string> */
    private array $files = [];

    private ?Connection $observer = null;

    private ?\Redis $redis = null;

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 9);
            $this->closeProcess();
        }
        foreach ($this->queues as $queue) {
            $queue->clear();
        }
        foreach ($this->files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        $this->observer?->close();
        $this->redis?->close();
        parent::tearDown();
    }

    public function testTheNotificationWorkerRunsItsQueueAndWaitsWithoutADatabaseConnection(): void
    {
        $notifications = $this->queue();
        $syncs = $this->queue();
        $record = $this->temporaryFile('record', '');
        $log = $this->temporaryFile('log', '');
        $notificationJob = (string)$notifications->push(new ConnectionProbeJob(['recordFile' => $record]));
        $syncJob = (string)$syncs->push(new ConnectionProbeJob(['recordFile' => $record]));

        $pid = $this->start([$log, $notifications->channel, $syncs->channel, 'worker/start', '--queue=notificationQueue']);
        $workerId = gethostname() . ':' . $pid;
        $connectionId = $this->waitForTheJob($record);

        $this->waitUntilTheServerDropped($connectionId);
        $this->assertSame(1, $this->exists(WorkerHeartbeat::NOTIFICATION_KEY_PREFIX . $workerId), 'it beats as the notification-worker');
        $this->assertSame(0, $this->exists(WorkerHeartbeat::KEY_PREFIX . $workerId), 'never as a sync worker');
        $this->assertNotContains($workerId, array_column(WorkerHeartbeat::all(), 'worker_id'), 'the project page counts no notification-worker');

        $exit = $this->stop();

        $this->assertSame(0, $exit, 'it ends cleanly on SIGTERM: ' . $this->output[2]);
        $this->assertSame('', $this->output[2]);
        $this->assertSame(sprintf(WorkerController::STARTED, $pid, gethostname(), 'notificationQueue') . "[worker] stopped cleanly.\n", $this->output[1]);
        $this->assertSame(0, $this->exists(WorkerHeartbeat::NOTIFICATION_KEY_PREFIX . $workerId), 'it removed its heartbeat');
        $this->assertSame([(string)$connectionId], self::lines($record), 'it ran the one job of its queue');
        $this->assertSame(RedisQueue::STATUS_WAITING, $syncs->status($syncJob), 'the job of the queue of project syncs waits for the queue-worker');
        $this->assertStringContainsString(
            "[{$notificationJob}] " . ConnectionProbeJob::class . " (attempt: 1, PID: {$pid}) is finished.",
            (string)file_get_contents($log),
            'the queue\'s log behaviour logs the job'
        );
    }

    /**
     * Starts the child with $args after the repository root; returns its PID.
     *
     * @param list<string> $args
     */
    private function start(array $args): int
    {
        $script = $this->temporaryFile('worker', self::WORKER);
        $process = proc_open([PHP_BINARY, $script, dirname(__DIR__, 3), ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $this->process = $process;
        $this->pipes = $pipes;
        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        return (int)proc_get_status($process)['pid'];
    }

    /**
     * Waits until the probe job recorded its connection, while the child
     * runs; returns the connection's ID.
     */
    private function waitForTheJob(string $record): int
    {
        $until = microtime(true) + self::RUN_DEADLINE;
        while (self::lines($record) === []) {
            $this->drain();
            $this->assertTrue($this->running(), 'The worker ended before it ran the job: ' . $this->output[1] . $this->output[2]);
            $this->assertLessThan($until, microtime(true), 'The worker ran no job within ' . self::RUN_DEADLINE . ' seconds: ' . $this->output[1] . $this->output[2]);
            usleep(100000);
        }

        return (int)self::lines($record)[0];
    }

    /**
     * Waits until the server no longer lists connection $id, while the
     * child still runs.
     */
    private function waitUntilTheServerDropped(int $id): void
    {
        $this->observer ??= self::connection();
        $until = microtime(true) + self::QUIT_DEADLINE;
        $command = $this->observer->createCommand('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = :id', [':id' => $id]);
        while ((int)$command->queryScalar() > 0) {
            $this->assertLessThan($until, microtime(true), "The worker still holds connection {$id} while it waits.");
            usleep(100000);
        }
        $this->assertTrue($this->running(), 'The worker waits for the next job.');
    }

    /**
     * Sends SIGTERM, as `docker stop` does, and waits until the child ended;
     * returns its exit code.
     */
    private function stop(): int
    {
        $process = $this->process;
        $this->assertIsResource($process);
        proc_terminate($process, 15);
        $until = microtime(true) + self::STOP_DEADLINE;
        while (true) {
            $this->drain();
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            $this->assertLessThan($until, microtime(true), 'The worker did not end within ' . self::STOP_DEADLINE . ' seconds of SIGTERM.');
            usleep(100000);
        }
        $this->assertFalse($status['signaled'], 'The worker was killed by signal ' . $status['termsig'] . '.');
        $exit = (int)$status['exitcode'];
        $this->closeProcess();

        return $exit;
    }

    private function running(): bool
    {
        $process = $this->process;

        return is_resource($process) && proc_get_status($process)['running'];
    }

    /** Reads what the child wrote, without waiting. */
    private function drain(): void
    {
        foreach ($this->pipes as $index => $pipe) {
            $this->output[$index] .= (string)stream_get_contents($pipe);
        }
    }

    private function closeProcess(): void
    {
        $this->drain();
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        $this->pipes = [];
        if (is_resource($this->process)) {
            proc_close($this->process);
        }
        $this->process = null;
    }

    /**
     * A queue on a channel of the test's own that takes the probe job.
     */
    private function queue(): RedisQueue
    {
        $queue = new RedisQueue([
            'redis' => \Yii::$app->get('redis'),
            'channel' => 'ansilume-test-notification-worker-' . bin2hex(random_bytes(6)),
            'serializer' => [
                'class' => AllowlistQueueSerializer::class,
                'allowedClasses' => [...AllowlistQueueSerializer::ALLOWED_CLASSES, ConnectionProbeJob::class],
            ],
        ]);
        $this->queues[] = $queue;

        return $queue;
    }

    private function exists(string $key): int
    {
        $this->redis ??= RedisSettings::fromEnvironment($_ENV)->connectPhpRedis(new \Redis());

        return (int)$this->redis->exists($key);
    }

    private function temporaryFile(string $name, string $content): string
    {
        $file = sys_get_temp_dir() . '/notification-worker-' . $name . '-' . bin2hex(random_bytes(6)) . ($name === 'worker' ? '.php' : '');
        file_put_contents($file, $content);
        $this->files[] = $file;

        return $file;
    }

    /**
     * The lines of $file.
     *
     * @return list<string>
     */
    private static function lines(string $file): array
    {
        clearstatcache(true, $file);

        return array_values(array_filter(explode("\n", (string)file_get_contents($file)), static fn (string $line): bool => $line !== ''));
    }

    /**
     * A new connection to the test database, as config/test.php configures it.
     */
    private static function connection(): Connection
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 3) . '/config/db-test.php';
        unset($config['class']);

        return new Connection($config);
    }
}
