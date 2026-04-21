<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Job;
use app\models\JobHostSummary;
use app\models\JobLog;
use app\models\JobTask;
use app\services\JobCompletionService;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for JobCompletionService using a real database.
 * Tests complete(), appendLog(), and saveTasks() against actual DB rows.
 */
class JobCompletionServiceIntegrationTest extends DbTestCase
{
    private JobCompletionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \Yii::$app->get('jobCompletionService');
    }

    // -------------------------------------------------------------------------
    // complete()
    // -------------------------------------------------------------------------

    public function testCompleteSetsSucceededStatusOnExitZero(): void
    {
        $job = $this->makeRunningJob();
        // Without at least one task or host summary, complete() now treats
        // exit-0 as "no hosts matched" and flips the status to FAILED.
        // This test exercises the normal succeeded path, so seed a task.
        $this->seedTaskFor($job, 'ok');

        $this->service->complete($job, 0);

        $job->refresh();
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);
    }

    /**
     * Regression: ansible-playbook returns exit 0 and an empty PLAY RECAP
     * when the `hosts:` pattern matches nothing. With the old behaviour
     * this showed up as "succeeded" in the UI even though nothing ran.
     * complete() must now flip these runs to FAILED and append a clear
     * log line so the operator sees why.
     */
    public function testCompleteWithNoTasksAndNoHostSummariesIsMarkedFailed(): void
    {
        $job = $this->makeRunningJob();

        $this->service->complete($job, 0);

        $job->refresh();
        $this->assertSame(
            Job::STATUS_FAILED,
            $job->status,
            'A run with no executed tasks and no host summary must not report as succeeded.',
        );

        $logs = JobLog::find()
            ->where(['job_id' => $job->id, 'stream' => 'stderr'])
            ->orderBy(['sequence' => SORT_DESC])
            ->limit(1)
            ->one();
        $this->assertNotNull($logs, 'An explanatory stderr log entry must be appended.');
        $this->assertStringContainsString('did not execute against any host', (string)$logs->content);
    }

    public function testCompleteWithOnlyHostSummaryIsStillSucceeded(): void
    {
        // A host summary alone (e.g. fact-gathering play with no tasks) is
        // still "something happened" — the safeguard must not fire.
        $job = $this->makeRunningJob();
        $summary = new JobHostSummary();
        $summary->job_id = $job->id;
        $summary->host = 'localhost';
        $summary->ok = 1;
        $summary->changed = 0;
        $summary->failed = 0;
        $summary->unreachable = 0;
        $summary->skipped = 0;
        $summary->rescued = 0;
        $summary->created_at = time();
        $summary->save(false);

        $this->service->complete($job, 0);

        $job->refresh();
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);
    }

    public function testCompleteSetsFailedStatusOnNonZeroExit(): void
    {
        $job = $this->makeRunningJob();

        $this->service->complete($job, 1);

        $job->refresh();
        $this->assertSame(Job::STATUS_FAILED, $job->status);
    }

    public function testCompletePersistsExitCode(): void
    {
        $job = $this->makeRunningJob();

        $this->service->complete($job, 42);

        $job->refresh();
        $this->assertSame(42, (int)$job->exit_code);
    }

    public function testCompletePersistsFinishedAt(): void
    {
        $before = time();
        $job    = $this->makeRunningJob();

        $this->service->complete($job, 0);

        $job->refresh();
        $this->assertGreaterThanOrEqual($before, (int)$job->finished_at);
    }

    public function testCompleteWithHasChangesMarksJob(): void
    {
        $job = $this->makeRunningJob();

        $this->service->complete($job, 0, hasChanges: true);

        $job->refresh();
        $this->assertSame(1, (int)$job->has_changes);
    }

    public function testCompleteWritesAuditLog(): void
    {
        $job    = $this->makeRunningJob();
        $before = (int)\app\models\AuditLog::find()->count();

        $this->service->complete($job, 0);

        $this->assertGreaterThan($before, (int)\app\models\AuditLog::find()->count());
    }

    // -------------------------------------------------------------------------
    // appendLog()
    // -------------------------------------------------------------------------

    public function testAppendLogCreatesJobLogRecord(): void
    {
        $job    = $this->makeRunningJob();
        $before = (int)JobLog::find()->where(['job_id' => $job->id])->count();

        $this->service->appendLog($job, JobLog::STREAM_STDOUT, 'Hello output', 1);

        $this->assertSame($before + 1, (int)JobLog::find()->where(['job_id' => $job->id])->count());
    }

    public function testAppendLogPersistsContent(): void
    {
        $job = $this->makeRunningJob();

        $this->service->appendLog($job, JobLog::STREAM_STDOUT, 'Test content', 1);

        $log = JobLog::find()->where(['job_id' => $job->id])->one();
        $this->assertNotNull($log);
        $this->assertSame('Test content', $log->content);
        $this->assertSame(JobLog::STREAM_STDOUT, $log->stream);
        $this->assertSame(1, $log->sequence);
    }

    public function testAppendLogSupportsStderrStream(): void
    {
        $job = $this->makeRunningJob();

        $this->service->appendLog($job, JobLog::STREAM_STDERR, 'Error output', 2);

        $log = JobLog::find()->where(['job_id' => $job->id, 'stream' => JobLog::STREAM_STDERR])->one();
        $this->assertNotNull($log);
        $this->assertSame('Error output', $log->content);
    }

    public function testAppendLogPreservesSequence(): void
    {
        $job = $this->makeRunningJob();

        $this->service->appendLog($job, JobLog::STREAM_STDOUT, 'line1', 10);
        $this->service->appendLog($job, JobLog::STREAM_STDOUT, 'line2', 20);

        $logs = JobLog::find()->where(['job_id' => $job->id])->orderBy(['sequence' => SORT_ASC])->all();
        $this->assertSame(10, $logs[0]->sequence);
        $this->assertSame(20, $logs[1]->sequence);
    }

    // -------------------------------------------------------------------------
    // saveTasks()
    // -------------------------------------------------------------------------

    public function testSaveTasksCreatesJobTaskRecords(): void
    {
        $job    = $this->makeRunningJob();
        $before = (int)JobTask::find()->where(['job_id' => $job->id])->count();

        $this->service->saveTasks($job, [
            ['seq' => 1, 'name' => 'Install nginx', 'action' => 'apt', 'host' => 'web1', 'status' => 'ok', 'changed' => false, 'duration_ms' => 120],
            ['seq' => 2, 'name' => 'Start nginx',   'action' => 'service', 'host' => 'web1', 'status' => 'ok', 'changed' => true, 'duration_ms' => 30],
        ]);

        $this->assertSame($before + 2, (int)JobTask::find()->where(['job_id' => $job->id])->count());
    }

    public function testSaveTasksCreatesHostSummaries(): void
    {
        $job = $this->makeRunningJob();

        $this->service->saveTasks($job, [
            ['seq' => 1, 'name' => 'task', 'action' => 'apt', 'host' => 'web1', 'status' => 'ok', 'changed' => false, 'duration_ms' => 0],
        ]);

        $summary = JobHostSummary::findOne(['job_id' => $job->id, 'host' => 'web1']);
        $this->assertNotNull($summary);
    }

    public function testSaveTasksCountsChangedTasksInSummary(): void
    {
        $job = $this->makeRunningJob();

        $this->service->saveTasks($job, [
            ['seq' => 1, 'name' => 'a', 'action' => 'copy', 'host' => 'web1', 'status' => 'ok', 'changed' => true, 'duration_ms' => 0],
            ['seq' => 2, 'name' => 'b', 'action' => 'copy', 'host' => 'web1', 'status' => 'ok', 'changed' => true, 'duration_ms' => 0],
        ]);

        $summary = JobHostSummary::findOne(['job_id' => $job->id, 'host' => 'web1']);
        $this->assertNotNull($summary);
        $this->assertSame(2, (int)$summary->changed);
    }

    public function testSaveTasksSetsHasChangesOnJob(): void
    {
        $job = $this->makeRunningJob();

        $this->service->saveTasks($job, [
            ['seq' => 1, 'name' => 'task', 'action' => 'copy', 'host' => 'h1', 'status' => 'ok', 'changed' => true, 'duration_ms' => 0],
        ]);

        $job->refresh();
        $this->assertSame(1, (int)$job->has_changes);
    }

    public function testSaveTasksHandlesEmptyTaskList(): void
    {
        $job = $this->makeRunningJob();

        $this->service->saveTasks($job, []);

        $this->assertSame(0, (int)JobTask::find()->where(['job_id' => $job->id])->count());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeRunningJob(): Job
    {
        $user     = $this->createUser();
        $group    = $this->createRunnerGroup($user->id);
        $project  = $this->createProject($user->id);
        $inv      = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
        return $this->createJob($template->id, $user->id, Job::STATUS_RUNNING);
    }

    /** Seed a minimal JobTask row — enough to clear the "did nothing" gate. */
    private function seedTaskFor(Job $job, string $status): void
    {
        $task = new JobTask();
        $task->job_id = $job->id;
        $task->sequence = 0;
        $task->task_name = 'seeded';
        $task->task_action = 'debug';
        $task->host = 'localhost';
        $task->status = $status;
        $task->changed = 0;
        $task->duration_ms = 0;
        $task->save(false);
    }
}
