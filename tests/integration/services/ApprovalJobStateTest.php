<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Job;
use app\models\JobTemplate;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\ApprovalJobState;
use app\services\ApprovalSettlement;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * ApprovalJobState for jobs without a job template. An approval step's
 * placeholder has none by design and still waits; any other job lost its
 * template when the template's row was purged (deleting a project purges
 * its deleted templates, and the foreign key sets job.job_template_id to
 * NULL), and counts as a job of a deleted template: it no longer waits, so
 * a vote or a timeout closes its request and cancels it (reason
 * template_deleted). A placeholder is told by its step's type (the
 * snapshot, else the live step), never by the missing template.
 */
class ApprovalJobStateTest extends DbTestCase
{
    use TeamScopeFixtures;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('job-state')->id;
    }

    /**
     * Regression: a pending_approval job whose template was purged counted
     * as waiting, so a vote approved it and queued a job no runner ever
     * claims (JobClaimService joins the template).
     */
    public function testRegressionAJobWhoseTemplateWasPurgedNoLongerWaits(): void
    {
        $job = $this->jobOfPurgedTemplate();

        $state = ApprovalJobState::read($job);

        $this->assertFalse($state->waits());
        $this->assertFalse($state->placeholder());
        $this->assertNull($state->step);
        $this->assertSame(ApprovalSettlement::REASON_TEMPLATE_DELETED, $state->closeReason());
        $this->assertTrue($state->cancelJob(time()), 'canceled in the settlement');
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertNotNull($job->finished_at);
        $this->assertNull($job->queued_at, 'never queued');
        $this->assertFalse($state->cancelJob(time()), 'once');
    }

    /**
     * A job with its live template still waits, and its template is read.
     */
    public function testAJobOfALiveTemplateWaits(): void
    {
        $job = $this->createJob((int)$this->template()->id, $this->userId, Job::STATUS_PENDING_APPROVAL);

        $state = ApprovalJobState::read($job);

        $this->assertTrue($state->waits());
        $this->assertSame(ApprovalSettlement::REASON_JOB_NOT_WAITING, $state->closeReason());
        $this->assertFalse($state->cancelJob(time()), 'a job that waits is left as it is');
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $job->status);
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null, 2: bool}> the execution's
     *     snapshot type (null: none), its live step's type (null: the step was removed), whether
     *     the template-less job is a placeholder that still waits
     */
    public static function stepProvider(): array
    {
        return [
            'an approval step' => [WorkflowStep::TYPE_APPROVAL, WorkflowStep::TYPE_APPROVAL, true],
            'an approval step that was removed: the snapshot tells' => [WorkflowStep::TYPE_APPROVAL, null, true],
            'an execution without a snapshot: the live approval step tells' => [null, WorkflowStep::TYPE_APPROVAL, true],
            'a job step: its template was purged' => [WorkflowStep::TYPE_JOB, WorkflowStep::TYPE_JOB, false],
            'a job step that was removed' => [WorkflowStep::TYPE_JOB, null, false],
            'a step whose type is unknown: fail closed' => [null, null, false],
            'a type other than approval, compared exactly' => ['APPROVAL', 'APPROVAL', false],
        ];
    }

    /**
     * A template-less job of a step running in an active run waits only as
     * the placeholder of an approval step.
     *
     * @dataProvider stepProvider
     */
    public function testATemplateLessJobOfAWorkflowStepWaitsOnlyAsAnApprovalPlaceholder(
        ?string $snapshotType,
        ?string $liveType,
        bool $placeholder
    ): void {
        $job = $this->templateLessJobOfRunningStep($snapshotType, $liveType);

        $state = ApprovalJobState::read($job);

        $this->assertNotNull($state->step);
        $this->assertSame($placeholder, $state->placeholder());
        $this->assertSame($placeholder, $state->waits());
        $this->assertSame(
            $placeholder ? ApprovalSettlement::REASON_JOB_NOT_WAITING : ApprovalSettlement::REASON_TEMPLATE_DELETED,
            $state->closeReason()
        );
        $this->assertSame(!$placeholder, $state->cancelJob(time()));
        $this->assertSame($placeholder ? Job::STATUS_PENDING_APPROVAL : Job::STATUS_CANCELED, $job->status);
    }

    /**
     * The job of an ended step goes by its run first, with or without a
     * template.
     */
    public function testAnEndedStepComesBeforeAPurgedTemplate(): void
    {
        $job = $this->templateLessJobOfRunningStep(WorkflowStep::TYPE_JOB, WorkflowStep::TYPE_JOB);
        WorkflowJobStep::updateAll(['status' => WorkflowJobStep::STATUS_FAILED], ['job_id' => $job->id]);

        $state = ApprovalJobState::read($job);

        $this->assertFalse($state->waits());
        $this->assertSame(ApprovalSettlement::REASON_WORKFLOW_STEP_ENDED, $state->closeReason());
    }

    // -- Helpers ----------------------------------------------------------------------

    private function template(): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
    }

    /**
     * A pending_approval job outside workflows whose template was purged:
     * the foreign key set its job_template_id to NULL.
     */
    private function jobOfPurgedTemplate(): Job
    {
        $template = $this->template();
        $job = $this->createJob((int)$template->id, $this->userId, Job::STATUS_PENDING_APPROVAL);
        Job::updateAll(['queued_at' => null], ['id' => $job->id]);
        $this->assertTrue($template->softDelete());
        JobTemplate::deleteAll(['id' => $template->id]);
        $job->refresh();
        $this->assertNull($job->job_template_id, 'the foreign key set it to NULL');

        return $job;
    }

    /**
     * A pending_approval job without a template, linked to a running
     * execution of a step of a running run. The execution's snapshot type is
     * $snapshotType (null: none); its live step has the type $liveType, or
     * is removed when $liveType is null.
     */
    private function templateLessJobOfRunningStep(?string $snapshotType, ?string $liveType): Job
    {
        $workflow = $this->createWorkflowTemplate($this->userId);
        $step = $this->createWorkflowStep((int)$workflow->id, 0, $liveType ?? WorkflowStep::TYPE_JOB);
        $run = $this->createWorkflowJob((int)$workflow->id, $this->userId);
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status);

        $job = new Job();
        $job->job_template_id = null;
        $job->launched_by = $this->userId;
        $job->status = Job::STATUS_PENDING_APPROVAL;
        $job->timeout_minutes = 0;
        $job->has_changes = 0;
        $job->save(false);

        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = (int)$step->id;
        $execution->job_id = (int)$job->id;
        $execution->status = WorkflowJobStep::STATUS_RUNNING;
        $execution->save(false);
        WorkflowJobStep::updateAll(['step_type' => $snapshotType], ['id' => $execution->id]);
        if ($liveType === null) {
            $step->delete();
        }

        return $job;
    }
}
