<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTask;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\JobCancellationService;
use app\services\JobCompletionService;
use app\services\WorkflowCancellationService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\db\BaseActiveRecord;

/**
 * WorkflowCancellationService::cancelRun(): the run is claimed first, its
 * running steps fail with the reason, their jobs are canceled (closing a
 * pending approval request), and one workflow.canceled audit entry and
 * notification follow.
 *
 * Before this service, WorkflowExecutionService::cancel() set the child jobs
 * to canceled with a plain save: the request of an approval step stayed
 * pending and decidable, no job.canceled entry was written, a stray
 * workflow.failed entry was, and the run was marked finished only at the
 * end, so a child job that completed meanwhile could still start the next
 * step.
 */
class WorkflowCancellationServiceTest extends DbTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;

    private RecordingNotificationDispatcher $notifications;

    /** The completion that completeBeforeTheCancelOfTheJob() hooks in, if any. */
    private ?\Closure $completion = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = $this->recordNotifications();
    }

    protected function tearDown(): void
    {
        if ($this->completion !== null) {
            Event::off(Job::class, BaseActiveRecord::EVENT_AFTER_FIND, $this->completion);
            $this->completion = null;
        }
        $this->restoreNotifications();
        parent::tearDown();
    }

    /**
     * Regression: canceling a run that waited at an approval step left the
     * step's approval request pending; approvers could still decide it. The
     * request is canceled with the placeholder now.
     */
    public function testCancelingARunAtAnApprovalStepClosesTheStepsRequest(): void
    {
        $flow = $this->runAtApprovalStep();
        $actor = (int)$flow['launcher']->id;
        $runId = (int)$flow['run']->id;
        $notificationsBefore = count($this->notifications->events);
        $before = time();

        $canceled = $this->runCancellation()->cancelRun($flow['run'], $actor, JobCancellationService::REASON_OPERATOR);

        $flow['request']->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $flow['request']->status);
        $this->assertGreaterThanOrEqual($before, (int)$flow['request']->resolved_at);
        $this->assertFalse($this->approvalService()->canUserApprove($flow['request'], (int)$flow['approver']->id));
        $this->assertTrue($canceled);
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $flow['run']->status, 'the model is refreshed');
        $this->assertGreaterThanOrEqual($before, (int)$flow['run']->finished_at);
        $execution = $this->executionOf($flow['run'], $flow['approval']);
        $this->assertNotNull($execution);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(WorkflowCancellationService::STEP_RUN_CANCELED, $execution->error_message);
        $this->assertGreaterThanOrEqual($before, (int)$execution->finished_at);
        $flow['placeholder']->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $flow['placeholder']->status);
        $this->assertNull($this->executionOf($flow['run'], $flow['onFailure']), 'no route is taken');
        $this->assertNull($this->executionOf($flow['run'], $flow['onSuccess']));

        $this->assertSame(
            ['reason' => JobCancellationService::REASON_WORKFLOW_CANCELED, 'workflow_job_id' => $runId],
            $this->contextOf($this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$flow['placeholder']->id))
        );
        $this->assertSame(
            ['job_id' => (int)$flow['placeholder']->id, 'rule_id' => (int)$flow['request']->approval_rule_id, 'reason' => JobCancellationService::REASON_WORKFLOW_CANCELED],
            $this->contextOf($this->onlyAuditOf(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', (int)$flow['request']->id))
        );
        $canceled = $this->onlyAuditOf(AuditLog::ACTION_WORKFLOW_CANCELED, 'workflow_job', $runId);
        $this->assertSame($actor, (int)$canceled->user_id);
        $this->assertSame(['reason' => JobCancellationService::REASON_OPERATOR], $this->contextOf($canceled));
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_WORKFLOW_FAILED, 'workflow_job', $runId), 'no stray workflow.failed entry');

        $sent = array_slice($this->notifications->events, $notificationsBefore);
        $this->assertSame([NotificationTemplate::EVENT_WORKFLOW_CANCELED], array_column($sent, 'event'), 'one notification, no job.canceled');
        $this->assertSame([
            'workflow' => [
                'id' => (string)$runId,
                'status' => WorkflowJob::STATUS_CANCELED,
                'template_id' => (string)$flow['run']->workflow_template_id,
                'template_name' => (string)$flow['run']->workflowTemplate->name,
            ],
        ], $sent[0]['payload']);
    }

    /**
     * A vote on the step's request after the run was canceled is refused,
     * and it moves neither the placeholder nor the run.
     */
    public function testTheRequestOfACanceledRunCannotBeDecided(): void
    {
        $flow = $this->runAtApprovalStep();
        $this->runCancellation()->cancelRun($flow['run'], (int)$flow['launcher']->id, JobCancellationService::REASON_OPERATOR);
        $flow['request']->refresh();

        $refused = null;
        try {
            $this->approvalService()->recordDecision($flow['request'], (int)$flow['approver']->id, ApprovalDecision::DECISION_APPROVED);
        } catch (\RuntimeException $e) {
            $refused = $e;
        }

        $this->assertNotNull($refused);
        $flow['placeholder']->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $flow['placeholder']->status);
        $this->assertNull($this->executionOf($flow['run'], $flow['onSuccess']));
    }

    /**
     * A run at a job step: the job is canceled (whether it waits or runs),
     * with its run's ID in the audit entry and no notification of its own.
     */
    public function testCancelingARunAtAJobStepCancelsTheStepsJob(): void
    {
        ['run' => $run, 'step' => $step, 'user' => $user] = $this->runAtJobStep();
        $execution = $this->executionOf($run, $step);
        $this->assertNotNull($execution);
        $job = Job::findOne($execution->job_id);
        $this->assertNotNull($job);
        Job::updateAll(['status' => Job::STATUS_RUNNING, 'started_at' => time()], ['id' => $job->id]);

        $this->assertTrue($this->runCancellation()->cancelRun($run, (int)$user->id, JobCancellationService::REASON_OPERATOR));

        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $execution->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(WorkflowCancellationService::STEP_RUN_CANCELED, $execution->error_message);
        $this->assertSame(
            ['reason' => JobCancellationService::REASON_WORKFLOW_CANCELED, 'workflow_job_id' => (int)$run->id],
            $this->contextOf($this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id))
        );
        $this->assertSame([], $this->notifications->payloadsOf(NotificationTemplate::EVENT_JOB_CANCELED));
    }

    /**
     * A pause step has no job: it fails with the message; earlier steps
     * keep their outcome.
     */
    public function testCancelingARunAtAPauseStepFailsOnlyThatStep(): void
    {
        $user = $this->createUserWithRole('cancel-pause', 'operator');
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $first = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $second = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $run = $this->workflowEngine()->launch($workflow, (int)$user->id);
        $this->workflowEngine()->resume($run, (int)$user->id);
        $done = $this->executionOf($run, $first);
        $this->assertNotNull($done);
        $doneBefore = $done->getAttributes();

        $this->assertTrue($this->runCancellation()->cancelRun($run, (int)$user->id, JobCancellationService::REASON_OPERATOR));

        $paused = $this->executionOf($run, $second);
        $this->assertNotNull($paused);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $paused->status);
        $this->assertNull($paused->job_id);
        $this->assertSame(WorkflowCancellationService::STEP_RUN_CANCELED, $paused->error_message);
        $done->refresh();
        $this->assertSame($doneBefore, $done->getAttributes(), 'a finished step keeps its outcome');
    }

    /**
     * Deleting the workflow template cancels its runs with that reason: the
     * steps say so, and so do the audit entries of the jobs and the request.
     * Without an actor (a system path) the entries have no user.
     */
    public function testATemplateDeletionCancelNamesItsReasonEverywhere(): void
    {
        $flow = $this->runAtApprovalStep();
        $runId = (int)$flow['run']->id;

        $this->assertTrue($this->runCancellation()->cancelRun($flow['run'], null, JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED));

        $this->assertSame(WorkflowCancellationService::STEP_TEMPLATE_DELETED, $this->executionOf($flow['run'], $flow['approval'])?->error_message);
        $canceled = $this->onlyAuditOf(AuditLog::ACTION_WORKFLOW_CANCELED, 'workflow_job', $runId);
        $this->assertNull($canceled->user_id);
        $this->assertSame(['reason' => JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED], $this->contextOf($canceled));
        $this->assertSame(
            ['reason' => JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED, 'workflow_job_id' => $runId],
            $this->contextOf($this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$flow['placeholder']->id))
        );
        $closed = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', (int)$flow['request']->id);
        $this->assertSame(JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED, $this->contextOf($closed)['reason'] ?? null);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function finishedRunProvider(): array
    {
        return [
            'succeeded' => [WorkflowJob::STATUS_SUCCEEDED],
            'failed' => [WorkflowJob::STATUS_FAILED],
            'canceled' => [WorkflowJob::STATUS_CANCELED],
        ];
    }

    /**
     * A finished run is not canceled, and nothing is written, also when the
     * caller's model still shows it running.
     *
     * @dataProvider finishedRunProvider
     */
    public function testAFinishedRunIsNotCanceled(string $status): void
    {
        $flow = $this->runAtApprovalStep();
        WorkflowJob::updateAll(['status' => $status, 'finished_at' => 1700000000], ['id' => $flow['run']->id]);
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $flow['run']->status, 'the model is stale');
        $notificationsBefore = count($this->notifications->events);

        $this->assertFalse($this->runCancellation()->cancelRun($flow['run'], (int)$flow['launcher']->id, JobCancellationService::REASON_OPERATOR));

        $this->assertSame($status, $flow['run']->status, 'the model is refreshed');
        $this->assertSame(1700000000, (int)$flow['run']->finished_at);
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $this->executionOf($flow['run'], $flow['approval'])?->status);
        $flow['placeholder']->refresh();
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $flow['placeholder']->status);
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_WORKFLOW_CANCELED, 'workflow_job', (int)$flow['run']->id));
        $this->assertCount($notificationsBefore, $this->notifications->events);
    }

    /**
     * Two cancels of one run (a user and a template deletion, or two users):
     * only the one that claims it acts.
     */
    public function testOnlyOneOfTwoCancelsActs(): void
    {
        $flow = $this->runAtApprovalStep();
        $second = WorkflowJob::findOne($flow['run']->id);
        $this->assertNotNull($second);

        $this->assertTrue($this->runCancellation()->cancelRun($flow['run'], (int)$flow['launcher']->id, JobCancellationService::REASON_OPERATOR));
        $this->assertFalse($this->runCancellation()->cancelRun($second, null, JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED));

        $this->assertCount(1, $this->auditsOf(AuditLog::ACTION_WORKFLOW_CANCELED, 'workflow_job', (int)$flow['run']->id));
        $this->assertCount(1, $this->notifications->payloadsOf(NotificationTemplate::EVENT_WORKFLOW_CANCELED));
        $this->assertSame(WorkflowCancellationService::STEP_RUN_CANCELED, $this->executionOf($flow['run'], $flow['approval'])?->error_message);
    }

    /**
     * Regression: the run was marked finished only after its child jobs were
     * canceled, so a child job that completed in between (its runner
     * reported) advanced the run and started the next step, whose job then
     * ran although the run was canceled. The run is claimed first now: the
     * completion finds it finished and takes no route.
     */
    public function testAChildJobThatCompletesWhileTheRunIsCanceledStartsNoNextStep(): void
    {
        ['run' => $run, 'step' => $step, 'next' => $next, 'user' => $user] = $this->runAtJobStep();
        $execution = $this->executionOf($run, $step);
        $this->assertNotNull($execution);
        $job = Job::findOne($execution->job_id);
        $this->assertNotNull($job);
        $this->completeBeforeTheCancelOfTheJob($job);
        $jobsBefore = (int)Job::find()->count();

        $this->assertTrue($this->runCancellation()->cancelRun($run, (int)$user->id, JobCancellationService::REASON_OPERATOR));

        $this->assertNull($this->executionOf($run, $next), 'the next step must not start');
        $this->assertSame($jobsBefore, (int)Job::find()->count(), 'no job was launched');
        $job->refresh();
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status, 'the completed job keeps its outcome');
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id));
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $run->status);
    }

    /**
     * A step whose job finished while the run was being canceled, and whose
     * completion has not ended the step yet: the step fails with the
     * message, and the job keeps its outcome.
     */
    public function testAStepWhoseJobFinishedFailsAndTheJobKeepsItsOutcome(): void
    {
        ['run' => $run, 'step' => $step, 'user' => $user] = $this->runAtJobStep();
        $execution = $this->executionOf($run, $step);
        $this->assertNotNull($execution);
        Job::updateAll(['status' => Job::STATUS_FAILED, 'exit_code' => 2, 'finished_at' => 1700000000], ['id' => $execution->job_id]);

        $this->assertTrue($this->runCancellation()->cancelRun($run, (int)$user->id, JobCancellationService::REASON_OPERATOR));

        $execution->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(WorkflowCancellationService::STEP_RUN_CANCELED, $execution->error_message);
        $this->assertSame(Job::STATUS_FAILED, Job::findOne($execution->job_id)?->status);
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$execution->job_id));
    }

    /**
     * A step whose job row is gone fails like the others.
     */
    public function testAStepWhoseJobIsGoneFails(): void
    {
        ['run' => $run, 'step' => $step, 'user' => $user] = $this->runAtJobStep();
        $execution = $this->executionOf($run, $step);
        $this->assertNotNull($execution);
        $execution->job_id = (int)Job::find()->max('id') + 1000;
        $execution->save(false);

        $this->assertTrue($this->runCancellation()->cancelRun($run, (int)$user->id, JobCancellationService::REASON_OPERATOR));

        $execution->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function wrongReasonProvider(): array
    {
        return [
            'a job template deletion' => [JobCancellationService::REASON_TEMPLATE_DELETED],
            'a reason of the jobs of a run' => [JobCancellationService::REASON_WORKFLOW_CANCELED],
            'an unknown reason' => ['because'],
        ];
    }

    /**
     * @dataProvider wrongReasonProvider
     */
    public function testAReasonARunIsNotCanceledForIsRefused(string $reason): void
    {
        $flow = $this->runAtApprovalStep();

        try {
            $this->runCancellation()->cancelRun($flow['run'], (int)$flow['launcher']->id, $reason);
            $this->fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(sprintf(
                JobCancellationService::UNKNOWN_REASON,
                $reason,
                JobCancellationService::REASON_OPERATOR . ', ' . JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED
            ), $e->getMessage());
        }

        $flow['run']->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $flow['run']->status, 'nothing was written');
    }

    // -- Helpers ------------------------------------------------------------------------

    /**
     * A run at its first step, a job step (whose job is queued), followed by
     * a second job step on success.
     *
     * @return array{user: User, run: WorkflowJob, step: WorkflowStep, next: WorkflowStep}
     */
    private function runAtJobStep(): array
    {
        $user = $this->createUserWithRole('cancel-jobstep', 'operator');
        $template = $this->cancelFixtureTemplate((int)$user->id);
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $step = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$template->id);
        $next = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$template->id);
        $step->on_success_step_id = $next->id;
        $step->save(false);
        $run = $this->workflowEngine()->launch($workflow, (int)$user->id);

        return ['user' => $user, 'run' => $run, 'step' => $step, 'next' => $next];
    }

    /**
     * The runner reports success for $job when the cancel loads the job to
     * cancel it, after the run's steps were read: the moment a completion
     * can land in production.
     */
    private function completeBeforeTheCancelOfTheJob(Job $job): void
    {
        $jobId = (int)$job->id;
        $fired = false;
        $this->completion = static function (Event $event) use ($jobId, &$fired): void {
            if ($fired || !$event->sender instanceof Job || (int)$event->sender->id !== $jobId) {
                return;
            }
            $fired = true;
            Job::updateAll(['status' => Job::STATUS_RUNNING, 'started_at' => time()], ['id' => $jobId]);
            JobTask::getDb()->createCommand()->insert('{{%job_task}}', [
                'job_id' => $jobId,
                'sequence' => 0,
                'task_name' => 'ok',
                'task_action' => 'ansible.builtin.debug',
                'host' => 'localhost',
                'status' => 'ok',
                'changed' => 0,
                'duration_ms' => 1,
                'created_at' => time(),
            ])->execute();
            $running = Job::findOne($jobId);
            /** @var JobCompletionService $completion */
            $completion = \Yii::$app->get('jobCompletionService');
            $completion->complete($running ?? $event->sender, 0);
            $event->sender->refresh();
        };
        Event::on(Job::class, BaseActiveRecord::EVENT_AFTER_FIND, $this->completion);
    }
}
