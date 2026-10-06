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

        unset($_ENV['RUNNER_TOKEN'], $_ENV['RUNNER_NAME'], $_ENV['RUNNER_BOOTSTRAP_SECRET'], $_ENV['RUNNER_GROUP']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            \app\helpers\FileHelper::safeUnlink($f);
        }
        \app\helpers\FileHelper::safeRmdir($this->tmpDir);

        unset($_ENV['RUNNER_TOKEN'], $_ENV['RUNNER_NAME'], $_ENV['RUNNER_BOOTSTRAP_SECRET'], $_ENV['RUNNER_GROUP']);
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
    // selfRegister() retry while the server awaits initial setup
    //
    // Regression: during first boot the runner registered before quickstart
    // had created the admin user; the server answered 503 ("No users exist
    // yet") and the runner logged scary ERROR lines and exited, restarting
    // in a loop. The resolver must instead wait and retry quietly.
    // -------------------------------------------------------------------------

    public function testSelfRegisterRetriesWhileServerAwaitsInitialSetup(): void
    {
        $_ENV['RUNNER_NAME'] = 'test-runner';
        $_ENV['RUNNER_BOOTSTRAP_SECRET'] = 'secret';

        $notReady = ['ok' => false, 'error' => 'No users exist yet. Run setup/admin first.'];
        $http = $this->createMock(RunnerHttpClient::class);
        $http->method('postUnauthenticated')->willReturnOnConsecutiveCalls(
            $notReady,
            $notReady,
            ['ok' => true, 'data' => ['token' => 'fresh-token']]
        );
        $http->method('getLastHttpStatus')->willReturn(503);

        $controller = new CapturingController('test', \Yii::$app);
        $resolver = new RetryCountingTokenResolver($http, $controller, $this->tmpDir);

        $this->assertSame('fresh-token', $resolver->resolve());
        $this->assertSame(2, $resolver->retryWaits);
        $this->assertStringNotContainsString('ERROR', $controller->capturedStderr);
        $this->assertStringContainsString('retrying', $controller->capturedStdout);
    }

    public function testSelfRegisterDoesNotRetryOnPermanentError(): void
    {
        $_ENV['RUNNER_NAME'] = 'test-runner';
        $_ENV['RUNNER_BOOTSTRAP_SECRET'] = 'secret';

        $http = $this->createMock(RunnerHttpClient::class);
        $http->method('postUnauthenticated')
            ->willReturn(['ok' => false, 'error' => 'Invalid bootstrap secret.']);
        $http->method('getLastHttpStatus')->willReturn(403);

        $controller = new CapturingController('test', \Yii::$app);
        $resolver = new RetryCountingTokenResolver($http, $controller, $this->tmpDir);

        $this->assertSame('', $resolver->resolve());
        $this->assertSame(0, $resolver->retryWaits);
        $this->assertStringContainsString('ERROR: Registration failed', $controller->capturedStderr);
    }

    public function testSelfRegisterGivesUpAfterMaxAttemptsWhenServerStaysUnready(): void
    {
        $_ENV['RUNNER_NAME'] = 'test-runner';
        $_ENV['RUNNER_BOOTSTRAP_SECRET'] = 'secret';

        $http = $this->createMock(RunnerHttpClient::class);
        $http->method('postUnauthenticated')
            ->willReturn(['ok' => false, 'error' => 'No users exist yet. Run setup/admin first.']);
        $http->method('getLastHttpStatus')->willReturn(503);

        $controller = new CapturingController('test', \Yii::$app);
        $resolver = new RetryCountingTokenResolver($http, $controller, $this->tmpDir);

        $this->assertSame('', $resolver->resolve());
        // 12 attempts total (kept in sync with RunnerTokenResolver) → 11 waits.
        $this->assertSame(11, $resolver->retryWaits);
        $this->assertStringContainsString('ERROR: Registration failed', $controller->capturedStderr);
        // Operators must get actionable guidance, not just the raw server error.
        $this->assertStringContainsString('complete the initial setup', $controller->capturedStderr);
    }

    public function testSelfRegisterStopsRetryingWhenServerBecomesReadyButRejectsRequest(): void
    {
        $_ENV['RUNNER_NAME'] = 'test-runner';
        $_ENV['RUNNER_BOOTSTRAP_SECRET'] = 'secret';

        $http = $this->createMock(RunnerHttpClient::class);
        $http->method('postUnauthenticated')->willReturnOnConsecutiveCalls(
            ['ok' => false, 'error' => 'No users exist yet. Run setup/admin first.'],
            ['ok' => false, 'error' => 'Runner group "missing" not found.']
        );
        // The status must be re-evaluated on EVERY attempt: once the server
        // stops answering 503, a rejection is permanent and must fail loudly.
        $http->method('getLastHttpStatus')->willReturnOnConsecutiveCalls(503, 400);

        $controller = new CapturingController('test', \Yii::$app);
        $resolver = new RetryCountingTokenResolver($http, $controller, $this->tmpDir);

        $this->assertSame('', $resolver->resolve());
        $this->assertSame(1, $resolver->retryWaits);
        $this->assertStringContainsString('ERROR: Registration failed', $controller->capturedStderr);
        $this->assertStringContainsString('not found', $controller->capturedStderr);
    }

    public function testSelfRegisterRetriesWhenNotReadyResponseHasNoJsonBody(): void
    {
        $_ENV['RUNNER_NAME'] = 'test-runner';
        $_ENV['RUNNER_BOOTSTRAP_SECRET'] = 'secret';

        // nginx answers 502/503 with an HTML error page while the app
        // container is still booting — the client returns null for the
        // unparsable body but the HTTP status is still available.
        $http = $this->createMock(RunnerHttpClient::class);
        $http->method('postUnauthenticated')->willReturnOnConsecutiveCalls(
            null,
            null,
            ['ok' => true, 'data' => ['token' => 'fresh-token']]
        );
        $http->method('getLastHttpStatus')->willReturn(503);

        $controller = new CapturingController('test', \Yii::$app);
        $resolver = new RetryCountingTokenResolver($http, $controller, $this->tmpDir);

        $this->assertSame('fresh-token', $resolver->resolve());
        $this->assertSame(2, $resolver->retryWaits);
        $this->assertStringNotContainsString('ERROR', $controller->capturedStderr);
    }

    public function testSelfRegisterFailsImmediatelyWhenServerUnreachable(): void
    {
        $_ENV['RUNNER_NAME'] = 'test-runner';
        $_ENV['RUNNER_BOOTSTRAP_SECRET'] = 'secret';

        $http = $this->createMock(RunnerHttpClient::class);
        $http->method('postUnauthenticated')->willReturn(null);
        $http->method('getLastHttpStatus')->willReturn(0);

        $controller = new CapturingController('test', \Yii::$app);
        $resolver = new RetryCountingTokenResolver($http, $controller, $this->tmpDir);

        $this->assertSame('', $resolver->resolve());
        $this->assertSame(0, $resolver->retryWaits);
        $this->assertStringContainsString('ERROR: Could not reach the server', $controller->capturedStderr);
    }

    public function testSelfRegisterGiveUpHintAfter502PointsAtTheAppContainerNotTheAdminUser(): void
    {
        $_ENV['RUNNER_NAME'] = 'test-runner';
        $_ENV['RUNNER_BOOTSTRAP_SECRET'] = 'secret';

        // nginx keeps answering 502 (HTML page, no JSON) because the app
        // container never came up. Telling the operator to "create the admin
        // user" would send them down the wrong path.
        $http = $this->createMock(RunnerHttpClient::class);
        $http->method('postUnauthenticated')->willReturn(null);
        $http->method('getLastHttpStatus')->willReturn(502);

        $controller = new CapturingController('test', \Yii::$app);
        $resolver = new RetryCountingTokenResolver($http, $controller, $this->tmpDir);

        $this->assertSame('', $resolver->resolve());
        $this->assertSame(11, $resolver->retryWaits);
        $this->assertStringContainsString('ERROR: Registration failed: HTTP 502', $controller->capturedStderr);
        $this->assertStringContainsString('app container', $controller->capturedStderr);
        $this->assertStringContainsString('docker compose logs app', $controller->capturedStderr);
        $this->assertStringNotContainsString('admin user', $controller->capturedStderr);
    }

    public function testSelfRegisterSendsRunnerGroupWhenConfigured(): void
    {
        $_ENV['RUNNER_NAME'] = 'test-runner';
        $_ENV['RUNNER_BOOTSTRAP_SECRET'] = 'secret';
        $_ENV['RUNNER_GROUP'] = 'dc2-prod';

        $http = $this->createMock(RunnerHttpClient::class);
        $http->expects($this->once())
            ->method('postUnauthenticated')
            ->with(
                '/api/runner/v1/register',
                $this->callback(static function (array $payload): bool {
                    return ($payload['group'] ?? null) === 'dc2-prod'
                        && $payload['name'] === 'test-runner'
                        && $payload['bootstrap_secret'] === 'secret'
                        && isset($payload['software_version']);
                })
            )
            ->willReturn(['ok' => true, 'data' => ['token' => 'grouped-token']]);

        $controller = new CapturingController('test', \Yii::$app);
        $resolver = new RetryCountingTokenResolver($http, $controller, $this->tmpDir);

        $this->assertSame('grouped-token', $resolver->resolve());
        $this->assertStringContainsString("in group 'dc2-prod'", $controller->capturedStdout);
    }

    public function testSelfRegisterOmitsGroupKeyWhenNoGroupConfigured(): void
    {
        $_ENV['RUNNER_NAME'] = 'test-runner';
        $_ENV['RUNNER_BOOTSTRAP_SECRET'] = 'secret';

        $http = $this->createMock(RunnerHttpClient::class);
        $http->expects($this->once())
            ->method('postUnauthenticated')
            ->with(
                '/api/runner/v1/register',
                $this->callback(static function (array $payload): bool {
                    return !array_key_exists('group', $payload);
                })
            )
            ->willReturn(['ok' => true, 'data' => ['token' => 'ungrouped-token']]);

        $controller = new CapturingController('test', \Yii::$app);
        $resolver = new RetryCountingTokenResolver($http, $controller, $this->tmpDir);

        $this->assertSame('ungrouped-token', $resolver->resolve());
        $this->assertStringNotContainsString('in group', $controller->capturedStdout);
    }

    public function testUnreachableServerErrorNamesTheConfiguredApiUrl(): void
    {
        $_ENV['RUNNER_NAME'] = 'test-runner';
        $_ENV['RUNNER_BOOTSTRAP_SECRET'] = 'secret';
        $_ENV['API_URL'] = 'http://ansilume.internal:8080';

        $http = $this->createMock(RunnerHttpClient::class);
        $http->method('postUnauthenticated')->willReturn(null);
        $http->method('getLastHttpStatus')->willReturn(0);

        $controller = new CapturingController('test', \Yii::$app);
        $resolver = new RetryCountingTokenResolver($http, $controller, $this->tmpDir);

        try {
            $this->assertSame('', $resolver->resolve());
        } finally {
            unset($_ENV['API_URL']);
        }
        $this->assertStringContainsString('http://ansilume.internal:8080', $controller->capturedStderr);
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
