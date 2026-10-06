<?php

declare(strict_types=1);

namespace app\tests\unit\config;

use app\components\RedactingRedisConnection;
use PHPUnit\Framework\TestCase;

/**
 * Every Redis connection the application opens (cache, session, queue, the
 * test 'redis' component) must use the shared settings: the redacting
 * connection class and the optional REDIS_PASSWORD. A connection that is
 * built by hand silently stays unauthenticated once Redis requires a password.
 */
class RedisConfigTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        $this->savedEnv = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->savedEnv;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function configProvider(): array
    {
        return ['web' => ['web.php'], 'console' => ['console.php'], 'test' => ['test.php']];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function redisConnections(string $file): array
    {
        $config = require dirname(__DIR__, 3) . '/config/' . $file;
        $components = $config['components'];
        $connections = [];
        foreach ($components as $id => $component) {
            if (!is_array($component)) {
                continue;
            }
            if ($id === 'redis') {
                $connections[] = $component;
            } elseif (isset($component['redis']) && is_array($component['redis'])) {
                $connections[] = $component['redis'];
            }
        }

        return $connections;
    }

    /**
     * @dataProvider configProvider
     */
    public function testEveryRedisConnectionCarriesTheSharedSettings(string $file): void
    {
        $_ENV['REDIS_PASSWORD'] = 'canary-redis';
        $_ENV['REDIS_HOST'] = 'redis.example';

        $connections = $this->redisConnections($file);

        $this->assertNotEmpty($connections);
        foreach ($connections as $connection) {
            $this->assertSame(RedactingRedisConnection::class, $connection['class'] ?? null);
            $this->assertSame('canary-redis', $connection['password'] ?? null);
            $this->assertSame('redis.example', $connection['hostname'] ?? null);
        }
    }

    /**
     * @dataProvider configProvider
     */
    public function testEmptyPasswordDisablesAuth(string $file): void
    {
        $_ENV['REDIS_PASSWORD'] = '';

        foreach ($this->redisConnections($file) as $connection) {
            $this->assertArrayHasKey('password', $connection);
            $this->assertNull($connection['password']);
        }
    }

    public function testWebUsesRedisForCacheSessionAndQueue(): void
    {
        $config = require dirname(__DIR__, 3) . '/config/web.php';

        foreach (['cache', 'session', 'queue'] as $id) {
            $this->assertSame(RedactingRedisConnection::class, $config['components'][$id]['redis']['class'] ?? null, $id);
        }
    }

    /**
     * Regression: cluster auto-detection swallowed a failed AUTH, so a wrong
     * REDIS_PASSWORD showed up as NOAUTH on the next command.
     */
    public function testCachesNeverProbeForClusterMode(): void
    {
        foreach (['web.php', 'console.php'] as $file) {
            $config = require dirname(__DIR__, 3) . '/config/' . $file;
            $this->assertFalse($config['components']['cache']['forceClusterMode'] ?? null, $file);
        }
    }

    /**
     * Guard: WorkerController pings a top-level 'redis' component from its
     * signal handler when one exists. The queue connection is blocked in BRPOP,
     * so a shared component would interleave commands on that socket.
     */
    public function testConsoleDefinesNoSharedRedisComponent(): void
    {
        $config = require dirname(__DIR__, 3) . '/config/console.php';

        $this->assertArrayNotHasKey('redis', $config['components']);
    }

    /**
     * Web and console share one definition of every application service.
     */
    public function testWebAndConsoleShareTheServiceDefinitions(): void
    {
        $services = require dirname(__DIR__, 3) . '/config/services.php';
        $web = (require dirname(__DIR__, 3) . '/config/web.php')['components'];
        $console = (require dirname(__DIR__, 3) . '/config/console.php')['components'];

        $this->assertArrayHasKey('jobReclaimService', $services);
        foreach (array_keys($services) as $id) {
            $this->assertArrayHasKey($id, $web, "web is missing {$id}");
            $this->assertArrayHasKey($id, $console, "console is missing {$id}");
        }
    }
}
