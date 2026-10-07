<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Project;
use app\models\ProjectSyncLog;
use app\services\ProjectSyncProcessRunner;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for the runner that drives git subprocesses with a hard
 * timeout and live log streaming. Uses real shell subprocesses (`sh -c …`)
 * because the timeout/EOF behaviour is exactly what we need to verify, and
 * it can't be observed without an actual child process.
 */
class ProjectSyncProcessRunnerTest extends DbTestCase
{
    public function testRunCapturesStdoutChunksToProjectSyncLog(): void
    {
        $project = $this->makeProject();
        $runner = new ProjectSyncProcessRunner();

        // sh -c so we don't depend on git for the test — the runner doesn't
        // care which process it's reading, only that it produces output.
        [$stdout, $stderr, $exitCode] = $runner->run(
            $project,
            ['sh', '-c', 'printf "hello\nworld\n"; printf "oops\n" >&2; exit 0'],
            [],
            5,
        );

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('hello', $stdout);
        $this->assertStringContainsString('oops', $stderr);

        $logs = ProjectSyncLog::find()
            ->where(['project_id' => $project->id])
            ->orderBy(['sequence' => SORT_ASC])
            ->all();
        $this->assertGreaterThanOrEqual(1, count($logs), 'every chunk must produce at least one log row');

        $byStream = [];
        foreach ($logs as $log) {
            /** @var ProjectSyncLog $log */
            $byStream[$log->stream][] = $log->content;
        }
        $this->assertNotEmpty($byStream[ProjectSyncLog::STREAM_STDOUT] ?? []);
        $this->assertNotEmpty($byStream[ProjectSyncLog::STREAM_STDERR] ?? []);
    }

    public function testRunReturnsNonZeroExitCodeWithoutThrowing(): void
    {
        $project = $this->makeProject();
        $runner = new ProjectSyncProcessRunner();

        [, , $exitCode] = $runner->run(
            $project,
            ['sh', '-c', 'echo "boom"; exit 7'],
            [],
            5,
        );

        $this->assertSame(7, $exitCode, 'The caller (ProjectService::runGit) is the one that converts non-zero exits to RuntimeException — runner just reports.');
    }

    public function testRunThrowsRuntimeExceptionOnTimeout(): void
    {
        $project = $this->makeProject();
        $runner = new ProjectSyncProcessRunner();

        $start = microtime(true);
        try {
            $runner->run($project, ['sh', '-c', 'sleep 30'], [], 1);
            $this->fail('Expected timeout to throw.');
        } catch (\RuntimeException $e) {
            $elapsed = microtime(true) - $start;
            $this->assertStringContainsString('timed out', $e->getMessage());
            $this->assertLessThan(
                10,
                $elapsed,
                'Timeout must terminate the child quickly — otherwise the queue worker stays wedged.',
            );
        }

        // The system-stream entry tells operators why the run aborted.
        $log = ProjectSyncLog::find()
            ->where(['project_id' => $project->id, 'stream' => ProjectSyncLog::STREAM_SYSTEM])
            ->orderBy(['sequence' => SORT_DESC])
            ->one();
        $this->assertNotNull($log);
        $this->assertStringContainsString('timed out', (string)$log->content);
    }

    public function testRunSequenceMonotonicAcrossChunks(): void
    {
        $project = $this->makeProject();
        $runner = new ProjectSyncProcessRunner();

        $runner->run(
            $project,
            ['sh', '-c', 'for i in 1 2 3 4 5; do printf "line-%s\n" "$i"; done'],
            [],
            5,
        );

        $sequences = ProjectSyncLog::find()
            ->where(['project_id' => $project->id])
            ->orderBy(['id' => SORT_ASC])
            ->select('sequence')
            ->column();
        $this->assertSame($sequences, array_values(array_unique($sequences)), 'sequence must be unique per project');
        $sortedAsc = $sequences;
        sort($sortedAsc);
        $this->assertSame($sortedAsc, $sequences, 'sequence must be monotonically increasing per insert order');
    }

    /**
     * Regression: a signal arriving while the runner waits in stream_select()
     * (the queue worker's SIGALRM heartbeat) makes select() fail with EINTR.
     * PHP reports that as an "Interrupted system call" warning, Yii's error
     * handler turns it into an ErrorException, and the exception aborted the
     * sync although nothing had gone wrong. The runner now selects again and
     * the run completes with the child's output.
     *
     * The kernel interrupts select() whether PHP runs the signal handler at
     * once (async signals) or later: the queue worker runs it deferred, as
     * yii2-queue's SignalLoop dispatches signals between jobs.
     *
     * @return array<string, array{0: bool}>
     */
    public static function signalDeliveryProvider(): array
    {
        return [
            'deferred signal handler, as in the queue worker' => [false],
            'asynchronous signal handler' => [true],
        ];
    }

    /**
     * @dataProvider signalDeliveryProvider
     * @requires extension pcntl
     */
    public function testASignalInterruptingTheSelectDoesNotAbortTheRun(bool $asyncSignals): void
    {
        $project = $this->makeProject();
        $alarms = 0;
        $interruptions = 0;
        $previousAsync = pcntl_async_signals($asyncSignals);
        $previousHandler = pcntl_signal_get_handler(SIGALRM);
        pcntl_signal(SIGALRM, static function () use (&$alarms): void {
            $alarms++;
        });
        $this->useYiiErrorHandler($interruptions);
        // The child stays silent for two seconds, so the runner is waiting in
        // stream_select() when the alarm goes off after one.
        pcntl_alarm(1);
        try {
            [$stdout, $stderr, $exitCode] = (new ProjectSyncProcessRunner())->run(
                $project,
                ['sh', '-c', 'sleep 2; echo after-the-signal'],
                [],
                30,
            );
        } finally {
            pcntl_alarm(0);
            restore_error_handler();
            pcntl_signal_dispatch();
            pcntl_signal(SIGALRM, $previousHandler);
            pcntl_async_signals($previousAsync);
        }

        $this->assertSame(1, $alarms);
        $this->assertGreaterThanOrEqual(1, $interruptions, 'no select was interrupted, so the test proves nothing');
        $this->assertSame(0, $exitCode);
        $this->assertSame("after-the-signal\n", $stdout);
        $this->assertSame('', $stderr);
        $this->assertSame(
            ["after-the-signal\n"],
            ProjectSyncLog::find()
                ->select('content')
                ->where(['project_id' => $project->id, 'stream' => ProjectSyncLog::STREAM_STDOUT])
                ->column()
        );
    }

    /**
     * Only an interrupted select is retried; any other stream_select() error
     * still aborts the run. Here a real one without a test seam: with more
     * than FD_SETSIZE (1024) descriptors open, the git pipes get numbers
     * select() cannot watch.
     *
     * @requires extension posix
     */
    public function testOtherStreamSelectErrorsStillAbortTheRun(): void
    {
        $project = $this->makeProject();
        $restoreLimit = $this->allowOpenFiles(2048);
        $handles = [];
        $interruptions = 0;
        try {
            while (count($handles) < 1100) {
                $handle = fopen('/dev/null', 'rb');
                if ($handle === false) {
                    $this->fail('Could not open more than ' . count($handles) . ' descriptors.');
                }
                $handles[] = $handle;
            }
            $this->useYiiErrorHandler($interruptions);
            try {
                (new ProjectSyncProcessRunner())->run($project, ['sh', '-c', 'echo never-read'], [], 5);
                $this->fail('The FD_SETSIZE error must reach the caller.');
            } catch (\ErrorException $e) {
                $this->assertStringStartsWith(
                    'stream_select(): You MUST recompile PHP with a larger value of FD_SETSIZE',
                    $e->getMessage()
                );
            } finally {
                restore_error_handler();
            }
        } finally {
            array_map('fclose', $handles);
            $restoreLimit();
        }
        $this->assertSame(0, $interruptions);
    }

    /**
     * Installs Yii's own error handler, which the queue worker runs under and
     * which turns warnings into yii\base\ErrorException, and counts the
     * "Interrupted system call" warnings it sees. Pair with
     * restore_error_handler().
     */
    private function useYiiErrorHandler(int &$interruptions): void
    {
        $yii = \Yii::$app->getErrorHandler();
        set_error_handler(
            static function (int $code, string $message, string $file, int $line) use ($yii, &$interruptions): bool {
                if (str_contains($message, 'Interrupted system call')) {
                    $interruptions++;
                }

                return $yii->handleError($code, $message, $file, $line);
            }
        );
    }

    /**
     * Raises the soft open-file limit to at least $files and returns a
     * callable that puts the previous limit back.
     */
    private function allowOpenFiles(int $files): callable
    {
        $limits = posix_getrlimit();
        $this->assertIsArray($limits);
        $soft = $limits['soft openfiles'];
        $hard = $limits['hard openfiles'];
        if ($soft === 'unlimited' || (int)$soft >= $files) {
            return static function (): void {
            };
        }
        if ($hard !== 'unlimited' && (int)$hard < $files) {
            $this->markTestSkipped("The hard open-file limit ({$hard}) allows no more than FD_SETSIZE descriptors.");
        }
        $hardLimit = $hard === 'unlimited' ? POSIX_RLIMIT_INFINITY : (int)$hard;
        $this->assertTrue(posix_setrlimit(POSIX_RLIMIT_NOFILE, $files, $hardLimit), 'cannot raise the open-file limit');

        return static function () use ($soft, $hardLimit): void {
            posix_setrlimit(POSIX_RLIMIT_NOFILE, (int)$soft, $hardLimit);
        };
    }

    private function makeProject(): Project
    {
        $user = $this->createUser();
        return $this->createProject($user->id);
    }
}
