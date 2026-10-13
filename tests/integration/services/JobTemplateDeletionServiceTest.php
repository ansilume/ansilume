<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\Schedule;
use app\models\User;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\JobCancellationService;
use app\services\JobTemplateDeletionService;
use app\services\ScheduleEnablement;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\ModelEvent;
use yii\db\BaseActiveRecord;
use yii\log\Logger;

/**
 * Deleting a job template: the soft delete with its schedules disabled in
 * one transaction (the template row locked first), then the template's
 * waiting jobs canceled; and the maintenance sweep that does the same for
 * every soft-deleted template.
 */
class JobTemplateDeletionServiceTest extends DbTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;

    private RecordingNotificationDispatcher $notifications;
    private User $admin;
    private User $launcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = $this->recordNotifications();
        $this->admin = $this->createUserWithRole('deletion-admin', 'admin');
        $this->launcher = $this->createUserWithRole('deletion-launcher', 'operator');
    }

    protected function tearDown(): void
    {
        $this->restoreNotifications();
        parent::tearDown();
    }

    // -- Waiting jobs ---------------------------------------------------------

    /**
     * Regression: deleting a job template left its waiting jobs queued. No
     * runner claims a job of a deleted template, so they stayed queued (or
     * waiting for approval) for good.
     */
    public function testDeletingATemplateCancelsItsWaitingJobsAndLeavesTheOthers(): void
    {
        $template = $this->cancelFixtureTemplate((int)$this->admin->id);
        $other = $this->cancelFixtureTemplate((int)$this->admin->id);
        $waiting = [];
        foreach (Job::waitingStatuses() as $status) {
            $waiting[$status] = $this->createJob((int)$template->id, (int)$this->launcher->id, $status);
        }
        $kept = [];
        foreach ([Job::STATUS_RUNNING, Job::STATUS_SUCCEEDED, Job::STATUS_FAILED, Job::STATUS_CANCELED] as $status) {
            $kept[$status] = $this->createJob((int)$template->id, (int)$this->launcher->id, $status);
        }
        $otherJob = $this->createJob((int)$other->id, (int)$this->launcher->id, Job::STATUS_QUEUED);

        $result = $this->deletion()->delete($template, (int)$this->admin->id, JobTemplateDeletionService::SOURCE_WEB);

        $this->assertSame(['deleted' => true, 'canceled_jobs' => 3, 'disabled_schedules' => 0], $result);
        foreach ($waiting as $status => $job) {
            $job->refresh();
            $this->assertSame(Job::STATUS_CANCELED, $job->status, "a {$status} job is canceled");
            $this->assertNotNull($job->finished_at);
        }
        foreach ($kept as $status => $job) {
            $job->refresh();
            $this->assertSame($status, $job->status, "a {$status} job is left alone");
        }
        $otherJob->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $otherJob->status, 'another template\'s job is left alone');
    }

    public function testTheCanceledJobsAreAuditedWithTheReasonAndTheActorAndNotifiedOnce(): void
    {
        $template = $this->cancelFixtureTemplate((int)$this->admin->id);
        $job = $this->createJob((int)$template->id, (int)$this->launcher->id, Job::STATUS_QUEUED);

        $this->deletion()->delete($template, (int)$this->admin->id, JobTemplateDeletionService::SOURCE_API);

        $entry = $this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id);
        $this->assertSame((int)$this->admin->id, (int)$entry->user_id);
        $this->assertSame(['reason' => JobCancellationService::REASON_TEMPLATE_DELETED], $this->contextOf($entry));
        $payloads = $this->notifications->payloadsOf(NotificationTemplate::EVENT_JOB_CANCELED);
        $this->assertCount(1, $payloads);
        $payload = $payloads[0]['job'] ?? null;
        $this->assertIsArray($payload);
        $this->assertSame((string)$job->id, $payload['id'] ?? null);
    }

    public function testTheRequestOfAJobWaitingForApprovalIsCanceled(): void
    {
        $template = $this->cancelFixtureTemplate((int)$this->admin->id);
        $approver = $this->createUserWithRole('deletion-approver', 'operator');
        ['job' => $job, 'request' => $request, 'rule' => $rule] = $this->jobWaitingForApproval($template, (int)$this->launcher->id, (int)$approver->id);

        $this->deletion()->delete($template, (int)$this->admin->id, JobTemplateDeletionService::SOURCE_WEB);

        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status);
        $this->assertNotNull($request->resolved_at);
        $entry = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', (int)$request->id);
        $this->assertSame((int)$this->admin->id, (int)$entry->user_id);
        $this->assertSame(
            ['job_id' => (int)$job->id, 'rule_id' => (int)$rule->id, 'reason' => JobCancellationService::REASON_TEMPLATE_DELETED],
            $this->contextOf($entry)
        );
    }

    /**
     * The job of a workflow's job step ends the step as failed, and the run
     * follows the step's failure route, as for a job that failed.
     */
    public function testAWorkflowStepsWaitingJobEndsTheStepAndTheRunMovesOn(): void
    {
        $template = $this->cancelFixtureTemplate((int)$this->launcher->id);
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $first = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$template->id);
        $onFailure = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $first->on_failure_step_id = $onFailure->id;
        $first->save(false);
        $run = $this->workflowEngine()->launch($workflow, (int)$this->launcher->id);
        $execution = $this->executionOf($run, $first);
        $this->assertNotNull($execution);
        $job = Job::findOne($execution->job_id);
        $this->assertNotNull($job);
        $this->assertSame(Job::STATUS_QUEUED, $job->status);

        $result = $this->deletion()->delete($template, (int)$this->admin->id, JobTemplateDeletionService::SOURCE_WEB);

        $this->assertSame(1, $result['canceled_jobs']);
        $execution->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $this->executionOf($run, $onFailure)?->status, 'the run follows the failure route');
    }

    /**
     * A cancel that fails is logged and left to the maintenance run; the
     * other jobs are canceled and the delete is still audited.
     *
     * The line names the error's class, SQLSTATE and driver error code
     * (wave 7: it used to name the class only, so a deadlock looked like a
     * lost connection), never its message, which carries the SQL with values.
     */
    public function testACancelThatFailsDoesNotStopTheOthers(): void
    {
        $template = $this->cancelFixtureTemplate((int)$this->admin->id);
        $broken = $this->createJob((int)$template->id, (int)$this->launcher->id, Job::STATUS_QUEUED);
        $fine = $this->createJob((int)$template->id, (int)$this->launcher->id, Job::STATUS_QUEUED);
        $deadlock = new \yii\db\Exception(
            "SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock\n"
            . "The SQL being executed was: UPDATE `job` SET `secret`='value-that-must-not-be-logged'",
            ['40001', 1213, 'Deadlock found when trying to get lock']
        );

        [$result, $errors] = $this->deleteWhileACancelFails($template, (int)$broken->id, $deadlock);

        $this->assertSame(['deleted' => true, 'canceled_jobs' => 1, 'disabled_schedules' => 0], $result);
        $broken->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $broken->status);
        $fine->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $fine->status);
        $entry = $this->onlyAuditOf(AuditLog::ACTION_TEMPLATE_DELETED, 'job_template', (int)$template->id);
        $this->assertSame(1, $this->contextOf($entry)['canceled_jobs'] ?? null);
        $this->assertSame(
            [
                "Canceling job #{$broken->id} of deleted job template #{$template->id} failed: yii\\db\\Exception "
                . '(SQLSTATE 40001, driver error 1213); the maintenance run retries while the job waits.',
            ],
            $errors
        );
        $this->assertSame(
            [sprintf(JobTemplateDeletionService::CANCEL_FAILED, $broken->id, $template->id, \yii\db\Exception::class, '40001', '1213')],
            $errors
        );
    }

    /**
     * A cancel that fails with an error that is no database error is logged
     * with its class only: "-" for the SQLSTATE and the driver error code.
     */
    public function testACancelThatFailsWithAnotherErrorIsLoggedWithItsClassOnly(): void
    {
        $template = $this->cancelFixtureTemplate((int)$this->admin->id);
        $broken = $this->createJob((int)$template->id, (int)$this->launcher->id, Job::STATUS_PENDING);

        [$result, $errors] = $this->deleteWhileACancelFails(
            $template,
            (int)$broken->id,
            new \RuntimeException('value-that-must-not-be-logged')
        );

        $this->assertSame(['deleted' => true, 'canceled_jobs' => 0, 'disabled_schedules' => 0], $result);
        $this->assertSame(
            [sprintf(JobTemplateDeletionService::CANCEL_FAILED, $broken->id, $template->id, \RuntimeException::class, '-', '-')],
            $errors
        );
    }

    /**
     * A job that finished between the delete's lookup and its cancel (its
     * runner reported, or its approval was decided) keeps its outcome and
     * is not counted.
     */
    public function testAJobThatFinishedMeanwhileIsNotCounted(): void
    {
        $template = $this->cancelFixtureTemplate((int)$this->admin->id);
        $finishing = $this->createJob((int)$template->id, (int)$this->launcher->id, Job::STATUS_QUEUED);
        $waiting = $this->createJob((int)$template->id, (int)$this->launcher->id, Job::STATUS_QUEUED);
        $original = \Yii::$app->getComponents(true)['jobCancellationService'] ?? null;
        \Yii::$app->set('jobCancellationService', new class ((int)$finishing->id) extends JobCancellationService {
            public function __construct(private readonly int $finishingJobId)
            {
                parent::__construct();
            }

            public function cancel(Job $job, ?int $actorId, string $reason = self::REASON_OPERATOR): bool
            {
                if ((int)$job->id === $this->finishingJobId) {
                    // Its runner reports first.
                    Job::updateAll(['status' => Job::STATUS_SUCCEEDED, 'finished_at' => time()], ['id' => $job->id]);
                }

                return parent::cancel($job, $actorId, $reason);
            }
        });
        try {
            $result = $this->deletion()->delete($template, (int)$this->admin->id, JobTemplateDeletionService::SOURCE_WEB);
        } finally {
            \Yii::$app->set('jobCancellationService', $original);
        }

        $this->assertSame(1, $result['canceled_jobs']);
        $finishing->refresh();
        $this->assertSame(Job::STATUS_SUCCEEDED, $finishing->status);
        $waiting->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $waiting->status);
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$finishing->id));
    }

    // -- Schedules --------------------------------------------------------------

    /**
     * Regression: a schedule of a deleted template stayed enabled (and failed
     * to launch on every due time).
     */
    public function testDeletingATemplateDisablesItsEnabledSchedules(): void
    {
        $template = $this->cancelFixtureTemplate((int)$this->admin->id);
        $other = $this->cancelFixtureTemplate((int)$this->admin->id);
        $enabled = $this->schedule($template, true);
        $disabled = $this->schedule($template, false);
        $othersSchedule = $this->schedule($other, true);

        $result = $this->deletion()->delete($template, (int)$this->admin->id, JobTemplateDeletionService::SOURCE_WEB);

        $this->assertSame(1, $result['disabled_schedules']);
        $enabled->refresh();
        $this->assertFalse((bool)$enabled->enabled);
        $this->assertNull($enabled->next_run_at);
        $entry = $this->onlyAuditOf(AuditLog::ACTION_SCHEDULE_TOGGLED, 'schedule', (int)$enabled->id);
        $this->assertSame((int)$this->admin->id, (int)$entry->user_id);
        $this->assertSame(
            ['name' => $enabled->name, 'enabled' => false, 'reason' => ScheduleEnablement::REASON_TEMPLATE_DELETED, 'job_template_id' => (int)$template->id],
            $this->contextOf($entry)
        );
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_SCHEDULE_TOGGLED, 'schedule', (int)$disabled->id));
        $othersSchedule->refresh();
        $this->assertTrue((bool)$othersSchedule->enabled, 'another template\'s schedule stays enabled');
    }

    // -- The template ------------------------------------------------------------

    public function testTheTemplateIsSoftDeletedWithItsTriggerTokenAndTheDeleteIsAudited(): void
    {
        $template = $this->cancelFixtureTemplate((int)$this->admin->id);
        $template->generateTriggerToken((int)$this->admin->id);
        $this->createJob((int)$template->id, (int)$this->launcher->id, Job::STATUS_QUEUED);
        $this->schedule($template, true);

        $this->deletion()->delete($template, (int)$this->admin->id, JobTemplateDeletionService::SOURCE_API);

        $this->assertNull(JobTemplate::findOne($template->id), 'hidden from every list');
        $stored = JobTemplate::findWithDeleted()->where(['id' => $template->id])->one();
        $this->assertInstanceOf(JobTemplate::class, $stored);
        $this->assertNotNull($stored->deleted_at);
        $this->assertNull($stored->trigger_token);
        $this->assertNull($stored->trigger_token_created_by);
        $entry = $this->onlyAuditOf(AuditLog::ACTION_TEMPLATE_DELETED, 'job_template', (int)$template->id);
        $this->assertSame((int)$this->admin->id, (int)$entry->user_id);
        $this->assertSame(
            ['name' => $template->name, 'source' => JobTemplateDeletionService::SOURCE_API, 'canceled_jobs' => 1, 'disabled_schedules' => 1],
            $this->contextOf($entry)
        );
    }

    /**
     * Two deletes of one template: the second finds it deleted under the
     * lock and does nothing, so nothing is canceled, disabled or audited
     * twice.
     */
    public function testATemplateDeletedMeanwhileIsLeftAsItIs(): void
    {
        $template = $this->cancelFixtureTemplate((int)$this->admin->id);
        $job = $this->createJob((int)$template->id, (int)$this->launcher->id, Job::STATUS_QUEUED);
        $schedule = $this->schedule($template, true);
        JobTemplate::updateAll(['deleted_at' => time() - 5], ['id' => $template->id]);

        $result = $this->deletion()->delete($template, (int)$this->admin->id, JobTemplateDeletionService::SOURCE_WEB);

        $this->assertSame(['deleted' => false, 'canceled_jobs' => 0, 'disabled_schedules' => 0], $result);
        $job->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $job->status, 'left to the sweep');
        $schedule->refresh();
        $this->assertTrue((bool)$schedule->enabled, 'left to the sweep');
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_TEMPLATE_DELETED, 'job_template', (int)$template->id));
    }

    public function testAnUnknownTemplateIsNotDeleted(): void
    {
        $template = $this->cancelFixtureTemplate((int)$this->admin->id);
        $ghost = new JobTemplate();
        $ghost->id = (int)$template->id + 1000000;
        $ghost->name = 'ghost';

        $this->assertSame(
            ['deleted' => false, 'canceled_jobs' => 0, 'disabled_schedules' => 0],
            $this->deletion()->delete($ghost, (int)$this->admin->id, JobTemplateDeletionService::SOURCE_WEB)
        );
    }

    /**
     * The template row is locked before anything is written, the schedules
     * are disabled in the same transaction, and the jobs are canceled after
     * the commit: a launch, which locks the same row, either commits first
     * (and its job is canceled) or finds the template deleted.
     */
    public function testTheTemplateRowIsLockedFirstAndTheJobsAreCanceledAfterTheCommit(): void
    {
        $template = $this->cancelFixtureTemplate((int)$this->admin->id);
        $job = $this->createJob((int)$template->id, (int)$this->launcher->id, Job::STATUS_QUEUED);
        $schedule = $this->schedule($template, true);

        $statements = $this->statementsOf(
            fn () => $this->deletion()->delete($template, (int)$this->admin->id, JobTemplateDeletionService::SOURCE_WEB)
        );

        $savepoint = $this->indexOf($statements, '~^SAVEPOINT LEVEL\d+$~');
        $lock = $this->indexOf($statements, '~^SELECT `id`, `deleted_at` FROM `job_template` WHERE `id` = ' . $template->id . ' FOR UPDATE$~');
        $softDelete = $this->indexOf($statements, '~^UPDATE `job_template` SET `deleted_at`=\d+.* WHERE `id`=' . $template->id . '$~s');
        $disable = $this->indexOf($statements, '~^UPDATE `schedule` SET `enabled`=0.* WHERE \(`id`=' . $schedule->id . '\) AND \(`enabled` <> 0\)$~s');
        $release = $this->indexOf($statements, '~^RELEASE SAVEPOINT LEVEL\d+$~');
        $cancel = $this->indexOf($statements, '~^UPDATE `job` SET .* WHERE \(`id`=' . $job->id . '\) AND \(`status` IN \(.*\)\)$~s');
        $this->assertTrue(
            $savepoint < $lock && $lock < $softDelete && $softDelete < $disable && $disable < $release && $release < $cancel,
            implode("\n", $statements)
        );
    }

    public function testAVetoedSoftDeleteChangesNothing(): void
    {
        $template = $this->cancelFixtureTemplate((int)$this->admin->id);
        $schedule = $this->schedule($template, true);
        $job = $this->createJob((int)$template->id, (int)$this->launcher->id, Job::STATUS_QUEUED);
        $template->on(BaseActiveRecord::EVENT_BEFORE_UPDATE, static function (ModelEvent $event): void {
            $event->isValid = false;
        });

        try {
            $this->deletion()->delete($template, (int)$this->admin->id, JobTemplateDeletionService::SOURCE_WEB);
            $this->fail('Expected the vetoed delete to throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame(sprintf(JobTemplateDeletionService::DELETE_REFUSED, $template->id), $e->getMessage());
        }

        $this->assertNotNull(JobTemplate::findOne($template->id), 'not deleted');
        $schedule->refresh();
        $this->assertTrue((bool)$schedule->enabled);
        $job->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_TEMPLATE_DELETED, 'job_template', (int)$template->id));
    }

    // -- sweep() --------------------------------------------------------------------

    /**
     * Templates soft-deleted another way (through the model, before this
     * release, or by a delete that failed half-way) are swept by the
     * maintenance run, as the system.
     */
    public function testTheSweepResolvesWhatDependsOnTemplatesDeletedAnotherWay(): void
    {
        $template = $this->cancelFixtureTemplate((int)$this->admin->id);
        $job = $this->createJob((int)$template->id, (int)$this->launcher->id, Job::STATUS_PENDING_APPROVAL);
        $schedule = $this->schedule($template, true);
        $template->softDelete();
        $live = $this->cancelFixtureTemplate((int)$this->admin->id);
        $liveJob = $this->createJob((int)$live->id, (int)$this->launcher->id, Job::STATUS_QUEUED);
        $liveSchedule = $this->schedule($live, true);

        $result = $this->deletion()->sweep();

        $this->assertSame(['canceled_jobs' => 1, 'disabled_schedules' => 1, 'remaining_jobs' => 0], $result);
        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertNull($this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id)->user_id, 'the system');
        $schedule->refresh();
        $this->assertFalse((bool)$schedule->enabled);
        $this->assertNull($this->onlyAuditOf(AuditLog::ACTION_SCHEDULE_TOGGLED, 'schedule', (int)$schedule->id)->user_id);
        $liveJob->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $liveJob->status, 'a live template\'s job is left alone');
        $liveSchedule->refresh();
        $this->assertTrue((bool)$liveSchedule->enabled);

        $this->assertSame(['canceled_jobs' => 0, 'disabled_schedules' => 0, 'remaining_jobs' => 0], $this->deletion()->sweep(), 'nothing left');
    }

    public function testTheSweepCancelsAtMostItsLimitOldestFirst(): void
    {
        $template = $this->cancelFixtureTemplate((int)$this->admin->id);
        $jobs = [];
        for ($i = 0; $i < 3; $i++) {
            $jobs[] = $this->createJob((int)$template->id, (int)$this->launcher->id, Job::STATUS_QUEUED);
        }
        $template->softDelete();

        $result = $this->deletion()->sweep(2);

        $this->assertSame(['canceled_jobs' => 2, 'disabled_schedules' => 0, 'remaining_jobs' => 1], $result);
        $statuses = array_map(static fn (Job $job): string => (string)Job::findOne($job->id)?->status, $jobs);
        $this->assertSame([Job::STATUS_CANCELED, Job::STATUS_CANCELED, Job::STATUS_QUEUED], $statuses);
    }

    // -- sweep() with a deadline (the maintenance run's time budget) ---------------

    /**
     * Regression (review of step 3, operations-1): a sweep canceled its whole
     * batch however long the notifications took. A sweep whose deadline has
     * passed starts no cancel: the jobs wait for the next run. Disabling the
     * schedules is one cheap update per schedule and is not bounded.
     */
    public function testASweepWhoseDeadlineHasPassedStartsNoCancel(): void
    {
        $template = $this->cancelFixtureTemplate((int)$this->admin->id);
        $job = $this->createJob((int)$template->id, (int)$this->launcher->id, Job::STATUS_QUEUED);
        $schedule = $this->schedule($template, true);
        $template->softDelete();

        $result = $this->deletion()->sweep(JobTemplateDeletionService::SWEEP_BATCH_SIZE, microtime(true) - 1);

        $this->assertSame(['canceled_jobs' => 0, 'disabled_schedules' => 1, 'remaining_jobs' => 1], $result);
        $job->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
        $schedule->refresh();
        $this->assertFalse((bool)$schedule->enabled);
        $this->assertSame([], $this->notifications->payloadsOf(NotificationTemplate::EVENT_JOB_CANCELED));
    }

    /**
     * Regression (review of step 3, operations-1): the deadline is checked
     * before each cancel starts, and a cancel that started is finished: the
     * clock reads 100 and 101 before the first two cancels, then 102, past
     * the deadline of 101.5, so the third job is left for the next run.
     */
    public function testASweepFinishesTheCancelItStartedAndStartsNoneAfterItsDeadline(): void
    {
        $template = $this->cancelFixtureTemplate((int)$this->admin->id);
        $jobs = [];
        for ($i = 0; $i < 3; $i++) {
            $jobs[] = $this->createJob((int)$template->id, (int)$this->launcher->id, Job::STATUS_QUEUED);
        }
        $template->softDelete();
        $deletion = $this->deletionWithClock([100.0, 101.0, 102.0]);

        $result = $deletion->sweep(JobTemplateDeletionService::SWEEP_BATCH_SIZE, 101.5);

        $this->assertSame(['canceled_jobs' => 2, 'disabled_schedules' => 0, 'remaining_jobs' => 1], $result);
        $statuses = array_map(static fn (Job $job): string => (string)Job::findOne($job->id)?->status, $jobs);
        $this->assertSame([Job::STATUS_CANCELED, Job::STATUS_CANCELED, Job::STATUS_QUEUED], $statuses);
        $this->assertSame([], $deletion->clock, 'the clock was read before each of the three');
    }

    /**
     * A delete, in the web UI or through the API, has no deadline: it
     * cancels every waiting job of the template and never reads the clock.
     */
    public function testADeleteHasNoDeadline(): void
    {
        $template = $this->cancelFixtureTemplate((int)$this->admin->id);
        $this->createJob((int)$template->id, (int)$this->launcher->id, Job::STATUS_QUEUED);
        $this->createJob((int)$template->id, (int)$this->launcher->id, Job::STATUS_PENDING);
        $deletion = $this->deletionWithClock([]);

        $result = $deletion->delete($template, (int)$this->admin->id, JobTemplateDeletionService::SOURCE_API);

        $this->assertSame(['deleted' => true, 'canceled_jobs' => 2, 'disabled_schedules' => 0], $result);
    }

    // -- cancelWaitingJobsOf() (for the project delete's purge) --------------------

    /**
     * The waiting jobs of the given templates are canceled as delete()
     * cancels them: every waiting status, the reason template_deleted, the
     * actor, the request closed. Other templates' jobs, running and finished
     * jobs are left alone, and the clock is never read.
     */
    public function testCancelWaitingJobsOfCancelsTheWaitingJobsOfTheGivenTemplatesOnly(): void
    {
        $first = $this->cancelFixtureTemplate((int)$this->admin->id);
        $second = $this->cancelFixtureTemplate((int)$this->admin->id);
        $other = $this->cancelFixtureTemplate((int)$this->admin->id);
        $approver = $this->createUserWithRole('deletion-of-approver', 'operator');
        $pending = $this->createJob((int)$first->id, (int)$this->launcher->id, Job::STATUS_PENDING);
        $queued = $this->createJob((int)$first->id, (int)$this->launcher->id, Job::STATUS_QUEUED);
        ['job' => $waiting, 'request' => $request] = $this->jobWaitingForApproval($second, (int)$this->launcher->id, (int)$approver->id);
        $running = $this->createJob((int)$second->id, (int)$this->launcher->id, Job::STATUS_RUNNING);
        $otherJob = $this->createJob((int)$other->id, (int)$this->launcher->id, Job::STATUS_QUEUED);
        foreach ([$first, $second] as $template) {
            $this->assertTrue($template->softDelete());
        }

        $canceled = $this->deletionWithClock([])->cancelWaitingJobsOf([(int)$first->id, (int)$second->id], (int)$this->admin->id);

        $this->assertSame(3, $canceled);
        foreach ([$pending, $queued, $waiting] as $job) {
            $this->assertSame(Job::STATUS_CANCELED, Job::findOne($job->id)?->status);
            $entry = $this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id);
            $this->assertSame((int)$this->admin->id, (int)$entry->user_id);
            $this->assertSame(['reason' => JobCancellationService::REASON_TEMPLATE_DELETED], $this->contextOf($entry));
        }
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status);
        $this->assertSame(Job::STATUS_RUNNING, Job::findOne($running->id)?->status);
        $this->assertSame(Job::STATUS_QUEUED, Job::findOne($otherJob->id)?->status);
        $this->assertCount(3, $this->notifications->payloadsOf(NotificationTemplate::EVENT_JOB_CANCELED));
    }

    /**
     * No templates, nothing to do: not a query that would match every job.
     */
    public function testCancelWaitingJobsOfNoTemplatesCancelsNothing(): void
    {
        $template = $this->cancelFixtureTemplate((int)$this->admin->id);
        $job = $this->createJob((int)$template->id, (int)$this->launcher->id, Job::STATUS_QUEUED);

        $this->assertSame([], $this->statementsOf(function (): void {
            $this->assertSame(0, $this->deletion()->cancelWaitingJobsOf([], (int)$this->admin->id));
        }));

        $this->assertSame(Job::STATUS_QUEUED, Job::findOne($job->id)?->status);
    }

    // -- helpers ----------------------------------------------------------------------

    private function deletion(): JobTemplateDeletionService
    {
        /** @var JobTemplateDeletionService $service */
        $service = \Yii::$app->get('jobTemplateDeletionService');

        return $service;
    }

    /**
     * A deletion service whose clock reads the values of $clock in turn; a
     * read beyond them fails the test.
     *
     * @param list<float> $clock
     * @return JobTemplateDeletionService&object{clock: list<float>}
     */
    private function deletionWithClock(array $clock): JobTemplateDeletionService
    {
        return new class ($clock) extends JobTemplateDeletionService {
            /**
             * @param list<float> $clock
             */
            public function __construct(public array $clock)
            {
                parent::__construct();
            }

            protected function now(): float
            {
                if ($this->clock === []) {
                    throw new \LogicException('The clock was read once more than expected.');
                }

                return array_shift($this->clock);
            }
        };
    }

    /**
     * Delete $template while the cancel of job #$brokenJobId throws $error
     * (every other cancel is real), and return the delete's result with the
     * errors JobTemplateDeletionService logged. No logged line may carry
     * 'value-that-must-not-be-logged', which the test's errors put in their
     * messages.
     *
     * @return array{0: array{deleted: bool, canceled_jobs: int, disabled_schedules: int}, 1: list<string>}
     */
    private function deleteWhileACancelFails(JobTemplate $template, int $brokenJobId, \Throwable $error): array
    {
        $original = \Yii::$app->getComponents(true)['jobCancellationService'] ?? null;
        \Yii::$app->set('jobCancellationService', new class ($brokenJobId, $error) extends JobCancellationService {
            public function __construct(private readonly int $brokenJobId, private readonly \Throwable $error)
            {
                parent::__construct();
            }

            public function cancel(Job $job, ?int $actorId, string $reason = self::REASON_OPERATOR): bool
            {
                if ((int)$job->id === $this->brokenJobId) {
                    throw $this->error;
                }

                return parent::cancel($job, $actorId, $reason);
            }
        });
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previousLogger = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $result = $this->deletion()->delete($template, (int)$this->admin->id, JobTemplateDeletionService::SOURCE_WEB);
        } finally {
            \Yii::setLogger($previousLogger);
            \Yii::$app->set('jobCancellationService', $original);
        }
        $errors = [];
        foreach ($logger->messages as $message) {
            if ($message[1] === Logger::LEVEL_ERROR && $message[2] === JobTemplateDeletionService::class && is_string($message[0])) {
                $this->assertStringNotContainsString('value-that-must-not-be-logged', $message[0]);
                $errors[] = $message[0];
            }
        }

        return [$result, $errors];
    }

    private function schedule(JobTemplate $template, bool $enabled): Schedule
    {
        $schedule = new Schedule();
        $schedule->name = 'deletion-' . uniqid('', true);
        $schedule->job_template_id = (int)$template->id;
        $schedule->cron_expression = '0 2 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = $enabled;
        $schedule->created_by = (int)$this->admin->id;
        if ($enabled) {
            $schedule->computeNextRunAt();
        }
        $schedule->save(false);

        return $schedule;
    }

    /**
     * The SQL statements $action sends, in order.
     *
     * @return list<string>
     */
    private function statementsOf(callable $action): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $action();
        } finally {
            \Yii::setLogger($previous);
        }
        $statements = [];
        foreach ($logger->messages as $message) {
            if (
                in_array($message[2], ['yii\db\Command::query', 'yii\db\Command::execute'], true)
                && $message[1] === Logger::LEVEL_INFO
                && is_string($message[0])
            ) {
                $statements[] = $message[0];
            }
        }

        return $statements;
    }

    /**
     * @param list<string> $statements
     */
    private function indexOf(array $statements, string $pattern): int
    {
        foreach ($statements as $index => $statement) {
            if (preg_match($pattern, $statement) === 1) {
                return $index;
            }
        }
        $this->fail("No statement matches {$pattern}:\n" . implode("\n", $statements));
    }
}
