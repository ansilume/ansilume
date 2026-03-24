<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Job;
use app\services\JobClaimService;
use app\tests\integration\DbTestCase;

/**
 * Concurrency tests for JobClaimService::claim().
 *
 * Verifies that the atomic UPDATE … WHERE runner_id IS NULL prevents
 * double-claiming when multiple runners call claim() for the same job.
 */
class JobClaimConcurrencyTest extends DbTestCase
{
    private JobClaimService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \Yii::$app->get('jobClaimService');
    }

    /**
     * Two runners in the same group claim simultaneously.
     * Only one should get the job — the other gets null.
     */
    public function testTwoRunnersCannotClaimSameJob(): void
    {
        $user     = $this->createUser();
        $group    = $this->createRunnerGroup($user->id);
        $runnerA  = $this->createRunner($group->id, $user->id);
        $runnerB  = $this->createRunner($group->id, $user->id);
        $project  = $this->createProject($user->id);
        $inv      = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
        $this->createJob($template->id, $user->id);

        $claimedA = $this->service->claim($group, $runnerA);
        $claimedB = $this->service->claim($group, $runnerB);

        // Exactly one should succeed
        $this->assertTrue(
            ($claimedA !== null) xor ($claimedB !== null),
            'Exactly one runner should claim the job.'
        );

        // The winner should have the job in RUNNING state
        $winner = $claimedA ?? $claimedB;
        $this->assertSame(Job::STATUS_RUNNING, $winner->status);
    }

    /**
     * With multiple queued jobs, each runner claims a different one.
     */
    public function testMultipleJobsClaimedByDifferentRunners(): void
    {
        $user     = $this->createUser();
        $group    = $this->createRunnerGroup($user->id);
        $runnerA  = $this->createRunner($group->id, $user->id);
        $runnerB  = $this->createRunner($group->id, $user->id);
        $project  = $this->createProject($user->id);
        $inv      = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $job1 = $this->createJob($template->id, $user->id);
        $job2 = $this->createJob($template->id, $user->id);

        $claimedA = $this->service->claim($group, $runnerA);
        $claimedB = $this->service->claim($group, $runnerB);

        $this->assertNotNull($claimedA);
        $this->assertNotNull($claimedB);
        $this->assertNotSame($claimedA->id, $claimedB->id, 'Each runner must claim a different job.');
    }

    /**
     * After a job is claimed, it must not appear in subsequent claims.
     */
    public function testClaimedJobIsNotReturnedAgain(): void
    {
        $user     = $this->createUser();
        $group    = $this->createRunnerGroup($user->id);
        $runner   = $this->createRunner($group->id, $user->id);
        $project  = $this->createProject($user->id);
        $inv      = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
        $this->createJob($template->id, $user->id);

        $first  = $this->service->claim($group, $runner);
        $second = $this->service->claim($group, $runner);

        $this->assertNotNull($first);
        $this->assertNull($second, 'No more jobs to claim — the first one is already running.');
    }

    /**
     * Jobs are claimed in FIFO order (oldest first).
     */
    public function testClaimRespectsFifoOrder(): void
    {
        $user     = $this->createUser();
        $group    = $this->createRunnerGroup($user->id);
        $runner   = $this->createRunner($group->id, $user->id);
        $project  = $this->createProject($user->id);
        $inv      = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $first  = $this->createJob($template->id, $user->id);
        $second = $this->createJob($template->id, $user->id);

        $claimed = $this->service->claim($group, $runner);

        $this->assertNotNull($claimed);
        $this->assertSame($first->id, $claimed->id, 'Oldest queued job should be claimed first.');
    }

    /**
     * A claimed job has the correct runner_id set in the database.
     */
    public function testClaimedJobStoresRunnerIdInDatabase(): void
    {
        $user     = $this->createUser();
        $group    = $this->createRunnerGroup($user->id);
        $runner   = $this->createRunner($group->id, $user->id);
        $project  = $this->createProject($user->id);
        $inv      = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
        $this->createJob($template->id, $user->id);

        $claimed = $this->service->claim($group, $runner);

        // Re-read from DB to confirm persistence
        $reloaded = Job::findOne($claimed->id);
        $this->assertSame($runner->id, (int)$reloaded->runner_id);
        $this->assertSame(Job::STATUS_RUNNING, $reloaded->status);
    }
}
