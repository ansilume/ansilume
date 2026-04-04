<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Job;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\WorkflowExecutionService;
use app\tests\integration\DbTestCase;

class WorkflowExecutionServiceTest extends DbTestCase
{
    private function service(): WorkflowExecutionService
    {
        /** @var WorkflowExecutionService $s */
        $s = \Yii::$app->get('workflowExecutionService');
        return $s;
    }

    /**
     * @return array{0: \app\models\User, 1: \app\models\JobTemplate}
     */
    private function scaffoldTemplate(): array
    {
        $user = $this->createUser('wf');
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        return [$user, $template];
    }

    public function testLaunchCreatesWorkflowJob(): void
    {
        [$user, $jt] = $this->scaffoldTemplate();
        $wt = $this->createWorkflowTemplate($user->id);
        $this->createWorkflowStep($wt->id, 0, WorkflowStep::TYPE_JOB, $jt->id);

        $wfJob = $this->service()->launch($wt, $user->id);

        $this->assertSame(WorkflowJob::STATUS_RUNNING, $wfJob->status);
        $this->assertSame($wt->id, $wfJob->workflow_template_id);
        $this->assertSame($user->id, $wfJob->launched_by);
        $this->assertNotNull($wfJob->started_at);
    }

    public function testLaunchThrowsForEmptyWorkflow(): void
    {
        $user = $this->createUser('wf_empty');
        $wt = $this->createWorkflowTemplate($user->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no steps');
        $this->service()->launch($wt, $user->id);
    }

    public function testLaunchCreatesFirstStepExecution(): void
    {
        [$user, $jt] = $this->scaffoldTemplate();
        $wt = $this->createWorkflowTemplate($user->id);
        $step = $this->createWorkflowStep($wt->id, 0, WorkflowStep::TYPE_JOB, $jt->id);

        $wfJob = $this->service()->launch($wt, $user->id);

        $steps = WorkflowJobStep::findAll(['workflow_job_id' => $wfJob->id]);
        $this->assertCount(1, $steps);
        $this->assertSame($step->id, $steps[0]->workflow_step_id);
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $steps[0]->status);
        $this->assertNotNull($steps[0]->job_id);
    }

    public function testChildJobCompletionAdvancesWorkflow(): void
    {
        [$user, $jt] = $this->scaffoldTemplate();
        $wt = $this->createWorkflowTemplate($user->id);
        $step1 = $this->createWorkflowStep($wt->id, 0, WorkflowStep::TYPE_JOB, $jt->id);
        $step2 = $this->createWorkflowStep($wt->id, 1, WorkflowStep::TYPE_JOB, $jt->id);
        $step1->on_success_step_id = $step2->id;
        $step1->save(false);

        $wfJob = $this->service()->launch($wt, $user->id);

        // Find the child job and complete it
        $wjs1 = WorkflowJobStep::findOne(['workflow_job_id' => $wfJob->id, 'workflow_step_id' => $step1->id]);
        $this->assertNotNull($wjs1);
        /** @var Job $childJob */
        $childJob = Job::findOne($wjs1->job_id);
        $childJob->status = Job::STATUS_SUCCEEDED;
        $childJob->finished_at = time();
        $childJob->save(false);

        $this->service()->onChildJobCompleted($childJob);

        // Step 1 should be succeeded, step 2 should be running
        $wjs1->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_SUCCEEDED, $wjs1->status);

        $wjs2 = WorkflowJobStep::findOne(['workflow_job_id' => $wfJob->id, 'workflow_step_id' => $step2->id]);
        $this->assertNotNull($wjs2);
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $wjs2->status);
    }

    public function testWorkflowCompletesOnLastStep(): void
    {
        [$user, $jt] = $this->scaffoldTemplate();
        $wt = $this->createWorkflowTemplate($user->id);
        $this->createWorkflowStep($wt->id, 0, WorkflowStep::TYPE_JOB, $jt->id);

        $wfJob = $this->service()->launch($wt, $user->id);

        $wjs = WorkflowJobStep::findOne(['workflow_job_id' => $wfJob->id]);
        $this->assertNotNull($wjs);
        /** @var Job $childJob */
        $childJob = Job::findOne($wjs->job_id);
        $childJob->status = Job::STATUS_SUCCEEDED;
        $childJob->finished_at = time();
        $childJob->save(false);

        $this->service()->onChildJobCompleted($childJob);

        $wfJob->refresh();
        $this->assertSame(WorkflowJob::STATUS_SUCCEEDED, $wfJob->status);
        $this->assertNotNull($wfJob->finished_at);
    }

    public function testFailedStepFollowsOnFailureBranch(): void
    {
        [$user, $jt] = $this->scaffoldTemplate();
        $wt = $this->createWorkflowTemplate($user->id);
        $step1 = $this->createWorkflowStep($wt->id, 0, WorkflowStep::TYPE_JOB, $jt->id);
        $step2 = $this->createWorkflowStep($wt->id, 1, WorkflowStep::TYPE_JOB, $jt->id);
        $stepFail = $this->createWorkflowStep($wt->id, 2, WorkflowStep::TYPE_JOB, $jt->id);
        $step1->on_success_step_id = $step2->id;
        $step1->on_failure_step_id = $stepFail->id;
        $step1->save(false);

        $wfJob = $this->service()->launch($wt, $user->id);

        $wjs1 = WorkflowJobStep::findOne(['workflow_job_id' => $wfJob->id, 'workflow_step_id' => $step1->id]);
        $this->assertNotNull($wjs1);
        /** @var Job $childJob */
        $childJob = Job::findOne($wjs1->job_id);
        $childJob->status = Job::STATUS_FAILED;
        $childJob->finished_at = time();
        $childJob->exit_code = 1;
        $childJob->save(false);

        $this->service()->onChildJobCompleted($childJob);

        // Should have followed on_failure, not on_success
        $wjsFail = WorkflowJobStep::findOne(['workflow_job_id' => $wfJob->id, 'workflow_step_id' => $stepFail->id]);
        $this->assertNotNull($wjsFail, 'Expected failure branch step to be created');

        $wjsSuccess = WorkflowJobStep::findOne(['workflow_job_id' => $wfJob->id, 'workflow_step_id' => $step2->id]);
        $this->assertNull($wjsSuccess, 'Success branch should not have been followed');
    }

    public function testWorkflowFailsOnLastStepFailure(): void
    {
        [$user, $jt] = $this->scaffoldTemplate();
        $wt = $this->createWorkflowTemplate($user->id);
        $this->createWorkflowStep($wt->id, 0, WorkflowStep::TYPE_JOB, $jt->id);

        $wfJob = $this->service()->launch($wt, $user->id);

        $wjs = WorkflowJobStep::findOne(['workflow_job_id' => $wfJob->id]);
        $this->assertNotNull($wjs);
        /** @var Job $childJob */
        $childJob = Job::findOne($wjs->job_id);
        $childJob->status = Job::STATUS_FAILED;
        $childJob->finished_at = time();
        $childJob->exit_code = 1;
        $childJob->save(false);

        $this->service()->onChildJobCompleted($childJob);

        $wfJob->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $wfJob->status);
    }

    public function testCancelStopsRunningWorkflow(): void
    {
        [$user, $jt] = $this->scaffoldTemplate();
        $wt = $this->createWorkflowTemplate($user->id);
        $this->createWorkflowStep($wt->id, 0, WorkflowStep::TYPE_JOB, $jt->id);

        $wfJob = $this->service()->launch($wt, $user->id);
        $this->service()->cancel($wfJob, $user->id);

        $wfJob->refresh();
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $wfJob->status);
        $this->assertNotNull($wfJob->finished_at);
    }

    public function testCancelThrowsForFinishedWorkflow(): void
    {
        [$user, $jt] = $this->scaffoldTemplate();
        $wt = $this->createWorkflowTemplate($user->id);
        $this->createWorkflowStep($wt->id, 0, WorkflowStep::TYPE_JOB, $jt->id);

        $wfJob = $this->service()->launch($wt, $user->id);
        $this->service()->cancel($wfJob, $user->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already finished');
        $this->service()->cancel($wfJob, $user->id);
    }

    public function testPauseStepStaysRunning(): void
    {
        $user = $this->createUser('wf_pause');
        $wt = $this->createWorkflowTemplate($user->id);
        $this->createWorkflowStep($wt->id, 0, WorkflowStep::TYPE_PAUSE);

        $wfJob = $this->service()->launch($wt, $user->id);

        $wjs = WorkflowJobStep::findOne(['workflow_job_id' => $wfJob->id]);
        $this->assertNotNull($wjs);
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $wjs->status);

        $wfJob->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $wfJob->status);
    }

    public function testMissingJobTemplateFailsStep(): void
    {
        $user = $this->createUser('wf_missing');
        $wt = $this->createWorkflowTemplate($user->id);
        // Step references a non-existent job template
        $this->createWorkflowStep($wt->id, 0, WorkflowStep::TYPE_JOB, 999999);

        $wfJob = $this->service()->launch($wt, $user->id);

        $wfJob->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $wfJob->status);
    }

    public function testOnAlwaysBranchTakesPriorityOverSuccess(): void
    {
        [$user, $jt] = $this->scaffoldTemplate();
        $wt = $this->createWorkflowTemplate($user->id);
        $step1 = $this->createWorkflowStep($wt->id, 0, WorkflowStep::TYPE_JOB, $jt->id);
        $stepSuccess = $this->createWorkflowStep($wt->id, 1, WorkflowStep::TYPE_JOB, $jt->id);
        $stepAlways = $this->createWorkflowStep($wt->id, 2, WorkflowStep::TYPE_JOB, $jt->id);
        $step1->on_success_step_id = $stepSuccess->id;
        $step1->on_always_step_id = $stepAlways->id;
        $step1->save(false);

        $wfJob = $this->service()->launch($wt, $user->id);

        $wjs1 = WorkflowJobStep::findOne([
            'workflow_job_id' => $wfJob->id,
            'workflow_step_id' => $step1->id,
        ]);
        $this->assertNotNull($wjs1);
        /** @var Job $childJob */
        $childJob = Job::findOne($wjs1->job_id);
        $childJob->status = Job::STATUS_SUCCEEDED;
        $childJob->finished_at = time();
        $childJob->save(false);

        $this->service()->onChildJobCompleted($childJob);

        // on_always should be followed, not on_success
        $wjsAlways = WorkflowJobStep::findOne([
            'workflow_job_id' => $wfJob->id,
            'workflow_step_id' => $stepAlways->id,
        ]);
        $this->assertNotNull($wjsAlways, 'on_always step should be created');

        $wjsSuccess = WorkflowJobStep::findOne([
            'workflow_job_id' => $wfJob->id,
            'workflow_step_id' => $stepSuccess->id,
        ]);
        $this->assertNull($wjsSuccess, 'on_success should not be followed when on_always exists');
    }

    public function testOnAlwaysBranchTakesPriorityOverFailure(): void
    {
        [$user, $jt] = $this->scaffoldTemplate();
        $wt = $this->createWorkflowTemplate($user->id);
        $step1 = $this->createWorkflowStep($wt->id, 0, WorkflowStep::TYPE_JOB, $jt->id);
        $stepFail = $this->createWorkflowStep($wt->id, 1, WorkflowStep::TYPE_JOB, $jt->id);
        $stepAlways = $this->createWorkflowStep($wt->id, 2, WorkflowStep::TYPE_JOB, $jt->id);
        $step1->on_failure_step_id = $stepFail->id;
        $step1->on_always_step_id = $stepAlways->id;
        $step1->save(false);

        $wfJob = $this->service()->launch($wt, $user->id);

        $wjs1 = WorkflowJobStep::findOne([
            'workflow_job_id' => $wfJob->id,
            'workflow_step_id' => $step1->id,
        ]);
        $this->assertNotNull($wjs1);
        /** @var Job $childJob */
        $childJob = Job::findOne($wjs1->job_id);
        $childJob->status = Job::STATUS_FAILED;
        $childJob->finished_at = time();
        $childJob->exit_code = 1;
        $childJob->save(false);

        $this->service()->onChildJobCompleted($childJob);

        $wjsAlways = WorkflowJobStep::findOne([
            'workflow_job_id' => $wfJob->id,
            'workflow_step_id' => $stepAlways->id,
        ]);
        $this->assertNotNull($wjsAlways, 'on_always step should be created on failure');
    }

    public function testCancelCancelsRunningChildJob(): void
    {
        [$user, $jt] = $this->scaffoldTemplate();
        $wt = $this->createWorkflowTemplate($user->id);
        $this->createWorkflowStep($wt->id, 0, WorkflowStep::TYPE_JOB, $jt->id);

        $wfJob = $this->service()->launch($wt, $user->id);

        $wjs = WorkflowJobStep::findOne(['workflow_job_id' => $wfJob->id]);
        $this->assertNotNull($wjs);
        $this->assertNotNull($wjs->job_id);

        $this->service()->cancel($wfJob, $user->id);

        /** @var Job $childJob */
        $childJob = Job::findOne($wjs->job_id);
        $this->assertSame(Job::STATUS_CANCELED, $childJob->status);
        $this->assertNotNull($childJob->finished_at);

        $wjs->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $wjs->status);
    }

    public function testVariablePassingBetweenSteps(): void
    {
        [$user, $jt] = $this->scaffoldTemplate();
        $wt = $this->createWorkflowTemplate($user->id);
        $step1 = $this->createWorkflowStep($wt->id, 0, WorkflowStep::TYPE_JOB, $jt->id);
        $step2 = $this->createWorkflowStep($wt->id, 1, WorkflowStep::TYPE_JOB, $jt->id);
        $step2->extra_vars_template = json_encode([
            'target_hosts' => 'hosts',
            'deploy_version' => 'version',
        ]) ?: null;
        $step2->save(false);
        $step1->on_success_step_id = $step2->id;
        $step1->save(false);

        $wfJob = $this->service()->launch($wt, $user->id);

        $wjs1 = WorkflowJobStep::findOne([
            'workflow_job_id' => $wfJob->id,
            'workflow_step_id' => $step1->id,
        ]);
        $this->assertNotNull($wjs1);

        // Simulate step completion with output vars
        $wjs1->output_vars = json_encode([
            'hosts' => 'web1,web2',
            'version' => '2.0.1',
        ]) ?: null;
        $wjs1->save(false);

        /** @var Job $childJob */
        $childJob = Job::findOne($wjs1->job_id);
        $childJob->status = Job::STATUS_SUCCEEDED;
        $childJob->finished_at = time();
        $childJob->save(false);

        $this->service()->onChildJobCompleted($childJob);

        // Step 2 should have been created with a child job that has extra vars
        $wjs2 = WorkflowJobStep::findOne([
            'workflow_job_id' => $wfJob->id,
            'workflow_step_id' => $step2->id,
        ]);
        $this->assertNotNull($wjs2);
        $this->assertNotNull($wjs2->job_id);

        /** @var Job $job2 */
        $job2 = Job::findOne($wjs2->job_id);
        $extraVars = json_decode((string)$job2->extra_vars, true);
        $this->assertIsArray($extraVars);
        $this->assertSame('web1,web2', $extraVars['target_hosts'] ?? null);
        $this->assertSame('2.0.1', $extraVars['deploy_version'] ?? null);
    }

    public function testOnChildJobCompletedIgnoresNonWorkflowJob(): void
    {
        [$user, $jt] = $this->scaffoldTemplate();
        $job = $this->createJob($jt->id, $user->id, Job::STATUS_SUCCEEDED);

        // Should be a no-op, not throw
        $this->service()->onChildJobCompleted($job);

        // Job should remain unchanged
        $job->refresh();
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);
    }

    public function testApprovalStepWithMissingRuleFailsStep(): void
    {
        $user = $this->createUser('wf_approval');
        $wt = $this->createWorkflowTemplate($user->id);
        // Approval step with no approval_rule_id
        $step = $this->createWorkflowStep($wt->id, 0, WorkflowStep::TYPE_APPROVAL);

        $wfJob = $this->service()->launch($wt, $user->id);

        $wjs = WorkflowJobStep::findOne([
            'workflow_job_id' => $wfJob->id,
            'workflow_step_id' => $step->id,
        ]);
        $this->assertNotNull($wjs);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $wjs->status);
    }

    public function testCompleteWorkflowSetsFinishedAt(): void
    {
        [$user, $jt] = $this->scaffoldTemplate();
        $wt = $this->createWorkflowTemplate($user->id);
        $this->createWorkflowStep($wt->id, 0, WorkflowStep::TYPE_JOB, $jt->id);

        $wfJob = $this->service()->launch($wt, $user->id);
        $this->assertNull($wfJob->finished_at);

        $this->service()->completeWorkflow($wfJob, WorkflowJob::STATUS_SUCCEEDED);

        $wfJob->refresh();
        $this->assertSame(WorkflowJob::STATUS_SUCCEEDED, $wfJob->status);
        $this->assertNotNull($wfJob->finished_at);
    }
}
