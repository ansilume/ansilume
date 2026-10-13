<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\JobCancellationService;
use app\services\StrandedJobSweep;
use app\services\WorkflowCancellationService;
use app\services\WorkflowStepRouter;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\db\BaseActiveRecord;

/**
 * StrandedJobSweep, run by the maintenance run: cancels the waiting jobs that
 * nothing else would ever end, and ends the steps still running in runs that
 * ended.
 *
 * - The job of a step of a run that ended: a run cancel or a template delete
 *   failed part-way (WorkflowCancellationService::STEP_CANCEL_FAILED) and left
 *   it queued or waiting for approval.
 * - A job that waits for approval without any request: 2.9.0 and older
 *   deleted the requests of a deleted rule.
 */
class StrandedJobSweepTest extends DbTestCase
{
    use TeamScopeFixtures;
    use WorkflowEngineFixtures;
    use CancellationFixtures;
    use StrandedRunFixtures;

    private RecordingNotificationDispatcher $notifications;
    private User $operator;
    private User $approver;
    private JobTemplate $template;

    /** @var list<\Closure> */
    private array $findHooks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = $this->recordNotifications();
        $this->operator = $this->operator('stranded-sweep');
        $this->approver = $this->operator('stranded-sweep-approver');
        $this->template = $this->openTemplate((int)$this->operator->id);
    }

    protected function tearDown(): void
    {
        foreach ($this->findHooks as $hook) {
            Event::off(Job::class, BaseActiveRecord::EVENT_AFTER_FIND, $hook);
        }
        $this->findHooks = [];
        $this->restoreSwappedServices();
        $this->restoreNotifications();
        parent::tearDown();
    }

    // -- Jobs of runs that ended ---------------------------------------------------------------

    /**
     * Regression: nothing ever canceled the queued job of a run whose cancel
     * failed after the run was claimed; a runner ran it. The sweep cancels
     * it within its run, for the run's cancel reason and without a user;
     * the run is not moved on and no job.canceled notification is sent.
     */
    public function testRegressionTheSweepCancelsAStrandedQueuedJob(): void
    {
        ['run' => $run, 'step' => $step, 'next' => $next, 'job' => $job] = $this->strandedRun($this->template);
        $sent = count($this->notifications->events);

        $result = $this->strandedJobSweep()->sweep();

        $this->assertSame(['stranded_jobs_canceled' => 1, 'stranded_jobs_failed' => 0, 'stranded_jobs_remaining' => 0], $result);
        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $entry = $this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id);
        $this->assertNull($entry->user_id, 'the maintenance run acts for the system');
        $this->assertSame(['reason' => JobCancellationService::REASON_WORKFLOW_CANCELED, 'workflow_job_id' => (int)$run->id], $this->contextOf($entry));
        $this->assertSame(WorkflowCancellationService::STEP_RUN_CANCELED, $this->executionOfStep($run, $step)->error_message);
        $this->assertNull($this->runOf($run, $next), 'the run is not moved on');
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $run->status);
        $this->assertCount($sent, $this->notifications->events, 'no notification: the run has its workflow.canceled');
        $this->assertSame(['stranded_jobs_canceled' => 0, 'stranded_jobs_failed' => 0, 'stranded_jobs_remaining' => 0], $this->strandedJobSweep()->sweep());
    }

    /**
     * Regression: the job of a gated job step of such a run kept waiting for
     * approval, with its request pending and decidable. The sweep cancels it
     * and closes the request.
     */
    public function testRegressionTheSweepCancelsAStrandedJobThatWaitsForApprovalAndClosesItsRequest(): void
    {
        ['job' => $job] = $this->strandedRun($this->gated());
        $request = $this->requestOfJob($job);

        $this->assertSame(1, $this->strandedJobSweep()->sweep()['stranded_jobs_canceled']);

        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status);
        $this->assertNotNull($request->resolved_at);
        $closed = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', (int)$request->id);
        $this->assertSame(JobCancellationService::REASON_WORKFLOW_CANCELED, $this->contextOf($closed)['reason'] ?? null);
    }

    /**
     * The approval step's placeholder of a run whose cancel failed: canceled
     * with its request, for the run's reason.
     */
    public function testTheSweepCancelsTheStrandedPlaceholderOfAnApprovalStep(): void
    {
        $flow = $this->runAtApprovalStep();
        $this->failJobCancels($this->lostConnection());
        $this->runCancellation()->cancelRun($flow['run'], null, JobCancellationService::REASON_OPERATOR);
        $this->restoreSwappedServices();

        $this->assertSame(1, $this->strandedJobSweep()->sweep()['stranded_jobs_canceled']);

        $flow['placeholder']->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $flow['placeholder']->status);
        $flow['request']->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $flow['request']->status);
    }

    /**
     * A run canceled because its workflow template was deleted: the job is
     * canceled for that reason, and a step still running ends with the
     * template's message.
     */
    public function testAStrandedJobOfADeletedWorkflowTemplateIsCanceledForThatReason(): void
    {
        $admin = $this->createUserWithRole('stranded-sweep-admin', 'admin');
        ['run' => $run, 'step' => $step, 'job' => $job] = $this->runWaitingForItsJob($this->operator, $this->template);
        WorkflowTemplate::updateAll(['deleted_at' => time()], ['id' => $run->workflow_template_id]);
        $this->failJobCancels($this->lostConnection());
        $this->runCancellation()->cancelRun($run, (int)$admin->id, JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED);
        $this->restoreSwappedServices();
        // As if failing the step had failed too.
        WorkflowJobStep::updateAll(['status' => WorkflowJobStep::STATUS_RUNNING, 'error_message' => null], ['id' => $this->executionOfStep($run, $step)->id]);

        $this->assertSame(1, $this->strandedJobSweep()->sweep()['stranded_jobs_canceled']);

        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertSame(
            ['reason' => JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED, 'workflow_job_id' => (int)$run->id],
            $this->contextOf($this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id))
        );
        $execution = $this->executionOfStep($run, $step);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(WorkflowCancellationService::STEP_TEMPLATE_DELETED, $execution->error_message);
        $this->assertNotNull($execution->finished_at);
    }

    /**
     * A waiting job of a run that ended without a cancel (it failed) is
     * stranded too: canceled, its step ended with STEP_RUN_ENDED.
     */
    public function testAWaitingJobOfARunThatFailedIsCanceled(): void
    {
        ['run' => $run, 'step' => $step, 'job' => $job] = $this->runWaitingForItsJob($this->operator, $this->template);
        WorkflowJob::updateAll(['status' => WorkflowJob::STATUS_FAILED, 'finished_at' => time()], ['id' => $run->id]);

        $this->assertSame(1, $this->strandedJobSweep()->sweep()['stranded_jobs_canceled']);

        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertSame(StrandedJobSweep::STEP_RUN_ENDED, $this->executionOfStep($run, $step)->error_message);
    }

    /**
     * A running job, or a job of an active run, is no stranded job.
     */
    public function testRunningJobsAndJobsOfActiveRunsAreLeftAlone(): void
    {
        ['run' => $active, 'job' => $waiting] = $this->runWaitingForItsJob($this->operator, $this->template);
        ['job' => $running] = $this->strandedRun($this->template);
        Job::updateAll(['status' => Job::STATUS_RUNNING, 'started_at' => time()], ['id' => $running->id]);

        $this->assertSame(['stranded_jobs_canceled' => 0, 'stranded_jobs_failed' => 0, 'stranded_jobs_remaining' => 0], $this->strandedJobSweep()->sweep());

        $this->assertSame(Job::STATUS_QUEUED, Job::findOne($waiting->id)?->status);
        $this->assertSame(Job::STATUS_RUNNING, Job::findOne($running->id)?->status);
        $active->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $active->status);
    }

    // -- Jobs waiting for an approval request that is gone -------------------------------------

    /**
     * Regression: a job that waits for approval without any request (2.9.0
     * deleted the requests of a deleted rule) waited for ever: nobody could
     * decide it, and no timeout applied. The sweep cancels it, as for an
     * operator's cancel with its own reason; a job that was only just
     * created is left for later.
     */
    public function testRegressionTheSweepCancelsAJobThatWaitsForApprovalWithoutARequest(): void
    {
        $orphan = $this->requestlessJob(time() - StrandedJobSweep::ORPHAN_MIN_AGE_SECONDS - 60);
        $young = $this->requestlessJob(time());
        $auditBefore = (int)AuditLog::find()->max('id');

        $result = $this->strandedJobSweep()->sweep();

        $this->assertSame(['stranded_jobs_canceled' => 1, 'stranded_jobs_failed' => 0, 'stranded_jobs_remaining' => 0], $result);
        $orphan->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $orphan->status);
        $entry = $this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$orphan->id);
        $this->assertNull($entry->user_id);
        $this->assertSame(['reason' => JobCancellationService::REASON_APPROVAL_MISSING], $this->contextOf($entry));
        $this->assertFalse(
            AuditLog::find()->where(['action' => AuditLog::ACTION_APPROVAL_CANCELED])->andWhere(['>', 'id', $auditBefore])->exists(),
            'there is no request to close'
        );
        $sent = $this->notifications->payloadsOf(NotificationTemplate::EVENT_JOB_CANCELED);
        $this->assertCount(1, $sent);
        $payload = $sent[0]['job'] ?? null;
        $this->assertIsArray($payload);
        $this->assertSame((string)$orphan->id, $payload['id'] ?? null);
        $young->refresh();
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $young->status, 'a writer may be about to add its request');
    }

    /**
     * The placeholder of an approval step whose request is gone: its gate
     * never opened, so the step fails closed. Without an On Failure branch
     * the run ends as failed, and neither the next step nor an Always branch
     * (saved by older versions) is taken.
     */
    public function testTheStepOfAnApprovalWhoseRequestIsGoneFailsClosed(): void
    {
        $flow = $this->runAtApprovalStep();
        $this->branchTo($flow['approval'], 'on_failure_step_id', null);
        $this->branchTo($flow['approval'], 'on_always_step_id', (int)$flow['onSuccess']->id);
        $this->loseTheRequest($flow['placeholder']);

        $this->assertSame(1, $this->strandedJobSweep()->sweep()['stranded_jobs_canceled']);

        $this->assertNull($this->runOf($flow['run'], $flow['onSuccess']), 'neither the next step nor Always');
        $this->assertNull($this->runOf($flow['run'], $flow['onFailure']));
        $flow['run']->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $flow['run']->status);
        $execution = $this->executionOfStep($flow['run'], $flow['approval']);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(WorkflowStepRouter::APPROVAL_MISSING, $execution->error_message);
        $this->assertSame(
            ['reason' => JobCancellationService::REASON_APPROVAL_MISSING],
            $this->contextOf($this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$flow['placeholder']->id))
        );
    }

    /**
     * A gated job step whose job's request is gone fails closed too, and an
     * explicit On Failure branch is followed.
     */
    public function testAGatedJobStepWhoseRequestIsGoneFollowsItsOnFailureBranch(): void
    {
        ['run' => $run, 'step' => $step, 'next' => $pause, 'job' => $job] = $this->runWaitingForItsJob($this->operator, $this->gated());
        $this->branchTo($step, 'on_failure_step_id', (int)$pause->id);
        $this->loseTheRequest($job);

        $this->assertSame(1, $this->strandedJobSweep()->sweep()['stranded_jobs_canceled']);

        $execution = $this->executionOfStep($run, $step);
        $this->assertSame(WorkflowStepRouter::JOB_APPROVAL_MISSING, $execution->error_message);
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $this->executionOfStep($run, $pause)->status);
    }

    /**
     * Without an On Failure branch, such a gated job step ends the run as
     * failed: the next step does not run.
     */
    public function testAGatedJobStepWhoseRequestIsGoneEndsTheRunWithoutAnOnFailureBranch(): void
    {
        ['run' => $run, 'step' => $step, 'next' => $pause, 'job' => $job] = $this->runWaitingForItsJob($this->operator, $this->gated());
        $this->loseTheRequest($job);

        $this->assertSame(1, $this->strandedJobSweep()->sweep()['stranded_jobs_canceled']);

        $this->assertNull($this->runOf($run, $pause), 'the next step does not run');
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
        $this->assertSame(WorkflowStepRouter::JOB_APPROVAL_MISSING, $this->executionOfStep($run, $step)->error_message);
    }

    /**
     * A job without its request whose run ended is canceled within its run,
     * not as an orphan: the run is not moved on.
     */
    public function testAJobWithoutItsRequestOfARunThatEndedIsCanceledWithinItsRun(): void
    {
        ['run' => $run, 'job' => $job] = $this->strandedRun($this->gated());
        $this->loseTheRequest($job);

        $this->assertSame(1, $this->strandedJobSweep()->sweep()['stranded_jobs_canceled']);

        $this->assertSame(
            ['reason' => JobCancellationService::REASON_WORKFLOW_CANCELED, 'workflow_job_id' => (int)$run->id],
            $this->contextOf($this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id))
        );
    }

    // -- Waiting jobs whose job template was purged --------------------------------------------

    /**
     * Regression: a waiting job of a purged job template could be approved
     * and stayed queued. Deleting a project purges its deleted templates,
     * and the foreign key set the job's job_template_id to NULL: no runner
     * claims a job without a template, and no sweep found it, so it stayed
     * queued for good. The sweep cancels it as the template delete cancels
     * its jobs (reason template_deleted, the job.canceled notification), as
     * the system.
     */
    public function testRegressionAQueuedJobOfAPurgedTemplateStayedQueuedForGood(): void
    {
        $job = $this->purgedJob(Job::STATUS_QUEUED);
        $auditBefore = (int)AuditLog::find()->max('id');

        $result = $this->strandedJobSweep()->sweep();

        $this->assertSame(['stranded_jobs_canceled' => 1, 'stranded_jobs_failed' => 0, 'stranded_jobs_remaining' => 0], $result);
        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertNotNull($job->finished_at);
        $entry = $this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id);
        $this->assertNull($entry->user_id, 'the maintenance run acts for the system');
        $this->assertSame(['reason' => JobCancellationService::REASON_TEMPLATE_DELETED], $this->contextOf($entry));
        $this->assertFalse(
            AuditLog::find()->where(['action' => AuditLog::ACTION_APPROVAL_CANCELED])->andWhere(['>', 'id', $auditBefore])->exists(),
            'it had no request to close'
        );
        $sent = $this->notifications->payloadsOf(NotificationTemplate::EVENT_JOB_CANCELED);
        $this->assertCount(1, $sent);
        $payload = $sent[0]['job'] ?? null;
        $this->assertIsArray($payload);
        $this->assertSame((string)$job->id, $payload['id'] ?? null);
        $this->assertSame(['stranded_jobs_canceled' => 0, 'stranded_jobs_failed' => 0, 'stranded_jobs_remaining' => 0], $this->strandedJobSweep()->sweep());
    }

    /**
     * Every waiting status: pending and queued jobs are canceled, and a job
     * that waits for approval is canceled with its request closed (reason
     * template_deleted). Running and finished jobs are left alone.
     */
    public function testEveryWaitingJobOfAPurgedTemplateIsCanceledAndItsRequestClosed(): void
    {
        $pending = $this->purgedJob(Job::STATUS_PENDING);
        $queued = $this->purgedJob(Job::STATUS_QUEUED);
        $template = $this->openTemplate((int)$this->operator->id);
        ['job' => $waiting, 'request' => $request, 'rule' => $rule] = $this->jobWaitingForApproval(
            $template,
            (int)$this->operator->id,
            (int)$this->approver->id
        );
        $this->purge($template, $waiting);
        $running = $this->purgedJob(Job::STATUS_RUNNING);
        $succeeded = $this->purgedJob(Job::STATUS_SUCCEEDED);

        $this->assertSame(['stranded_jobs_canceled' => 3, 'stranded_jobs_failed' => 0, 'stranded_jobs_remaining' => 0], $this->strandedJobSweep()->sweep());

        foreach ([$pending, $queued, $waiting] as $job) {
            $this->assertSame(Job::STATUS_CANCELED, Job::findOne($job->id)?->status);
        }
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status);
        $this->assertNotNull($request->resolved_at);
        $this->assertSame(
            ['job_id' => (int)$waiting->id, 'rule_id' => (int)$rule->id, 'reason' => JobCancellationService::REASON_TEMPLATE_DELETED],
            $this->contextOf($this->onlyAuditOf(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', (int)$request->id))
        );
        $this->assertSame(Job::STATUS_RUNNING, Job::findOne($running->id)?->status);
        $this->assertSame(Job::STATUS_SUCCEEDED, Job::findOne($succeeded->id)?->status);
    }

    /**
     * A job of a purged template that also lost its approval request is
     * canceled for the purge: without a template it could never run, with a
     * request or without.
     */
    public function testAJobOfAPurgedTemplateWithoutItsRequestIsCanceledForThePurge(): void
    {
        $job = $this->purgedJob(Job::STATUS_PENDING_APPROVAL);

        $this->assertSame(1, $this->strandedJobSweep()->sweep()['stranded_jobs_canceled']);

        $this->assertSame(
            ['reason' => JobCancellationService::REASON_TEMPLATE_DELETED],
            $this->contextOf($this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id))
        );
    }

    /**
     * The job of a gated job step whose template was purged while the run
     * waited for its approval: canceled with its request, its step ends as
     * a gate that never opened, and without an On Failure branch the run
     * ends as failed; the next step never runs.
     */
    public function testAGatedJobStepWhoseTemplateWasPurgedEndsAsAClosedGate(): void
    {
        $gated = $this->gated();
        ['run' => $run, 'step' => $step, 'next' => $pause, 'job' => $job] = $this->runWaitingForItsJob($this->operator, $gated);
        $request = $this->requestOfJob($job);
        $this->purge($gated, $job);

        $this->assertSame(1, $this->strandedJobSweep()->sweep()['stranded_jobs_canceled']);

        $this->assertSame(Job::STATUS_CANCELED, Job::findOne($job->id)?->status);
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status);
        $execution = $this->executionOfStep($run, $step);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(sprintf(WorkflowStepRouter::JOB_APPROVAL_CANCELED, (int)$request->id), $execution->error_message);
        $this->assertNull($this->runOf($run, $pause), 'the next step does not run');
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
    }

    /**
     * @return array<string, array{0: \Closure(WorkflowJobStep, WorkflowStep): void, 1: bool}> how the
     *     waiting placeholder's execution or step is changed, whether the sweep cancels the job
     */
    public static function placeholderProvider(): array
    {
        return [
            'an approval step' => [static function (): void {
            }, false],
            'an approval step that was removed: the snapshot tells' => [static function (WorkflowJobStep $execution, WorkflowStep $step): void {
                WorkflowStep::deleteAll(['id' => $step->id]);
            }, false],
            'an execution without a snapshot: the live step tells' => [static function (WorkflowJobStep $execution): void {
                WorkflowJobStep::updateAll(['step_type' => null], ['id' => $execution->id]);
            }, false],
            'an execution with an empty snapshot: the live step tells' => [static function (WorkflowJobStep $execution): void {
                WorkflowJobStep::updateAll(['step_type' => ''], ['id' => $execution->id]);
            }, false],
            'a step whose type is unknown: fail closed' => [static function (WorkflowJobStep $execution, WorkflowStep $step): void {
                WorkflowJobStep::updateAll(['step_type' => null], ['id' => $execution->id]);
                WorkflowStep::deleteAll(['id' => $step->id]);
            }, true],
            'a type other than approval, compared exactly' => [static function (WorkflowJobStep $execution): void {
                WorkflowJobStep::updateAll(['step_type' => 'APPROVAL'], ['id' => $execution->id]);
            }, true],
        ];
    }

    /**
     * The placeholder of an approval step has no job template by design: it
     * is told by its step's type, as WorkflowJobStep::isApprovalPlaceholder()
     * tells it, and left alone while its run waits for the decision.
     *
     * @dataProvider placeholderProvider
     * @param \Closure(WorkflowJobStep, WorkflowStep): void $change
     */
    public function testAnApprovalStepsPlaceholderIsToldByItsStepsType(\Closure $change, bool $canceled): void
    {
        $flow = $this->runAtApprovalStep();
        $change($this->executionOfStep($flow['run'], $flow['approval']), $flow['approval']);
        Job::updateAll(['created_at' => time() - 86400], ['id' => $flow['placeholder']->id]);

        $this->assertSame($canceled ? 1 : 0, $this->strandedJobSweep()->sweep()['stranded_jobs_canceled']);

        $this->assertSame(
            $canceled ? Job::STATUS_CANCELED : Job::STATUS_PENDING_APPROVAL,
            Job::findOne($flow['placeholder']->id)?->status
        );
        $flow['request']->refresh();
        $this->assertSame($canceled ? ApprovalRequest::STATUS_CANCELED : ApprovalRequest::STATUS_PENDING, $flow['request']->status);
    }

    /**
     * A job of a purged template that was only just created is left for a
     * later sweep, and not counted: a writer that creates a placeholder and
     * links its step one after the other is never taken for one.
     */
    public function testAJobOfAPurgedTemplateThatWasJustCreatedIsLeftForLater(): void
    {
        $job = $this->purgedJob(Job::STATUS_QUEUED, time());

        $this->assertSame(['stranded_jobs_canceled' => 0, 'stranded_jobs_failed' => 0, 'stranded_jobs_remaining' => 0], $this->strandedJobSweep()->sweep());

        $this->assertSame(Job::STATUS_QUEUED, Job::findOne($job->id)?->status);
    }

    /**
     * A job of a purged template whose run ended goes by its run: it is
     * canceled within the run, for the run's reason, and the run is not
     * moved on.
     */
    public function testAJobOfAPurgedTemplateOfARunThatEndedIsCanceledWithinItsRun(): void
    {
        $template = $this->openTemplate((int)$this->operator->id);
        ['run' => $run, 'job' => $job] = $this->strandedRun($template);
        $this->purge($template, $job);

        $this->assertSame(1, $this->strandedJobSweep()->sweep()['stranded_jobs_canceled']);

        $this->assertSame(
            ['reason' => JobCancellationService::REASON_WORKFLOW_CANCELED, 'workflow_job_id' => (int)$run->id],
            $this->contextOf($this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id))
        );
        $this->assertSame([], $this->notifications->payloadsOf(NotificationTemplate::EVENT_JOB_CANCELED));
    }

    // -- Steps still running in runs that ended ------------------------------------------------

    /**
     * Steps still running in runs that ended (their end failed during the
     * cancel; a pause step has no job) end with the message of the run's
     * cancel, or STEP_RUN_ENDED when the run ended otherwise.
     */
    public function testStepsStillRunningInRunsThatEndedAreEnded(): void
    {
        $canceled = $this->pausedRun();
        $this->runCancellation()->cancelRun($canceled['run'], (int)$this->operator->id, JobCancellationService::REASON_OPERATOR);
        $deleted = $this->pausedRun();
        WorkflowTemplate::updateAll(['deleted_at' => time()], ['id' => $deleted['run']->workflow_template_id]);
        WorkflowJob::updateAll(['status' => WorkflowJob::STATUS_CANCELED], ['id' => $deleted['run']->id]);
        $failed = $this->pausedRun();
        WorkflowJob::updateAll(['status' => WorkflowJob::STATUS_FAILED], ['id' => $failed['run']->id]);
        $active = $this->pausedRun();
        // As if failing the step had failed during the cancel.
        WorkflowJobStep::updateAll(['status' => WorkflowJobStep::STATUS_RUNNING, 'error_message' => null], ['id' => $canceled['execution']->id]);

        $this->assertSame(['stranded_jobs_canceled' => 0, 'stranded_jobs_failed' => 0, 'stranded_jobs_remaining' => 0], $this->strandedJobSweep()->sweep());

        $this->assertSame([WorkflowJobStep::STATUS_FAILED, WorkflowCancellationService::STEP_RUN_CANCELED], $this->outcomeOf($canceled['execution']));
        $this->assertSame([WorkflowJobStep::STATUS_FAILED, WorkflowCancellationService::STEP_TEMPLATE_DELETED], $this->outcomeOf($deleted['execution']));
        $this->assertSame([WorkflowJobStep::STATUS_FAILED, StrandedJobSweep::STEP_RUN_ENDED], $this->outcomeOf($failed['execution']));
        $this->assertSame([WorkflowJobStep::STATUS_RUNNING, null], $this->outcomeOf($active['execution']), 'a step of an active run runs on');
    }

    /**
     * A step that cannot be ended is logged without values; the others are
     * ended, and the sweep reports its jobs as usual.
     */
    public function testAStepThatCannotBeEndedIsLoggedAndTheOthersAreEnded(): void
    {
        $first = $this->pausedRun();
        $second = $this->pausedRun();
        foreach ([$first, $second] as $paused) {
            WorkflowJob::updateAll(['status' => WorkflowJob::STATUS_FAILED], ['id' => $paused['run']->id]);
        }
        $failingRunId = (int)$first['run']->id;
        $error = $this->lostConnection('step-marker-4711');
        $this->swapService('workflowCancellationService', new class ($failingRunId, $error) extends WorkflowCancellationService {
            public function __construct(private readonly int $failingRunId, private readonly \Throwable $error)
            {
                parent::__construct();
            }

            public function cancelOf(WorkflowJob $run): array
            {
                if ((int)$run->id === $this->failingRunId) {
                    throw $this->error;
                }

                return parent::cancelOf($run);
            }
        });

        $logged = $this->errorsLoggedBy(StrandedJobSweep::class, fn () => $this->strandedJobSweep()->sweep());

        $this->assertSame(['stranded_jobs_canceled' => 0, 'stranded_jobs_failed' => 0, 'stranded_jobs_remaining' => 0], $logged['result']);
        $this->assertSame(
            [sprintf(StrandedJobSweep::STEP_FAILED, $first['execution']->id, $failingRunId, \yii\db\Exception::class, 'HY000', '2006')],
            $logged['errors']
        );
        $this->assertStringNotContainsString('step-marker-4711', implode("\n", $logged['errors']));
        $this->assertSame([WorkflowJobStep::STATUS_RUNNING, null], $this->outcomeOf($first['execution']));
        $this->assertSame([WorkflowJobStep::STATUS_FAILED, StrandedJobSweep::STEP_RUN_ENDED], $this->outcomeOf($second['execution']));
    }

    // -- One job at a time, in batches, within the deadline ------------------------------------

    /**
     * A job whose cancel fails is logged without values and counted; the
     * others are canceled, and it is left for the next sweep.
     */
    public function testAJobThatCannotBeCanceledIsLoggedAndTheOthersAreCanceled(): void
    {
        ['job' => $first] = $this->strandedRun($this->template);
        ['job' => $second] = $this->strandedRun($this->template);
        $this->swapService('jobCancellationService', new FlakyJobCancellation($this->lostConnection('sweep-marker-4711')));

        $logged = $this->errorsLoggedBy(StrandedJobSweep::class, fn () => $this->strandedJobSweep()->sweep());

        $this->assertSame(['stranded_jobs_canceled' => 1, 'stranded_jobs_failed' => 1, 'stranded_jobs_remaining' => 1], $logged['result']);
        $this->assertSame(
            [sprintf(StrandedJobSweep::JOB_FAILED, $first->id, \yii\db\Exception::class, 'HY000', '2006')],
            $logged['errors']
        );
        $this->assertStringNotContainsString('sweep-marker-4711', implode("\n", $logged['errors']));
        $this->assertSame(Job::STATUS_QUEUED, Job::findOne($first->id)?->status, 'left for the next sweep');
        $this->assertSame(Job::STATUS_CANCELED, Job::findOne($second->id)?->status);
    }

    /**
     * At most $limit jobs, oldest first; the rest are reported as remaining.
     */
    public function testASweepCancelsAtMostItsLimitOldestFirst(): void
    {
        $jobs = [];
        foreach ([1, 2, 3] as $ignored) {
            $jobs[] = $this->strandedRun($this->template)['job'];
        }

        $this->assertSame(['stranded_jobs_canceled' => 2, 'stranded_jobs_failed' => 0, 'stranded_jobs_remaining' => 1], $this->strandedJobSweep()->sweep(2));

        $this->assertSame(
            [Job::STATUS_CANCELED, Job::STATUS_CANCELED, Job::STATUS_QUEUED],
            array_map(static fn (Job $job): ?string => Job::findOne($job->id)?->status, $jobs)
        );
        $this->assertSame(['stranded_jobs_canceled' => 1, 'stranded_jobs_failed' => 0, 'stranded_jobs_remaining' => 0], $this->strandedJobSweep()->sweep(2));
    }

    /**
     * Past the deadline nothing is started: no job, no step.
     */
    public function testNothingStartsAfterTheDeadline(): void
    {
        ['job' => $job] = $this->strandedRun($this->template);
        $paused = $this->pausedRun();
        WorkflowJob::updateAll(['status' => WorkflowJob::STATUS_FAILED], ['id' => $paused['run']->id]);

        $result = $this->strandedJobSweep()->sweep(StrandedJobSweep::BATCH_SIZE, microtime(true) - 1);

        $this->assertSame(['stranded_jobs_canceled' => 0, 'stranded_jobs_failed' => 0, 'stranded_jobs_remaining' => 1], $result);
        $this->assertSame(Job::STATUS_QUEUED, Job::findOne($job->id)?->status);
        $this->assertSame([WorkflowJobStep::STATUS_RUNNING, null], $this->outcomeOf($paused['execution']));
    }

    /**
     * The deadline is checked before each job and each step: one that
     * started is finished, the next one is not started.
     */
    public function testTheDeadlineIsCheckedBeforeEachJobAndEachStep(): void
    {
        ['job' => $first] = $this->strandedRun($this->template);
        ['job' => $second] = $this->strandedRun($this->template);
        $paused = [$this->pausedRun(), $this->pausedRun()];
        foreach ($paused as $run) {
            WorkflowJob::updateAll(['status' => WorkflowJob::STATUS_FAILED], ['id' => $run['run']->id]);
        }
        // The clock reads before the deadline twice (the first job, the first step), past it after each.
        $sweep = new class ([1.0, 3.0, 1.0, 3.0]) extends StrandedJobSweep {
            /** @param list<float> $readings */
            public function __construct(private array $readings)
            {
                parent::__construct();
            }

            protected function now(): float
            {
                return array_shift($this->readings) ?? 9.0;
            }
        };

        $this->assertSame(['stranded_jobs_canceled' => 1, 'stranded_jobs_failed' => 0, 'stranded_jobs_remaining' => 1], $sweep->sweep(StrandedJobSweep::BATCH_SIZE, 2.0));

        $this->assertSame(Job::STATUS_CANCELED, Job::findOne($first->id)?->status);
        $this->assertSame(Job::STATUS_QUEUED, Job::findOne($second->id)?->status);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $this->outcomeOf($paused[0]['execution'])[0]);
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $this->outcomeOf($paused[1]['execution'])[0]);
    }

    /**
     * A job that is gone when its turn comes is skipped, and not counted.
     */
    public function testAJobThatIsGoneWhenItsTurnComesIsSkipped(): void
    {
        ['job' => $first] = $this->strandedRun($this->template);
        ['job' => $second] = $this->strandedRun($this->template);
        $firstId = (int)$first->id;
        $secondId = (int)$second->id;
        $hook = static function (Event $event) use ($firstId, $secondId): void {
            if ($event->sender instanceof Job && (int)$event->sender->id === $firstId) {
                Job::deleteAll(['id' => $secondId]);
            }
        };
        Event::on(Job::class, BaseActiveRecord::EVENT_AFTER_FIND, $hook);
        $this->findHooks[] = $hook;

        $this->assertSame(['stranded_jobs_canceled' => 1, 'stranded_jobs_failed' => 0, 'stranded_jobs_remaining' => 0], $this->strandedJobSweep()->sweep());
    }

    // -- Helpers -------------------------------------------------------------------------------

    /**
     * A run waiting at a job step of $template whose cancel failed after the
     * run was claimed: the run is canceled, the step failed, the job waits.
     *
     * @return array{run: WorkflowJob, step: WorkflowStep, next: WorkflowStep, job: Job}
     */
    private function strandedRun(JobTemplate $template): array
    {
        $flow = $this->runWaitingForItsJob($this->operator, $template);
        $this->failJobCancels($this->lostConnection());
        $this->runCancellation()->cancelRun($flow['run'], (int)$this->operator->id, JobCancellationService::REASON_OPERATOR);
        $this->restoreSwappedServices();
        $flow['job']->refresh();
        $this->assertTrue($flow['job']->isCancelable(), 'the job still waits');

        return $flow;
    }

    /**
     * A job template whose jobs wait for the approver's approval.
     */
    private function gated(): JobTemplate
    {
        return $this->gatedTemplate((int)$this->operator->id, $this->usersRuleOf((int)$this->operator->id, (int)$this->approver->id));
    }

    /**
     * A run of a new workflow waiting at its pause step.
     *
     * @return array{run: WorkflowJob, execution: WorkflowJobStep}
     */
    private function pausedRun(): array
    {
        $workflow = $this->createWorkflowTemplate((int)$this->operator->id);
        $pause = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $run = $this->engine()->launch($workflow, (int)$this->operator->id);

        return ['run' => $run, 'execution' => $this->executionOfStep($run, $pause)];
    }

    /**
     * A standalone job of $this->template that waits for approval without
     * any request, created at $createdAt.
     */
    private function requestlessJob(int $createdAt): Job
    {
        $job = $this->createJob((int)$this->template->id, (int)$this->operator->id, Job::STATUS_PENDING_APPROVAL);
        Job::updateAll(['created_at' => $createdAt, 'queued_at' => null], ['id' => $job->id]);
        $job->refresh();

        return $job;
    }

    /**
     * A job in $status outside workflows, created at $createdAt (default: a
     * day ago), whose job template was purged as a project delete purges it.
     */
    private function purgedJob(string $status, ?int $createdAt = null): Job
    {
        $template = $this->openTemplate((int)$this->operator->id);
        $job = $this->createJob((int)$template->id, (int)$this->operator->id, $status);
        $this->purge($template, $job, $createdAt);

        return $job;
    }

    /**
     * Purge $template as a project delete purges the rows of its deleted
     * templates (soft-deleted, then its row deleted: the foreign key sets the
     * job_template_id of its jobs to NULL), without canceling its jobs first,
     * as older versions did; $job, one of them, was created at $createdAt
     * (default: a day ago).
     */
    private function purge(JobTemplate $template, Job $job, ?int $createdAt = null): void
    {
        $this->assertTrue($template->softDelete());
        $this->assertSame(1, JobTemplate::deleteAll(['id' => $template->id]));
        Job::updateAll(['created_at' => $createdAt ?? time() - 86400], ['id' => $job->id]);
        $job->refresh();
        $this->assertNull($job->job_template_id, 'the foreign key set it to NULL');
    }

    /**
     * Delete $job's approval request, as 2.9.0's rule delete did, long after
     * the job was created.
     */
    private function loseTheRequest(Job $job): void
    {
        ApprovalRequest::deleteAll(['job_id' => $job->id]);
        Job::updateAll(['created_at' => time() - 86400], ['id' => $job->id]);
        $job->refresh();
    }

    /**
     * @return array{0: string, 1: string|null} the status and the error message of $execution as stored
     */
    private function outcomeOf(WorkflowJobStep $execution): array
    {
        $execution->refresh();

        return [(string)$execution->status, $execution->error_message];
    }
}
