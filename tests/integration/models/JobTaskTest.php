<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\Job;
use app\models\JobTask;
use app\tests\integration\DbTestCase;

class JobTaskTest extends DbTestCase
{
    public function testTableName(): void
    {
        $this->assertSame('{{%job_task}}', JobTask::tableName());
    }

    public function testStatusCssClassOk(): void
    {
        $this->assertSame('success', JobTask::statusCssClass(JobTask::STATUS_OK));
    }

    public function testStatusCssClassChanged(): void
    {
        $this->assertSame('warning', JobTask::statusCssClass(JobTask::STATUS_CHANGED));
    }

    public function testStatusCssClassFailed(): void
    {
        $this->assertSame('danger', JobTask::statusCssClass(JobTask::STATUS_FAILED));
    }

    public function testStatusCssClassSkipped(): void
    {
        $this->assertSame('secondary', JobTask::statusCssClass(JobTask::STATUS_SKIPPED));
    }

    public function testStatusCssClassUnreachable(): void
    {
        $this->assertSame('dark', JobTask::statusCssClass(JobTask::STATUS_UNREACHABLE));
    }

    public function testStatusCssClassUnknownDefaultsBranch(): void
    {
        $this->assertSame('secondary', JobTask::statusCssClass('totally-unknown'));
    }

    public function testPersistAndRetrieve(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);
        $job = $this->createJob((int)$tpl->id, $user->id);

        $task = new JobTask();
        $task->job_id = $job->id;
        $task->sequence = 1;
        $task->task_name = 'Gather facts';
        $task->task_action = 'setup';
        $task->host = 'web01.example.com';
        $task->status = JobTask::STATUS_OK;
        $task->changed = 0;
        $task->duration_ms = 1234;
        $task->created_at = time();
        $this->assertTrue($task->save(false));

        $reloaded = JobTask::findOne($task->id);
        $this->assertNotNull($reloaded);
        $this->assertSame($job->id, $reloaded->job_id);
        $this->assertSame(1, $reloaded->sequence);
        $this->assertSame('Gather facts', $reloaded->task_name);
        $this->assertSame('setup', $reloaded->task_action);
        $this->assertSame('web01.example.com', $reloaded->host);
        $this->assertSame(JobTask::STATUS_OK, $reloaded->status);
        $this->assertSame(0, $reloaded->changed);
        $this->assertSame(1234, $reloaded->duration_ms);
    }
}
