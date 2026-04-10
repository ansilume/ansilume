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

    public function testExecuteHappyPathRunsSyncAndLint(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        // Create a job template in the same project so runForTemplate is hit.
        $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);

        $originalProjectService = \Yii::$app->getComponents(true)['projectService'] ?? null;
        $originalLintService = \Yii::$app->getComponents(true)['lintService'] ?? null;

        $projectStub = new class extends \yii\base\Component {
            public int $syncCalls = 0;
            public function sync(\app\models\Project $p): void
            {
                $this->syncCalls++;
            }
        };
        $lintStub = new class extends \yii\base\Component {
            public int $projectCalls = 0;
            public int $templateCalls = 0;
            public function runForProject(\app\models\Project $p): void
            {
                $this->projectCalls++;
            }
            public function runForTemplate(\app\models\JobTemplate $t): void
            {
                $this->templateCalls++;
            }
        };
        \Yii::$app->set('projectService', $projectStub);
        \Yii::$app->set('lintService', $lintStub);

        try {
            $job = new SyncProjectJob(['projectId' => $project->id]);
            $job->execute(null);

            $this->assertSame(1, $projectStub->syncCalls);
            $this->assertSame(1, $lintStub->projectCalls);
            $this->assertSame(1, $lintStub->templateCalls);
        } finally {
            \Yii::$app->set('projectService', $originalProjectService);
            \Yii::$app->set('lintService', $originalLintService);
        }
    }
}
