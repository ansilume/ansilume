<?php

declare(strict_types=1);

namespace app\tests\integration\jobs;

use app\jobs\RunAnsibleJob;
use app\models\Job;
use app\models\JobTask;
use app\services\JobCompletionService;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for RunAnsibleJob — methods that require a real database.
 *
 * Task persistence tests target JobCompletionService::saveTasks() since
 * RunAnsibleJob delegates to it.
 */
class RunAnsibleJobIntegrationTest extends DbTestCase
{
    // -------------------------------------------------------------------------
    // saveTasks (via JobCompletionService, called by RunAnsibleJob)
    // -------------------------------------------------------------------------

    public function testSaveTasksCreatesJobTaskRecords(): void
    {
        $job = $this->makeRunningJob();
        $service = new JobCompletionService();
        $tasks = [
            ['seq' => 1, 'name' => 'Gather facts', 'action' => 'setup', 'host' => 'web1', 'status' => 'ok', 'changed' => false, 'duration_ms' => 500],
            ['seq' => 2, 'name' => 'Install pkg', 'action' => 'apt', 'host' => 'web1', 'status' => 'ok', 'changed' => true, 'duration_ms' => 3200],
        ];

        $service->saveTasks($job, $tasks);

        $this->assertSame(2, (int)JobTask::find()->where(['job_id' => $job->id])->count());
        $job->refresh();
        $this->assertSame(1, (int)$job->has_changes);
    }

    public function testSaveTasksNoChangesWhenNoneReported(): void
    {
        $job = $this->makeRunningJob();
        $service = new JobCompletionService();
        $tasks = [
            ['seq' => 1, 'name' => 'Gather facts', 'action' => 'setup', 'host' => 'web1', 'status' => 'ok', 'changed' => false, 'duration_ms' => 500],
        ];

        $service->saveTasks($job, $tasks);

        $job->refresh();
        $this->assertSame(0, (int)$job->has_changes);
    }

    public function testSaveTasksHandlesEmptyArray(): void
    {
        $job = $this->makeRunningJob();
        $service = new JobCompletionService();

        $service->saveTasks($job, []);

        $this->assertSame(0, (int)JobTask::find()->where(['job_id' => $job->id])->count());
    }

    public function testSaveTasksSetsCorrectFields(): void
    {
        $job = $this->makeRunningJob();
        $service = new JobCompletionService();
        $tasks = [
            ['seq' => 5, 'name' => 'Deploy app', 'action' => 'copy', 'host' => 'srv1', 'status' => 'ok', 'changed' => true, 'duration_ms' => 1500],
        ];

        $service->saveTasks($job, $tasks);

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

    public function testSaveTasksHandlesMultipleHosts(): void
    {
        $job = $this->makeRunningJob();
        $service = new JobCompletionService();
        $tasks = [
            ['seq' => 1, 'name' => 'ping', 'action' => 'ping', 'host' => 'web1', 'status' => 'ok', 'changed' => false, 'duration_ms' => 10],
            ['seq' => 2, 'name' => 'ping', 'action' => 'ping', 'host' => 'web2', 'status' => 'ok', 'changed' => false, 'duration_ms' => 12],
            ['seq' => 3, 'name' => 'ping', 'action' => 'ping', 'host' => 'db1', 'status' => 'unreachable', 'changed' => false, 'duration_ms' => 5000],
        ];

        $service->saveTasks($job, $tasks);

        $records = JobTask::find()->where(['job_id' => $job->id])->orderBy('sequence')->all();
        $this->assertCount(3, $records);
        $this->assertSame('web1', $records[0]->host);
        $this->assertSame('web2', $records[1]->host);
        $this->assertSame('db1', $records[2]->host);
        $this->assertSame('unreachable', $records[2]->status);
    }

    public function testSaveTasksDefaultsForMissingFields(): void
    {
        $job = $this->makeRunningJob();
        $service = new JobCompletionService();
        $tasks = [['seq' => 0]];

        $service->saveTasks($job, $tasks);

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
    // parseCallbackFile (via testable subclass)
    // -------------------------------------------------------------------------

    public function testParseCallbackFileSkipsMalformedJson(): void
    {
        $file = sys_get_temp_dir() . '/ansilume_test_cb_' . uniqid('', true) . '.ndjson';
        file_put_contents($file, "not valid json\n" . json_encode(['seq' => 1]) . "\n{broken\n");

        $runner = new TestableRunAnsibleJobDb();
        $tasks = $runner->parseCallbackFile($file);
        unlink($file);

        $this->assertCount(1, $tasks);
        $this->assertSame(1, $tasks[0]['seq']);
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
        $job = $this->makeJobWithStatus(Job::STATUS_SUCCEEDED);
        $runner = new RunAnsibleJob(['jobId' => $job->id]);

        $runner->execute(null);

        $job->refresh();
        // Status should remain unchanged
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);
    }

    public function testExecuteSkipsRunningJob(): void
    {
        $job = $this->makeJobWithStatus(Job::STATUS_RUNNING);
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
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
        return $this->createJob($template->id, $user->id, Job::STATUS_RUNNING);
    }

    private function makeJobWithStatus(string $status): Job
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
        return $this->createJob($template->id, $user->id, $status);
    }
}

/**
 * Testable subclass exposing protected methods for integration tests.
 */
class TestableRunAnsibleJobDb extends RunAnsibleJob
{
    public function parseCallbackFile(string $callbackFile): array
    {
        return parent::parseCallbackFile($callbackFile);
    }
}
