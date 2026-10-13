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
use app\services\WorkflowCancellationService;
use app\services\WorkflowRunEvents;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * A run cancel that fails part-way. Once WorkflowCancellationService has
 * claimed the run, the run is canceled: its workflow.canceled audit entry and
 * notification follow at once, each running step is a unit of its own (a job
 * cancel that hits a lock conflict is tried once more), a step that still
 * fails is logged without values, and the cancel goes on with the next step.
 * No runner claims the job of such a step, and the maintenance run's
 * StrandedJobSweep cancels it.
 *
 * Regression: an error after the claim (a deadlock or a lost connection in
 * the job cancel's request-then-job transaction) escaped cancelRun(): the run
 * showed canceled while its step's job stayed queued, or waited for approval,
 * and a runner then ran it. Nothing retried: the deleted-template sweep only
 * looks at active runs. The operator's cancel answered 500, and no
 * workflow.canceled entry was written.
 */
class WorkflowCancellationFailureTest extends DbTestCase
{
    use TeamScopeFixtures;
    use WorkflowEngineFixtures;
    use CancellationFixtures;
    use StrandedRunFixtures;

    private RecordingNotificationDispatcher $notifications;
    private User $operator;
    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = $this->recordNotifications();
        $this->operator = $this->operator('wf-cancel-failure');
        $this->template = $this->openTemplate((int)$this->operator->id);
    }

    protected function tearDown(): void
    {
        $this->restoreSwappedServices();
        $this->restoreNotifications();
        parent::tearDown();
    }

    /**
     * Regression (see the class description): the cancel of a run whose
     * step's job cannot be canceled still cancels the run and its other
     * steps, logs the step without values, and no runner claims the job;
     * the maintenance run's sweep cancels it.
     */
    public function testRegressionARunCancelGoesOnAfterOneStepsJobCancelFails(): void
    {
        ['run' => $run, 'step' => $step, 'next' => $pause, 'job' => $job] = $this->runWaitingForItsJob($this->operator, $this->template);
        $paused = $this->secondRunningExecution($run, $pause);
        $execution = $this->executionOfStep($run, $step);
        $this->failJobCancels($this->lostConnection('cancel-marker-4711'));

        $logged = $this->errorsLoggedBy(
            WorkflowCancellationService::class,
            fn () => $this->runCancellation()->cancelRun($run, (int)$this->operator->id, JobCancellationService::REASON_OPERATOR)
        );

        $this->assertTrue($logged['result'], 'the run is canceled');
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $run->status);
        $this->assertSame(
            [sprintf(WorkflowCancellationService::STEP_CANCEL_FAILED, $run->id, $execution->id, \yii\db\Exception::class, 'HY000', '2006')],
            $logged['errors']
        );
        $this->assertStringNotContainsString('cancel-marker-4711', implode("\n", $logged['errors']));
        $execution->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(WorkflowCancellationService::STEP_RUN_CANCELED, $execution->error_message);
        $paused->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $paused->status, 'the cancel went on with the next step');
        $this->assertSame(WorkflowCancellationService::STEP_RUN_CANCELED, $paused->error_message);
        $canceled = $this->onlyAuditOf(AuditLog::ACTION_WORKFLOW_CANCELED, 'workflow_job', (int)$run->id);
        $this->assertSame((int)$this->operator->id, (int)$canceled->user_id);
        $this->assertSame(['reason' => JobCancellationService::REASON_OPERATOR], $this->contextOf($canceled));
        $this->assertCount(1, $this->notifications->payloadsOf(NotificationTemplate::EVENT_WORKFLOW_CANCELED));

        $job->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $job->status, 'the job cancel failed');
        $this->assertNull($this->claimFor($this->template), 'no runner takes the job of a canceled run');

        $this->restoreSwappedServices();
        $this->assertSame(
            ['stranded_jobs_canceled' => 1, 'stranded_jobs_failed' => 0, 'stranded_jobs_remaining' => 0],
            $this->strandedJobSweep()->sweep()
        );
        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertSame(
            ['reason' => JobCancellationService::REASON_WORKFLOW_CANCELED, 'workflow_job_id' => (int)$run->id],
            $this->contextOf($this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id))
        );
    }

    /**
     * Regression: the operator's cancel answered 500 (a database error is no
     * RuntimeException) although the run was canceled. It is a cancel now,
     * carried by the operator, and the job is not claimed.
     */
    public function testRegressionAnOperatorsCancelWhoseJobCancelFailedIsACancel(): void
    {
        ['run' => $run, 'job' => $job] = $this->runWaitingForItsJob($this->operator, $this->template);
        $this->failJobCancels($this->deadlock());

        $this->engine()->cancel($run, (int)$this->operator->id);

        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $run->status);
        $canceled = $this->onlyAuditOf(AuditLog::ACTION_WORKFLOW_CANCELED, 'workflow_job', (int)$run->id);
        $this->assertSame((int)$this->operator->id, (int)$canceled->user_id);
        $job->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
        $this->assertNull($this->claimFor($this->template));
    }

    /**
     * The gated variant: the job waits for approval with a pending request
     * when its run's cancel fails; the sweep cancels the job and closes the
     * request.
     */
    public function testRegressionTheGatedJobOfARunWhoseCancelFailedIsCanceledWithItsRequestLater(): void
    {
        $approver = $this->operator('wf-cancel-failure-approver');
        $gated = $this->gatedTemplate((int)$this->operator->id, $this->usersRuleOf((int)$this->operator->id, (int)$approver->id));
        ['run' => $run, 'job' => $job] = $this->runWaitingForItsJob($this->operator, $gated);
        $request = $this->requestOfJob($job);
        $this->failJobCancels($this->lostConnection());

        $this->assertTrue($this->runCancellation()->cancelRun($run, (int)$this->operator->id, JobCancellationService::REASON_OPERATOR));

        $job->refresh();
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $job->status);
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);

        $this->restoreSwappedServices();
        $this->assertSame(1, $this->strandedJobSweep()->sweep()['stranded_jobs_canceled']);
        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status);
        $closed = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', (int)$request->id);
        $this->assertSame(JobCancellationService::REASON_WORKFLOW_CANCELED, $this->contextOf($closed)['reason'] ?? null);
    }

    /**
     * A deadlock or lock wait timeout rolled the job cancel back: it is
     * tried once more, and then it goes through, nothing logged.
     *
     * @dataProvider lockConflictProvider
     */
    public function testALockConflictOfAStepsJobCancelIsTriedOnceMore(\yii\db\Exception $conflict): void
    {
        ['run' => $run, 'job' => $job] = $this->runWaitingForItsJob($this->operator, $this->template);
        $flaky = new FlakyJobCancellation($conflict);
        $this->swapService('jobCancellationService', $flaky);

        $logged = $this->errorsLoggedBy(
            WorkflowCancellationService::class,
            fn () => $this->runCancellation()->cancelRun($run, (int)$this->operator->id, JobCancellationService::REASON_OPERATOR)
        );

        $this->assertTrue($logged['result']);
        $this->assertSame([], $logged['errors']);
        $this->assertSame(2, $flaky->withinWorkflowCalls);
        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $entry = $this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id);
        $this->assertSame((int)$this->operator->id, (int)$entry->user_id);
    }

    /**
     * @return array<string, array{0: \yii\db\Exception}>
     */
    public static function lockConflictProvider(): array
    {
        return [
            'a deadlock' => [new \yii\db\Exception('Deadlock', ['40001', 1213, 'Deadlock found'])],
            'a lock wait timeout' => [new \yii\db\Exception('Lock wait', ['HY000', 1205, 'Lock wait timeout exceeded'])],
        ];
    }

    /**
     * A second lock conflict is not tried a third time: the step is logged.
     */
    public function testASecondLockConflictIsLogged(): void
    {
        ['run' => $run, 'step' => $step, 'job' => $job] = $this->runWaitingForItsJob($this->operator, $this->template);
        $flaky = new FlakyJobCancellation($this->deadlock(), $this->deadlock());
        $this->swapService('jobCancellationService', $flaky);

        $logged = $this->errorsLoggedBy(
            WorkflowCancellationService::class,
            fn () => $this->runCancellation()->cancelRun($run, null, JobCancellationService::REASON_OPERATOR)
        );

        $this->assertTrue($logged['result']);
        $this->assertSame(2, $flaky->withinWorkflowCalls);
        $this->assertSame(
            [sprintf(WorkflowCancellationService::STEP_CANCEL_FAILED, $run->id, $this->executionOfStep($run, $step)->id, \yii\db\Exception::class, '40001', '1213')],
            $logged['errors']
        );
        $job->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
    }

    /**
     * An error that is no lock conflict is not tried again.
     */
    public function testAnErrorThatIsNoLockConflictIsNotTriedAgain(): void
    {
        ['run' => $run, 'job' => $job] = $this->runWaitingForItsJob($this->operator, $this->template);
        $flaky = new FlakyJobCancellation($this->lostConnection());
        $this->swapService('jobCancellationService', $flaky);

        $logged = $this->errorsLoggedBy(
            WorkflowCancellationService::class,
            fn () => $this->runCancellation()->cancelRun($run, null, JobCancellationService::REASON_OPERATOR)
        );

        $this->assertTrue($logged['result']);
        $this->assertSame(1, $flaky->withinWorkflowCalls);
        $this->assertCount(1, $logged['errors']);
        $job->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
    }

    /**
     * The run's workflow.canceled entry is written right after the claim,
     * before any step is touched: a step that starts meanwhile reads the
     * reason and the user from it (WorkflowStepDispatcher::settleStart()).
     */
    public function testTheCancelIsAuditedRightAfterTheClaim(): void
    {
        ['run' => $run, 'step' => $step] = $this->runWaitingForItsJob($this->operator, $this->template);
        $execution = $this->executionOfStep($run, $step);

        $statements = $this->statementsOf(
            fn () => $this->runCancellation()->cancelRun($run, (int)$this->operator->id, JobCancellationService::REASON_OPERATOR)
        );

        $claim = $this->statementIndex($statements, '~^UPDATE `workflow_job` SET `status`=\'canceled\'.* WHERE \(`id`=' . $run->id . '\) AND \(`status`=\'running\'\)$~s');
        $audit = $this->statementIndex($statements, '~^INSERT INTO `audit_log` .*workflow\.canceled~s');
        $failStep = $this->statementIndex($statements, '~^UPDATE `workflow_job_step` SET .* WHERE \(`id`=' . $execution->id . '\)~s');
        $this->assertTrue($claim < $audit && $audit < $failStep, implode("\n", $statements));
    }

    /**
     * Regression: the workflow.canceled notification was sent after the
     * steps, and an error in it (reading the notification templates) escaped
     * the cancel. It is best-effort now: logged, and the cancel goes on.
     */
    public function testRegressionAFailedNotificationDoesNotStopTheCancel(): void
    {
        ['run' => $run, 'job' => $job] = $this->runWaitingForItsJob($this->operator, $this->template);
        $this->swapService(
            'notificationDispatcher',
            new FailingNotificationDispatcher([NotificationTemplate::EVENT_WORKFLOW_CANCELED], $this->lostConnection('notify-marker-4711'))
        );

        $logged = $this->errorsLoggedBy(
            WorkflowRunEvents::class,
            fn () => $this->runCancellation()->cancelRun($run, (int)$this->operator->id, JobCancellationService::REASON_OPERATOR)
        );

        $this->assertTrue($logged['result']);
        $this->assertSame(
            [sprintf(WorkflowRunEvents::NOTIFY_FAILED, $run->id, NotificationTemplate::EVENT_WORKFLOW_CANCELED, \yii\db\Exception::class, 'HY000', '2006')],
            $logged['errors']
        );
        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
    }

    // -- cancelOf(), stepMessage(), jobReason() ----------------------------------------------

    /**
     * Why a run was canceled, and by whom, from its workflow.canceled entry.
     *
     * @dataProvider cancelReasonProvider
     */
    public function testCancelOfReadsTheReasonAndTheUserOfTheCancel(string $reason, bool $withActor): void
    {
        ['run' => $run] = $this->runWaitingForItsJob($this->operator, $this->template);
        $actor = $withActor ? (int)$this->operator->id : null;
        $this->runCancellation()->cancelRun($run, $actor, $reason);

        $this->assertSame(['reason' => $reason, 'actor_id' => $actor], $this->runCancellation()->cancelOf($run));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function cancelReasonProvider(): array
    {
        return [
            'a user' => [JobCancellationService::REASON_OPERATOR, true],
            'a template delete by a user' => [JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED, true],
            'a template delete by the maintenance run' => [JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED, false],
        ];
    }

    /**
     * Without a usable workflow.canceled entry (none yet, one of an older
     * version without a reason, one with a reason this version does not
     * know), the template's state decides, and no user is named.
     *
     * @dataProvider missingEntryProvider
     */
    public function testCancelOfFallsBackToTheTemplatesState(?string $metadata, bool $templateDeleted, string $expected): void
    {
        ['run' => $run] = $this->runWaitingForItsJob($this->operator, $this->template);
        WorkflowJob::updateAll(['status' => WorkflowJob::STATUS_CANCELED], ['id' => $run->id]);
        if ($metadata !== null) {
            $this->auditCanceled((int)$run->id, $metadata === '' ? null : $metadata);
        }
        if ($templateDeleted) {
            WorkflowTemplate::updateAll(['deleted_at' => time()], ['id' => $run->workflow_template_id]);
        }

        $this->assertSame(['reason' => $expected, 'actor_id' => null], $this->runCancellation()->cancelOf($run));
    }

    /**
     * @return array<string, array{0: string|null, 1: bool, 2: string}>
     */
    public static function missingEntryProvider(): array
    {
        $operator = JobCancellationService::REASON_OPERATOR;
        $deleted = JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED;

        return [
            'no entry, a live template' => [null, false, $operator],
            'no entry, a deleted template' => [null, true, $deleted],
            'an entry without context, a deleted template' => ['', true, $deleted],
            'an entry with an unknown reason' => ['{"reason":"because"}', false, $operator],
            'an entry with a reason that is no text' => ['{"reason":7}', true, $deleted],
            'an entry that is no JSON object' => ['"operator"', false, $operator],
        ];
    }

    /**
     * The newest workflow.canceled entry counts.
     */
    public function testCancelOfReadsTheNewestEntry(): void
    {
        ['run' => $run] = $this->runWaitingForItsJob($this->operator, $this->template);
        $this->auditCanceled((int)$run->id, '{"reason":"operator"}');
        $this->auditCanceled((int)$run->id, '{"reason":"workflow_template_deleted"}');

        $this->assertSame(
            ['reason' => JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED, 'actor_id' => null],
            $this->runCancellation()->cancelOf($run)
        );
    }

    /**
     * A run whose template row is gone counts as one of a deleted template.
     */
    public function testCancelOfARunWhoseTemplateIsGone(): void
    {
        $run = new WorkflowJob();
        $run->id = (int)WorkflowJob::find()->max('id') + 1000;
        $run->workflow_template_id = (int)WorkflowTemplate::findWithDeleted()->max('id') + 1000;

        $this->assertSame(
            ['reason' => JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED, 'actor_id' => null],
            $this->runCancellation()->cancelOf($run)
        );
    }

    /**
     * The step message and the jobs' cancel reason of each reason a run is
     * canceled for: one mapping, used by the cancel, settleStart() and the
     * sweep, so they cannot drift apart.
     */
    public function testEachReasonMapsToAStepMessageAndAJobReason(): void
    {
        $this->assertSame(WorkflowCancellationService::STEP_RUN_CANCELED, WorkflowCancellationService::stepMessage(JobCancellationService::REASON_OPERATOR));
        $this->assertSame(JobCancellationService::REASON_WORKFLOW_CANCELED, WorkflowCancellationService::jobReason(JobCancellationService::REASON_OPERATOR));
        $this->assertSame(
            WorkflowCancellationService::STEP_TEMPLATE_DELETED,
            WorkflowCancellationService::stepMessage(JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED)
        );
        $this->assertSame(
            JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED,
            WorkflowCancellationService::jobReason(JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED)
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function mappingProvider(): array
    {
        return ['stepMessage' => ['stepMessage'], 'jobReason' => ['jobReason']];
    }

    /**
     * @dataProvider mappingProvider
     */
    public function testAReasonARunIsNotCanceledForHasNoMapping(string $method): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            JobCancellationService::UNKNOWN_REASON,
            JobCancellationService::REASON_TEMPLATE_DELETED,
            JobCancellationService::REASON_OPERATOR . ', ' . JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED
        ));

        $method === 'stepMessage'
            ? WorkflowCancellationService::stepMessage(JobCancellationService::REASON_TEMPLATE_DELETED)
            : WorkflowCancellationService::jobReason(JobCancellationService::REASON_TEMPLATE_DELETED);
    }

    // -- Helpers ------------------------------------------------------------------------

    /**
     * A second running execution in $run, of the pause step $step: a run
     * normally has one, but the cancel ends every one it finds.
     */
    private function secondRunningExecution(WorkflowJob $run, WorkflowStep $step): WorkflowJobStep
    {
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = (int)$step->id;
        $execution->snapshotStep($step);
        $execution->status = WorkflowJobStep::STATUS_RUNNING;
        $execution->started_at = time();
        $execution->save(false);

        return $execution;
    }

    /**
     * A workflow.canceled entry of run #$runId with $metadata as stored.
     */
    private function auditCanceled(int $runId, ?string $metadata): void
    {
        AuditLog::getDb()->createCommand()->insert(AuditLog::tableName(), [
            'action' => AuditLog::ACTION_WORKFLOW_CANCELED,
            'object_type' => 'workflow_job',
            'object_id' => $runId,
            'user_id' => null,
            'metadata' => $metadata,
            'created_at' => time(),
        ])->execute();
    }
}
