<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\Job;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\JobCancellationService;
use app\services\WorkflowCancellationService;
use app\services\WorkflowTemplateDeletionService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\db\BaseActiveRecord;

/**
 * A step that starts while its run is canceled. The cancel claims the run
 * first and then ends the running steps it finds; a step it did not find,
 * or whose job it could not see yet, is ended by the engine once the step
 * has started: no job runs, and no request waits, in a canceled run.
 */
class WorkflowExecutionCancelRaceTest extends DbTestCase
{
    use TeamScopeFixtures;
    use WorkflowEngineFixtures;

    private User $user;

    /** @var list<array{0: string, 1: \Closure}> */
    private array $hooks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->operator('wf-cancel-race');
    }

    protected function tearDown(): void
    {
        foreach ($this->hooks as [$event, $hook]) {
            Event::off(WorkflowJobStep::class, $event, $hook);
        }
        $this->hooks = [];
        parent::tearDown();
    }

    /**
     * Regression: the run was canceled after the next step's execution was
     * written but before its job was linked to it; the cancel ended the step
     * but could not see the job, which stayed queued and ran.
     */
    public function testRegressionAJobStepStartedWhileItsRunIsCanceledDoesNotRun(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $first = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->openTemplate((int)$this->user->id)->id);
        $second = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$this->openTemplate((int)$this->user->id)->id);
        $run = $this->engine()->launch($workflow, (int)$this->user->id);
        $this->cancelWhenTheStepIsWritten($run, $second, BaseActiveRecord::EVENT_AFTER_INSERT);

        $this->finishJob($this->jobOfStep($run, $first));

        $job = $this->jobOfStep($run, $second);
        $this->assertSame(Job::STATUS_CANCELED, $job->status, 'the job of the canceled run does not run');
        $execution = $this->executionOfStep($run, $second);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(WorkflowCancellationService::STEP_RUN_CANCELED, $execution->error_message);
        $canceled = $this->auditEntries(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id);
        $this->assertCount(1, $canceled);
        $this->assertSame(
            ['reason' => JobCancellationService::REASON_WORKFLOW_CANCELED, 'workflow_job_id' => (int)$run->id],
            $this->metadataOf($canceled[0])
        );
        // Regression: the job's cancel named no user (or the signed-in user of
        // the request that started the step), not the user who canceled the run.
        $this->assertSame((int)$this->user->id, (int)$canceled[0]->user_id);
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $run->status);
    }

    /**
     * Regression: a step that started while its workflow template was
     * deleted was abandoned as if a user had canceled its run: its error
     * said "the run was canceled", and its job's cancel named the reason
     * workflow_canceled and no user. Both come from the run's cancel now:
     * the template's message, its reason, and the admin who deleted it.
     *
     * @dataProvider writeEventProvider
     */
    public function testRegressionAStepStartedWhileItsWorkflowTemplateWasDeletedIsAbandonedForThatReason(string $event): void
    {
        $admin = $this->createUserWithRole('wf-cancel-race-admin', 'admin');
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $step = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->openTemplate((int)$this->user->id)->id);
        $this->deleteTheTemplateWhenTheStepIsWritten($workflow, $step, (int)$admin->id, $event);

        $run = $this->engine()->launch($workflow, (int)$this->user->id);

        $execution = $this->executionOfStep($run, $step);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(WorkflowCancellationService::STEP_TEMPLATE_DELETED, $execution->error_message);
        $job = $this->jobOfStep($run, $step);
        $this->assertSame(Job::STATUS_CANCELED, $job->status, 'the job of the canceled run does not run');
        $canceled = $this->auditEntries(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id);
        $this->assertCount(1, $canceled);
        $this->assertSame(
            ['reason' => JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED, 'workflow_job_id' => (int)$run->id],
            $this->metadataOf($canceled[0])
        );
        $this->assertSame((int)$admin->id, (int)$canceled[0]->user_id);
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $run->status);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function writeEventProvider(): array
    {
        return [
            'before the step is written' => [BaseActiveRecord::EVENT_BEFORE_INSERT],
            'after the step is written' => [BaseActiveRecord::EVENT_AFTER_INSERT],
        ];
    }

    /**
     * Without a workflow.canceled entry yet (the cancel claimed the run but
     * has not written it), the step goes by the template's state: a deleted
     * template's message and reason, and no user.
     */
    public function testAStepOfARunEndedWithoutACancelEntryGoesByTheTemplatesState(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $step = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->openTemplate((int)$this->user->id)->id);
        $workflowId = (int)$workflow->id;
        $fired = false;
        $hook = static function (Event $e) use ($workflowId, &$fired): void {
            if ($fired || !$e->sender instanceof WorkflowJobStep) {
                return;
            }
            $fired = true;
            WorkflowTemplate::updateAll(['deleted_at' => time()], ['id' => $workflowId]);
            WorkflowJob::updateAll(['status' => WorkflowJob::STATUS_CANCELED], ['id' => $e->sender->workflow_job_id]);
        };
        Event::on(WorkflowJobStep::class, BaseActiveRecord::EVENT_AFTER_INSERT, $hook);
        $this->hooks[] = [BaseActiveRecord::EVENT_AFTER_INSERT, $hook];

        $run = $this->engine()->launch($workflow, (int)$this->user->id);

        $this->assertSame(WorkflowCancellationService::STEP_TEMPLATE_DELETED, $this->executionOfStep($run, $step)->error_message);
        $canceled = $this->auditEntries(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$this->jobOfStep($run, $step)->id);
        $this->assertCount(1, $canceled);
        $this->assertSame(JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED, $this->metadataOf($canceled[0])['reason'] ?? null);
        $this->assertNull($canceled[0]->user_id);
    }

    /**
     * The cancel ended before the approval step's execution was written: the
     * step is ended here, and its placeholder canceled with its request.
     */
    public function testAnApprovalStepStartedAfterItsRunWasCanceledLeavesNoPendingRequest(): void
    {
        $approver = $this->operator('wf-cancel-race-approver');
        $rule = $this->usersRuleOf((int)$this->user->id, (int)$approver->id);
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $gate = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $run = $this->engine()->launch($workflow, (int)$this->user->id);
        $this->cancelWhenTheStepIsWritten($run, $gate, BaseActiveRecord::EVENT_BEFORE_INSERT);

        $this->engine()->resume($run, (int)$this->user->id);

        $execution = $this->executionOfStep($run, $gate);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(WorkflowCancellationService::STEP_RUN_CANCELED, $execution->error_message);
        $placeholder = $this->jobOfStep($run, $gate);
        $this->assertSame(Job::STATUS_CANCELED, $placeholder->status);
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $this->requestOfJob($placeholder)->status);
    }

    public function testAPauseStepStartedAfterItsRunWasCanceledEnds(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $first = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->openTemplate((int)$this->user->id)->id);
        $pause = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $run = $this->engine()->launch($workflow, (int)$this->user->id);
        $this->cancelWhenTheStepIsWritten($run, $pause, BaseActiveRecord::EVENT_BEFORE_INSERT);

        $this->finishJob($this->jobOfStep($run, $first));

        $execution = $this->executionOfStep($run, $pause);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(WorkflowCancellationService::STEP_RUN_CANCELED, $execution->error_message);
        $this->assertSame([], $this->auditEntries(AuditLog::ACTION_WORKFLOW_FAILED, 'workflow_job', (int)$run->id));
    }

    /**
     * $adminId deletes $workflow (through the workflow template deletion,
     * which cancels its runs) when the engine writes the execution of $step.
     */
    private function deleteTheTemplateWhenTheStepIsWritten(WorkflowTemplate $workflow, WorkflowStep $step, int $adminId, string $event): void
    {
        $stepId = (int)$step->id;
        $fired = false;
        $hook = static function (Event $e) use ($workflow, $stepId, $adminId, &$fired): void {
            if ($fired || !$e->sender instanceof WorkflowJobStep || (int)$e->sender->workflow_step_id !== $stepId) {
                return;
            }
            $fired = true;
            /** @var WorkflowTemplateDeletionService $deletion */
            $deletion = \Yii::$app->get('workflowTemplateDeletionService');
            $deletion->delete($workflow, $adminId, 'web');
        };
        Event::on(WorkflowJobStep::class, $event, $hook);
        $this->hooks[] = [$event, $hook];
    }

    /**
     * A user cancels $run when the engine writes the execution of $step:
     * before the row is inserted (the cancel cannot see the step) or after
     * (it sees the step, but not yet its job).
     */
    private function cancelWhenTheStepIsWritten(WorkflowJob $run, WorkflowStep $step, string $event): void
    {
        $stepId = (int)$step->id;
        $userId = (int)$this->user->id;
        $fired = false;
        $hook = static function (Event $e) use ($run, $stepId, $userId, &$fired): void {
            if ($fired || !$e->sender instanceof WorkflowJobStep || (int)$e->sender->workflow_step_id !== $stepId) {
                return;
            }
            $fired = true;
            /** @var WorkflowCancellationService $cancellation */
            $cancellation = \Yii::$app->get('workflowCancellationService');
            $cancellation->cancelRun(WorkflowJob::findOne($run->id) ?? $run, $userId, JobCancellationService::REASON_OPERATOR);
        };
        Event::on(WorkflowJobStep::class, $event, $hook);
        $this->hooks[] = [$event, $hook];
    }
}
