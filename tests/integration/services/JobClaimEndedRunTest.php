<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\services\JobCancellationService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * JobClaimService never claims the job of a workflow step whose run is no
 * longer active: a run cancel that failed part-way leaves such a job queued
 * (WorkflowCancellationService::STEP_CANCEL_FAILED), and runners poll every
 * few seconds, long before the maintenance run's StrandedJobSweep cancels
 * it. Other jobs, those of active runs and standalone ones, are claimed as
 * before, and a stranded job does not hold the queue up.
 */
class JobClaimEndedRunTest extends DbTestCase
{
    use TeamScopeFixtures;
    use WorkflowEngineFixtures;
    use CancellationFixtures;
    use StrandedRunFixtures;

    private User $operator;
    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordNotifications();
        $this->operator = $this->operator('claim-ended-run');
        $this->template = $this->openTemplate((int)$this->operator->id);
    }

    protected function tearDown(): void
    {
        $this->restoreSwappedServices();
        $this->restoreNotifications();
        parent::tearDown();
    }

    /**
     * Regression: claim() took the queued job of a canceled run (its cancel
     * had failed after the run was claimed), and the playbook of a canceled
     * or deleted workflow ran.
     *
     * @dataProvider endedRunProvider
     */
    public function testRegressionAJobOfARunThatEndedIsNeverClaimed(string $status): void
    {
        ['run' => $run, 'job' => $job] = $this->runWaitingForItsJob($this->operator, $this->template);
        WorkflowJob::updateAll(['status' => $status, 'finished_at' => time()], ['id' => $run->id]);

        $this->assertNull($this->claimFor($this->template));

        $job->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $job->status, 'left for the sweep');
        $this->assertNull($job->runner_id);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function endedRunProvider(): array
    {
        return [
            'canceled' => [WorkflowJob::STATUS_CANCELED],
            'failed' => [WorkflowJob::STATUS_FAILED],
            'succeeded' => [WorkflowJob::STATUS_SUCCEEDED],
        ];
    }

    /**
     * Through the real cancel: a run whose step's job could not be canceled.
     */
    public function testTheJobOfARunWhoseCancelFailedIsNotClaimed(): void
    {
        ['run' => $run, 'job' => $job] = $this->runWaitingForItsJob($this->operator, $this->template);
        $this->failJobCancels($this->lostConnection());
        $this->assertTrue($this->runCancellation()->cancelRun($run, (int)$this->operator->id, JobCancellationService::REASON_OPERATOR));

        $this->assertNull($this->claimFor($this->template));
        $this->assertSame(Job::STATUS_QUEUED, Job::findOne($job->id)?->status);
    }

    /**
     * The job of an active run is claimed, and so is a standalone job, also
     * when an older stranded job waits in the same runner group.
     */
    public function testOtherJobsAreClaimedPastAStrandedJob(): void
    {
        ['run' => $ended, 'job' => $stranded] = $this->runWaitingForItsJob($this->operator, $this->template);
        WorkflowJob::updateAll(['status' => WorkflowJob::STATUS_CANCELED], ['id' => $ended->id]);
        ['job' => $active] = $this->runWaitingForItsJob($this->operator, $this->template);
        $standalone = $this->createJob((int)$this->template->id, (int)$this->operator->id);
        $this->assertTrue($stranded->id < $active->id && $active->id < $standalone->id);

        $this->assertSame((int)$active->id, (int)$this->claimFor($this->template)?->id, 'the oldest claimable job');
        $this->assertSame((int)$standalone->id, (int)$this->claimFor($this->template)?->id);
        $this->assertNull($this->claimFor($this->template));
        $this->assertSame(Job::STATUS_QUEUED, Job::findOne($stranded->id)?->status);
    }
}
