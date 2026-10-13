<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\PendingMigrations;
use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\Job;
use app\models\Schedule;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\ApprovalService;
use app\services\JobCancellationService;
use app\services\JobTemplateDeletionService;
use app\services\MaintenanceService;
use app\services\StrandedJobSweep;
use app\services\WorkflowTemplateDeletionService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\log\Logger;

/**
 * The maintenance run applies approval timeouts on every tick, and sweeps
 * the dependants of deleted job and workflow templates and the stranded
 * jobs once per cooldown. Both wait while database migrations are pending,
 * and both share one time budget per tick. An error in either is logged
 * without values: the approval timeouts are retried on the next tick, the
 * sweep once its interval has passed, because its cooldown, taken before it
 * starts, is kept after an error (the cooldown is the backoff).
 */
class MaintenanceApprovalTasksTest extends DbTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;

    /** What a sweep of stranded jobs answers when it finds none. */
    private const NO_STRANDED_JOBS = ['stranded_jobs_canceled' => 0, 'stranded_jobs_failed' => 0, 'stranded_jobs_remaining' => 0];

    /**
     * How long one slow notification takes in the budget tests, and their
     * budget: the first item a loop starts uses the whole budget up.
     */
    private const SLOW_SECONDS = 0.4;

    /** @var array<string, mixed> component definitions a test replaced */
    private array $replaced = [];

    protected function setUp(): void
    {
        parent::setUp();
        \Yii::$app->cache?->flush();
        $this->recordNotifications();
    }

    protected function tearDown(): void
    {
        foreach ($this->replaced as $id => $definition) {
            \Yii::$app->set($id, $definition);
        }
        $this->replaced = [];
        $this->restoreNotifications();
        \Yii::$app->cache?->flush();
        parent::tearDown();
    }

    // -- approval-timeouts ------------------------------------------------------

    /**
     * Regression: approval timeouts never ran in a stock installation. Only
     * approval/check-timeouts applied them, which no stock deployment runs,
     * so an expired request stayed pending (and decidable) for good.
     */
    public function testTheMaintenanceRunAppliesTheTimeoutOfAnExpiredRequest(): void
    {
        $launcher = $this->createUserWithRole('timeout-launcher', 'operator');
        $approver = $this->createUserWithRole('timeout-approver', 'operator');
        ['job' => $job, 'request' => $request] = $this->jobWaitingForApproval(
            $this->cancelFixtureTemplate((int)$launcher->id),
            (int)$launcher->id,
            (int)$approver->id
        );
        ApprovalRequest::updateAll(['expires_at' => time() - 120], ['id' => $request->id]);

        $report = $this->maintenance()->runIfDue();

        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_TIMED_OUT, $request->status);
        $job->refresh();
        $this->assertSame(Job::STATUS_REJECTED, $job->status, 'the rule\'s timeout action (reject) was applied');
        $this->assertContains('approval-timeouts', $report['ran']);
        $this->assertSame(['resolved' => 1], $report['results']['approval-timeouts']);
    }

    public function testATickTimesOutAtMostOneBatch(): void
    {
        $approvals = $this->stubApprovals(3);

        $report = $this->maintenance()->runIfDue();

        $this->assertSame([MaintenanceService::APPROVAL_TIMEOUT_BATCH_SIZE], $approvals->limits);
        $this->assertSame(100, MaintenanceService::APPROVAL_TIMEOUT_BATCH_SIZE);
        $this->assertSame(['resolved' => 3], $report['results'][MaintenanceService::TASK_APPROVAL_TIMEOUTS]);
    }

    public function testNothingExpiredIsReportedAsSkipped(): void
    {
        $this->stubApprovals(0);

        $report = $this->maintenance()->runIfDue();

        $this->assertNotContains(MaintenanceService::TASK_APPROVAL_TIMEOUTS, $report['ran']);
        $this->assertContains(
            ['task' => MaintenanceService::TASK_APPROVAL_TIMEOUTS, 'reason' => MaintenanceService::SKIP_NONE_EXPIRED],
            $report['skipped']
        );
    }

    public function testAnErrorIsLoggedWithoutItsMessageAndTheOtherTasksStillRun(): void
    {
        $this->stubApprovals(0, new \yii\db\Exception('SQLSTATE[HY000]: UPDATE approval_request SET comment = \'secret-comment-value\''));
        $maintenance = $this->maintenance();

        [$report, $warnings] = $this->withWarnings(fn (): array => $maintenance->runIfDue());

        $this->assertContains(
            ['task' => MaintenanceService::TASK_APPROVAL_TIMEOUTS, 'reason' => MaintenanceService::SKIP_ERROR],
            $report['skipped']
        );
        $this->assertContains(MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP, array_column($report['skipped'], 'task'), 'the next task ran');
        $this->assertSame([sprintf(MaintenanceService::TASK_FAILED, 'applying approval timeouts', \yii\db\Exception::class)], $warnings);
    }

    /**
     * The approval timeouts have no cooldown: an error is retried on the
     * very next tick.
     */
    public function testAnApprovalTimeoutErrorIsRetriedOnTheNextTick(): void
    {
        $approvals = $this->stubApprovals(0, new \RuntimeException('fails once'), 1);
        $maintenance = $this->maintenance();

        $this->withWarnings(fn (): array => $maintenance->runIfDue());
        $maintenance->runIfDue();

        $this->assertSame([MaintenanceService::APPROVAL_TIMEOUT_BATCH_SIZE], $approvals->limits, 'the second tick ran them');
    }

    // -- deleted-template-sweep --------------------------------------------------

    public function testTheSweepCancelsWaitingJobsAndDisablesSchedulesOfDeletedTemplates(): void
    {
        $owner = $this->createUserWithRole('sweep-owner', 'admin');
        $template = $this->cancelFixtureTemplate((int)$owner->id);
        $job = $this->createJob((int)$template->id, (int)$owner->id, Job::STATUS_QUEUED);
        $schedule = $this->schedule((int)$template->id, (int)$owner->id);
        $template->softDelete();

        $report = $this->maintenance()->runIfDue();

        $this->assertContains(MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP, $report['ran']);
        $this->assertSame(
            ['canceled_jobs' => 1, 'disabled_schedules' => 1, 'remaining_jobs' => 0, 'canceled_runs' => 0, 'failed_runs' => 0, 'remaining_runs' => 0]
                + self::NO_STRANDED_JOBS,
            $report['results'][MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP]
        );
        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $schedule->refresh();
        $this->assertFalse((bool)$schedule->enabled);
    }

    public function testTheSweepWaitsForItsCooldown(): void
    {
        $deletion = $this->stubDeletion(['canceled_jobs' => 0, 'disabled_schedules' => 0, 'remaining_jobs' => 0]);
        $workflows = $this->stubWorkflowDeletion(['canceled_runs' => 0, 'failed_runs' => 0, 'remaining_runs' => 0]);
        $stranded = $this->stubStranded(self::NO_STRANDED_JOBS);
        $maintenance = $this->maintenance();

        $first = $maintenance->runIfDue();
        $second = $maintenance->runIfDue();

        $this->assertSame(1, $deletion->sweeps);
        $this->assertSame(1, $workflows->sweeps);
        $this->assertSame(1, $stranded->sweeps);
        $this->assertContains(
            ['task' => MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP, 'reason' => MaintenanceService::SKIP_NONE_FOUND],
            $first['skipped']
        );
        $this->assertContains(['task' => MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP, 'reason' => 'cooldown'], $second['skipped']);
    }

    /**
     * A sweep that leaves waiting jobs behind (a full batch) lets its
     * cooldown go, so the next tick goes on with the rest.
     */
    public function testASweepWithJobsLeftIsFollowedOnTheNextTick(): void
    {
        $deletion = $this->stubDeletion(['canceled_jobs' => 100, 'disabled_schedules' => 0, 'remaining_jobs' => 5]);
        $this->stubWorkflowDeletion(['canceled_runs' => 0, 'failed_runs' => 0, 'remaining_runs' => 0]);
        $this->stubStranded(self::NO_STRANDED_JOBS);
        $maintenance = $this->maintenance();

        $first = $maintenance->runIfDue();
        $second = $maintenance->runIfDue();

        $this->assertSame(2, $deletion->sweeps);
        $this->assertSame(
            ['canceled_jobs' => 100, 'disabled_schedules' => 0, 'remaining_jobs' => 5, 'canceled_runs' => 0, 'failed_runs' => 0, 'remaining_runs' => 0]
                + self::NO_STRANDED_JOBS,
            $first['results'][MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP]
        );
        $this->assertContains(MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP, $second['ran']);
    }

    /**
     * Jobs left over although nothing was canceled (every cancel failed)
     * are reported, not hidden as "none found".
     */
    public function testJobsLeftOverAreReportedEvenWhenNoneWasCanceled(): void
    {
        $this->stubDeletion(['canceled_jobs' => 0, 'disabled_schedules' => 0, 'remaining_jobs' => 2]);
        $this->stubStranded(self::NO_STRANDED_JOBS);

        $report = $this->maintenance()->runIfDue();

        $this->assertContains(MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP, $report['ran']);
        $this->assertSame(2, $report['results'][MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP]['remaining_jobs']);
    }

    /**
     * regression: a run of a deleted workflow template stayed running for
     * good when nothing canceled it: the delete's cancel failed, or the
     * template was deleted before deletes canceled runs. The sweep cancels
     * it, as the system.
     */
    public function testTheSweepCancelsActiveRunsOfDeletedWorkflowTemplates(): void
    {
        $owner = $this->createUserWithRole('sweep-wf-owner', 'admin');
        $workflow = $this->createWorkflowTemplate((int)$owner->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$owner->id);
        $workflow->softDelete();

        $report = $this->maintenance()->runIfDue();

        $this->assertContains(MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP, $report['ran']);
        $this->assertSame(
            ['canceled_jobs' => 0, 'disabled_schedules' => 0, 'remaining_jobs' => 0, 'canceled_runs' => 1, 'failed_runs' => 0, 'remaining_runs' => 0]
                + self::NO_STRANDED_JOBS,
            $report['results'][MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP]
        );
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $run->status);
        $canceled = $this->onlyAuditOf(AuditLog::ACTION_WORKFLOW_CANCELED, 'workflow_job', (int)$run->id);
        $this->assertNull($canceled->user_id);
        $this->assertSame(['reason' => JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED], $this->contextOf($canceled));
    }

    /**
     * Runs left over (a full batch, or cancels that failed) are reported and
     * let the cooldown go, so the next tick goes on.
     */
    public function testRunsLeftOverAreReportedAndFollowedOnTheNextTick(): void
    {
        $this->stubDeletion(['canceled_jobs' => 0, 'disabled_schedules' => 0, 'remaining_jobs' => 0]);
        $workflows = $this->stubWorkflowDeletion(['canceled_runs' => 0, 'failed_runs' => 2, 'remaining_runs' => 2]);
        $this->stubStranded(self::NO_STRANDED_JOBS);
        $maintenance = $this->maintenance();

        $first = $maintenance->runIfDue();
        $second = $maintenance->runIfDue();

        $this->assertSame(2, $workflows->sweeps);
        $this->assertSame(
            ['canceled_jobs' => 0, 'disabled_schedules' => 0, 'remaining_jobs' => 0, 'canceled_runs' => 0, 'failed_runs' => 2, 'remaining_runs' => 2]
                + self::NO_STRANDED_JOBS,
            $first['results'][MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP]
        );
        $this->assertContains(MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP, $second['ran']);
    }

    /**
     * The runs go first: an error there is logged without its message, and
     * the stranded jobs and the jobs of deleted templates wait for the next
     * sweep.
     */
    public function testARunSweepErrorIsLoggedWithoutItsMessage(): void
    {
        $deletion = $this->stubDeletion(['canceled_jobs' => 1, 'disabled_schedules' => 0, 'remaining_jobs' => 0]);
        $this->stubWorkflowDeletion(
            ['canceled_runs' => 0, 'failed_runs' => 0, 'remaining_runs' => 0],
            new \yii\db\Exception("SQLSTATE[HY000]: SELECT * FROM workflow_job WHERE name = 'secret-run-value'")
        );
        $stranded = $this->stubStranded(self::NO_STRANDED_JOBS);
        $maintenance = $this->maintenance();

        [$report, $warnings] = $this->withWarnings(fn (): array => $maintenance->runIfDue());

        $this->assertSame(0, $stranded->sweeps);
        $this->assertSame(0, $deletion->sweeps);
        $this->assertContains(
            ['task' => MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP, 'reason' => MaintenanceService::SKIP_ERROR],
            $report['skipped']
        );
        $this->assertSame([sprintf(MaintenanceService::TASK_FAILED, 'the sweep of deleted job and workflow templates', \yii\db\Exception::class)], $warnings);
    }

    public function testTheSweepCanBeDisabled(): void
    {
        $deletion = $this->stubDeletion(['canceled_jobs' => 1, 'disabled_schedules' => 0, 'remaining_jobs' => 0]);
        $workflows = $this->stubWorkflowDeletion(['canceled_runs' => 1, 'failed_runs' => 0, 'remaining_runs' => 0]);
        $stranded = $this->stubStranded(['stranded_jobs_canceled' => 1, 'stranded_jobs_failed' => 0, 'stranded_jobs_remaining' => 0]);
        $maintenance = $this->maintenance();
        $maintenance->deletedTemplateSweepIntervalSeconds = 0;

        $report = $maintenance->runIfDue();

        $this->assertSame(0, $deletion->sweeps);
        $this->assertSame(0, $workflows->sweeps);
        $this->assertSame(0, $stranded->sweeps);
        $this->assertContains(['task' => MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP, 'reason' => 'disabled'], $report['skipped']);
    }

    public function testASweepErrorIsLoggedWithoutItsMessage(): void
    {
        $this->stubDeletion(['canceled_jobs' => 0, 'disabled_schedules' => 0, 'remaining_jobs' => 0], new \RuntimeException('carries a value that must not be logged'));
        $this->stubStranded(self::NO_STRANDED_JOBS);
        $maintenance = $this->maintenance();

        [$report, $warnings] = $this->withWarnings(fn (): array => $maintenance->runIfDue());

        $this->assertContains(
            ['task' => MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP, 'reason' => MaintenanceService::SKIP_ERROR],
            $report['skipped']
        );
        $this->assertSame([sprintf(MaintenanceService::TASK_FAILED, 'the sweep of deleted job and workflow templates', \RuntimeException::class)], $warnings);
    }

    /**
     * Gap 4: a sweep error is not retried on the next tick. Its cooldown is
     * taken before the sweep starts and kept after the error, so the sweep
     * runs again once its interval has passed: the cooldown is the backoff
     * for an error that repeats.
     */
    public function testASweepErrorWaitsForTheSweepInterval(): void
    {
        $deletion = $this->stubDeletion(['canceled_jobs' => 1, 'disabled_schedules' => 0, 'remaining_jobs' => 0]);
        $this->stubWorkflowDeletion(['canceled_runs' => 0, 'failed_runs' => 0, 'remaining_runs' => 0]);
        $failing = $this->stubStranded(self::NO_STRANDED_JOBS, new \RuntimeException('fails'));
        $maintenance = $this->maintenance();

        [$first] = $this->withWarnings(fn (): array => $maintenance->runIfDue());
        $second = $maintenance->runIfDue();

        $this->assertContains(['task' => MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP, 'reason' => MaintenanceService::SKIP_ERROR], $first['skipped']);
        $this->assertContains(['task' => MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP, 'reason' => 'cooldown'], $second['skipped']);
        $this->assertCount(1, $failing->calledAt, 'tried once, then the cooldown holds');
        $this->assertSame(0, $deletion->sweeps);

        // The interval has passed, and the error is gone.
        \Yii::$app->cache?->delete(MaintenanceService::DELETED_TEMPLATE_SWEEP_KEY);
        $this->stubStranded(self::NO_STRANDED_JOBS);
        $third = $maintenance->runIfDue();

        $this->assertContains(MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP, $third['ran'], 'the sweep runs again once its interval has passed');
        $this->assertSame(1, $deletion->sweeps);
    }

    // -- stranded jobs (contract K1) ----------------------------------------------

    /**
     * The sweep runs the stranded job sweep after the runs and before the
     * jobs of deleted job templates; its three counts end the result line,
     * after the keys of the job templates and of the runs.
     */
    public function testTheSweepCancelsStrandedJobsAfterTheRunsAndReportsThemLast(): void
    {
        $deletion = $this->stubDeletion(['canceled_jobs' => 1, 'disabled_schedules' => 2, 'remaining_jobs' => 0]);
        $workflows = $this->stubWorkflowDeletion(['canceled_runs' => 3, 'failed_runs' => 0, 'remaining_runs' => 0]);
        $stranded = $this->stubStranded(['stranded_jobs_canceled' => 4, 'stranded_jobs_failed' => 5, 'stranded_jobs_remaining' => 0]);

        $report = $this->maintenance()->runIfDue();

        $this->assertSame(
            [
                'canceled_jobs' => 1,
                'disabled_schedules' => 2,
                'remaining_jobs' => 0,
                'canceled_runs' => 3,
                'failed_runs' => 0,
                'remaining_runs' => 0,
                'stranded_jobs_canceled' => 4,
                'stranded_jobs_failed' => 5,
                'stranded_jobs_remaining' => 0,
            ],
            $report['results'][MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP],
            'the keys in this order'
        );
        $this->assertSame([StrandedJobSweep::BATCH_SIZE], $stranded->limits);
        $this->assertLessThan($stranded->calledAt[0], $workflows->calledAt[0], 'the runs first');
        $this->assertLessThan($deletion->calledAt[0], $stranded->calledAt[0], 'the stranded jobs before the jobs of deleted templates');
    }

    /**
     * Stranded jobs alone are reported: the sweep ran.
     */
    public function testStrandedJobsAloneAreReported(): void
    {
        $this->stubDeletion(['canceled_jobs' => 0, 'disabled_schedules' => 0, 'remaining_jobs' => 0]);
        $this->stubWorkflowDeletion(['canceled_runs' => 0, 'failed_runs' => 0, 'remaining_runs' => 0]);
        $this->stubStranded(['stranded_jobs_canceled' => 0, 'stranded_jobs_failed' => 1, 'stranded_jobs_remaining' => 0]);

        $report = $this->maintenance()->runIfDue();

        $this->assertContains(MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP, $report['ran']);
        $this->assertSame(1, $report['results'][MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP]['stranded_jobs_failed']);
    }

    /**
     * Stranded jobs left over (a full batch, the deadline) let the cooldown
     * go, as runs and jobs do; nothing left keeps it.
     */
    public function testStrandedJobsLeftOverAreFollowedOnTheNextTick(): void
    {
        $this->stubDeletion(['canceled_jobs' => 0, 'disabled_schedules' => 0, 'remaining_jobs' => 0]);
        $this->stubWorkflowDeletion(['canceled_runs' => 0, 'failed_runs' => 0, 'remaining_runs' => 0]);
        $leftOver = $this->stubStranded(['stranded_jobs_canceled' => 100, 'stranded_jobs_failed' => 0, 'stranded_jobs_remaining' => 7]);
        $maintenance = $this->maintenance();

        $maintenance->runIfDue();
        $second = $maintenance->runIfDue();

        $this->assertSame(2, $leftOver->sweeps);
        $this->assertContains(MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP, $second['ran']);

        $done = $this->stubStranded(['stranded_jobs_canceled' => 7, 'stranded_jobs_failed' => 0, 'stranded_jobs_remaining' => 0]);
        $maintenance->runIfDue();
        $fourth = $maintenance->runIfDue();

        $this->assertSame(1, $done->sweeps, 'nothing left: the cooldown holds');
        $this->assertContains(['task' => MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP, 'reason' => 'cooldown'], $fourth['skipped']);
    }

    /**
     * With the real sweep: a job that still waits under a run that has ended
     * (here a canceled run whose cancel did not reach the job) is canceled
     * by the maintenance run, and reported.
     */
    public function testTheMaintenanceRunCancelsAJobLeftWaitingUnderARunThatEnded(): void
    {
        $owner = $this->createUserWithRole('stranded-owner', 'admin');
        $template = $this->cancelFixtureTemplate((int)$owner->id);
        $workflow = $this->createWorkflowTemplate((int)$owner->id);
        $step = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$template->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$owner->id, WorkflowJob::STATUS_CANCELED);
        $job = $this->createJob((int)$template->id, (int)$owner->id, Job::STATUS_QUEUED);
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = (int)$step->id;
        $execution->job_id = (int)$job->id;
        $execution->status = WorkflowJobStep::STATUS_RUNNING;
        $execution->started_at = time();
        $this->assertTrue($execution->save(false));

        $report = $this->maintenance()->runIfDue();

        $this->assertContains(MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP, $report['ran']);
        $result = $report['results'][MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP];
        $this->assertSame(1, $result['stranded_jobs_canceled']);
        $this->assertSame(0, $result['stranded_jobs_remaining']);
        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
    }

    // -- the time budget (operations-1) -------------------------------------------

    /**
     * One deadline per tick, taken before the approval timeouts, bounds both
     * tasks: every loop gets the same one, with its batch size.
     */
    public function testOneDeadlineTakenBeforeTheApprovalTimeoutsBoundsEveryLoop(): void
    {
        $approvals = $this->stubApprovals(1);
        $deletion = $this->stubDeletion(['canceled_jobs' => 1, 'disabled_schedules' => 0, 'remaining_jobs' => 0]);
        $workflows = $this->stubWorkflowDeletion(['canceled_runs' => 0, 'failed_runs' => 0, 'remaining_runs' => 0]);
        $stranded = $this->stubStranded(self::NO_STRANDED_JOBS);
        $maintenance = $this->maintenance();
        $this->assertSame(45.0, $maintenance->jobTaskBudgetSeconds, 'the default budget');

        $before = microtime(true);
        $maintenance->runIfDue();

        $this->assertCount(1, $approvals->deadlines);
        $deadline = $approvals->deadlines[0];
        $this->assertIsFloat($deadline);
        $this->assertGreaterThanOrEqual($before + 45.0, $deadline);
        $this->assertLessThanOrEqual($approvals->calledAt[0] + 45.0, $deadline, 'taken before the approval timeouts started');
        $this->assertSame([$deadline], $workflows->deadlines);
        $this->assertSame([$deadline], $stranded->deadlines);
        $this->assertSame([$deadline], $deletion->deadlines);
        $this->assertSame([WorkflowTemplateDeletionService::SWEEP_BATCH_SIZE], $workflows->limits);
        $this->assertSame([JobTemplateDeletionService::SWEEP_BATCH_SIZE], $deletion->limits);
    }

    public function testABudgetOfZeroRemovesTheBound(): void
    {
        $approvals = $this->stubApprovals(0);
        $deletion = $this->stubDeletion(['canceled_jobs' => 0, 'disabled_schedules' => 0, 'remaining_jobs' => 0]);
        $workflows = $this->stubWorkflowDeletion(['canceled_runs' => 0, 'failed_runs' => 0, 'remaining_runs' => 0]);
        $stranded = $this->stubStranded(self::NO_STRANDED_JOBS);
        $maintenance = $this->maintenance();
        $maintenance->jobTaskBudgetSeconds = 0;

        $maintenance->runIfDue();

        $this->assertSame([null], $approvals->deadlines);
        $this->assertSame([null], $workflows->deadlines);
        $this->assertSame([null], $stranded->deadlines);
        $this->assertSame([null], $deletion->deadlines);
    }

    /**
     * Regression: one maintenance tick canceled a whole batch of waiting
     * jobs, each with its job.canceled notification sent synchronously, and
     * held the schedule runner up for as long as they took: 100 jobs whose
     * notification endpoint does not answer (10 seconds each) kept
     * schedule/run waiting for over 15 minutes, and the next tick went on
     * the same way. Now a tick starts no cancel once its time budget is used
     * up, lets the sweep's cooldown go, and the next tick cancels the rest.
     */
    public function testATickStopsStartingCancelsWhenItsBudgetIsUsedUpAndTheNextTickGoesOn(): void
    {
        $owner = $this->createUserWithRole('budget-owner', 'admin');
        $template = $this->cancelFixtureTemplate((int)$owner->id);
        $oldest = $this->createJob((int)$template->id, (int)$owner->id, Job::STATUS_QUEUED);
        $newest = $this->createJob((int)$template->id, (int)$owner->id, Job::STATUS_QUEUED);
        $template->softDelete();
        $this->slowNotifications(self::SLOW_SECONDS);
        $maintenance = $this->maintenance();
        $maintenance->jobTaskBudgetSeconds = self::SLOW_SECONDS;

        $first = $maintenance->runIfDue();

        $sweep = $first['results'][MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP];
        $this->assertSame(1, $sweep['canceled_jobs'], 'the first cancel used the budget up');
        $this->assertSame(1, $sweep['remaining_jobs']);
        $this->assertSame(Job::STATUS_CANCELED, $this->statusOf($oldest), 'oldest first');
        $this->assertSame(Job::STATUS_QUEUED, $this->statusOf($newest));
        $this->assertFalse((bool)\Yii::$app->cache?->exists(MaintenanceService::DELETED_TEMPLATE_SWEEP_KEY), 'the cooldown was let go');

        $second = $maintenance->runIfDue();

        $sweep = $second['results'][MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP];
        $this->assertSame(1, $sweep['canceled_jobs']);
        $this->assertSame(0, $sweep['remaining_jobs']);
        $this->assertSame(Job::STATUS_CANCELED, $this->statusOf($newest));
        $third = $maintenance->runIfDue();
        $this->assertContains(['task' => MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP, 'reason' => 'cooldown'], $third['skipped'], 'nothing left: the cooldown holds');
    }

    /**
     * The deadline is shared: a run of a deleted workflow template whose
     * workflow.canceled notification uses the budget up leaves the jobs of
     * deleted job templates to the next tick.
     */
    public function testWhatTheRunSweepUsesUpIsNotLeftToTheJobSweep(): void
    {
        $owner = $this->createUserWithRole('budget-wf-owner', 'admin');
        $workflow = $this->createWorkflowTemplate((int)$owner->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$owner->id);
        $workflow->softDelete();
        $template = $this->cancelFixtureTemplate((int)$owner->id);
        $job = $this->createJob((int)$template->id, (int)$owner->id, Job::STATUS_QUEUED);
        $template->softDelete();
        $this->slowNotifications(self::SLOW_SECONDS);
        $maintenance = $this->maintenance();
        $maintenance->jobTaskBudgetSeconds = self::SLOW_SECONDS;

        $first = $maintenance->runIfDue();

        $this->assertSame(
            ['canceled_jobs' => 0, 'disabled_schedules' => 0, 'remaining_jobs' => 1, 'canceled_runs' => 1, 'failed_runs' => 0, 'remaining_runs' => 0]
                + self::NO_STRANDED_JOBS,
            $first['results'][MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP]
        );
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $run->status);
        $this->assertSame(Job::STATUS_QUEUED, $this->statusOf($job), 'no cancel started after the deadline');

        $second = $maintenance->runIfDue();

        $this->assertSame(1, $second['results'][MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP]['canceled_jobs']);
        $this->assertSame(Job::STATUS_CANCELED, $this->statusOf($job));
    }

    /**
     * Approval timeouts that use the budget up leave the sweep nothing to
     * start this tick: it reports what is left and goes on at the next one.
     */
    public function testApprovalTimeoutsThatUseTheBudgetUpLeaveTheSweepToTheNextTick(): void
    {
        $this->stubApprovals(1, null, 0, 0.3);
        $owner = $this->createUserWithRole('budget-timeouts-owner', 'admin');
        $template = $this->cancelFixtureTemplate((int)$owner->id);
        $job = $this->createJob((int)$template->id, (int)$owner->id, Job::STATUS_QUEUED);
        $template->softDelete();
        $maintenance = $this->maintenance();
        $maintenance->jobTaskBudgetSeconds = 0.2;

        $first = $maintenance->runIfDue();

        $sweep = $first['results'][MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP];
        $this->assertSame(0, $sweep['canceled_jobs']);
        $this->assertSame(1, $sweep['remaining_jobs']);
        $this->assertSame(Job::STATUS_QUEUED, $this->statusOf($job));

        $maintenance->jobTaskBudgetSeconds = 0;
        $second = $maintenance->runIfDue();

        $this->assertSame(1, $second['results'][MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP]['canceled_jobs']);
        $this->assertSame(Job::STATUS_CANCELED, $this->statusOf($job));
    }

    // -- pending migrations -------------------------------------------------------

    /**
     * While migrations are pending, neither task touches jobs or approval
     * requests, and the sweep's cooldown is not used up: it runs on the
     * first tick after the update.
     */
    public function testBothTasksWaitWhileMigrationsArePending(): void
    {
        $approvals = $this->stubApprovals(1);
        $deletion = $this->stubDeletion(['canceled_jobs' => 1, 'disabled_schedules' => 0, 'remaining_jobs' => 0]);
        $workflows = $this->stubWorkflowDeletion(['canceled_runs' => 0, 'failed_runs' => 0, 'remaining_runs' => 0]);
        $stranded = $this->stubStranded(self::NO_STRANDED_JOBS);

        $report = $this->maintenance(fn (): int => 2)->runIfDue();

        $this->assertSame([], $approvals->limits);
        $this->assertSame(0, $deletion->sweeps);
        $this->assertSame(0, $workflows->sweeps, 'no run is canceled while migrations are pending');
        $this->assertSame(0, $stranded->sweeps, 'no stranded job is canceled while migrations are pending');
        $this->assertContains(
            ['task' => MaintenanceService::TASK_APPROVAL_TIMEOUTS, 'reason' => MaintenanceService::SKIP_MIGRATIONS_PENDING],
            $report['skipped']
        );
        $this->assertContains(
            ['task' => MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP, 'reason' => MaintenanceService::SKIP_MIGRATIONS_PENDING],
            $report['skipped']
        );

        $after = $this->maintenance()->runIfDue();
        $this->assertSame([MaintenanceService::APPROVAL_TIMEOUT_BATCH_SIZE], $approvals->limits);
        $this->assertSame(1, $deletion->sweeps, 'the cooldown was not used up while waiting');
        $this->assertSame(1, $workflows->sweeps);
        $this->assertSame(1, $stranded->sweeps);
        $this->assertContains(MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP, $after['ran']);
    }

    public function testAnUnreadableMigrationStateHoldsBothTasksBack(): void
    {
        $approvals = $this->stubApprovals(1);
        $deletion = $this->stubDeletion(['canceled_jobs' => 1, 'disabled_schedules' => 0, 'remaining_jobs' => 0]);
        $stranded = $this->stubStranded(self::NO_STRANDED_JOBS);
        $maintenance = $this->maintenance(static function (): int {
            throw new \yii\db\Exception('SQLSTATE[42S02]: Base table or view not found: migration');
        });

        [$report, $warnings] = $this->withWarnings(fn (): array => $maintenance->runIfDue());

        $this->assertSame([], $approvals->limits);
        $this->assertSame(0, $deletion->sweeps);
        $this->assertSame(0, $stranded->sweeps);
        $this->assertContains(['task' => MaintenanceService::TASK_APPROVAL_TIMEOUTS, 'reason' => MaintenanceService::SKIP_ERROR], $report['skipped']);
        $this->assertContains(['task' => MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP, 'reason' => MaintenanceService::SKIP_ERROR], $report['skipped']);
        $this->assertSame([sprintf(MaintenanceService::TASK_FAILED, 'reading the migration state', \yii\db\Exception::class)], $warnings);
    }

    /**
     * The service as configured reads the real migration state: the test
     * database is up to date, so both tasks run.
     */
    public function testTheConfiguredServiceRunsBothTasksOnAnUpToDateDatabase(): void
    {
        $approvals = $this->stubApprovals(1);
        $deletion = $this->stubDeletion(['canceled_jobs' => 1, 'disabled_schedules' => 0, 'remaining_jobs' => 0]);
        $maintenance = new MaintenanceService(['artifactCleanupIntervalSeconds' => 0, 'surveySecretScrubBudgetSeconds' => 0]);

        $report = $maintenance->runIfDue();

        $this->assertSame([MaintenanceService::APPROVAL_TIMEOUT_BATCH_SIZE], $approvals->limits);
        $this->assertSame(1, $deletion->sweeps);
        $this->assertSame([MaintenanceService::TASK_APPROVAL_TIMEOUTS, MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP], $report['ran']);
    }

    // -- helpers -------------------------------------------------------------------

    /**
     * A MaintenanceService with only the tested tasks: no artifact cleanup,
     * no survey scrub. $pending answers the migration check (default: none).
     *
     * @param (callable(): int)|null $pending
     */
    private function maintenance(?callable $pending = null): MaintenanceService
    {
        $maintenance = new class ($pending ?? static fn (): int => 0) extends MaintenanceService {
            /** @var callable(): int */
            private $pending;

            public function __construct(callable $pending)
            {
                $this->pending = $pending;
                parent::__construct();
            }

            protected function pendingMigrations(): PendingMigrations
            {
                return new class ($this->pending) extends PendingMigrations {
                    /** @var callable(): int */
                    private $answer;

                    public function __construct(callable $answer)
                    {
                        $this->answer = $answer;
                        parent::__construct();
                    }

                    public function count(): int
                    {
                        return ($this->answer)();
                    }
                };
            }
        };
        $maintenance->artifactCleanupIntervalSeconds = 0;
        $maintenance->surveySecretScrubBudgetSeconds = 0;

        return $maintenance;
    }

    /**
     * An approval service whose processTimeouts() records its limit, its
     * deadline and when it was called, takes $takesSeconds, and answers
     * $timedOut, or throws $failWith ($failures times; 0: every time).
     *
     * @return ApprovalService&object{limits: list<int>, deadlines: list<float|null>, calledAt: list<float>}
     */
    private function stubApprovals(int $timedOut, ?\Throwable $failWith = null, int $failures = 0, float $takesSeconds = 0.0): ApprovalService
    {
        $stub = new class ($timedOut, $failWith, $failures, $takesSeconds) extends ApprovalService {
            /** @var list<int> */
            public array $limits = [];
            /** @var list<float|null> */
            public array $deadlines = [];
            /** @var list<float> */
            public array $calledAt = [];
            private int $failed = 0;

            public function __construct(
                private readonly int $timedOut,
                private readonly ?\Throwable $failWith,
                private readonly int $failures,
                private readonly float $takesSeconds
            ) {
                parent::__construct();
            }

            public function processTimeouts(int $limit = 0, ?float $deadline = null): int
            {
                $this->calledAt[] = microtime(true);
                if ($this->failWith !== null && ($this->failures === 0 || $this->failed < $this->failures)) {
                    $this->failed++;
                    throw $this->failWith;
                }
                $this->limits[] = $limit;
                $this->deadlines[] = $deadline;
                usleep((int)($this->takesSeconds * 1000000));

                return $this->timedOut;
            }
        };
        $this->replace('approvalService', $stub);

        return $stub;
    }

    /**
     * A deletion service whose sweep() records its calls, limits and
     * deadlines and answers $result, or throws $failWith.
     *
     * @param array{canceled_jobs: int, disabled_schedules: int, remaining_jobs: int} $result
     * @return JobTemplateDeletionService&object{sweeps: int, limits: list<int>, deadlines: list<float|null>, calledAt: list<int>}
     */
    private function stubDeletion(array $result, ?\Throwable $failWith = null): JobTemplateDeletionService
    {
        $stub = new class ($result, $failWith) extends JobTemplateDeletionService {
            public int $sweeps = 0;
            /** @var list<int> */
            public array $limits = [];
            /** @var list<float|null> */
            public array $deadlines = [];
            /** @var list<int> hrtime() of each call */
            public array $calledAt = [];

            /**
             * @param array{canceled_jobs: int, disabled_schedules: int, remaining_jobs: int} $result
             */
            public function __construct(private readonly array $result, private readonly ?\Throwable $failWith)
            {
                parent::__construct();
            }

            public function sweep(int $limit = self::SWEEP_BATCH_SIZE, ?float $deadline = null): array
            {
                $this->calledAt[] = (int)hrtime(true);
                if ($this->failWith !== null) {
                    throw $this->failWith;
                }
                $this->sweeps++;
                $this->limits[] = $limit;
                $this->deadlines[] = $deadline;

                return $this->result;
            }
        };
        $this->replace('jobTemplateDeletionService', $stub);

        return $stub;
    }

    /**
     * A workflow template deletion service whose sweep() records its calls,
     * limits and deadlines and answers $result, or throws $failWith.
     *
     * @param array{canceled_runs: int, failed_runs: int, remaining_runs: int} $result
     * @return WorkflowTemplateDeletionService&object{sweeps: int, limits: list<int>, deadlines: list<float|null>, calledAt: list<int>}
     */
    private function stubWorkflowDeletion(array $result, ?\Throwable $failWith = null): WorkflowTemplateDeletionService
    {
        $stub = new class ($result, $failWith) extends WorkflowTemplateDeletionService {
            public int $sweeps = 0;
            /** @var list<int> */
            public array $limits = [];
            /** @var list<float|null> */
            public array $deadlines = [];
            /** @var list<int> hrtime() of each call */
            public array $calledAt = [];

            /**
             * @param array{canceled_runs: int, failed_runs: int, remaining_runs: int} $result
             */
            public function __construct(private readonly array $result, private readonly ?\Throwable $failWith)
            {
                parent::__construct();
            }

            public function sweep(int $limit = self::SWEEP_BATCH_SIZE, ?float $deadline = null): array
            {
                $this->calledAt[] = (int)hrtime(true);
                if ($this->failWith !== null) {
                    throw $this->failWith;
                }
                $this->sweeps++;
                $this->limits[] = $limit;
                $this->deadlines[] = $deadline;

                return $this->result;
            }
        };
        $this->replace('workflowTemplateDeletionService', $stub);

        return $stub;
    }

    /**
     * A stranded job sweep whose sweep() records its calls, limits and
     * deadlines and answers $result, or throws $failWith.
     *
     * @param array{stranded_jobs_canceled: int, stranded_jobs_failed: int, stranded_jobs_remaining: int} $result
     * @return StrandedJobSweep&object{sweeps: int, limits: list<int>, deadlines: list<float|null>, calledAt: list<int>}
     */
    private function stubStranded(array $result, ?\Throwable $failWith = null): StrandedJobSweep
    {
        $stub = new class ($result, $failWith) extends StrandedJobSweep {
            public int $sweeps = 0;
            /** @var list<int> */
            public array $limits = [];
            /** @var list<float|null> */
            public array $deadlines = [];
            /** @var list<int> hrtime() of each call */
            public array $calledAt = [];

            /**
             * @param array{stranded_jobs_canceled: int, stranded_jobs_failed: int, stranded_jobs_remaining: int} $result
             */
            public function __construct(private readonly array $result, private readonly ?\Throwable $failWith)
            {
                parent::__construct();
            }

            public function sweep(int $limit = self::BATCH_SIZE, ?float $deadline = null): array
            {
                $this->calledAt[] = (int)hrtime(true);
                if ($this->failWith !== null) {
                    throw $this->failWith;
                }
                $this->sweeps++;
                $this->limits[] = $limit;
                $this->deadlines[] = $deadline;

                return $this->result;
            }
        };
        $this->replace('strandedJobSweep', $stub);

        return $stub;
    }

    /**
     * Every notification takes $seconds, as with an endpoint that answers
     * slowly (notifications are sent synchronously); they are recorded, not
     * sent.
     */
    private function slowNotifications(float $seconds): void
    {
        $this->replace('notificationDispatcher', new class ($seconds) extends RecordingNotificationDispatcher {
            public function __construct(private readonly float $seconds)
            {
                parent::__construct();
            }

            public function dispatch(string $event, array $payload = []): void
            {
                usleep((int)($this->seconds * 1000000));
                parent::dispatch($event, $payload);
            }
        });
    }

    private function replace(string $id, object $component): void
    {
        if (!array_key_exists($id, $this->replaced)) {
            $this->replaced[$id] = \Yii::$app->getComponents(true)[$id] ?? null;
        }
        \Yii::$app->set($id, $component);
    }

    private function statusOf(Job $job): string
    {
        $job->refresh();

        return (string)$job->status;
    }

    /**
     * Run $action and return its result with the warnings MaintenanceService logged.
     *
     * @template T
     * @param callable(): T $action
     * @return array{0: T, 1: list<string>}
     */
    private function withWarnings(callable $action): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $result = $action();
        } finally {
            \Yii::setLogger($previous);
        }
        $warnings = [];
        foreach ($logger->messages as $message) {
            if ($message[1] === Logger::LEVEL_WARNING && $message[2] === MaintenanceService::class && is_string($message[0])) {
                $warnings[] = $message[0];
            }
        }

        return [$result, $warnings];
    }

    private function schedule(int $templateId, int $createdBy): Schedule
    {
        $schedule = new Schedule();
        $schedule->name = 'sweep-' . uniqid('', true);
        $schedule->job_template_id = $templateId;
        $schedule->cron_expression = '0 2 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->created_by = $createdBy;
        $schedule->computeNextRunAt();
        $schedule->save(false);

        return $schedule;
    }
}
