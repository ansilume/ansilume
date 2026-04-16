<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\JobController;
use app\models\Job;
use app\models\Runner;
use app\models\RunnerGroup;
use app\services\JobReclaimService;
use app\tests\integration\DbTestCase;
use yii\console\ExitCode;

/**
 * Integration tests for the console JobController (job/reclaim-stale).
 */
class JobControllerTest extends DbTestCase
{
    /**
     * @return JobController&object{captured: string}
     */
    private function makeController(): JobController
    {
        return new class ('job', \Yii::$app) extends JobController {
            public string $captured = '';

            public function stdout($string): int
            {
                $this->captured .= $string;
                return 0;
            }

            public function stderr($string): int
            {
                $this->captured .= $string;
                return 0;
            }
        };
    }

    public function testReclaimStaleReportsCountWhenJobsReclaimed(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $runner = $this->createRunner($group->id, $user->id);
        $runner->last_seen_at = time() - (RunnerGroup::STALE_AFTER + 60);
        $runner->save(false);

        $job = $this->createJob($template->id, $user->id, Job::STATUS_RUNNING);
        $job->runner_id = $runner->id;
        $job->started_at = time() - 1000;
        $job->last_progress_at = time() - 1000;
        $job->save(false);

        $service = new JobReclaimService();
        $service->progressTimeoutSeconds = 600;
        \Yii::$app->set('jobReclaimService', $service);

        $ctrl = $this->makeController();
        $result = $ctrl->actionReclaimStale();

        $this->assertSame(ExitCode::OK, $result);
        $this->assertStringContainsString('Processed 1 stuck job', $ctrl->captured);
        $this->assertStringContainsString('mode: fail', $ctrl->captured);
        $this->assertStringContainsString('600s', $ctrl->captured);
    }

    public function testReclaimStaleReportsRequeueModeInOutput(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $runner = $this->createRunner($group->id, $user->id);
        $runner->last_seen_at = time() - (RunnerGroup::STALE_AFTER + 60);
        $runner->save(false);

        $job = $this->createJob($template->id, $user->id, Job::STATUS_RUNNING);
        $job->runner_id = $runner->id;
        $job->started_at = time() - 1000;
        $job->last_progress_at = time() - 1000;
        $job->max_attempts = 3;
        $job->save(false);

        $service = new JobReclaimService();
        $service->progressTimeoutSeconds = 600;
        $service->mode = JobReclaimService::MODE_REQUEUE;
        \Yii::$app->set('jobReclaimService', $service);

        $ctrl = $this->makeController();
        $ctrl->actionReclaimStale();

        $this->assertStringContainsString('mode: requeue', $ctrl->captured);
    }

    public function testReclaimStaleAlsoReportsOrphanedQueuedCount(): void
    {
        // Drain Redis worker keys so the starvation sweep sees no live worker.
        try {
            $r = new \Redis();
            $r->connect($_ENV['REDIS_HOST'] ?? 'redis', (int)($_ENV['REDIS_PORT'] ?? 6379));
            foreach ($r->keys('ansilume:worker:*') as $k) {
                $r->del($k);
            }
        } catch (\Throwable) {
        }

        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        // No runners online + no live workers + queued long enough.
        $job = $this->createJob($template->id, $user->id, Job::STATUS_QUEUED);
        $job->queued_at = time() - 200;
        $job->save(false);

        $service = new JobReclaimService();
        $service->queueTimeoutSeconds = 60;
        \Yii::$app->set('jobReclaimService', $service);

        $ctrl = $this->makeController();
        $ctrl->actionReclaimStale();

        $this->assertStringContainsString('Failed 1 orphaned queued job', $ctrl->captured);
    }

    public function testReclaimStaleSilentWhenNothingToDo(): void
    {
        $service = new JobReclaimService();
        \Yii::$app->set('jobReclaimService', $service);

        $ctrl = $this->makeController();
        $result = $ctrl->actionReclaimStale();

        $this->assertSame(ExitCode::OK, $result);
        $this->assertSame('', $ctrl->captured);
    }
}
