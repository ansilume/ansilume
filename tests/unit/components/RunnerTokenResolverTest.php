<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\RunnerHttpClient;
use app\components\RunnerTokenResolver;
use PHPUnit\Framework\TestCase;

class RunnerTokenResolverTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/runner_token_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0700, true);

        unset($_ENV['RUNNER_TOKEN'], $_ENV['RUNNER_NAME'], $_ENV['RUNNER_BOOTSTRAP_SECRET']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            \app\helpers\FileHelper::safeUnlink($f);
        }
        \app\helpers\FileHelper::safeRmdir($this->tmpDir);

        unset($_ENV['RUNNER_TOKEN'], $_ENV['RUNNER_NAME'], $_ENV['RUNNER_BOOTSTRAP_SECRET']);
    }

    private function makeResolver(?RunnerHttpClient $http = null): RunnerTokenResolver
    {
        $http = $http ?? new RunnerHttpClient('http://stub', '');
        $controller = new SilentController('test', \Yii::$app);

        $tmpDir = $this->tmpDir;
        return new class ($http, $controller, $tmpDir) extends RunnerTokenResolver {
            private string $tmpDir;

            public function __construct(RunnerHttpClient $http, \yii\console\Controller $controller, string $tmpDir)
            {
                parent::__construct($http, $controller);
                $this->tmpDir = $tmpDir;
            }

            // Override to use test temp dir instead of /var/www/runtime.
            protected function tokenCacheFile(string $name): string
            {
                return $this->tmpDir . '/runner-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) . '.token';
            }
        };
    }

    // -------------------------------------------------------------------------
    // resolve()
    // -------------------------------------------------------------------------

    public function testResolveReturnsExplicitToken(): void
    {
        $_ENV['RUNNER_TOKEN'] = 'explicit-token';

        $resolver = $this->makeResolver();
        $this->assertSame('explicit-token', $resolver->resolve());
    }

    public function testResolveReturnsEmptyWhenNoTokenAndNoRegistrationVars(): void
    {
        $resolver = $this->makeResolver();
        $this->assertSame('', $resolver->resolve());
    }

    public function testResolveReturnsEmptyWhenNameSetButNoSecret(): void
    {
        $_ENV['RUNNER_NAME'] = 'test-runner';

        $resolver = $this->makeResolver();
        $this->assertSame('', $resolver->resolve());
    }

    public function testResolveReturnsCachedToken(): void
    {
        $_ENV['RUNNER_NAME'] = 'test-runner';
        $_ENV['RUNNER_BOOTSTRAP_SECRET'] = 'secret';

        // Pre-populate cache file.
        $cacheFile = $this->tmpDir . '/runner-test-runner.token';
        file_put_contents($cacheFile, 'cached-token');

        $resolver = $this->makeResolver();
        $this->assertSame('cached-token', $resolver->resolve());
    }

    public function testResolveSelfRegistersWhenNoCachedToken(): void
    {
        $_ENV['RUNNER_NAME'] = 'test-runner';
        $_ENV['RUNNER_BOOTSTRAP_SECRET'] = 'secret';

        $http = $this->createMock(RunnerHttpClient::class);
        $http->method('postUnauthenticated')
            ->willReturn(['ok' => true, 'data' => ['token' => 'new-token']]);

        $resolver = $this->makeResolver($http);
        $this->assertSame('new-token', $resolver->resolve());

        // Token should be cached.
        $cacheFile = $this->tmpDir . '/runner-test-runner.token';
        $this->assertFileExists($cacheFile);
        $this->assertSame('new-token', file_get_contents($cacheFile));
    }

    public function testResolveReturnsEmptyWhenRegistrationFails(): void
    {
        $_ENV['RUNNER_NAME'] = 'test-runner';
        $_ENV['RUNNER_BOOTSTRAP_SECRET'] = 'secret';

        $http = $this->createMock(RunnerHttpClient::class);
        $http->method('postUnauthenticated')
            ->willReturn(['ok' => false, 'error' => 'bad secret']);

        $resolver = $this->makeResolver($http);
        $this->assertSame('', $resolver->resolve());
    }

    public function testResolveReturnsEmptyWhenRegistrationReturnsNull(): void
    {
        $_ENV['RUNNER_NAME'] = 'test-runner';
        $_ENV['RUNNER_BOOTSTRAP_SECRET'] = 'secret';

        $http = $this->createMock(RunnerHttpClient::class);
        $http->method('postUnauthenticated')->willReturn(null);

        $resolver = $this->makeResolver($http);
        $this->assertSame('', $resolver->resolve());
    }

    // -------------------------------------------------------------------------
    // hasCacheFile()
    // -------------------------------------------------------------------------

    public function testHasCacheFileReturnsFalseWithoutRunnerName(): void
    {
        $resolver = $this->makeResolver();
        $this->assertFalse($resolver->hasCacheFile());
    }

    public function testHasCacheFileReturnsFalseWhenNoFile(): void
    {
        $_ENV['RUNNER_NAME'] = 'test-runner';

        $resolver = $this->makeResolver();
        $this->assertFalse($resolver->hasCacheFile());
    }

    public function testHasCacheFileReturnsTrueWhenFileExists(): void
    {
        $_ENV['RUNNER_NAME'] = 'test-runner';
        file_put_contents($this->tmpDir . '/runner-test-runner.token', 'some-token');

        $resolver = $this->makeResolver();
        $this->assertTrue($resolver->hasCacheFile());
    }

    // -------------------------------------------------------------------------
    // clearCacheAndResolve()
    // -------------------------------------------------------------------------

    public function testClearCacheAndResolveDeletesFileAndReResolves(): void
    {
        $_ENV['RUNNER_NAME'] = 'test-runner';
        $_ENV['RUNNER_BOOTSTRAP_SECRET'] = 'secret';

        $cacheFile = $this->tmpDir . '/runner-test-runner.token';
        file_put_contents($cacheFile, 'stale-token');

        $http = $this->createMock(RunnerHttpClient::class);
        $http->method('postUnauthenticated')
            ->willReturn(['ok' => true, 'data' => ['token' => 'fresh-token']]);

        $resolver = $this->makeResolver($http);
        $result = $resolver->clearCacheAndResolve();

        $this->assertSame('fresh-token', $result);
        // Cache file is recreated with the fresh token (old one was deleted).
        $this->assertSame('fresh-token', file_get_contents($cacheFile));
    }

    // -------------------------------------------------------------------------
    // tokenCacheFile path sanitization (via real RunnerTokenResolver)
    // -------------------------------------------------------------------------

    public function testTokenCacheFileUsesRuntimeDirectory(): void
    {
        $http = new RunnerHttpClient('http://stub', '');
        $controller = new SilentController('test', \Yii::$app);
        $resolver = new RunnerTokenResolver($http, $controller);

        $ref = new \ReflectionMethod($resolver, 'tokenCacheFile');
        $ref->setAccessible(true);

        $path = $ref->invoke($resolver, 'runner-1');
        $this->assertStringContainsString('runner-runner-1', $path);
        $this->assertStringEndsWith('.token', $path);
    }

    public function testTokenCacheFileSanitizesSpecialChars(): void
    {
        $http = new RunnerHttpClient('http://stub', '');
        $controller = new SilentController('test', \Yii::$app);
        $resolver = new RunnerTokenResolver($http, $controller);

        $ref = new \ReflectionMethod($resolver, 'tokenCacheFile');
        $ref->setAccessible(true);

        $path = $ref->invoke($resolver, 'runner/bad name!@#');
        $filename = basename($path);
        $this->assertMatchesRegularExpression('/^runner-[a-zA-Z0-9_\-]+\.token$/', $filename);
    }
}
