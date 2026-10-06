<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\RedactingRedisConnection;
use app\components\RedisSettings;
use PHPUnit\Framework\TestCase;

class RedisSettingsTest extends TestCase
{
    public function testDefaultsMatchThePreviousConfiguration(): void
    {
        $settings = RedisSettings::fromEnvironment([]);

        $this->assertSame('redis', $settings->hostname);
        $this->assertSame(6379, $settings->port);
        $this->assertSame(0, $settings->database);
        $this->assertFalse($settings->hasPassword());
    }

    public function testValuesAreReadAndCast(): void
    {
        $settings = RedisSettings::fromEnvironment([
            'REDIS_HOST' => 'cache.internal',
            'REDIS_PORT' => '6380',
            'REDIS_DB' => '3',
            'REDIS_PASSWORD' => 's3cret',
        ]);

        $this->assertSame('cache.internal', $settings->hostname);
        $this->assertSame(6380, $settings->port);
        $this->assertSame(3, $settings->database);
        $this->assertTrue($settings->hasPassword());
    }

    /**
     * yii2-redis sends AUTH for every non-null password; an empty string would
     * make it send a bare AUTH that every server rejects.
     */
    public function testEmptyPasswordMeansNoAuth(): void
    {
        $config = RedisSettings::fromEnvironment(['REDIS_PASSWORD' => ''])->connectionConfig();

        $this->assertNull($config['password']);
    }

    public function testConnectionConfigUsesTheRedactingConnection(): void
    {
        $config = RedisSettings::fromEnvironment([
            'REDIS_HOST' => 'redis',
            'REDIS_PORT' => '6379',
            'REDIS_DB' => '1',
            'REDIS_PASSWORD' => 's3cret',
        ])->connectionConfig();

        $this->assertSame([
            'class' => RedactingRedisConnection::class,
            'hostname' => 'redis',
            'port' => 6379,
            'database' => 1,
            'password' => 's3cret',
        ], $config);
    }

    public function testDebugOutputNeverShowsThePassword(): void
    {
        $settings = RedisSettings::fromEnvironment(['REDIS_PASSWORD' => 'leak-canary-redis']);

        // Debug dumps (Yii's VarDumper, the debug toolbar, var_dump) use __debugInfo().
        $dump = \yii\helpers\VarDumper::dumpAsString($settings);

        $this->assertSame('***', $settings->__debugInfo()['password']);
        $this->assertStringNotContainsString('leak-canary-redis', $dump);
        $this->assertStringContainsString('***', $dump);
        $this->assertNull(RedisSettings::fromEnvironment([])->__debugInfo()['password']);
    }

    /**
     * @return \Redis&object{calls: list<array<int, mixed>>, authResult: bool}
     */
    private function recordingRedis(bool $authResult = true): \Redis
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('phpredis extension not loaded.');
        }

        return new class ($authResult) extends \Redis {
            /** @var list<array<int, mixed>> */
            public array $calls = [];

            public function __construct(public bool $authResult)
            {
            }

            public function connect($host, $port = 6379, $timeout = 0, $persistent_id = null, $retry_interval = 0, $read_timeout = 0, $context = null): bool
            {
                $this->calls[] = ['connect', $host, $port];
                return true;
            }

            public function auth(mixed $credentials): \Redis|bool
            {
                $this->calls[] = ['auth', $credentials];
                return $this->authResult;
            }

            public function select(int $db): \Redis|bool
            {
                $this->calls[] = ['select', $db];
                return true;
            }
        };
    }

    public function testConnectPhpRedisAuthenticatesBeforeSelectingTheDatabase(): void
    {
        $redis = $this->recordingRedis();

        RedisSettings::fromEnvironment(['REDIS_PASSWORD' => 'pw', 'REDIS_DB' => '2'])->connectPhpRedis($redis);

        $this->assertSame([['connect', 'redis', 6379], ['auth', 'pw'], ['select', 2]], $redis->calls);
    }

    public function testConnectPhpRedisSkipsAuthAndSelectWhenNotNeeded(): void
    {
        $redis = $this->recordingRedis();

        RedisSettings::fromEnvironment([])->connectPhpRedis($redis);

        $this->assertSame([['connect', 'redis', 6379]], $redis->calls);
    }

    public function testRejectedPasswordFailsWithoutRevealingIt(): void
    {
        $redis = $this->recordingRedis(false);

        try {
            RedisSettings::fromEnvironment(['REDIS_PASSWORD' => 'leak-canary-redis'])->connectPhpRedis($redis);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('AUTH failed', $e->getMessage());
            $this->assertStringNotContainsString('leak-canary-redis', $e->getMessage());
        }
    }
}
