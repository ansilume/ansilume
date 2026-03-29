<?php

declare(strict_types=1);

namespace app\tests\unit\commands;

use app\commands\RunnerController;
use PHPUnit\Framework\TestCase;
use yii\console\ExitCode;

/**
 * Tests for RunnerController startup auth logic.
 *
 * We use anonymous subclasses to stub out apiPost(), resolveToken(), and
 * tokenCacheFile() so no real HTTP or filesystem calls are made.
 *
 * Critical regression covered: a 401 response with a parseable JSON body
 * ({"ok":false,"error":"..."}) must NOT be treated as a successful heartbeat.
 * Before the fix, apiPost() returned the decoded body (not null), so the
 * $info === null guard was bypassed and the runner entered the polling loop
 * with an invalid token.
 */
class RunnerControllerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/runner_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0700, true);

        $_ENV['API_URL'] = 'http://test-server';
        $_ENV['RUNNER_NAME'] = 'test-runner';
    }

    protected function tearDown(): void
    {
        // Clean up any token files written during tests
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            \app\helpers\FileHelper::safeUnlink($f);
        }
        \app\helpers\FileHelper::safeRmdir($this->tmpDir);

        unset($_ENV['API_URL'], $_ENV['RUNNER_NAME'], $_ENV['RUNNER_BOOTSTRAP_SECRET'], $_ENV['RUNNER_TOKEN']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a testable RunnerController.
     *
     * @param list<array{int, ?array}> $apiSequence   Each entry is [httpStatus, responseBody].
     *     responseBody null means file_get_contents returned false (network error).
     * @param list<string>             $tokenSequence Tokens returned by successive resolveToken() calls.
     * @param bool                     $hasCacheFile  Whether a cache file should appear to exist.
     */
    private function makeController(
        array $apiSequence,
        array $tokenSequence = ['some-token'],
        bool $hasCacheFile = false,
    ): RunnerController {
        $tmpDir = $this->tmpDir;

        return new class (
            'runner',
            \Yii::$app,
            $apiSequence,
            $tokenSequence,
            $hasCacheFile,
            $tmpDir,
        ) extends RunnerController {
            private array $seq;
            private int $seqIdx = 0;
            private array $tokenSequence;
            private int $tokenIdx = 0;
            private bool $hasCacheFile;
            private string $tmpDir;

            public function __construct(
                $id,
                $module,
                array $seq,
                array $tokenSequence,
                bool $hasCacheFile,
                string $tmpDir,
            ) {
                parent::__construct($id, $module);
                $this->seq = $seq;
                $this->tokenSequence = $tokenSequence;
                $this->hasCacheFile = $hasCacheFile;
                $this->tmpDir = $tmpDir;
            }

            protected function apiPost(string $path, array $body): ?array
            {
                if ($this->seqIdx >= count($this->seq)) {
                    // No more scripted responses — stop the loop and return null.
                    $this->running = false;
                    return null;
                }

                [$status, $result] = $this->seq[$this->seqIdx++];
                $this->lastHttpStatus = $status;

                // Once the startup succeeds and we enter the loop, stop immediately.
                if ($this->seqIdx >= count($this->seq)) {
                    $this->running = false;
                }

                return $result;
            }

            protected function resolveToken(): string
            {
                if ($this->tokenIdx >= count($this->tokenSequence)) {
                    return '';
                }
                return $this->tokenSequence[$this->tokenIdx++];
            }

            protected function tokenCacheFile(string $name): string
            {
                $path = $this->tmpDir . '/runner-' . $name . '.token';
                if ($this->hasCacheFile) {
                    file_put_contents($path, 'stale-token');
                }
                // Reset so subsequent calls (after unlink) reflect real state.
                $this->hasCacheFile = false;
                return $path;
            }

            // Suppress output during tests.
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

    // -------------------------------------------------------------------------
    // tokenCacheFile
    // -------------------------------------------------------------------------

    public function testTokenCacheFileUsesRuntimeDirectory(): void
    {
        $ctrl = new RunnerController('runner', \Yii::$app);
        $ref = new \ReflectionMethod($ctrl, 'tokenCacheFile');
        $ref->setAccessible(true);

        $path = $ref->invoke($ctrl, 'runner-1');
        $this->assertStringContainsString('runner-runner-1', $path);
        $this->assertStringEndsWith('.token', $path);
    }

    public function testTokenCacheFileSanitizesSpecialChars(): void
    {
        $ctrl = new RunnerController('runner', \Yii::$app);
        $ref = new \ReflectionMethod($ctrl, 'tokenCacheFile');
        $ref->setAccessible(true);

        $path = $ref->invoke($ctrl, 'runner/bad name!@#');
        // Only alphanumeric, dash and underscore allowed after "runner-"
        $filename = basename($path);
        $this->assertMatchesRegularExpression('/^runner-[a-zA-Z0-9_\-]+\.token$/', $filename);
    }

    // -------------------------------------------------------------------------
    // Startup heartbeat — failure cases
    // -------------------------------------------------------------------------

    /**
     * REGRESSION: before the fix, a 401 response with a JSON body was returned
     * as ['ok' => false, ...] by apiPost(). The old guard ($info === null) did
     * not catch this, so the runner printed "Runner 'unknown' started" and
     * entered the polling loop with an invalid token.
     */
    public function testExitsWhenHeartbeatReturnsOkFalseWith401(): void
    {
        $ctrl = $this->makeController(
            apiSequence:   [[401, ['ok' => false, 'error' => 'Invalid runner token.']]],
            hasCacheFile:  false,
        );

        $result = $ctrl->actionStart();

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $result);
    }

    public function testExitsWhenHeartbeatReturnsNull(): void
    {
        $ctrl = $this->makeController(
            apiSequence: [[0, null]],
        );

        $result = $ctrl->actionStart();

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $result);
    }

    public function testExitsWhenHeartbeatReturnsOkFalseWithoutCacheFile(): void
    {
        $ctrl = $this->makeController(
            apiSequence:  [[401, ['ok' => false, 'error' => 'Invalid runner token.']]],
            hasCacheFile: false,
        );

        $result = $ctrl->actionStart();

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $result);
    }

    public function testExitsWhenApiUrlIsEmpty(): void
    {
        unset($_ENV['API_URL']);

        $ctrl   = $this->makeController(apiSequence: []);
        $result = $ctrl->actionStart();

        $this->assertSame(ExitCode::CONFIG, $result);
    }

    public function testExitsWhenResolvedTokenIsEmpty(): void
    {
        $ctrl   = $this->makeController(apiSequence: [], tokenSequence: ['']);
        $result = $ctrl->actionStart();

        $this->assertSame(ExitCode::CONFIG, $result);
    }

    // -------------------------------------------------------------------------
    // Startup heartbeat — recovery via stale cache
    // -------------------------------------------------------------------------

    public function testClearsStaleTokenAndReRegistersOn401(): void
    {
        // Sequence: first heartbeat → 401, second heartbeat (after re-register) → 200 ok
        // tokenSequence: first resolveToken() returns stale token, second returns fresh one
        $ctrl = $this->makeController(
            apiSequence:   [
                [401, ['ok' => false, 'error' => 'Invalid runner token.']],
                [200, ['ok' => true, 'data' => ['runner_name' => 'test-runner', 'group_name' => 'default', 'server_time' => time()]]],
            ],
            tokenSequence: ['stale-token', 'fresh-token'],
            hasCacheFile:  true,
        );

        $result = $ctrl->actionStart();

        $this->assertSame(ExitCode::OK, $result);
    }

    public function testCacheFileIsDeletedDuringRecovery(): void
    {
        $cacheFile = $this->tmpDir . '/runner-test-runner.token';
        file_put_contents($cacheFile, 'stale-token');

        // We need the real tokenCacheFile path for this assertion,
        // so build the controller slightly differently.
        $tmpDir = $this->tmpDir;
        $ctrl = new class ('runner', \Yii::$app, $tmpDir) extends RunnerController {
            private array $seq = [];
            private int $seqIdx = 0;
            private string $tmpDir;

            public function __construct($id, $module, string $tmpDir)
            {
                parent::__construct($id, $module);
                $this->tmpDir = $tmpDir;
                $this->seq = [
                    [401, ['ok' => false, 'error' => 'Invalid runner token.']],
                    [200, ['ok' => true, 'data' => ['runner_name' => 'test-runner', 'group_name' => 'default', 'server_time' => time()]]],
                ];
            }

            protected function apiPost(string $path, array $body): ?array
            {
                if ($this->seqIdx >= count($this->seq)) {
                    $this->running = false;
                    return null;
                }
                [$status, $result] = $this->seq[$this->seqIdx++];
                $this->lastHttpStatus = $status;
                if ($this->seqIdx >= count($this->seq)) {
                    $this->running = false;
                }
                return $result;
            }

            protected function resolveToken(): string
            {
                return 'fresh-token';
            }

            protected function tokenCacheFile(string $name): string
            {
                return $this->tmpDir . '/runner-' . $name . '.token';
            }

            public function stdout($string): int
            {
                return 0;
            }
            public function stderr($string): int
            {
                return 0;
            }
        };

        $ctrl->actionStart();

        $this->assertFileDoesNotExist($cacheFile, 'Stale token cache file should be deleted during recovery.');
    }

    public function testExitsWhenReRegistrationFailsAfter401(): void
    {
        // First resolveToken() returns stale token so actionStart proceeds past the empty-token check.
        // First heartbeat → 401, cache file exists → clears it, calls resolveToken() again → ''
        // No second heartbeat is attempted, runner exits.
        $ctrl = $this->makeController(
            apiSequence:   [[401, ['ok' => false, 'error' => 'Invalid runner token.']]],
            tokenSequence: ['stale-token', ''], // second call returns '' (re-registration failed)
            hasCacheFile:  true,
        );

        $result = $ctrl->actionStart();

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $result);
    }

    // -------------------------------------------------------------------------
    // Startup heartbeat — success
    // -------------------------------------------------------------------------

    public function testStartsSuccessfullyWhenHeartbeatReturnsOk(): void
    {
        $ctrl = $this->makeController(
            apiSequence:   [[200, ['ok' => true, 'data' => ['runner_name' => 'test-runner', 'group_name' => 'default', 'server_time' => time()]]]],
            tokenSequence: ['valid-token'],
        );

        $result = $ctrl->actionStart();

        $this->assertSame(ExitCode::OK, $result);
    }
}
