<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\Job;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\tests\integration\DbTestCase;

/**
 * The step executions of a run outlive the steps they ran (migration m000084:
 * workflow_job_step.workflow_step_id is set to NULL when its step is
 * deleted), and keep the step's name and type.
 *
 * Regression: the foreign key deleted them with the step (ON DELETE
 * CASCADE). Replacing a workflow's steps through the API (PUT with steps)
 * or removing one on the web deleted the executions of every run, finished
 * or running: finished runs lost their history, running runs their state.
 */
class WorkflowJobStepHistoryTest extends DbTestCase
{
    /**
     * Regression: removing a step (web remove-step) deleted its executions
     * from every run.
     */
    public function testRemovingAStepKeepsItsExecutionsInEveryRun(): void
    {
        [$workflow, $steps, $userId] = $this->workflowWithSteps();
        $finished = $this->createRun($workflow, $userId, WorkflowJob::STATUS_SUCCEEDED);
        $first = $this->execution($finished, $steps['deploy'], WorkflowJobStep::STATUS_SUCCEEDED);
        $second = $this->execution($finished, $steps['approve'], WorkflowJobStep::STATUS_SUCCEEDED);
        $active = $this->createRun($workflow, $userId, WorkflowJob::STATUS_RUNNING);
        $running = $this->execution($active, $steps['deploy'], WorkflowJobStep::STATUS_RUNNING);

        $this->assertSame(1, $steps['deploy']->delete());

        foreach ([$first, $running] as $execution) {
            $kept = WorkflowJobStep::findOne($execution->id);
            $this->assertNotNull($kept, 'the execution of the removed step is kept');
            $this->assertNull($kept->workflow_step_id);
            $this->assertNull($kept->workflowStep);
            $this->assertSame('deploy', $kept->stepName());
            $this->assertSame(WorkflowStep::TYPE_JOB, $kept->stepType());
            $this->assertSame($execution->status, $kept->status);
        }
        $other = WorkflowJobStep::findOne($second->id);
        $this->assertNotNull($other);
        $this->assertSame($steps['approve']->id, $other->workflow_step_id, 'the other step\'s execution is untouched');
        $this->assertSame([$first->id, $second->id], array_map(
            static fn (WorkflowJobStep $s): int => (int)$s->id,
            $this->reloadRun($finished)->stepExecutions
        ));
    }

    /**
     * Regression: replacing the steps (API PUT with steps, which deletes all
     * of them and inserts new ones) deleted the history of every run.
     */
    public function testReplacingAllStepsKeepsTheHistoryOfEveryRun(): void
    {
        [$workflow, $steps, $userId] = $this->workflowWithSteps();
        $run = $this->createRun($workflow, $userId, WorkflowJob::STATUS_FAILED);
        $job = $this->createJob((int)$steps['deploy']->job_template_id, $userId, Job::STATUS_FAILED);
        $deploy = $this->execution($run, $steps['deploy'], WorkflowJobStep::STATUS_FAILED, (int)$job->id);
        $approve = $this->execution($run, $steps['approve'], WorkflowJobStep::STATUS_FAILED);

        WorkflowStep::deleteAll(['workflow_template_id' => $workflow->id]);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);

        $history = $this->reloadRun($run)->stepExecutions;
        $this->assertCount(2, $history, 'both executions are kept');
        $this->assertSame([$deploy->id, $approve->id], array_map(static fn (WorkflowJobStep $s): int => (int)$s->id, $history));
        $this->assertSame(['deploy', 'approve'], array_map(static fn (WorkflowJobStep $s): string => $s->stepName(), $history));
        $this->assertSame(
            [WorkflowStep::TYPE_JOB, WorkflowStep::TYPE_APPROVAL],
            array_map(static fn (WorkflowJobStep $s): ?string => $s->stepType(), $history)
        );
        $this->assertSame([null, null], array_map(static fn (WorkflowJobStep $s): ?int => $s->workflow_step_id, $history));
        $this->assertSame($job->id, $history[0]->job_id, 'the job of the step stays linked');
        $this->assertTrue($history[1]->isApprovalPlaceholder(), 'the type survives for the approval logic');
    }

    /**
     * Deleting the run still deletes its executions.
     */
    public function testDeletingTheRunDeletesItsExecutions(): void
    {
        [$workflow, $steps, $userId] = $this->workflowWithSteps();
        $run = $this->createRun($workflow, $userId, WorkflowJob::STATUS_SUCCEEDED);
        $execution = $this->execution($run, $steps['deploy'], WorkflowJobStep::STATUS_SUCCEEDED);

        $this->assertSame(1, $run->delete());

        $this->assertNull(WorkflowJobStep::findOne($execution->id));
        $this->assertNotNull(WorkflowStep::findOne($steps['deploy']->id), 'the step stays');
    }

    /**
     * @return array{0: WorkflowTemplate, 1: array{deploy: WorkflowStep, approve: WorkflowStep}, 2: int}
     */
    private function workflowWithSteps(): array
    {
        $userId = (int)$this->createUser('wjs_history')->id;
        $template = $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
        $rule = $this->createApprovalRule($userId);
        $workflow = $this->createWorkflowTemplate($userId);
        $deploy = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$template->id);
        $deploy->name = 'deploy';
        $deploy->save(false, ['name']);
        $approve = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $approve->name = 'approve';
        $approve->save(false, ['name']);

        return [$workflow, ['deploy' => $deploy, 'approve' => $approve], $userId];
    }

    private function createRun(WorkflowTemplate $workflow, int $userId, string $status): WorkflowJob
    {
        $run = new WorkflowJob();
        $run->workflow_template_id = (int)$workflow->id;
        $run->launched_by = $userId;
        $run->status = $status;
        $run->started_at = time() - 60;
        $run->finished_at = $status === WorkflowJob::STATUS_RUNNING ? null : time();
        $this->assertTrue($run->save(false));

        return $run;
    }

    private function execution(WorkflowJob $run, WorkflowStep $step, string $status, ?int $jobId = null): WorkflowJobStep
    {
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = (int)$step->id;
        $execution->job_id = $jobId;
        $execution->status = $status;
        $execution->started_at = time() - 50;
        $this->assertTrue($execution->save());

        return $execution;
    }

    private function reloadRun(WorkflowJob $run): WorkflowJob
    {
        $reloaded = WorkflowJob::findOne($run->id);
        $this->assertNotNull($reloaded);

        return $reloaded;
    }
}
