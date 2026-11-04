<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\models\ApprovalRequest;
use app\models\Job;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\notification\JobPayloadBuilder;
use app\services\notification\NotificationRefs;
use app\services\NotificationDispatcher;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The refs of a notification's event (NotificationRefs): the objects it is
 * about, which NotificationVisibility checks for each template, when the
 * event happens and when it is delivered. A job or an approval of a
 * workflow step names its run too: the workflow filter applies to it, and
 * its deliveries keep the order of the run's thread.
 */
class NotificationRefsTest extends DbTestCase
{
    use TeamScopeFixtures;

    public function testAJobOfNoRunNamesTheJobOnly(): void
    {
        $job = $this->job();

        $this->assertSame(['job_id' => (int)$job->id], NotificationRefs::ofJob($job));
    }

    /**
     * The job of a workflow step names the run of the step that records it.
     */
    public function testAJobOfAWorkflowStepNamesItsRun(): void
    {
        $job = $this->job();
        $run = $this->stepOf($job);

        $this->assertSame(['workflow_job_id' => (int)$run->id, 'job_id' => (int)$job->id], NotificationRefs::ofJob($job));
    }

    /**
     * The launch of a step's job announces it before the step records the
     * job: the caller names the run.
     */
    public function testTheRunTheCallerNamesIsTheRunOfTheJob(): void
    {
        $job = $this->job();

        $this->assertSame(['workflow_job_id' => 4711, 'job_id' => (int)$job->id], NotificationRefs::ofJob($job, 4711));
    }

    /**
     * An approval request names itself, its job and the run of the job's
     * step; the run the caller names wins, as for a job.
     */
    public function testAnApprovalNamesItsRequestItsJobAndTheRunOfItsJob(): void
    {
        $job = $this->job(Job::STATUS_PENDING_APPROVAL);
        $request = $this->requestOf($job);

        $this->assertSame(['job_id' => (int)$job->id, 'approval_request_id' => (int)$request->id], NotificationRefs::ofApproval($request, $job));
        $this->assertSame(
            ['workflow_job_id' => 4711, 'job_id' => (int)$job->id, 'approval_request_id' => (int)$request->id],
            NotificationRefs::ofApproval($request, $job, 4711)
        );
        $run = $this->stepOf($job);
        $this->assertSame(
            ['workflow_job_id' => (int)$run->id, 'job_id' => (int)$job->id, 'approval_request_id' => (int)$request->id],
            NotificationRefs::ofApproval($request, $job)
        );
    }

    /**
     * A request whose job is gone names itself only.
     */
    public function testAnApprovalWithoutItsJobNamesItsRequestOnly(): void
    {
        $request = $this->requestOf($this->job(Job::STATUS_PENDING_APPROVAL));

        $this->assertSame(['approval_request_id' => (int)$request->id], NotificationRefs::ofApproval($request, null));
    }

    /**
     * What the dispatcher takes of the refs a producer hands it: the IDs of
     * the refs it knows, as whole numbers above 0, in the order of
     * NotificationDelivery::REF_ATTRIBUTES; anything else is left out, so
     * that the event fails closed.
     */
    public function testOfTakesOnlyTheIdsOfKnownRefs(): void
    {
        $this->assertSame([], NotificationRefs::of(null));
        $this->assertSame([], NotificationRefs::of('job_id=5'));
        $this->assertSame(
            ['workflow_job_id' => 3, 'job_id' => 5, 'project_id' => 9],
            NotificationRefs::of([
                'project_id' => '9',
                'job_id' => 5,
                'workflow_job_id' => 3,
                'approval_request_id' => 0,
                'job_template_id' => 5.5,
                'unknown_id' => 7,
            ])
        );
        foreach ([-1, '0', '', '1e3', ' 4', true, [4], null, 1.0] as $value) {
            $this->assertSame([], NotificationRefs::of(['job_id' => $value]), var_export($value, true));
        }
    }

    /**
     * A job event's payload carries the job's refs under REFS.
     */
    public function testAJobPayloadCarriesTheRefsOfTheJob(): void
    {
        $job = $this->job();
        $run = $this->stepOf($job);

        $payload = JobPayloadBuilder::build($job);

        $this->assertSame(['workflow_job_id' => (int)$run->id, 'job_id' => (int)$job->id], $payload[NotificationDispatcher::REFS] ?? null);
        $this->assertSame(['workflow_job_id' => 4711, 'job_id' => (int)$job->id], JobPayloadBuilder::build($job, 4711)[NotificationDispatcher::REFS] ?? null);
    }

    // -- Helpers ----------------------------------------------------------------

    private function job(string $status = Job::STATUS_QUEUED): Job
    {
        $admin = $this->createUserWithRole('refs-admin', 'admin');
        $template = $this->createJobTemplate(
            (int)$this->createProject((int)$admin->id)->id,
            (int)$this->createInventory((int)$admin->id)->id,
            (int)$this->createRunnerGroup((int)$admin->id)->id,
            (int)$admin->id
        );

        return $this->createJob((int)$template->id, (int)$admin->id, $status);
    }

    /**
     * A running run whose job step records $job.
     */
    private function stepOf(Job $job): WorkflowJob
    {
        $workflow = $this->createWorkflowWithJobSteps((int)$job->launched_by, (int)$job->job_template_id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$job->launched_by);
        $step = WorkflowStep::findOne(['workflow_template_id' => $workflow->id]);
        $this->assertInstanceOf(WorkflowStep::class, $step);
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = (int)$step->id;
        $execution->job_id = (int)$job->id;
        $execution->status = WorkflowJobStep::STATUS_RUNNING;
        $execution->created_at = time();
        $execution->updated_at = time();
        $this->assertTrue($execution->save(false));

        return $run;
    }

    private function requestOf(Job $job): ApprovalRequest
    {
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $this->assertTrue($request->save(false));

        return $request;
    }
}
