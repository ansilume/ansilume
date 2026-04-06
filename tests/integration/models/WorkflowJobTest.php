<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\tests\integration\DbTestCase;

class WorkflowJobTest extends DbTestCase
{
    private function createWorkflowJob(int $workflowTemplateId, int $launchedBy, string $status = WorkflowJob::STATUS_RUNNING): WorkflowJob
    {
        $wfJob = new WorkflowJob();
        $wfJob->workflow_template_id = $workflowTemplateId;
        $wfJob->launched_by = $launchedBy;
        $wfJob->status = $status;
        $wfJob->save(false);
        return $wfJob;
    }

    public function testTableName(): void
    {
        $this->assertSame('{{%workflow_job}}', WorkflowJob::tableName());
    }

    public function testPersistAndRetrieve(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);
        $wfJob = $this->createWorkflowJob((int)$wt->id, $user->id);

        $this->assertNotNull($wfJob->id);
        $reloaded = WorkflowJob::findOne($wfJob->id);
        $this->assertNotNull($reloaded);
        $this->assertSame($wt->id, $reloaded->workflow_template_id);
        $this->assertSame($user->id, $reloaded->launched_by);
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $reloaded->status);
    }

    public function testStatuses(): void
    {
        $statuses = WorkflowJob::statuses();
        $this->assertCount(4, $statuses);
        $this->assertContains(WorkflowJob::STATUS_RUNNING, $statuses);
        $this->assertContains(WorkflowJob::STATUS_SUCCEEDED, $statuses);
        $this->assertContains(WorkflowJob::STATUS_FAILED, $statuses);
        $this->assertContains(WorkflowJob::STATUS_CANCELED, $statuses);
    }

    public function testIsFinishedForTerminalStatuses(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);

        foreach ([WorkflowJob::STATUS_SUCCEEDED, WorkflowJob::STATUS_FAILED, WorkflowJob::STATUS_CANCELED] as $status) {
            $wfJob = $this->createWorkflowJob((int)$wt->id, $user->id, $status);
            $this->assertTrue($wfJob->isFinished(), "Expected isFinished() to be true for status: $status");
        }
    }

    public function testIsFinishedForNonTerminal(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);
        $wfJob = $this->createWorkflowJob((int)$wt->id, $user->id, WorkflowJob::STATUS_RUNNING);

        $this->assertFalse($wfJob->isFinished());
    }

    public function testStatusLabelForAllStatuses(): void
    {
        $expected = [
            WorkflowJob::STATUS_RUNNING => 'Running',
            WorkflowJob::STATUS_SUCCEEDED => 'Succeeded',
            WorkflowJob::STATUS_FAILED => 'Failed',
            WorkflowJob::STATUS_CANCELED => 'Canceled',
        ];
        foreach ($expected as $status => $label) {
            $this->assertSame($label, WorkflowJob::statusLabel($status));
        }
    }

    public function testStatusCssClassForAllStatuses(): void
    {
        $expected = [
            WorkflowJob::STATUS_RUNNING => 'primary',
            WorkflowJob::STATUS_SUCCEEDED => 'success',
            WorkflowJob::STATUS_FAILED => 'danger',
            WorkflowJob::STATUS_CANCELED => 'warning',
        ];
        foreach ($expected as $status => $cssClass) {
            $this->assertSame($cssClass, WorkflowJob::statusCssClass($status));
        }
    }

    public function testStatusLabelDefault(): void
    {
        $this->assertSame('unknown-status', WorkflowJob::statusLabel('unknown-status'));
    }

    public function testStatusCssClassDefault(): void
    {
        $this->assertSame('secondary', WorkflowJob::statusCssClass('unknown-status'));
    }

    public function testWorkflowTemplateRelation(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);
        $wfJob = $this->createWorkflowJob((int)$wt->id, $user->id);

        $reloaded = WorkflowJob::findOne($wfJob->id);
        $this->assertNotNull($reloaded);
        $this->assertInstanceOf(WorkflowTemplate::class, $reloaded->workflowTemplate);
        $this->assertSame($wt->id, $reloaded->workflowTemplate->id);
    }

    public function testLauncherRelation(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);
        $wfJob = $this->createWorkflowJob((int)$wt->id, $user->id);

        $reloaded = WorkflowJob::findOne($wfJob->id);
        $this->assertNotNull($reloaded);
        $this->assertInstanceOf(User::class, $reloaded->launcher);
        $this->assertSame($user->id, $reloaded->launcher->id);
    }

    public function testStepExecutionsRelation(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);
        $wfStep = $this->createWorkflowStep((int)$wt->id, 1);
        $wfJob = $this->createWorkflowJob((int)$wt->id, $user->id);

        $jobStep = new WorkflowJobStep();
        $jobStep->workflow_job_id = $wfJob->id;
        $jobStep->workflow_step_id = $wfStep->id;
        $jobStep->status = WorkflowJobStep::STATUS_PENDING;
        $jobStep->save(false);

        $reloaded = WorkflowJob::findOne($wfJob->id);
        $this->assertNotNull($reloaded);
        $steps = $reloaded->stepExecutions;
        $this->assertCount(1, $steps);
        $this->assertInstanceOf(WorkflowJobStep::class, $steps[0]);
        $this->assertSame($jobStep->id, $steps[0]->id);
    }

    public function testCurrentStepRelation(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);
        $wfStep = $this->createWorkflowStep((int)$wt->id, 1);
        $wfJob = $this->createWorkflowJob((int)$wt->id, $user->id);
        $wfJob->current_step_id = $wfStep->id;
        $wfJob->save(false);

        $reloaded = WorkflowJob::findOne($wfJob->id);
        $this->assertNotNull($reloaded);
        $this->assertInstanceOf(WorkflowStep::class, $reloaded->currentStep);
        $this->assertSame($wfStep->id, $reloaded->currentStep->id);
    }
}
