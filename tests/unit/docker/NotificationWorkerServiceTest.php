<?php

declare(strict_types=1);

namespace app\tests\unit\docker;

use app\commands\WorkerController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use yii\queue\cli\SignalLoop;

/**
 * The notification-worker service: every stack (docker-compose.yml,
 * docker-compose.prebuilt.yml, and the deploy role with either image mode)
 * runs the notification queue in a container of its own,
 * ansilume_notification_worker, with
 * `php yii worker/start --queue=notificationQueue`, so that a notification
 * never waits behind a project sync of the queue-worker.
 *
 * It runs the queue-worker's image as the queue-worker does: the same
 * environment (a git checkout reads .env itself, the prebuilt stack and the
 * deploy role hand it .env, OUTBOUND_CA_BUNDLE among it), dependencies,
 * network, restart policy and health check, and it mounts what the
 * queue-worker mounts but the project checkouts, which a delivery never
 * reads. A stop lets the delivery in flight finish. What it does with its
 * database connection is in IdleDatabaseConnectionTest, its health check's
 * user in HealthcheckUserTest, its ini in ExceptionIgnoreArgsTest, its
 * network in ComposeIsolationTest, its dependencies on an external database
 * or Redis in DeployRoleComposeTest.
 */
final class NotificationWorkerServiceTest extends TestCase
{
    use RendersDeployRole;

    private const SERVICE = 'notification-worker';

    /** The command of the service: the worker of the notification queue. */
    private const COMMAND = ['php', 'yii', 'worker/start', '--queue=notificationQueue'];

    /** What the notification-worker shares with the queue-worker, in every stack. */
    private const SHARED = ['image', 'build', 'restart', 'working_dir', 'env_file', 'environment', 'depends_on', 'networks', 'healthcheck'];

    /** The mount of the project checkouts, which only the app and the queue-worker need. */
    private const PROJECTS_VOLUME = 'runtime_projects:';

    /** SIGTERM, the stop signal of the service: the worker's loop ends on it after the job in flight (SignalLoop). */
    private const SIGTERM = 15;

    /**
     * @return array<string, array{0: string}>
     */
    public static function stackProvider(): array
    {
        return [
            'docker-compose.yml' => ['dev'],
            'docker-compose.prebuilt.yml' => ['prebuilt'],
            'the deploy role, building the images' => ['deploy build'],
            'the deploy role, with prebuilt images' => ['deploy prebuilt'],
        ];
    }

    #[DataProvider('stackProvider')]
    public function testEveryStackRunsTheNotificationQueueInAContainerOfItsOwn(string $stack): void
    {
        $service = $this->service($stack);

        $this->assertSame(self::COMMAND, $service['command'] ?? null);
        $this->assertSame('ansilume_notification_worker', $service['container_name'] ?? null);
        $this->assertArrayHasKey('notificationQueue', WorkerController::QUEUES, 'worker/start runs the queue that --queue names');
        $this->assertNotSame(WorkerController::QUEUES['queue'], WorkerController::QUEUES['notificationQueue'], 'it beats as no sync worker');
    }

    #[DataProvider('stackProvider')]
    public function testItRunsTheImageOfTheQueueWorkerAsTheQueueWorkerDoes(string $stack): void
    {
        $services = $this->services($stack);
        $this->assertIsArray($services['queue-worker'] ?? null);

        foreach (self::SHARED as $key) {
            $this->assertSame($services['queue-worker'][$key] ?? null, $this->service($stack)[$key] ?? null, "{$stack}: {$key}");
        }
    }

    /**
     * The environment reaches it in every stack: a git checkout mounts the
     * checkout, whose .env the yii script reads; the prebuilt stack and the
     * deploy role hand it .env as env_file, so that OUTBOUND_CA_BUNDLE, the
     * mail and the Redis settings reach the deliveries.
     */
    #[DataProvider('stackProvider')]
    public function testItGetsTheEnvironmentOfTheServer(string $stack): void
    {
        $service = $this->service($stack);

        if ($stack === 'dev') {
            $this->assertContains('.:/var/www', (array)($service['volumes'] ?? []));
            $this->assertArrayNotHasKey('env_file', $service);
        } else {
            $this->assertSame(['.env'], (array)($service['env_file'] ?? []));
        }
    }

    /**
     * It mounts what the queue-worker mounts but the project checkouts: the
     * code and the ini in a git checkout, nothing with the prebuilt images.
     */
    #[DataProvider('stackProvider')]
    public function testItMountsWhatTheQueueWorkerMountsButTheProjects(string $stack): void
    {
        $queueWorker = (array)($this->services($stack)['queue-worker']['volumes'] ?? []);
        $expected = array_values(array_filter($queueWorker, static fn (mixed $volume): bool => !str_starts_with((string)$volume, self::PROJECTS_VOLUME)));

        $this->assertSame($expected, (array)($this->service($stack)['volumes'] ?? []));
    }

    /**
     * A stop lets the delivery in flight finish: the container gets SIGTERM,
     * on which the worker's loop ends after its job, and a grace period that
     * outlasts the notification queue's TTR before Docker kills it.
     */
    #[DataProvider('stackProvider')]
    public function testAStopLetsTheDeliveryInFlightFinish(string $stack): void
    {
        $service = $this->service($stack);
        $config = require dirname(__DIR__, 3) . '/config/console.php';
        $ttr = (int)($config['components']['notificationQueue']['ttr'] ?? 0);
        $grace = (string)($service['stop_grace_period'] ?? '');

        $this->assertSame('SIGTERM', $service['stop_signal'] ?? null);
        // Read, not made: a SignalLoop installs its signal handlers in this process.
        $exitSignals = (new \ReflectionProperty(SignalLoop::class, 'exitSignals'))->getDefaultValue();
        $this->assertIsArray($exitSignals);
        $this->assertContains(self::SIGTERM, $exitSignals);
        $this->assertGreaterThan(0, $ttr);
        $this->assertSame(1, preg_match('/^(\d+)s$/', $grace, $seconds), "{$stack}: a grace period in seconds");
        $this->assertGreaterThanOrEqual($ttr, (int)($seconds[1] ?? 0));
    }

    /**
     * The notification-worker of $stack.
     *
     * @return array<string, mixed>
     */
    private function service(string $stack): array
    {
        $service = $this->services($stack)[self::SERVICE] ?? null;
        $this->assertIsArray($service, "{$stack} has the service " . self::SERVICE);

        /** @var array<string, mixed> $service */
        return $service;
    }

    /**
     * The services of $stack, by name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function services(string $stack): array
    {
        $compose = match ($stack) {
            'dev' => Yaml::parseFile(dirname(__DIR__, 3) . '/docker-compose.yml'),
            'prebuilt' => Yaml::parseFile(dirname(__DIR__, 3) . '/docker-compose.prebuilt.yml'),
            'deploy build' => $this->renderDeployTemplate(false),
            default => $this->renderDeployTemplate(true),
        };
        $this->assertIsArray($compose);
        $this->assertIsArray($compose['services'] ?? null);

        /** @var array<string, array<string, mixed>> $services */
        $services = $compose['services'];

        return $services;
    }
}
