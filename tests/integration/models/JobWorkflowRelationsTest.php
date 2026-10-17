<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\ApprovalService;
use app\tests\integration\CountsQueries;
use app\tests\integration\DbTestCase;

/**
 * Job::getApprovalRequest() and Job::getWorkflowJobStep(): the approval
 * request a job waits or waited for, and the workflow step execution it was
 * created for. The rows are written the way the workflow engine and the
 * launch write them: the placeholder of an approval step has no job template
 * and holds the step's request; the job of a job step whose template has an
 * approval rule has both; a job of a job template outside any workflow has
 * neither. Both relations load eagerly with one query each, whatever the
 * number of jobs.
 */
class JobWorkflowRelationsTest extends DbTestCase
{
    use CountsQueries;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('job-relations')->id;
    }

    public function testThePlaceholderOfAnApprovalStepHoldsTheStepsRequestAndBelongsToItsExecution(): void
    {
        $flow = $this->approvalStepWaiting();

        $job = $this->reload($flow['job']);

        $this->assertNull($job->job_template_id, 'a placeholder has no job template');
        $this->assertInstanceOf(ApprovalRequest::class, $job->approvalRequest);
        $this->assertSame((int)$flow['request']->id, (int)$job->approvalRequest->id);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $job->approvalRequest->status);
        $this->assertInstanceOf(WorkflowJobStep::class, $job->workflowJobStep);
        $this->assertSame((int)$flow['execution']->id, (int)$job->workflowJobStep->id);
        $this->assertTrue($job->workflowJobStep->isApprovalPlaceholder());
        $this->assertSame((int)$flow['run']->id, (int)$job->workflowJobStep->workflow_job_id);
    }

    public function testTheJobOfAGatedJobStepHasItsRequestAndItsExecution(): void
    {
        $flow = $this->gatedJobStepWaiting();

        $job = $this->reload($flow['job']);

        $this->assertNotNull($job->job_template_id);
        $this->assertSame((int)$flow['request']->id, (int)$job->approvalRequest?->id);
        $this->assertInstanceOf(WorkflowJobStep::class, $job->workflowJobStep);
        $this->assertSame((int)$flow['execution']->id, (int)$job->workflowJobStep->id);
        $this->assertFalse($job->workflowJobStep->isApprovalPlaceholder(), 'the job of a job step is a real job');
    }

    public function testTheJobOfAJobStepWithoutAnApprovalRuleHasItsExecutionOnly(): void
    {
        $template = $this->template();
        $step = $this->createWorkflowStep((int)$this->createWorkflowTemplate($this->userId)->id, 10, WorkflowStep::TYPE_JOB, (int)$template->id);
        $execution = $this->executionOf($step, $this->createJob((int)$template->id, $this->userId, Job::STATUS_RUNNING));

        $job = $this->reload($this->jobOf($execution));

        $this->assertNull($job->approvalRequest);
        $this->assertSame((int)$execution->id, (int)$job->workflowJobStep?->id);
    }

    public function testAJobOfAGatedTemplateOutsideAnyWorkflowHasItsRequestOnly(): void
    {
        $rule = $this->createApprovalRule($this->userId);
        $template = $this->template($rule);
        $outside = $this->createJob((int)$template->id, $this->userId, Job::STATUS_PENDING_APPROVAL);
        $request = $this->approvals()->openRequest($outside, $rule, ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE);

        $job = $this->reload($outside);

        $this->assertSame((int)$request->id, (int)$job->approvalRequest?->id);
        $this->assertNull($job->workflowJobStep);
    }

    public function testAJobOfATemplateOutsideAnyWorkflowHasNeither(): void
    {
        $job = $this->reload($this->createJob((int)$this->template()->id, $this->userId));

        $this->assertNull($job->approvalRequest);
        $this->assertNull($job->workflowJobStep);
    }

    /**
     * The execution stays in the run's history when its step is removed
     * from the workflow, and so does the link from its job.
     */
    public function testTheExecutionOfARemovedStepStaysTheJobs(): void
    {
        $flow = $this->approvalStepWaiting();
        $this->assertSame(1, $flow['step']->delete());

        $job = $this->reload($flow['job']);

        $this->assertInstanceOf(WorkflowJobStep::class, $job->workflowJobStep);
        $this->assertSame((int)$flow['execution']->id, (int)$job->workflowJobStep->id);
        $this->assertNull($job->workflowJobStep->workflow_step_id);
        $this->assertSame($flow['step']->name, $job->workflowJobStep->stepName(), 'the snapshot keeps the step');
        $this->assertTrue($job->workflowJobStep->isApprovalPlaceholder());
        $this->assertSame((int)$flow['request']->id, (int)$job->approvalRequest?->id);
    }

    public function testBothRelationsLoadEagerlyWithOneQueryEachWhateverTheNumberOfJobs(): void
    {
        $few = $this->jobsOfEveryKind();
        $many = $this->jobsOfEveryKind() + $this->jobsOfEveryKind() + $this->jobsOfEveryKind();
        $this->loadEagerly(array_keys($few));

        $this->assertSame(3, $this->queriesOf(fn () => $this->loadEagerly(array_keys($few))), 'the jobs, then each relation once');
        $this->assertSame(3, $this->queriesOf(fn () => $this->loadEagerly(array_keys($many))), 'the same for three times the jobs');

        $loaded = [];
        foreach ($this->loadEagerly(array_keys($many)) as $job) {
            $this->assertTrue($job->isRelationPopulated('approvalRequest'));
            $this->assertTrue($job->isRelationPopulated('workflowJobStep'));
            $loaded[(int)$job->id] = [
                'request' => $job->approvalRequest === null ? null : (int)$job->approvalRequest->id,
                'execution' => $job->workflowJobStep === null ? null : (int)$job->workflowJobStep->id,
            ];
        }
        ksort($loaded);
        ksort($many);
        $this->assertSame($many, $loaded, 'each job gets its own request and execution, or none');
    }

    // -- Helpers -------------------------------------------------------------

    /**
     * A run of a workflow whose only step is an approval step, waiting at it:
     * the execution, and its placeholder job with the step's request, as
     * WorkflowStepDispatcher::approvalStep() writes them.
     *
     * @return array{step: WorkflowStep, run: WorkflowJob, execution: WorkflowJobStep, job: Job, request: ApprovalRequest}
     */
    private function approvalStepWaiting(): array
    {
        $rule = $this->createApprovalRule($this->userId);
        $step = $this->createWorkflowStep(
            (int)$this->createWorkflowTemplate($this->userId)->id,
            10,
            WorkflowStep::TYPE_APPROVAL,
            null,
            (int)$rule->id
        );
        $placeholder = new Job();
        $placeholder->job_template_id = null;
        $placeholder->launched_by = $this->userId;
        $placeholder->status = Job::STATUS_PENDING_APPROVAL;
        $placeholder->timeout_minutes = 0;
        $placeholder->has_changes = 0;
        $placeholder->save(false);
        $execution = $this->executionOf($step, $placeholder);

        return [
            'step' => $step,
            'run' => $this->runOf($execution),
            'execution' => $execution,
            'job' => $placeholder,
            'request' => $this->approvals()->openRequest($placeholder, $rule, ApprovalRequest::REQUIRED_BY_APPROVAL_STEP),
        ];
    }

    /**
     * A run of a workflow whose only step is a job step on a job template
     * with an approval rule, waiting for the approval of the step's job.
     *
     * @return array{run: WorkflowJob, execution: WorkflowJobStep, job: Job, request: ApprovalRequest}
     */
    private function gatedJobStepWaiting(): array
    {
        $rule = $this->createApprovalRule($this->userId);
        $template = $this->template($rule);
        $step = $this->createWorkflowStep((int)$this->createWorkflowTemplate($this->userId)->id, 10, WorkflowStep::TYPE_JOB, (int)$template->id);
        $job = $this->createJob((int)$template->id, $this->userId, Job::STATUS_PENDING_APPROVAL);
        $execution = $this->executionOf($step, $job);

        return [
            'run' => $this->runOf($execution),
            'execution' => $execution,
            'job' => $job,
            'request' => $this->approvals()->openRequest($job, $rule, ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE),
        ];
    }

    /**
     * One job of each kind, by job ID: the request and execution each one
     * has (their IDs), or null.
     *
     * @return array<int, array{request: int|null, execution: int|null}>
     */
    private function jobsOfEveryKind(): array
    {
        $jobs = [];
        foreach ([$this->approvalStepWaiting(), $this->gatedJobStepWaiting()] as $flow) {
            $jobs[(int)$flow['job']->id] = ['request' => (int)$flow['request']->id, 'execution' => (int)$flow['execution']->id];
        }
        $template = $this->template();
        $step = $this->createWorkflowStep((int)$this->createWorkflowTemplate($this->userId)->id, 10, WorkflowStep::TYPE_JOB, (int)$template->id);
        $execution = $this->executionOf($step, $this->createJob((int)$template->id, $this->userId, Job::STATUS_RUNNING));
        $jobs[(int)$execution->job_id] = ['request' => null, 'execution' => (int)$execution->id];
        $outside = $this->createJob((int)$template->id, $this->userId);
        $jobs[(int)$outside->id] = ['request' => null, 'execution' => null];

        return $jobs;
    }

    /**
     * @param list<int> $jobIds
     * @return list<Job>
     */
    private function loadEagerly(array $jobIds): array
    {
        /** @var list<Job> $jobs */
        $jobs = Job::find()->where(['id' => $jobIds])->with(['approvalRequest', 'workflowJobStep'])->orderBy(['id' => SORT_ASC])->all();
        $this->assertCount(count($jobIds), $jobs);

        return $jobs;
    }

    /**
     * A running execution of $step, in a new run of its workflow, linked to
     * $job, as WorkflowExecutionService::executeStep() and the dispatcher
     * write it.
     */
    private function executionOf(WorkflowStep $step, Job $job): WorkflowJobStep
    {
        $run = new WorkflowJob();
        $run->workflow_template_id = (int)$step->workflow_template_id;
        $run->launched_by = $this->userId;
        $run->status = WorkflowJob::STATUS_RUNNING;
        $run->current_step_id = null;
        $run->started_at = time();
        $run->save(false);

        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = (int)$step->id;
        $execution->snapshotStep($step);
        $execution->status = WorkflowJobStep::STATUS_RUNNING;
        $execution->started_at = time();
        $execution->job_id = (int)$job->id;
        $execution->save(false);

        return $execution;
    }

    private function runOf(WorkflowJobStep $execution): WorkflowJob
    {
        $run = WorkflowJob::findOne((int)$execution->workflow_job_id);
        $this->assertNotNull($run);

        return $run;
    }

    private function jobOf(WorkflowJobStep $execution): Job
    {
        $job = Job::findOne((int)$execution->job_id);
        $this->assertNotNull($job);

        return $job;
    }

    private function reload(Job $job): Job
    {
        $fresh = Job::findOne((int)$job->id);
        $this->assertNotNull($fresh);

        return $fresh;
    }

    private function template(?ApprovalRule $rule = null): JobTemplate
    {
        $template = $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
        if ($rule !== null) {
            $template->approval_rule_id = (int)$rule->id;
            $template->save(false);
        }

        return $template;
    }

    private function approvals(): ApprovalService
    {
        /** @var ApprovalService $approvals */
        $approvals = \Yii::$app->get('approvalService');

        return $approvals;
    }
}
