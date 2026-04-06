<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\Job;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\tests\integration\DbTestCase;

class WorkflowJobStepTest extends DbTestCase
{
    /**
     * @return array{0: \app\models\User, 1: WorkflowJob, 2: WorkflowStep}
     */
    private function buildWorkflowFixture(): array
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);
        $wfTemplate = $this->createWorkflowTemplate($user->id);
        $wfStep = $this->createWorkflowStep((int)$wfTemplate->id, 1, WorkflowStep::TYPE_JOB, (int)$tpl->id);

        $wfJob = new WorkflowJob();
        $wfJob->workflow_template_id = $wfTemplate->id;
        $wfJob->launched_by = $user->id;
        $wfJob->status = WorkflowJob::STATUS_RUNNING;
        $wfJob->save(false);

        return [$user, $wfJob, $wfStep];
    }

    private function createWorkflowJobStep(WorkflowJob $wfJob, WorkflowStep $wfStep, string $status = WorkflowJobStep::STATUS_PENDING): WorkflowJobStep
    {
        $step = new WorkflowJobStep();
        $step->workflow_job_id = $wfJob->id;
        $step->workflow_step_id = $wfStep->id;
        $step->status = $status;
        $step->save(false);
        return $step;
    }

    public function testTableName(): void
    {
        $this->assertSame('{{%workflow_job_step}}', WorkflowJobStep::tableName());
    }

    public function testPersistAndRetrieve(): void
    {
        [$user, $wfJob, $wfStep] = $this->buildWorkflowFixture();
        $step = $this->createWorkflowJobStep($wfJob, $wfStep);

        $this->assertNotNull($step->id);
        $reloaded = WorkflowJobStep::findOne($step->id);
        $this->assertNotNull($reloaded);
        $this->assertSame($wfJob->id, $reloaded->workflow_job_id);
        $this->assertSame($wfStep->id, $reloaded->workflow_step_id);
        $this->assertSame(WorkflowJobStep::STATUS_PENDING, $reloaded->status);
    }

    public function testIsFinishedForTerminalStatuses(): void
    {
        [$user, $wfJob, $wfStep] = $this->buildWorkflowFixture();

        foreach ([WorkflowJobStep::STATUS_SUCCEEDED, WorkflowJobStep::STATUS_FAILED, WorkflowJobStep::STATUS_SKIPPED] as $status) {
            $step = $this->createWorkflowJobStep($wfJob, $wfStep, $status);
            $this->assertTrue($step->isFinished(), "Expected isFinished() to be true for status: $status");
        }
    }

    public function testIsFinishedForNonTerminalStatuses(): void
    {
        [$user, $wfJob, $wfStep] = $this->buildWorkflowFixture();

        foreach ([WorkflowJobStep::STATUS_PENDING, WorkflowJobStep::STATUS_RUNNING] as $status) {
            $step = $this->createWorkflowJobStep($wfJob, $wfStep, $status);
            $this->assertFalse($step->isFinished(), "Expected isFinished() to be false for status: $status");
        }
    }

    public function testGetParsedOutputVarsEmptyReturnsEmptyArray(): void
    {
        $step = new WorkflowJobStep();
        $step->output_vars = null;
        $this->assertSame([], $step->getParsedOutputVars());

        $step->output_vars = '';
        $this->assertSame([], $step->getParsedOutputVars());
    }

    public function testGetParsedOutputVarsWithValidJson(): void
    {
        $step = new WorkflowJobStep();
        $step->output_vars = '{"key":"val","count":42}';
        $parsed = $step->getParsedOutputVars();
        $this->assertSame('val', $parsed['key']);
        $this->assertSame(42, $parsed['count']);
    }

    public function testGetParsedOutputVarsWithInvalidJson(): void
    {
        $step = new WorkflowJobStep();
        $step->output_vars = '{not valid json';
        $this->assertSame([], $step->getParsedOutputVars());
    }

    public function testStatusLabelForAllStatuses(): void
    {
        $expected = [
            WorkflowJobStep::STATUS_PENDING => 'Pending',
            WorkflowJobStep::STATUS_RUNNING => 'Running',
            WorkflowJobStep::STATUS_SUCCEEDED => 'Succeeded',
            WorkflowJobStep::STATUS_FAILED => 'Failed',
            WorkflowJobStep::STATUS_SKIPPED => 'Skipped',
        ];
        foreach ($expected as $status => $label) {
            $this->assertSame($label, WorkflowJobStep::statusLabel($status));
        }
    }

    public function testStatusCssClassForAllStatuses(): void
    {
        $expected = [
            WorkflowJobStep::STATUS_PENDING => 'secondary',
            WorkflowJobStep::STATUS_RUNNING => 'primary',
            WorkflowJobStep::STATUS_SUCCEEDED => 'success',
            WorkflowJobStep::STATUS_FAILED => 'danger',
            WorkflowJobStep::STATUS_SKIPPED => 'info',
        ];
        foreach ($expected as $status => $cssClass) {
            $this->assertSame($cssClass, WorkflowJobStep::statusCssClass($status));
        }
    }

    public function testStatusLabelDefault(): void
    {
        $this->assertSame('unknown-status', WorkflowJobStep::statusLabel('unknown-status'));
    }

    public function testStatusCssClassDefault(): void
    {
        $this->assertSame('secondary', WorkflowJobStep::statusCssClass('unknown-status'));
    }

    public function testWorkflowJobRelation(): void
    {
        [$user, $wfJob, $wfStep] = $this->buildWorkflowFixture();
        $step = $this->createWorkflowJobStep($wfJob, $wfStep);

        $reloaded = WorkflowJobStep::findOne($step->id);
        $this->assertNotNull($reloaded);
        $this->assertInstanceOf(WorkflowJob::class, $reloaded->workflowJob);
        $this->assertSame($wfJob->id, $reloaded->workflowJob->id);
    }

    public function testWorkflowStepRelation(): void
    {
        [$user, $wfJob, $wfStep] = $this->buildWorkflowFixture();
        $step = $this->createWorkflowJobStep($wfJob, $wfStep);

        $reloaded = WorkflowJobStep::findOne($step->id);
        $this->assertNotNull($reloaded);
        $this->assertInstanceOf(WorkflowStep::class, $reloaded->workflowStep);
        $this->assertSame($wfStep->id, $reloaded->workflowStep->id);
    }

    public function testJobRelationWhenSet(): void
    {
        [$user, $wfJob, $wfStep] = $this->buildWorkflowFixture();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);
        $job = $this->createJob((int)$tpl->id, $user->id);

        $step = $this->createWorkflowJobStep($wfJob, $wfStep);
        $step->job_id = $job->id;
        $step->save(false);

        $reloaded = WorkflowJobStep::findOne($step->id);
        $this->assertNotNull($reloaded);
        $this->assertInstanceOf(Job::class, $reloaded->job);
        $this->assertSame($job->id, $reloaded->job->id);
    }
}
