<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\OutputMasker;
use app\components\ProcessStarter;
use app\components\RunnerHttpClient;
use app\components\RunnerProcessExecutor;
use app\tests\unit\ProcessStartFailure;
use PHPUnit\Framework\TestCase;

class RunnerProcessExecutorTest extends TestCase
{
    public function testRunSuccessfulCommand(): void
    {
        $logs = [];
        $http = $this->createStubHttp($logs);
        $controller = new SilentController('test', \Yii::$app);
        $executor = new RunnerProcessExecutor($http, $controller);

        [$exitCode, $sequence] = $executor->run(
            99,
            ['echo', 'hello world'],
            [],
            getenv() ?: [],
            1,
        );

        $this->assertSame(0, $exitCode);
        $this->assertGreaterThanOrEqual(1, $sequence);

        // At least one log chunk was posted.
        $logPosts = array_filter($logs, fn ($l) => str_contains($l['path'], '/logs'));
        $this->assertNotEmpty($logPosts);
    }

    public function testRunFailingCommand(): void
    {
        $logs = [];
        $http = $this->createStubHttp($logs);
        $controller = new SilentController('test', \Yii::$app);
        $executor = new RunnerProcessExecutor($http, $controller);

        [$exitCode] = $executor->run(
            99,
            ['sh', '-c', 'exit 42'],
            [],
            getenv() ?: [],
            1,
        );

        $this->assertSame(42, $exitCode);
    }

    /**
     * Regression (PHP 8.3): proc_open() reports a program it cannot run with
     * a warning (posix_spawn), which threw: the runner died with the job it
     * had claimed, and the job stayed running. PHP 8.2 started the process,
     * which exited with 127. The job log now says why, and the runner
     * completes the job with run()'s exit code, once: the executor no longer
     * reports completion itself.
     */
    public function testRunWithInvalidCommandReturnsNonZero(): void
    {
        $logs = [];
        $http = $this->createStubHttp($logs);
        $controller = new SilentController('test', \Yii::$app);
        $executor = new RunnerProcessExecutor($http, $controller);

        $result = $executor->run(
            99,
            ['/nonexistent-binary-xyz'],
            [],
            getenv() ?: [],
            1,
        );

        $this->assertSame([-1, 0, false], $result);
        $this->assertSame([[
            'path' => '/api/runner/v1/jobs/99/logs',
            'body' => [
                'stream' => 'stderr',
                'content' => sprintf(
                    RunnerProcessExecutor::NOT_STARTED,
                    sprintf(ProcessStarter::NOT_STARTED, '/nonexistent-binary-xyz', ProcessStartFailure::NO_SUCH_FILE)
                ),
                'sequence' => 0,
            ],
        ]], $logs);
    }

    /**
     * Regression (PHP 8.3): as above for ansible-playbook not on the
     * runner's PATH, as on a runner installed by hand whose service PATH
     * lacks it. PHP 8.3 looks the program up in the PATH of the runner
     * process: the job's environment names the directory that holds
     * ansible-playbook, and still it does not start. The project directory
     * exists, so the reason does not blame it. The runner's own output says
     * why as well.
     */
    public function testAPlaybookThatIsNotOnThePathFailsTheJobWithTheReason(): void
    {
        $playbookDirectory = $this->directoryOnThePath('ansible-playbook');
        $logs = [];
        $controller = new CapturingController('test', \Yii::$app);
        $executor = new RunnerProcessExecutor($this->createStubHttp($logs), $controller);
        $reason = sprintf(
            RunnerProcessExecutor::NOT_STARTED,
            sprintf(ProcessStarter::NOT_STARTED, 'ansible-playbook', ProcessStartFailure::NO_SUCH_FILE)
        );

        $path = ProcessStartFailure::emptyPath();
        try {
            $result = $executor->run(7, ['ansible-playbook', 'site.yml'], ['project_path' => sys_get_temp_dir()], ['PATH' => $playbookDirectory], 1);
        } finally {
            $path->restore();
        }

        $this->assertSame([-1, 0, false], $result);
        $this->assertSame([['path' => '/api/runner/v1/jobs/7/logs', 'body' => ['stream' => 'stderr', 'content' => $reason, 'sequence' => 0]]], $logs);
        $this->assertSame($reason, $controller->capturedStderr);
    }

    /**
     * Review finding: a NUL byte in a command element, which a job's limit
     * can hold, made proc_open() throw a ValueError on every PHP version,
     * and the runner died with the job it had claimed. The job fails with
     * the reason, which names the element, never its value.
     */
    public function testANulByteInTheCommandFailsTheJobWithTheReason(): void
    {
        $logs = [];
        $executor = new RunnerProcessExecutor($this->createStubHttp($logs), new SilentController('test', \Yii::$app));

        $result = $executor->run(7, ['ansible-playbook', '--limit', "web\0x", 'site.yml'], [], getenv() ?: [], 1);

        $this->assertSame([-1, 0, false], $result);
        $this->assertSame([[
            'path' => '/api/runner/v1/jobs/7/logs',
            'body' => [
                'stream' => 'stderr',
                'content' => sprintf(
                    RunnerProcessExecutor::NOT_STARTED,
                    sprintf(ProcessStarter::NOT_STARTED, 'ansible-playbook', 'Command array element 3 contains a null byte')
                ),
                'sequence' => 0,
            ],
        ]], $logs);
    }

    public function testRunCapturesStderr(): void
    {
        $logs = [];
        $http = $this->createStubHttp($logs);
        $controller = new SilentController('test', \Yii::$app);
        $executor = new RunnerProcessExecutor($http, $controller);

        [$exitCode] = $executor->run(
            99,
            ['sh', '-c', 'echo error >&2'],
            [],
            getenv() ?: [],
            1,
        );

        $this->assertSame(0, $exitCode);

        $stderrPosts = array_filter($logs, fn ($l) => str_contains($l['path'], '/logs') && ($l['body']['stream'] ?? '') === 'stderr');
        $this->assertNotEmpty($stderrPosts);
    }

    public function testRunUsesProjectCwd(): void
    {
        $logs = [];
        $http = $this->createStubHttp($logs);
        $controller = new SilentController('test', \Yii::$app);
        $executor = new RunnerProcessExecutor($http, $controller);

        [$exitCode] = $executor->run(
            99,
            ['pwd'],
            ['project_path' => '/tmp'],
            getenv() ?: [],
            1,
        );

        $this->assertSame(0, $exitCode);

        // Find the stdout log that contains /tmp.
        $stdoutPosts = array_filter($logs, fn ($l) => str_contains($l['path'], '/logs') && ($l['body']['stream'] ?? '') === 'stdout');
        $combined = implode('', array_map(fn ($l) => $l['body']['content'] ?? '', $stdoutPosts));
        $this->assertStringContainsString('/tmp', $combined);
    }

    public function testRunKillsProcessOnTimeoutAndPostsTimeoutLog(): void
    {
        $logs = [];
        $http = $this->createStubHttp($logs);
        $controller = new SilentController('test', \Yii::$app);
        $executor = new RunnerProcessExecutor($http, $controller);

        // timeoutMinutes=0 → deadline == start_time, so the first loop iteration
        // sees remaining <= 0 and triggers killTimedOutProcess + sendTimeoutLog.
        // `sleep 10` keeps the child alive long enough for the loop to run.
        [$exitCode, $sequence] = $executor->run(
            99,
            ['sh', '-c', 'sleep 10'],
            [],
            getenv() ?: [],
            0,
        );

        $this->assertSame(-1, $exitCode);
        $this->assertGreaterThanOrEqual(1, $sequence);

        // sendTimeoutLog was invoked.
        $timeoutLogs = array_filter(
            $logs,
            fn ($l) => str_contains($l['path'], '/logs') && str_contains((string)($l['body']['content'] ?? ''), 'exceeded timeout')
        );
        $this->assertNotEmpty($timeoutLogs);
    }

    /**
     * A password answer the process writes in two pieces, with a pause in
     * between so they arrive as separate chunks, is masked in the posted log;
     * no post is empty and the sequence numbers stay consecutive.
     */
    public function testTheMaskerHidesAnAnswerSplitAcrossChunks(): void
    {
        $logs = [];
        $executor = new RunnerProcessExecutor(
            $this->createStubHttp($logs),
            new SilentController('test', \Yii::$app),
            new OutputMasker(['db_password' => 'example-db-answer-4711'])
        );

        [$exitCode, $sequence] = $executor->run(
            99,
            ['sh', '-c', 'printf "value: example-db"; sleep 0.3; printf -- "-answer-4711 done\n"; printf "example-db-answer-4711" >&2'],
            [],
            getenv() ?: [],
            1,
        );

        $this->assertSame(0, $exitCode);
        $posts = array_values(array_filter($logs, fn ($l) => str_contains($l['path'], '/logs')));
        $stdout = implode('', array_map(fn ($l) => $l['body']['stream'] === 'stdout' ? $l['body']['content'] : '', $posts));
        $stderr = implode('', array_map(fn ($l) => $l['body']['stream'] === 'stderr' ? $l['body']['content'] : '', $posts));
        $this->assertSame("value: ******** done\n", $stdout);
        $this->assertSame('********', $stderr);
        foreach ($posts as $i => $post) {
            $this->assertNotSame('', $post['body']['content'], 'empty chunks are not posted');
            $this->assertSame($i, $post['body']['sequence']);
        }
        $this->assertSame(count($posts), $sequence);
    }

    /**
     * What the masker still holds when the process ends is posted before
     * the run returns, so before the runner reports completion.
     */
    public function testTheHeldBackTailIsPostedWhenTheProcessEnds(): void
    {
        $logs = [];
        $executor = new RunnerProcessExecutor(
            $this->createStubHttp($logs),
            new SilentController('test', \Yii::$app),
            new OutputMasker(['db_password' => 'example-db-answer-4711'])
        );

        $executor->run(99, ['sh', '-c', 'printf "last word: exam"'], [], getenv() ?: [], 1);

        $contents = array_map(fn ($l) => $l['body']['content'], array_filter($logs, fn ($l) => str_contains($l['path'], '/logs')));
        $this->assertSame('last word: exam', implode('', $contents));
        $this->assertSame('exam', end($contents), 'the tail comes last');
    }

    /**
     * The directory of the PATH that holds $program.
     */
    private function directoryOnThePath(string $program): string
    {
        foreach (explode(':', (string)getenv('PATH')) as $directory) {
            if ($directory !== '' && is_file($directory . '/' . $program) && is_executable($directory . '/' . $program)) {
                return $directory;
            }
        }
        $this->markTestSkipped("{$program} is not on the PATH");
    }

    /**
     * Create a stub RunnerHttpClient that records all post() calls.
     *
     * @param array<int, array{path: string, body: array}> $logs Reference to capture calls.
     */
    private function createStubHttp(array &$logs): RunnerHttpClient
    {
        $mock = $this->createMock(RunnerHttpClient::class);
        $mock->method('post')
            ->willReturnCallback(function (string $path, array $body) use (&$logs): ?array {
                $logs[] = ['path' => $path, 'body' => $body];
                return ['ok' => true];
            });

        return $mock;
    }
}
