<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\components\AppLinks;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\NotificationDispatcher;
use app\services\notification\RunContext;
use app\services\notification\TemplateRenderer;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The context of a workflow run that its notifications carry (RunContext):
 * the run (workflow.*) and the step an event is about (step.*), every value
 * a string, built when the event happens.
 */
class RunContextTest extends DbTestCase
{
    use TeamScopeFixtures;

    private User $user;

    private WorkflowTemplate $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createUserWithRole('run-context', 'admin');
        $this->workflow = $this->createWorkflowTemplate((int)$this->user->id);
    }

    /**
     * steps_done counts the steps of the workflow that ended in the run,
     * whatever their outcome, each once however often a loop ran it; a step
     * that runs, or waits, has not ended. steps_total is the steps the
     * workflow has.
     */
    public function testStepsDoneCountsEachStepThatEndedOnce(): void
    {
        $build = $this->createWorkflowStep((int)$this->workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $check = $this->createWorkflowStep((int)$this->workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $ship = $this->createWorkflowStep((int)$this->workflow->id, 2, WorkflowStep::TYPE_PAUSE);
        $this->createWorkflowStep((int)$this->workflow->id, 3, WorkflowStep::TYPE_PAUSE);
        $run = $this->newRun();
        $this->execution($run, $build, WorkflowJobStep::STATUS_SUCCEEDED);
        $this->execution($run, $build, WorkflowJobStep::STATUS_FAILED);
        $this->execution($run, $check, WorkflowJobStep::STATUS_SKIPPED);
        $newest = $this->execution($run, $ship, WorkflowJobStep::STATUS_RUNNING);

        $workflow = RunContext::ofRun($run)->payload()['workflow'];

        $this->assertSame(['steps_done' => '2', 'steps_total' => '4'], ['steps_done' => $workflow['steps_done'], 'steps_total' => $workflow['steps_total']]);
        $this->assertSame((string)$newest->id, RunContext::ofRun($run)->payload()['step']['execution_id'] ?? null, 'a run event names its newest step');
    }

    /**
     * A step removed from the workflow while the run ran keeps the name and
     * the type it had (its snapshot), and has no position and no step ID
     * any more; it no longer counts among the workflow's steps.
     */
    public function testARemovedStepKeepsItsNameAndTypeAndHasNoPosition(): void
    {
        $kept = $this->createWorkflowStep((int)$this->workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $removed = $this->createWorkflowStep((int)$this->workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $run = $this->newRun();
        $this->execution($run, $kept, WorkflowJobStep::STATUS_SUCCEEDED);
        $execution = $this->execution($run, $removed, WorkflowJobStep::STATUS_FAILED, 'This step was removed from the workflow while it ran, so the run cannot go on.');
        WorkflowStep::deleteAll(['id' => $removed->id]);
        $execution->refresh();

        $context = RunContext::of($run, $execution)->payload();

        $this->assertSame([
            'id' => (string)$execution->id,
            'job_id' => '',
            'execution_id' => (string)$execution->id,
            'workflow_step_id' => '',
            'position' => '',
            'name' => 'step-1',
            'type' => 'pause',
            'waiting_for' => '',
        ], $context['step'] ?? null);
        $this->assertSame('This step was removed from the workflow while it ran, so the run cannot go on.', $context['workflow']['reason']);
        $this->assertSame(['steps_done' => '1', 'steps_total' => '1'], ['steps_done' => $context['workflow']['steps_done'], 'steps_total' => $context['workflow']['steps_total']]);
    }

    /**
     * A run that has no step yet (an event of the whole run, such as its
     * launch) carries the run only; its workflow template, deleted since,
     * is still named.
     */
    public function testARunWithoutAStepCarriesTheRunOnly(): void
    {
        $this->createWorkflowStep((int)$this->workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $run = $this->newRun();
        $this->workflow->softDelete();

        $context = RunContext::ofRun(WorkflowJob::findOne($run->id) ?? $run);

        $this->assertNull($context->execution);
        $this->assertNull($context->waitingFor);
        $this->assertNull($context->approvalRequestId);
        $this->assertSame(['workflow'], array_keys($context->payload()));
        $this->assertSame([
            'id' => (string)$run->id,
            'status' => WorkflowJob::STATUS_RUNNING,
            'template_id' => (string)$this->workflow->id,
            'template_name' => (string)$this->workflow->name,
            'url' => $this->runUrl($run),
            'steps_done' => '0',
            'steps_total' => '1',
            'reason' => '',
        ], $context->payload()['workflow']);

        $stepless = $this->createWorkflowJob((int)$this->createWorkflowTemplate((int)$this->user->id)->id, (int)$this->user->id);
        $counts = RunContext::ofRun($stepless)->payload()['workflow'];
        $this->assertSame(
            ['steps_done' => '0', 'steps_total' => '0'],
            ['steps_done' => $counts['steps_done'], 'steps_total' => $counts['steps_total']],
            'a workflow whose steps were all removed'
        );
    }

    /**
     * The context of a job of a run is the step that records the job; a
     * job of no run, and of a run that is gone, has none. A job the run's
     * step has not recorded yet belongs to the step that launches it, the
     * run's newest running step without a job; without one, the run alone.
     */
    public function testAJobBelongsToTheStepThatRecordsIt(): void
    {
        $template = $this->openJobTemplate();
        $step = $this->createWorkflowStep((int)$this->workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$template->id);
        $run = $this->newRun();
        $recorded = $this->createJob((int)$template->id, (int)$this->user->id, Job::STATUS_RUNNING);
        $execution = $this->execution($run, $step, WorkflowJobStep::STATUS_RUNNING, null, $recorded);
        $lone = $this->createJob((int)$template->id, (int)$this->user->id);

        $ofRecorded = RunContext::ofJob((int)$recorded->id, (int)$run->id);
        $this->assertNotNull($ofRecorded);
        $this->assertSame((int)$execution->id, $ofRecorded->execution?->id);
        $this->assertSame((int)$recorded->id, $ofRecorded->jobId);
        $this->assertSame('', $ofRecorded->payload()['step']['waiting_for'] ?? null, 'its job runs');
        $this->assertNull(RunContext::ofJob((int)$lone->id, null), 'a job of no run');
        $this->assertNull(RunContext::ofJob((int)$lone->id, 987654321), 'a run that is gone');
        $context = RunContext::ofJob((int)$lone->id, (int)$run->id);
        $this->assertNotNull($context);
        $this->assertNull($context->execution, 'the run has no running step without a job');
        $this->assertSame(['workflow'], array_keys($context->payload()));
    }

    /**
     * Every variable of the context is one of the run's (workflow.*,
     * step.*): the dispatcher leaves exactly those out for an identity that
     * may not see the run's workflow (RUN_CONTEXT_PREFIXES).
     */
    public function testEveryVariableIsOneOfTheRunsContext(): void
    {
        $step = $this->createWorkflowStep((int)$this->workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $run = $this->newRun();
        $execution = $this->execution($run, $step, WorkflowJobStep::STATUS_RUNNING);

        $variables = (new TemplateRenderer())->buildVariables(RunContext::of($run, $execution)->payload());
        unset($variables['timestamp'], $variables['app.url']);

        $this->assertCount(16, $variables);
        foreach ($variables as $name => $value) {
            $this->assertIsString($value, $name);
            $this->assertTrue(
                str_starts_with($name, NotificationDispatcher::RUN_CONTEXT_PREFIXES[0]) || str_starts_with($name, NotificationDispatcher::RUN_CONTEXT_PREFIXES[1]),
                $name
            );
        }
    }

    /**
     * without() leaves out exactly the run's context: every variable of the
     * context, and no other, also none whose name only begins like one;
     * the order of the others stays.
     */
    public function testWithoutLeavesOutTheRunsContextOnly(): void
    {
        $step = $this->createWorkflowStep((int)$this->workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $run = $this->newRun();
        $execution = $this->execution($run, $step, WorkflowJobStep::STATUS_RUNNING);
        $others = [
            'event' => 'job.failed',
            'job.id' => '7',
            'workflows' => 'x',
            'stepper.name' => 'y',
            'template.workflow.name' => 'z',
            '123' => 'a number for a name',
        ];
        $context = (new TemplateRenderer())->buildVariables(RunContext::of($run, $execution)->payload());
        unset($context['timestamp'], $context['app.url']);

        $this->assertSame($others, RunContext::without($others + $context));
        $this->assertSame([], RunContext::without($context));
        $this->assertSame($others, RunContext::without($others));
    }

    // -- Helpers ------------------------------------------------------------------------

    private function newRun(): WorkflowJob
    {
        return $this->createWorkflowJob((int)$this->workflow->id, (int)$this->user->id);
    }

    private function runUrl(WorkflowJob $run): string
    {
        return AppLinks::base() . '/workflow-job/view?id=' . $run->id;
    }

    private function openJobTemplate(): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject((int)$this->user->id)->id,
            (int)$this->createInventory((int)$this->user->id)->id,
            (int)$this->createRunnerGroup((int)$this->user->id)->id,
            (int)$this->user->id
        );
    }

    /** An execution of $step in $run, with its snapshot, in $status. */
    private function execution(WorkflowJob $run, WorkflowStep $step, string $status, ?string $error = null, ?Job $job = null): WorkflowJobStep
    {
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = (int)$step->id;
        $execution->snapshotStep($step);
        $execution->job_id = $job === null ? null : (int)$job->id;
        $execution->status = $status;
        $execution->error_message = $error;
        $execution->started_at = time();
        $execution->created_at = time();
        $execution->updated_at = time();
        $this->assertTrue($execution->save(false));

        return $execution;
    }
}
