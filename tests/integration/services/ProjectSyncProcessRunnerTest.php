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
    /** Test values, not secrets: a repository URL with credentials, and how it is shown. */
    private const SECRET = 'w5c-runner-token-6602';
    private const URL = 'https://deploy:' . self::SECRET . '@git.example.com/org/playbooks.git';
    private const SHOWN = 'https://***@git.example.com/org/playbooks.git';

    /**
     * How git names a URL whose password has a raw "@" (https://u:p@TAIL@host):
     * it splits at the first "@", so the rest of the password stands as the user.
     */
    private const TAIL = 'w5c-runner-tail-6603';
    private const GIT_NAMED_URL = "'https://" . self::TAIL . "@git.example.com/org/playbooks.git/': URL rejected: Bad hostname";
    private const GIT_NAMED_SHOWN = "'https://***@git.example.com/org/playbooks.git/': URL rejected: Bad hostname";

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

    /**
     * regression: a URL with credentials that git wrote (git names the URL
     * in its messages; with a raw "@" in the password, the rest of the
     * password as the user) reached the sync output, which every project
     * viewer polls, and the output returned for the error, as it was.
     */
    public function testRegressionGitOutputNamingCredentialsWasWrittenAsItCame(): void
    {
        $project = $this->makeProject();

        [$stdout, $stderr, $exitCode] = (new ProjectSyncProcessRunner())->run(
            $project,
            ['/bin/sh', '-c', 'printf "remote: %s\n" "$1"; printf "fatal: unable to access %s\n" "$2" >&2; exit 128', 'sh', self::URL, self::GIT_NAMED_URL],
            [],
            5,
        );

        $written = $this->writtenOutput($project);
        $this->assertStringNotContainsString(self::SECRET, $written . $stdout . $stderr);
        $this->assertStringNotContainsString(self::TAIL, $written . $stdout . $stderr);
        $this->assertSame(128, $exitCode);
        $this->assertSame('remote: ' . self::SHOWN . "\n", $stdout);
        $this->assertSame('fatal: unable to access ' . self::GIT_NAMED_SHOWN . "\n", $stderr);
        $this->assertSame(
            [
                [ProjectSyncLog::STREAM_STDOUT, 'remote: ' . self::SHOWN . "\n"],
                [ProjectSyncLog::STREAM_STDERR, 'fatal: unable to access ' . self::GIT_NAMED_SHOWN . "\n"],
            ],
            $this->rowsByStream($project)
        );
    }

    /**
     * regression: every read became a row of its own, so a URL that came in
     * two reads was split across two rows, and neither showed its
     * credentials whole to redact. A row ends at whitespace now: the rest
     * waits for the next read.
     */
    public function testRegressionAUrlSplitAcrossTwoReadsReachedTheOutputWhole(): void
    {
        $project = $this->makeProject();
        $half = (int)(strlen(self::URL) / 2);

        [, $stderr] = (new ProjectSyncProcessRunner())->run(
            $project,
            ['/bin/sh', '-c', 'printf "fatal: %s" "$1" >&2; sleep 1; printf "%s/info/refs not valid\n" "$2" >&2', 'sh', substr(self::URL, 0, $half), substr(self::URL, $half)],
            [],
            10,
        );

        $written = $this->writtenOutput($project);
        $this->assertStringNotContainsString(self::SECRET, $written . $stderr);
        $this->assertStringNotContainsString('deploy:', $written . $stderr);
        $this->assertSame('fatal: ' . self::SHOWN . "/info/refs not valid\n", $written);
        $this->assertSame($written, $stderr);
        $this->assertSame(
            [[ProjectSyncLog::STREAM_STDERR, 'fatal: '], [ProjectSyncLog::STREAM_STDERR, self::SHOWN . "/info/refs not valid\n"]],
            $this->rowsByStream($project),
            'the first read is written up to its last whitespace, the rest with the next read'
        );
    }

    /**
     * Output that ends without whitespace is written when the stream ends;
     * a run without whitespace longer than what is held back is written as
     * it came, so nothing waits for whitespace that never comes.
     */
    public function testOutputWithoutWhitespaceIsWrittenWhole(): void
    {
        $project = $this->makeProject();
        $run = str_repeat('x', 9000);

        [$stdout, $stderr] = (new ProjectSyncProcessRunner())->run(
            $project,
            ['/bin/sh', '-c', 'printf "%s" "$1"; sleep 1; printf "%s" "$2"; printf "stderr-without-newline" >&2', 'sh', $run, 'tail-without-newline'],
            [],
            10,
        );

        $this->assertSame($run . 'tail-without-newline', $stdout);
        $this->assertSame('stderr-without-newline', $stderr);
        $rows = $this->rowsByStream($project);
        $this->assertSame($run . 'tail-without-newline', implode('', array_column(array_filter($rows, static fn (array $r): bool => $r[0] === ProjectSyncLog::STREAM_STDOUT), 1)));
        $this->assertContains([ProjectSyncLog::STREAM_STDERR, 'stderr-without-newline'], $rows);
        $this->assertGreaterThan(1, count($rows), 'the long run was written before its stream ended');
    }

    /**
     * regression: the timeout's row and error named the command with the
     * URL's credentials; the error became the project's last sync error and
     * was sent with project.sync_failed.
     */
    public function testRegressionATimeoutNamedTheCredentialsOfTheCommand(): void
    {
        $project = $this->makeProject();

        try {
            (new ProjectSyncProcessRunner())->run($project, ['/bin/sh', '-c', 'sleep 30', 'git', 'clone', '--', self::URL, '/tmp/dest'], [], 1);
            $this->fail('Expected timeout to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString(self::SECRET, (string)$e);
            $this->assertSame(sprintf(ProjectSyncProcessRunner::TIMED_OUT, 1, '/bin/sh -c sleep 30 git clone -- ' . self::SHOWN . ' /tmp/dest'), $e->getMessage());
        }

        $this->assertStringNotContainsString(self::SECRET, $this->writtenOutput($project));
        $this->assertSame(
            [[ProjectSyncLog::STREAM_SYSTEM, sprintf(ProjectSyncProcessRunner::TIMED_OUT, 1, '/bin/sh -c sleep 30 git clone -- ' . self::SHOWN . ' /tmp/dest') . "\n"]],
            $this->rowsByStream($project)
        );
    }

    /**
     * The system rows that ProjectService writes (the git command) are
     * redacted here as well.
     */
    public function testASystemRowIsWrittenWithoutCredentials(): void
    {
        $project = $this->makeProject();

        (new ProjectSyncProcessRunner())->appendSystem($project, '$ git clone -- ' . self::URL . " /tmp/dest\n");

        $this->assertSame([[ProjectSyncLog::STREAM_SYSTEM, '$ git clone -- ' . self::SHOWN . " /tmp/dest\n"]], $this->rowsByStream($project));
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

    /**
     * The rows of $project's sync output, in order, as [stream, content].
     *
     * @return list<array{0: string, 1: string}>
     */
    private function rowsByStream(Project $project): array
    {
        $rows = [];
        foreach (ProjectSyncLog::find()->where(['project_id' => $project->id])->orderBy(['sequence' => SORT_ASC])->all() as $row) {
            /** @var ProjectSyncLog $row */
            $rows[] = [(string)$row->stream, (string)$row->content];
        }

        return $rows;
    }

    /** Everything $project's sync output holds, as the page shows it: its rows one after the other. */
    private function writtenOutput(Project $project): string
    {
        return implode('', array_column($this->rowsByStream($project), 1));
    }
}
