<?php

declare(strict_types=1);

namespace app\tests\integration\jobs;

use app\jobs\SyncProjectJob;
use app\models\Project;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for SyncProjectJob.
 */
class SyncProjectJobTest extends DbTestCase
{
    public function testExecuteSkipsNonExistentProject(): void
    {
        $job = new SyncProjectJob(['projectId' => 999999]);
        // Should not throw, just log and return
        $job->execute(null);
        $this->assertTrue(true);
    }

    public function testExecuteSkipsProjectIdZero(): void
    {
        $job = new SyncProjectJob(['projectId' => 0]);
        $job->execute(null);
        $this->assertTrue(true);
    }

    public function testExecuteHandlesManualProject(): void
    {
        $user    = $this->createUser();
        $project = $this->createProject($user->id);
        // Manual projects have no SCM URL — sync should handle gracefully
        $project->scm_type = Project::SCM_TYPE_MANUAL;
        $project->scm_url  = '';
        $project->save(false);

        $job = new SyncProjectJob(['projectId' => $project->id]);
        // sync() on a manual project may throw RuntimeException — that's caught
        $job->execute(null);
        $this->assertTrue(true);
    }

    public function testExecuteDoesNotCrashOnGitProjectWithoutUrl(): void
    {
        $user    = $this->createUser();
        $project = $this->createProject($user->id);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url  = '';
        $project->save(false);

        $job = new SyncProjectJob(['projectId' => $project->id]);
        // RuntimeException from sync is caught internally
        $job->execute(null);
        $this->assertTrue(true);
    }

    public function testJobIdPropertyDefaultsToZero(): void
    {
        $job = new SyncProjectJob();
        $this->assertSame(0, $job->projectId);
    }

    public function testJobIdCanBeSetViaConstructor(): void
    {
        $job = new SyncProjectJob(['projectId' => 42]);
        $this->assertSame(42, $job->projectId);
    }
}
