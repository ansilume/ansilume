<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\Job;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\JobCancellationService;
use app\services\StrandedJobSweep;
use app\services\WorkflowCancellationService;
use app\services\WorkflowExecutionService;
use app\services\WorkflowLaunchRejectedException;
use app\services\WorkflowTemplateDeletionService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\base\ModelEvent;
use yii\db\ActiveRecord;
use yii\log\Logger;

/**
 * Deleting a workflow template cancels its active runs.
 *
 * Regression: the delete only set deleted_at. Runs of the template went on:
 * a run waiting at an approval step kept its request pending and decidable,
 * and a run at a job step launched the next steps of a workflow nobody could
 * see any more.
 */
class WorkflowTemplateDeletionServiceTest extends DbTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;

    private User $admin;
    private mixed $originalCancellation = null;
    private bool $cancellationSwapped = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('wf-delete-admin', 'admin');
    }

    protected function tearDown(): void
    {
        $this->restoreNotifications();
        if ($this->cancellationSwapped) {
            \Yii::$app->set('workflowCancellationService', $this->originalCancellation);
        }
        parent::tearDown();
    }

    public function testDeletingAWorkflowCancelsItsActiveRunsAndCountsThem(): void
    {
        $f = $this->runAtApprovalStep();
        $workflow = WorkflowTemplate::findOne($f['run']->workflow_template_id);
        $this->assertNotNull($workflow);
        $workflow->generateTriggerToken((int)$f['launcher']->id);
        $paused = $this->runAtPauseStep($workflow);
        $finished = $this->createWorkflowJob((int)$workflow->id, (int)$f['launcher']->id, WorkflowJob::STATUS_SUCCEEDED);
        $notifications = $this->recordNotifications();

        $result = $this->deletion()->delete($workflow, (int)$this->admin->id, 'web');

        $this->assertSame(['deleted' => true, 'canceled_runs' => 2, 'failed_runs' => 0], $result);
        $this->assertNull(WorkflowTemplate::findOne($workflow->id), 'soft-deleted');
        $deleted = WorkflowTemplate::findWithDeleted()->where(['id' => $workflow->id])->one();
        $this->assertInstanceOf(WorkflowTemplate::class, $deleted);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertNull($deleted->trigger_token, 'the trigger token goes with it');

        foreach ([$f['run'], $paused['run']] as $run) {
            $run->refresh();
            $this->assertSame(WorkflowJob::STATUS_CANCELED, $run->status);
            $this->assertNotNull($run->finished_at);
            $canceled = $this->onlyAuditOf(AuditLog::ACTION_WORKFLOW_CANCELED, 'workflow_job', (int)$run->id);
            $this->assertSame($this->admin->id, (int)$canceled->user_id);
            $this->assertSame(['reason' => JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED], $this->contextOf($canceled));
        }
        $approvalStep = $this->executionOf($f['run'], $f['approval']);
        $this->assertNotNull($approvalStep);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $approvalStep->status);
        $this->assertSame(WorkflowCancellationService::STEP_TEMPLATE_DELETED, $approvalStep->error_message);
        $paused['execution']->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $paused['execution']->status);
        $this->assertSame(WorkflowCancellationService::STEP_TEMPLATE_DELETED, $paused['execution']->error_message);

        $f['placeholder']->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $f['placeholder']->status);
        $f['request']->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $f['request']->status, 'nobody can decide on it any more');
        $jobCanceled = $this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$f['placeholder']->id);
        $this->assertSame(JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED, $this->contextOf($jobCanceled)['reason'] ?? null);

        $finished->refresh();
        $this->assertSame(WorkflowJob::STATUS_SUCCEEDED, $finished->status, 'a finished run keeps its outcome');
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_WORKFLOW_CANCELED, 'workflow_job', (int)$finished->id));

        $this->assertCount(2, $notifications->payloadsOf(NotificationTemplate::EVENT_WORKFLOW_CANCELED));
        $entry = $this->onlyAuditOf(AuditLog::ACTION_WORKFLOW_TEMPLATE_DELETED, 'workflow_template', (int)$workflow->id);
        $this->assertSame($this->admin->id, (int)$entry->user_id);
        $this->assertSame(['name' => $workflow->name, 'canceled_runs' => 2, 'failed_runs' => 0, 'source' => 'web'], $this->contextOf($entry));
    }

    public function testAWorkflowWithoutActiveRunsIsDeletedWithNothingCanceled(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $finished = $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id, WorkflowJob::STATUS_FAILED);

        $result = $this->deletion()->delete($workflow, (int)$this->admin->id, 'api');

        $this->assertSame(['deleted' => true, 'canceled_runs' => 0, 'failed_runs' => 0], $result);
        $this->assertNull(WorkflowTemplate::findOne($workflow->id));
        $finished->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $finished->status);
        $entry = $this->onlyAuditOf(AuditLog::ACTION_WORKFLOW_TEMPLATE_DELETED, 'workflow_template', (int)$workflow->id);
        $this->assertSame(['name' => $workflow->name, 'canceled_runs' => 0, 'failed_runs' => 0, 'source' => 'api'], $this->contextOf($entry));
    }

    /**
     * A second delete with the template as it was loaded before the first
     * one does nothing: it finds the row deleted under its lock.
     */
    public function testAWorkflowDeletedMeanwhileIsLeftAsItIs(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $stale = WorkflowTemplate::findOne($workflow->id);
        $this->assertNotNull($stale);
        $this->assertTrue($this->deletion()->delete($workflow, (int)$this->admin->id, 'web')['deleted']);
        $deletedAt = WorkflowTemplate::findWithDeleted()->select('deleted_at')->where(['id' => $workflow->id])->scalar();
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);

        $result = $this->deletion()->delete($stale, (int)$this->admin->id, 'api');

        $this->assertSame(['deleted' => false, 'canceled_runs' => 0, 'failed_runs' => 0], $result);
        $this->assertCount(1, $this->auditsOf(AuditLog::ACTION_WORKFLOW_TEMPLATE_DELETED, 'workflow_template', (int)$workflow->id));
        $this->assertSame($deletedAt, WorkflowTemplate::findWithDeleted()->select('deleted_at')->where(['id' => $workflow->id])->scalar());
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status, 'nothing is canceled');
    }

    /**
     * The soft delete holds the row lock a launch takes, and the runs are
     * canceled after its commit: a launch either committed before (its run
     * is canceled here) or finds the template deleted.
     */
    public function testTheRowIsLockedForTheSoftDeleteAndRunsAreCanceledAfterItsCommit(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);
        $id = (int)$workflow->id;

        $statements = $this->statementsOf(fn () => $this->deletion()->delete($workflow, (int)$this->admin->id, 'web'));

        $savepoint = $this->indexOf($statements, '~^SAVEPOINT LEVEL\d+$~');
        $lock = $this->indexOf($statements, '~^SELECT `deleted_at` FROM `workflow_template` WHERE `id` = ' . $id . ' FOR UPDATE$~');
        $softDelete = $this->indexOf($statements, '~^UPDATE `workflow_template` SET .*`deleted_at`=\d+.* WHERE `id`=' . $id . '$~s');
        $release = $this->indexOf($statements, '~^RELEASE SAVEPOINT LEVEL\d+$~');
        $cancel = $this->indexOf($statements, '~^UPDATE `workflow_job` SET `status`=\'canceled\'.* WHERE \(`id`=' . $run->id . '\) AND \(`status`=\'running\'\)$~s');
        $audit = $this->indexOf($statements, '~^INSERT INTO `audit_log`.*workflow-template\.deleted~s');
        $this->assertTrue(
            $savepoint < $lock && $lock < $softDelete && $softDelete < $release && $release < $cancel && $cancel < $audit,
            "lock, soft delete, commit, then the cancels and the audit entry:\n" . implode("\n", $statements)
        );
    }

    public function testAVetoedSoftDeleteWritesNothing(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);
        $handler = static function (ModelEvent $event): void {
            $event->isValid = false;
        };
        Event::on(WorkflowTemplate::class, ActiveRecord::EVENT_BEFORE_UPDATE, $handler);
        try {
            $this->deletion()->delete($workflow, (int)$this->admin->id, 'web');
            $this->fail('A vetoed delete must not pass for one.');
        } catch (\RuntimeException $e) {
            $this->assertSame(sprintf(WorkflowTemplateDeletionService::DELETE_REFUSED, $workflow->id), $e->getMessage());
        } finally {
            Event::off(WorkflowTemplate::class, ActiveRecord::EVENT_BEFORE_UPDATE, $handler);
        }

        $this->assertNotNull(WorkflowTemplate::findOne($workflow->id), 'still there');
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status);
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_WORKFLOW_TEMPLATE_DELETED, 'workflow_template', (int)$workflow->id));
    }

    /**
     * regression: a failed cancel during a workflow template delete left the
     * other runs running. The exception of one cancelRun() ended the loop:
     * the runs after it were never canceled, and the delete answered with an
     * error although the template was gone. Now each run is canceled on its
     * own; a failure is logged without its message and counted, and a run
     * that finished meanwhile is not counted at all.
     */
    public function testAFailedCancelDoesNotStopTheOtherRunsAndIsCounted(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $failing = $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);
        $finishedMeanwhile = $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);
        $after = $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);
        $this->failCancelOf((int)$failing->id, (int)$finishedMeanwhile->id);

        [$result, $errors] = $this->withErrors(fn (): array => $this->deletion()->delete($workflow, (int)$this->admin->id, 'web'));

        $this->assertSame(['deleted' => true, 'canceled_runs' => 1, 'failed_runs' => 1], $result);
        $this->assertNull(WorkflowTemplate::findOne($workflow->id), 'the delete itself was committed');
        $after->refresh();
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $after->status, 'the run after the failing one is canceled');
        $failing->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $failing->status, 'left to the maintenance run');
        $entry = $this->onlyAuditOf(AuditLog::ACTION_WORKFLOW_TEMPLATE_DELETED, 'workflow_template', (int)$workflow->id);
        $this->assertSame(['name' => $workflow->name, 'canceled_runs' => 1, 'failed_runs' => 1, 'source' => 'web'], $this->contextOf($entry));
        $this->assertSame(
            [sprintf(WorkflowTemplateDeletionService::CANCEL_FAILED, $failing->id, $workflow->id, \yii\db\Exception::class, '40001', '1213')],
            $errors
        );
    }

    /**
     * A cancel that fails with an error that is no database error is logged
     * without a SQLSTATE or a driver error code.
     */
    public function testAFailureThatIsNoDatabaseErrorIsLoggedWithoutASqlState(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $failing = $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);
        $this->failCancelOf((int)$failing->id, 0, new \RuntimeException('carries a value that must not be logged'));

        [$result, $errors] = $this->withErrors(fn (): array => $this->deletion()->delete($workflow, (int)$this->admin->id, 'api'));

        $this->assertSame(['deleted' => true, 'canceled_runs' => 0, 'failed_runs' => 1], $result);
        $this->assertSame(
            [sprintf(WorkflowTemplateDeletionService::CANCEL_FAILED, $failing->id, $workflow->id, \RuntimeException::class, '-', '-')],
            $errors
        );
    }

    /**
     * Regression: CANCEL_FAILED said "the maintenance run retries while the
     * run is active", but a cancel can fail after it claimed the run: then
     * the run is canceled at once, its step still runs and the step's job
     * still waits for approval, and the run is no longer active. The line
     * now says what happens in both cases ("the maintenance run cancels the
     * run, or the jobs it left") and names the driver error code; here the
     * second case, which the stranded-job sweep finishes.
     */
    public function testRegressionACancelThatFailedAfterItsClaimIsFinishedByTheStrandedJobSweep(): void
    {
        $f = $this->runAtApprovalStep();
        $workflow = WorkflowTemplate::findOne($f['run']->workflow_template_id);
        $this->assertNotNull($workflow);
        $this->recordNotifications();
        $failure = static function (): void {
            // Reading the run's running steps, right after the claim, fails.
            throw new \yii\db\Exception(
                'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away; The SQL being executed was: SELECT '
                . FailingWorkflowCancellation::SECRET_VALUE,
                ['HY000', 2006, 'MySQL server has gone away']
            );
        };
        Event::on(WorkflowJobStep::class, ActiveRecord::EVENT_AFTER_FIND, $failure);
        try {
            [$result, $errors] = $this->withErrors(fn (): array => $this->deletion()->delete($workflow, (int)$this->admin->id, 'web'));
        } finally {
            Event::off(WorkflowJobStep::class, ActiveRecord::EVENT_AFTER_FIND, $failure);
        }

        $this->assertSame(['deleted' => true, 'canceled_runs' => 0, 'failed_runs' => 1], $result);
        $this->assertSame(
            [
                "Canceling run #{$f['run']->id} of deleted workflow template #{$workflow->id} failed: yii\\db\\Exception "
                . '(SQLSTATE HY000, driver error 2006); the maintenance run cancels the run, or the jobs it left.',
            ],
            $errors
        );
        $f['run']->refresh();
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $f['run']->status, 'the claim was committed: the run is no longer active');
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $this->statusOfJob((int)$f['placeholder']->id), 'the cancel left the job waiting');
        $this->assertSame(
            ['canceled_runs' => 0, 'failed_runs' => 0, 'remaining_runs' => 0],
            $this->deletion()->sweep(),
            'nothing is left for the sweep of deleted workflow templates'
        );

        /** @var StrandedJobSweep $stranded */
        $stranded = \Yii::$app->get('strandedJobSweep');
        $this->assertSame(
            ['stranded_jobs_canceled' => 1, 'stranded_jobs_failed' => 0, 'stranded_jobs_remaining' => 0],
            $stranded->sweep()
        );

        $this->assertSame(Job::STATUS_CANCELED, $this->statusOfJob((int)$f['placeholder']->id), 'the stranded-job sweep canceled the job');
        $request = ApprovalRequest::findOne($f['request']->id);
        $this->assertNotNull($request);
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status);
        $execution = $this->executionOf($f['run'], $f['approval']);
        $this->assertNotNull($execution);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(WorkflowCancellationService::STEP_TEMPLATE_DELETED, $execution->error_message);
    }

    /**
     * When the runs cannot even be read, the committed delete is audited all
     * the same, and the error reaches the caller.
     */
    public function testTheDeleteIsAuditedWhenItsRunsCannotBeRead(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);
        $handler = static function (): void {
            throw new \RuntimeException('the runs cannot be read');
        };
        Event::on(WorkflowJob::class, ActiveRecord::EVENT_AFTER_FIND, $handler);
        try {
            $this->deletion()->delete($workflow, (int)$this->admin->id, 'web');
            $this->fail('The error must reach the caller.');
        } catch (\RuntimeException $e) {
            $this->assertSame('the runs cannot be read', $e->getMessage());
        } finally {
            Event::off(WorkflowJob::class, ActiveRecord::EVENT_AFTER_FIND, $handler);
        }

        $this->assertNull(WorkflowTemplate::findOne($workflow->id), 'the delete itself was committed');
        $entry = $this->onlyAuditOf(AuditLog::ACTION_WORKFLOW_TEMPLATE_DELETED, 'workflow_template', (int)$workflow->id);
        $this->assertSame(['name' => $workflow->name, 'canceled_runs' => 0, 'failed_runs' => 0, 'source' => 'web'], $this->contextOf($entry));
    }

    // -- sweep() ---------------------------------------------------------------------

    /**
     * The maintenance run cancels the active runs of deleted workflow
     * templates, as the system: here a template soft-deleted through the
     * model, which cancels nothing.
     */
    public function testTheSweepCancelsActiveRunsOfDeletedTemplatesAsTheSystem(): void
    {
        $deleted = $this->createWorkflowTemplate((int)$this->admin->id);
        $paused = $this->runAtPauseStep($deleted);
        $finished = $this->createWorkflowJob((int)$deleted->id, (int)$this->admin->id, WorkflowJob::STATUS_FAILED);
        $deleted->softDelete();
        $live = $this->createWorkflowTemplate((int)$this->admin->id);
        $liveRun = $this->createWorkflowJob((int)$live->id, (int)$this->admin->id);
        $notifications = $this->recordNotifications();

        $result = $this->deletion()->sweep();

        $this->assertSame(['canceled_runs' => 1, 'failed_runs' => 0, 'remaining_runs' => 0], $result);
        $paused['run']->refresh();
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $paused['run']->status);
        $paused['execution']->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $paused['execution']->status);
        $this->assertSame(WorkflowCancellationService::STEP_TEMPLATE_DELETED, $paused['execution']->error_message);
        $canceled = $this->onlyAuditOf(AuditLog::ACTION_WORKFLOW_CANCELED, 'workflow_job', (int)$paused['run']->id);
        $this->assertNull($canceled->user_id, 'the system canceled it');
        $this->assertSame(['reason' => JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED], $this->contextOf($canceled));
        $this->assertCount(1, $notifications->payloadsOf(NotificationTemplate::EVENT_WORKFLOW_CANCELED));
        $finished->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $finished->status, 'a finished run keeps its outcome');
        $liveRun->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $liveRun->status, 'a run of a live template goes on');

        $this->assertSame(['canceled_runs' => 0, 'failed_runs' => 0, 'remaining_runs' => 0], $this->deletion()->sweep(), 'nothing left');
    }

    /**
     * One sweep cancels at most $limit runs, oldest first, and reports the
     * rest; a run whose cancel fails stays active, so it is reported too.
     */
    public function testTheSweepCancelsOneBatchOldestFirstAndReportsWhatIsLeft(): void
    {
        $this->assertSame(100, WorkflowTemplateDeletionService::SWEEP_BATCH_SIZE);
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $failing = $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);
        $oldest = $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);
        $newest = $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);
        $workflow->softDelete();
        $this->failCancelOf((int)$failing->id, 0);

        [$result, $errors] = $this->withErrors(fn (): array => $this->deletion()->sweep(2));

        $this->assertSame(['canceled_runs' => 1, 'failed_runs' => 1, 'remaining_runs' => 2], $result);
        $this->assertCount(1, $errors);
        $oldest->refresh();
        $newest->refresh();
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $oldest->status);
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $newest->status, 'left for the next sweep');
    }

    // -- sweep() with a deadline (the maintenance run's time budget) ---------------

    /**
     * Regression (review of step 3, operations-1): a sweep canceled its whole
     * batch however long the notifications took. A sweep whose deadline has
     * passed starts no cancel: the runs wait for the next sweep.
     */
    public function testASweepWhoseDeadlineHasPassedStartsNoCancel(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);
        $workflow->softDelete();
        $notifications = $this->recordNotifications();

        $result = $this->deletion()->sweep(WorkflowTemplateDeletionService::SWEEP_BATCH_SIZE, microtime(true) - 1);

        $this->assertSame(['canceled_runs' => 0, 'failed_runs' => 0, 'remaining_runs' => 1], $result);
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status);
        $this->assertSame([], $notifications->payloadsOf(NotificationTemplate::EVENT_WORKFLOW_CANCELED));
    }

    /**
     * Regression (review of step 3, operations-1): the deadline is checked
     * before each cancel starts, and a cancel that started is finished: the
     * clock reads 100 and 101 before the first two cancels, then 102, past
     * the deadline of 101.5, so the newest run is left for the next sweep.
     */
    public function testASweepFinishesTheCancelItStartedAndStartsNoneAfterItsDeadline(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $runs = [];
        for ($i = 0; $i < 3; $i++) {
            $runs[] = $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);
        }
        $workflow->softDelete();
        $this->recordNotifications();
        $deletion = $this->deletionWithClock([100.0, 101.0, 102.0]);

        $result = $deletion->sweep(WorkflowTemplateDeletionService::SWEEP_BATCH_SIZE, 101.5);

        $this->assertSame(['canceled_runs' => 2, 'failed_runs' => 0, 'remaining_runs' => 1], $result);
        $statuses = array_map(static fn (WorkflowJob $run): string => (string)WorkflowJob::findOne($run->id)?->status, $runs);
        $this->assertSame([WorkflowJob::STATUS_CANCELED, WorkflowJob::STATUS_CANCELED, WorkflowJob::STATUS_RUNNING], $statuses);
        $this->assertSame([], $deletion->clock, 'the clock was read before each of the three');
    }

    /**
     * A delete, in the web UI or through the API, has no deadline: it
     * cancels every active run of the template and never reads the clock.
     */
    public function testADeleteHasNoDeadline(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);
        $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);
        $this->recordNotifications();
        $deletion = $this->deletionWithClock([]);

        $result = $deletion->delete($workflow, (int)$this->admin->id, 'api');

        $this->assertSame(['deleted' => true, 'canceled_runs' => 2, 'failed_runs' => 0], $result);
    }

    /**
     * Contract C4: a launch takes the same row lock as the delete, so a
     * launch with the template as it was loaded before the delete is
     * refused, and writes no run.
     */
    public function testALaunchOfTheTemplateAsLoadedBeforeTheDeleteIsRefused(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $loaded = WorkflowTemplate::findOne($workflow->id);
        $this->assertNotNull($loaded);
        $this->deletion()->delete($workflow, (int)$this->admin->id, 'web');

        try {
            $this->workflowEngine()->launch($loaded, (int)$this->admin->id);
            $this->fail('The launch of a deleted workflow template must be refused.');
        } catch (WorkflowLaunchRejectedException $e) {
            $this->assertSame(sprintf(WorkflowExecutionService::WORKFLOW_TEMPLATE_DELETED, $workflow->id), $e->getMessage());
        }
        $this->assertFalse(WorkflowJob::find()->where(['workflow_template_id' => $workflow->id])->exists());
    }

    // -- Helpers ------------------------------------------------------------------

    /**
     * A run of $workflow waiting at a pause step.
     *
     * @return array{run: WorkflowJob, execution: WorkflowJobStep}
     */
    private function runAtPauseStep(WorkflowTemplate $workflow): array
    {
        $pause = $this->createWorkflowStep((int)$workflow->id, 9, WorkflowStep::TYPE_PAUSE);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = (int)$pause->id;
        $execution->status = WorkflowJobStep::STATUS_RUNNING;
        $execution->started_at = time();
        $execution->save(false);

        return ['run' => $run, 'execution' => $execution];
    }

    private function swapCancellation(WorkflowCancellationService $replacement): void
    {
        $this->originalCancellation = \Yii::$app->getComponents(true)['workflowCancellationService'] ?? null;
        $this->cancellationSwapped = true;
        \Yii::$app->set('workflowCancellationService', $replacement);
    }

    /**
     * Canceling run #$failingRunId throws $error (default: a deadlock whose
     * message carries a value); run #$finishedRunId reports that it had
     * finished meanwhile; every other run is canceled for real.
     */
    private function failCancelOf(int $failingRunId, int $finishedRunId, ?\Throwable $error = null): void
    {
        $this->swapCancellation(new FailingWorkflowCancellation($failingRunId, $finishedRunId, $error));
    }

    /**
     * Run $action and return its result with the errors
     * WorkflowTemplateDeletionService logged.
     *
     * @template T
     * @param callable(): T $action
     * @return array{0: T, 1: list<string>}
     */
    private function withErrors(callable $action): array
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
        $errors = [];
        foreach ($logger->messages as $message) {
            if ($message[1] === Logger::LEVEL_ERROR && $message[2] === WorkflowTemplateDeletionService::class && is_string($message[0])) {
                $this->assertStringNotContainsString(FailingWorkflowCancellation::SECRET_VALUE, $message[0], 'no value of the error is logged');
                $errors[] = $message[0];
            }
        }

        return [$result, $errors];
    }

    private function statusOfJob(int $jobId): string
    {
        $job = Job::findOne($jobId);
        $this->assertNotNull($job);

        return (string)$job->status;
    }

    private function deletion(): WorkflowTemplateDeletionService
    {
        /** @var WorkflowTemplateDeletionService $service */
        $service = \Yii::$app->get('workflowTemplateDeletionService');
        return $service;
    }

    /**
     * A deletion service whose clock reads the values of $clock in turn; a
     * read beyond them fails the test.
     *
     * @param list<float> $clock
     * @return WorkflowTemplateDeletionService&object{clock: list<float>}
     */
    private function deletionWithClock(array $clock): WorkflowTemplateDeletionService
    {
        return new class ($clock) extends WorkflowTemplateDeletionService {
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
