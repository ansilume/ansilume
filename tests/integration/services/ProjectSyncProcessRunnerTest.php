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

    private function makeProject(): Project
    {
        $user = $this->createUser();
        return $this->createProject($user->id);
    }
}
