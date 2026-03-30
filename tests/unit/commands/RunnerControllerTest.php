<?php

declare(strict_types=1);

namespace app\tests\unit\commands;

use app\commands\RunnerController;
use app\components\RunnerHttpClient;
use app\components\RunnerTokenResolver;
use PHPUnit\Framework\TestCase;
use yii\console\ExitCode;

/**
 * Tests for RunnerController startup auth logic.
 *
 * Uses stub RunnerHttpClient and RunnerTokenResolver injected via the
 * controller's protected properties, so no real HTTP or filesystem calls are made.
 *
 * Critical regression covered: a 401 response with a parseable JSON body
 * ({"ok":false,"error":"..."}) must NOT be treated as a successful heartbeat.
 * Before the fix, apiPost() returned the decoded body (not null), so the
 * $info === null guard was bypassed and the runner entered the polling loop
 * with an invalid token.
 */
class RunnerControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['API_URL'] = 'http://test-server';
        $_ENV['RUNNER_NAME'] = 'test-runner';
    }

    protected function tearDown(): void
    {
        unset($_ENV['API_URL'], $_ENV['RUNNER_NAME'], $_ENV['RUNNER_BOOTSTRAP_SECRET'], $_ENV['RUNNER_TOKEN']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a testable RunnerController with stub collaborators.
     *
     * @param list<array{int, ?array}> $apiSequence   Each entry is [httpStatus, responseBody].
     * @param list<string>             $tokenSequence Tokens returned by successive resolve() calls.
     * @param bool                     $hasCacheFile  Whether a cache file should appear to exist.
     */
    private function makeController(
        array $apiSequence,
        array $tokenSequence = ['some-token'],
        bool $hasCacheFile = false,
    ): RunnerController {
        $stubHttp = new StubRunnerHttpClient($apiSequence);
        $stubTokenResolver = new StubRunnerTokenResolver($tokenSequence, $hasCacheFile);

        $ctrl = new class ('runner', \Yii::$app, $stubHttp, $stubTokenResolver) extends RunnerController {
            public function __construct($id, $module, RunnerHttpClient $http, RunnerTokenResolver $tokenResolver)
            {
                parent::__construct($id, $module);
                $this->http = $http;
                $this->tokenResolver = $tokenResolver;
            }

            // Stop the poll loop after exhausting scripted responses.
            protected function pollLoop(): void
            {
                // Do not actually poll — startup tests only care about auth.
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

        // Wire the stop-on-exhaust callback.
        $stubHttp->setOnExhausted(function () use ($ctrl): void {
            // This shouldn't be reached in startup tests, but safety net.
        });

        return $ctrl;
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

        $ctrl = $this->makeController(apiSequence: []);
        $result = $ctrl->actionStart();

        $this->assertSame(ExitCode::CONFIG, $result);
    }

    public function testExitsWhenResolvedTokenIsEmpty(): void
    {
        $ctrl = $this->makeController(apiSequence: [], tokenSequence: ['']);
        $result = $ctrl->actionStart();

        $this->assertSame(ExitCode::CONFIG, $result);
    }

    // -------------------------------------------------------------------------
    // Startup heartbeat — recovery via stale cache
    // -------------------------------------------------------------------------

    public function testClearsStaleTokenAndReRegistersOn401(): void
    {
        // Sequence: first heartbeat → 401, second heartbeat (after re-register) → 200 ok
        // tokenSequence: first resolve() returns stale token, second returns fresh one
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

    public function testExitsWhenReRegistrationFailsAfter401(): void
    {
        // First resolve() returns stale token so actionStart proceeds past the empty-token check.
        // First heartbeat → 401, cache file exists → clears it, calls resolve() again → ''
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

// ---------------------------------------------------------------------------
// Stub collaborators — defined in the same file for test isolation.
// ---------------------------------------------------------------------------

/**
 * Stub HTTP client that returns scripted responses.
 */
class StubRunnerHttpClient extends RunnerHttpClient
{
    /** @var list<array{int, ?array}> */
    private array $seq;
    private int $seqIdx = 0;
    private int $lastStatus = 0;
    /** @var callable|null */
    private $onExhausted;

    /**
     * @param list<array{int, ?array}> $seq
     */
    public function __construct(array $seq)
    {
        parent::__construct('http://test-server', '');
        $this->seq = $seq;
    }

    public function setOnExhausted(callable $cb): void
    {
        $this->onExhausted = $cb;
    }

    public function post(string $path, array $body): ?array
    {
        if ($this->seqIdx >= count($this->seq)) {
            if ($this->onExhausted) {
                ($this->onExhausted)();
            }
            return null;
        }

        [$status, $result] = $this->seq[$this->seqIdx++];
        $this->lastStatus = $status;
        return $result;
    }

    public function postUnauthenticated(string $path, array $body): ?array
    {
        return $this->post($path, $body);
    }

    public function getLastHttpStatus(): int
    {
        return $this->lastStatus;
    }

    public function setToken(string $token): void
    {
        // No-op in stub.
    }
}

/**
 * Stub token resolver that returns scripted tokens.
 */
class StubRunnerTokenResolver extends RunnerTokenResolver
{
    /** @var list<string> */
    private array $tokens;
    private int $tokenIdx = 0;
    private bool $stubHasCacheFile;

    /**
     * @param list<string> $tokens
     */
    public function __construct(array $tokens, bool $hasCacheFile = false)
    {
        parent::__construct(
            new RunnerHttpClient('http://stub', ''),
            new \yii\console\Controller('stub', \Yii::$app),
        );
        $this->tokens = $tokens;
        $this->stubHasCacheFile = $hasCacheFile;
    }

    public function resolve(): string
    {
        if ($this->tokenIdx >= count($this->tokens)) {
            return '';
        }
        return $this->tokens[$this->tokenIdx++];
    }

    public function clearCacheAndResolve(): string
    {
        return $this->resolve();
    }

    public function hasCacheFile(): bool
    {
        return $this->stubHasCacheFile;
    }
}
