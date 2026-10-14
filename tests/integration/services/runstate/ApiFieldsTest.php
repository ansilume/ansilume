<?php

declare(strict_types=1);

namespace app\tests\integration\services\runstate;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\runstate\ApiFields;
use app\services\runstate\Viewer;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The API arrays of runs, their steps and the approval requests they wait
 * for (contract C13): the stored fields stay as they were, and waiting_for,
 * current_step, job_status and approval are added.
 */
class ApiFieldsTest extends DbTestCase
{
    use TeamScopeFixtures;
    use RunStateFixtures;

    private User $admin;

    private WorkflowTemplate $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('af-admin', 'admin');
        $this->workflow = $this->createWorkflowTemplate((int)$this->admin->id);
    }

    public function testARunSaysWhatItWaitsForAndWhereItStands(): void
    {
        $approver = $this->namedUser('af-approver', 'operator');
        $rule = $this->ruleOf((int)$this->admin->id, [$approver]);
        $approval = $this->namedStep($this->workflow, 10, 'sign off', WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $run = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);
        $placeholder = $this->jobOf(null, (int)$this->admin->id, Job::STATUS_PENDING_APPROVAL);
        $this->requestOf($placeholder, $rule);
        $execution = $this->executionOf($run, $approval, 'running', $placeholder);
        $state = $this->runStates()->forRuns([$run], Viewer::of((int)$this->admin->id))[(int)$run->id];

        $this->assertSame([
            'id' => $run->id,
            'workflow_template_id' => $run->workflow_template_id,
            'status' => WorkflowJob::STATUS_RUNNING,
            'launched_by' => $run->launched_by,
            'started_at' => $run->started_at,
            'finished_at' => null,
            'created_at' => $run->created_at,
            'waiting_for' => 'approval',
            'current_step' => [
                'id' => (int)$execution->id,
                'workflow_step_id' => (int)$approval->id,
                'step_name' => 'sign off',
                'step_type' => WorkflowStep::TYPE_APPROVAL,
            ],
        ], ApiFields::run($run, $state));
    }

    public function testAFinishedRunWaitsForNothingAndHasNoCurrentStep(): void
    {
        $run = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id, WorkflowJob::STATUS_CANCELED);
        $state = $this->runStates()->forRuns([$run], Viewer::of((int)$this->admin->id))[(int)$run->id];

        $fields = ApiFields::run($run, $state);

        $this->assertSame(WorkflowJob::STATUS_CANCELED, $fields['status']);
        $this->assertNull($fields['waiting_for']);
        $this->assertNull($fields['current_step']);
    }

    public function testTheRunDetailListsEveryStepWithItsJobAndApproval(): void
    {
        $approver = $this->namedUser('af-approver', 'operator');
        $launcher = $this->namedUser('af-launcher', 'operator');
        $rule = $this->ruleOf((int)$this->admin->id, [$approver], 1, 60, ApprovalRule::TIMEOUT_ACTION_REJECT);
        $templateId = (int)$this->openJobTemplate((int)$this->admin->id)->id;
        $build = $this->namedStep($this->workflow, 10, 'build', WorkflowStep::TYPE_JOB, $templateId);
        $approval = $this->namedStep($this->workflow, 20, 'sign off', WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $run = $this->createWorkflowJob((int)$this->workflow->id, (int)$launcher->id);
        $run->setLaunchExtraVars(['env' => 'prod']);
        $run->save(false);
        $buildJob = $this->jobOf($templateId, (int)$launcher->id, Job::STATUS_SUCCEEDED, 1760000160);
        $first = $this->executionOf($run, $build, 'succeeded', $buildJob);
        $placeholder = $this->jobOf(null, (int)$launcher->id, Job::STATUS_PENDING_APPROVAL);
        $request = $this->requestOf($placeholder, $rule, ApprovalRequest::STATUS_PENDING, 1760000150, 1760003750);
        $this->voteOn($request, $launcher, ApprovalDecision::DECISION_APPROVED);
        $second = $this->executionOf($run, $approval, 'running', $placeholder);

        $detail = ApiFields::runDetail($run, $this->runStates()->forRun($run, Viewer::of((int)$this->admin->id)));

        $this->assertSame('approval', $detail['waiting_for']);
        $this->assertSame((int)$second->id, $detail['current_step']['id']);
        $this->assertEquals((object)['env' => 'prod'], $detail['extra_vars']);
        $this->assertSame([
            [
                'id' => (int)$first->id,
                'workflow_step_id' => (int)$build->id,
                'step_name' => 'build',
                'step_type' => WorkflowStep::TYPE_JOB,
                'job_id' => (int)$buildJob->id,
                'status' => 'succeeded',
                'started_at' => 1760000100,
                'finished_at' => 1760000200,
                'error' => null,
                'waiting_for' => null,
                'job_status' => Job::STATUS_SUCCEEDED,
                'approval_request_id' => null,
                'approval' => null,
            ],
            [
                'id' => (int)$second->id,
                'workflow_step_id' => (int)$approval->id,
                'step_name' => 'sign off',
                'step_type' => WorkflowStep::TYPE_APPROVAL,
                'job_id' => (int)$placeholder->id,
                'status' => 'running',
                'started_at' => 1760000100,
                'finished_at' => null,
                'error' => null,
                'waiting_for' => 'approval',
                'job_status' => Job::STATUS_PENDING_APPROVAL,
                'approval_request_id' => (int)$request->id,
                'approval' => [
                    'id' => (int)$request->id,
                    'status' => ApprovalRequest::STATUS_PENDING,
                    'approval_rule_id' => (int)$rule->id,
                    'approval_rule_name' => $rule->name,
                    'approval_count' => 0,
                    'rejection_count' => 0,
                    'required_approvals' => 1,
                    'requested_at' => 1760000150,
                    'expires_at' => 1760003750,
                    'resolved_at' => null,
                    'timeout_action' => ApprovalRule::TIMEOUT_ACTION_REJECT,
                    'eligible_count' => 1,
                    'open_count' => 1,
                    'can_still_be_approved' => true,
                ],
            ],
        ], $detail['steps']);
    }

    public function testARunWithoutLaunchVarsHasNone(): void
    {
        $run = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id, WorkflowJob::STATUS_SUCCEEDED);

        $detail = ApiFields::runDetail($run, $this->runStates()->forRun($run, Viewer::of((int)$this->admin->id)));

        $this->assertNull($detail['extra_vars']);
        $this->assertSame([], $detail['steps']);
        $this->assertNull($detail['current_step']);
    }

    /**
     * A step of a run that the caller may see, whose request the caller
     * may not see (its job template belongs to another team's project):
     * the request is left out of the step.
     */
    public function testAStepWhoseRequestTheCallerMayNotSeeHasNoApprovalFields(): void
    {
        $scope = $this->teamScope();
        $adminId = (int)$scope['admin']->id;
        $rule = $this->ruleOf($adminId, [$scope['outsider']]);
        $workflow = $this->createWorkflowWithJobSteps($adminId, (int)$scope['own']->id);
        $foreignStep = $this->namedStep($workflow, 5, 'old deploy', WorkflowStep::TYPE_JOB, (int)$scope['foreign']->id);
        $run = $this->createWorkflowJob((int)$workflow->id, $adminId, WorkflowJob::STATUS_FAILED);
        $job = $this->jobOf((int)$scope['foreign']->id, $adminId, Job::STATUS_REJECTED);
        $this->requestOf($job, $rule, ApprovalRequest::STATUS_REJECTED, 1760000150, null, 1760000300);
        $this->executionOf($run, $foreignStep, 'failed', $job, 'The approval was rejected.');
        $foreignStep->delete();

        $step = ApiFields::runDetail($run, $this->runStates()->forRun($run, Viewer::of((int)$scope['member']->id)))['steps'][0];

        $this->assertNull($step['approval_request_id']);
        $this->assertNull($step['approval']);
        $this->assertSame(Job::STATUS_REJECTED, $step['job_status']);
        $this->assertSame('The approval was rejected.', $step['error']);
        $this->assertNull($step['workflow_step_id'], 'the step was removed since');
        $this->assertSame('old deploy', $step['step_name']);
    }

    public function testNoApprovalIsNull(): void
    {
        $this->assertNull(ApiFields::approval(null));
    }
}
