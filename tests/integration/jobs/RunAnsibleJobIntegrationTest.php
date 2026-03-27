<?php

declare(strict_types=1);

namespace app\tests\integration\jobs;

use app\jobs\RunAnsibleJob;
use app\models\Job;
use app\models\JobLog;
use app\models\JobTask;
use app\models\Webhook;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for RunAnsibleJob — methods that require a real database.
 */
class RunAnsibleJobIntegrationTest extends DbTestCase
{
    // -------------------------------------------------------------------------
    // persistTaskLines
    // -------------------------------------------------------------------------

    public function testPersistTaskLinesCreatesJobTaskRecords(): void
    {
        $job     = $this->makeRunningJob();
        $runner  = new TestableRunAnsibleJobDb();
        $lines   = [
            json_encode(['seq' => 1, 'name' => 'Gather facts', 'action' => 'setup', 'host' => 'web1', 'status' => 'ok', 'changed' => false, 'duration_ms' => 500]),
            json_encode(['seq' => 2, 'name' => 'Install pkg',  'action' => 'apt',   'host' => 'web1', 'status' => 'ok', 'changed' => true,  'duration_ms' => 3200]),
        ];

        $hasChanges = $runner->persistTaskLines($job, $lines);

        $this->assertTrue($hasChanges);
        $this->assertSame(2, (int)JobTask::find()->where(['job_id' => $job->id])->count());
    }

    public function testPersistTaskLinesReturnsFalseWhenNoChanges(): void
    {
        $job     = $this->makeRunningJob();
        $runner  = new TestableRunAnsibleJobDb();
        $lines   = [
            json_encode(['seq' => 1, 'name' => 'Gather facts', 'action' => 'setup', 'host' => 'web1', 'status' => 'ok', 'changed' => false, 'duration_ms' => 500]),
        ];

        $hasChanges = $runner->persistTaskLines($job, $lines);

        $this->assertFalse($hasChanges);
    }

    public function testPersistTaskLinesSkipsMalformedJson(): void
    {
        $job     = $this->makeRunningJob();
        $runner  = new TestableRunAnsibleJobDb();
        $lines   = [
            'not valid json',
            json_encode(['seq' => 1, 'name' => 'task', 'action' => 'ping', 'host' => 'h1', 'status' => 'ok', 'changed' => false, 'duration_ms' => 10]),
            '{broken',
        ];

        $runner->persistTaskLines($job, $lines);

        $this->assertSame(1, (int)JobTask::find()->where(['job_id' => $job->id])->count());
    }

    public function testPersistTaskLinesHandlesEmptyArray(): void
    {
        $job     = $this->makeRunningJob();
        $runner  = new TestableRunAnsibleJobDb();

        $hasChanges = $runner->persistTaskLines($job, []);

        $this->assertFalse($hasChanges);
        $this->assertSame(0, (int)JobTask::find()->where(['job_id' => $job->id])->count());
    }

    public function testPersistTaskLinesSetsCorrectFields(): void
    {
        $job    = $this->makeRunningJob();
        $runner = new TestableRunAnsibleJobDb();
        $lines  = [
            json_encode(['seq' => 5, 'name' => 'Deploy app', 'action' => 'copy', 'host' => 'srv1', 'status' => 'ok', 'changed' => true, 'duration_ms' => 1500]),
        ];

        $runner->persistTaskLines($job, $lines);

        $task = JobTask::find()->where(['job_id' => $job->id])->one();
        $this->assertNotNull($task);
        $this->assertSame(5, $task->sequence);
        $this->assertSame('Deploy app', $task->task_name);
        $this->assertSame('copy', $task->task_action);
        $this->assertSame('srv1', $task->host);
        $this->assertSame('ok', $task->status);
        $this->assertSame(1, (int)$task->changed);
        $this->assertSame(1500, (int)$task->duration_ms);
    }

    public function testPersistTaskLinesHandlesMultipleHosts(): void
    {
        $job    = $this->makeRunningJob();
        $runner = new TestableRunAnsibleJobDb();
        $lines  = [
            json_encode(['seq' => 1, 'name' => 'ping', 'action' => 'ping', 'host' => 'web1', 'status' => 'ok',          'changed' => false, 'duration_ms' => 10]),
            json_encode(['seq' => 2, 'name' => 'ping', 'action' => 'ping', 'host' => 'web2', 'status' => 'ok',          'changed' => false, 'duration_ms' => 12]),
            json_encode(['seq' => 3, 'name' => 'ping', 'action' => 'ping', 'host' => 'db1',  'status' => 'unreachable', 'changed' => false, 'duration_ms' => 5000]),
        ];

        $runner->persistTaskLines($job, $lines);

        $tasks = JobTask::find()->where(['job_id' => $job->id])->orderBy('sequence')->all();
        $this->assertCount(3, $tasks);
        $this->assertSame('web1', $tasks[0]->host);
        $this->assertSame('web2', $tasks[1]->host);
        $this->assertSame('db1', $tasks[2]->host);
        $this->assertSame('unreachable', $tasks[2]->status);
    }

    public function testPersistTaskLinesDefaultsForMissingFields(): void
    {
        $job    = $this->makeRunningJob();
        $runner = new TestableRunAnsibleJobDb();
        // Minimal line — most fields missing
        $lines  = [json_encode(['seq' => 0])];

        $runner->persistTaskLines($job, $lines);

        $task = JobTask::find()->where(['job_id' => $job->id])->one();
        $this->assertNotNull($task);
        $this->assertSame('', $task->task_name);
        $this->assertSame('', $task->task_action);
        $this->assertSame('', $task->host);
        $this->assertSame('ok', $task->status);
        $this->assertSame(0, (int)$task->changed);
        $this->assertSame(0, (int)$task->duration_ms);
    }

    // -------------------------------------------------------------------------
    // execute — guard clauses
    // -------------------------------------------------------------------------

    public function testExecuteSkipsNonExistentJob(): void
    {
        $runner = new RunAnsibleJob(['jobId' => 999999]);
        // Should not throw, just log and return
        $runner->execute(null);
        $this->assertTrue(true);
    }

    public function testExecuteSkipsJobInWrongStatus(): void
    {
        $job    = $this->makeJobWithStatus(Job::STATUS_SUCCEEDED);
        $runner = new RunAnsibleJob(['jobId' => $job->id]);

        $runner->execute(null);

        $job->refresh();
        // Status should remain unchanged
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);
    }

    public function testExecuteSkipsRunningJob(): void
    {
        $job    = $this->makeJobWithStatus(Job::STATUS_RUNNING);
        $runner = new RunAnsibleJob(['jobId' => $job->id]);

        $runner->execute(null);

        $job->refresh();
        $this->assertSame(Job::STATUS_RUNNING, $job->status);
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

    private function makeJobWithStatus(string $status): Job
    {
        $user     = $this->createUser();
        $group    = $this->createRunnerGroup($user->id);
        $project  = $this->createProject($user->id);
        $inv      = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
        return $this->createJob($template->id, $user->id, $status);
    }
}

/**
 * Testable subclass exposing protected methods for integration tests.
 */
class TestableRunAnsibleJobDb extends RunAnsibleJob
{
    public function persistTaskLines(\app\models\Job $job, array $lines): bool
    {
        return parent::persistTaskLines($job, $lines);
    }
}
