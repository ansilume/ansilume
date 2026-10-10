<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\TeamMember;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\JobLaunchService;
use app\services\WorkflowAccessDeniedException;
use app\services\WorkflowExecutionService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Regression: the workflow engine launched every job step as the launcher
 * without checking team access, so an operator could run another team's
 * playbooks, with that team's credentials and inventory, through a workflow.
 * Launch, resume and cancel now need operator access to every job step's
 * project, and each job step is checked again when it is dispatched.
 */
class WorkflowExecutionScopingTest extends DbTestCase
{
    use TeamScopeFixtures;

    /** @var list<array{0: string, 1: object}> */
    private array $swapped = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->swapped) as [$id, $original]) {
            \Yii::$app->set($id, $original);
        }
        $this->swapped = [];
        parent::tearDown();
    }

    private function service(): WorkflowExecutionService
    {
        /** @var WorkflowExecutionService $service */
        $service = \Yii::$app->get('workflowExecutionService');
        return $service;
    }

    // -- Launch -----------------------------------------------------------------

    public function testLaunchRefusesAWorkflowWithAForeignStep(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['foreign']->id);

        try {
            $this->service()->launch($workflow, $s['member']->id, [], 'api');
            $this->fail('expected a refusal');
        } catch (WorkflowAccessDeniedException $e) {
            $this->assertSame([], $e->jobTemplateIds, 'the member may not see the workflow: its templates are not named');
        }

        $this->assertSame(0, (int)WorkflowJob::find()->where(['workflow_template_id' => $workflow->id])->count(), 'nothing is created');
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => [$s['own']->id, $s['foreign']->id]])->count());
        /** @var AuditLog|null $audit */
        $audit = AuditLog::find()->where(['action' => AuditLog::ACTION_WORKFLOW_LAUNCH_DENIED, 'object_id' => $workflow->id])->one();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('"job_template_ids":[' . $s['foreign']->id . ']', (string)$audit->metadata);
    }

    public function testLaunchRefusesAStepTheTeamMayOnlyView(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['viewed']->id);

        $this->expectException(WorkflowAccessDeniedException::class);
        $this->service()->launch($workflow, $s['member']->id);
    }

    public function testTheOwningTeamAndAdminsLaunch(): void
    {
        $s = $this->teamScope();
        $own = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $foreign = $this->createWorkflowWithJobSteps($s['admin']->id, $s['foreign']->id);

        $memberRun = $this->service()->launch($own, $s['member']->id);
        $adminRun = $this->service()->launch($foreign, $s['admin']->id);

        $this->assertSame($s['member']->id, (int)$this->firstJobOf($memberRun)->launched_by);
        $this->assertSame($s['own']->id, (int)$this->firstJobOf($memberRun)->job_template_id);
        $this->assertSame($s['foreign']->id, (int)$this->firstJobOf($adminRun)->job_template_id);
        /** @var AuditLog $launched */
        $launched = AuditLog::find()->where(['action' => AuditLog::ACTION_WORKFLOW_LAUNCHED, 'object_id' => $memberRun->id])->one();
        $this->assertStringContainsString('"source":"web"', (string)$launched->metadata);
    }

    // -- Dispatch of later steps ------------------------------------------------

    /**
     * Access is checked again when a later step is dispatched: losing team
     * membership while step 1 runs stops step 2.
     */
    public function testALaterStepIsNotDispatchedOnceTheLauncherLostAccess(): void
    {
        $s = $this->teamScope();
        $second = $this->secondTemplateIn($s['own'], $s['admin']->id);
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $second->id);
        $run = $this->service()->launch($workflow, $s['member']->id);

        TeamMember::deleteAll(['user_id' => $s['member']->id]);
        $this->finishFirstJob($run);

        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $second->id])->count(), 'step 2 never launched');
        $denied = $this->stepOf($run, 1);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $denied->status);
        $this->assertNull($denied->job_id);
        $this->assertSame(
            "Not launched: user #{$s['member']->id}, whom this workflow runs as, may not launch job template \"{$second->name}\" (#{$second->id}).",
            $denied->error_message
        );
        /** @var AuditLog|null $audit */
        $audit = AuditLog::find()->where(['action' => AuditLog::ACTION_WORKFLOW_STEP_DENIED, 'object_id' => $denied->id])->one();
        $this->assertNotNull($audit);
        $this->assertSame($s['member']->id, (int)$audit->user_id);
        $this->assertStringContainsString('"job_template_id":' . $second->id, (string)$audit->metadata);
    }

    public function testADisabledLauncherStopsTheWorkflow(): void
    {
        $s = $this->teamScope();
        $second = $this->secondTemplateIn($s['own'], $s['admin']->id);
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $second->id);
        $run = $this->service()->launch($workflow, $s['member']->id);

        $s['member']->status = User::STATUS_INACTIVE;
        $s['member']->save(false);
        $this->finishFirstJob($run);

        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $second->id])->count());
    }

    public function testAMissingTemplateFailsTheStepWithAReason(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_JOB, 987654321);

        $run = $this->service()->launch($workflow, $s['admin']->id);

        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
        $this->assertSame('The job template of this step no longer exists.', $this->stepOf($run, 0)->error_message);
    }

    /**
     * Regression: an exception from JobLaunchService escaped and left the
     * step and the workflow running forever.
     */
    public function testALaunchErrorFailsTheStepInsteadOfLeavingItRunning(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->swap('jobLaunchService', new class extends JobLaunchService {
            public function launch(JobTemplate $template, int $launchedBy, array $overrides = []): Job
            {
                throw new \RuntimeException('Failed to create job: {"limit":["too long"]}');
            }
        });

        $run = $this->service()->launch($workflow, $s['member']->id);

        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
        $this->assertSame('The job could not be launched; see the application log.', $this->stepOf($run, 0)->error_message);
    }

    /**
     * Regression: an approval step without a rule failed the step but left
     * the workflow running forever.
     */
    public function testAnApprovalStepWithoutARuleFailsTheWorkflow(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_APPROVAL);

        $run = $this->service()->launch($workflow, $s['member']->id);

        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
        $this->assertSame('The approval rule of this step no longer exists.', $this->stepOf($run, 0)->error_message);
    }

    // -- Resume and cancel ------------------------------------------------------

    public function testResumeAndCancelNeedOperateAccessToEveryStep(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $this->createWorkflowStep($workflow->id, 1, WorkflowStep::TYPE_JOB, $s['own']->id);
        $run = $this->service()->launch($workflow, $s['admin']->id);

        foreach (['resume', 'cancel'] as $action) {
            try {
                $this->service()->{$action}($run, $s['outsider']->id);
                $this->fail("{$action} must be refused");
            } catch (WorkflowAccessDeniedException $e) {
                $this->assertSame('You may not operate this workflow.', $e->getMessage());
                $this->assertSame([], $e->jobTemplateIds, 'the outsider may not see the workflow');
            }
        }
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status, 'nothing changed');
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $this->stepOf($run, 0)->status);

        $this->service()->resume($run, $s['member']->id);
        $this->assertSame(WorkflowJobStep::STATUS_SUCCEEDED, $this->stepOf($run, 0)->status);
        $this->service()->cancel($run, $s['member']->id);
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $run->status);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function stateOfARunProvider(): array
    {
        return [
            'resume a finished run' => ['resume', WorkflowJob::STATUS_SUCCEEDED],
            'cancel a finished run' => ['cancel', WorkflowJob::STATUS_FAILED],
            'resume a run that waits at no pause step' => ['resume', WorkflowJob::STATUS_RUNNING],
        ];
    }

    /**
     * Regression: resume() and cancel() looked at the run's state before
     * checking access, so a user who may not even see the run learned that
     * it was finished, or not waiting at a pause step.
     *
     * @dataProvider stateOfARunProvider
     */
    public function testAUserWithoutAccessLearnsNothingAboutTheStateOfARun(string $action, string $status): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['foreign']->id);
        $run = $this->createWorkflowJob($workflow->id, $s['admin']->id, $status);

        try {
            $this->service()->{$action}($run, $s['member']->id);
            $this->fail("{$action} must be refused");
        } catch (WorkflowAccessDeniedException $e) {
            $this->assertSame('You may not operate this workflow.', $e->getMessage());
        }
        $run->refresh();
        $this->assertSame($status, $run->status, 'nothing changed');
    }

    // -- Steps that cannot start ---------------------------------------------
    //
    // A step that is refused at dispatch, whose job template was purged, or
    // an approval step without its rule fails the workflow without following
    // its branches: neither on_failure, on_always nor the default next step
    // runs, as the steps after it depend on a step that never ran.

    /**
     * Regression guard: these paths were only tested with the step last in
     * its workflow, where following the failure branch also ends it.
     */
    public function testARefusedStepDoesNotFollowItsFailureBranch(): void
    {
        $s = $this->teamScope();
        $second = $this->secondTemplateIn($s['own'], $s['admin']->id);
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $second->id, $s['open']->id);
        $branch = $this->stepAt($workflow, 2);
        $this->branch($this->stepAt($workflow, 1), 'on_failure_step_id', $branch);
        $run = $this->service()->launch($workflow, $s['member']->id);

        TeamMember::deleteAll(['user_id' => $s['member']->id]);
        $this->finishFirstJob($run);

        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $this->stepOf($run, 1)->status);
        $this->assertEndedBefore($run, $branch);
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $s['open']->id])->count(), 'the branch launched nothing');
    }

    public function testARefusedStepDoesNotGoOnToTheNextStep(): void
    {
        $s = $this->teamScope();
        $second = $this->secondTemplateIn($s['own'], $s['admin']->id);
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $second->id, $s['open']->id);
        $run = $this->service()->launch($workflow, $s['member']->id);

        TeamMember::deleteAll(['user_id' => $s['member']->id]);
        $this->finishFirstJob($run);

        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $this->stepOf($run, 1)->status);
        $this->assertEndedBefore($run, $this->stepAt($workflow, 2));
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $s['open']->id])->count());
    }

    public function testAStepWithAPurgedTemplateDoesNotFollowItsAlwaysBranch(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $purged = $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_JOB, 987654321);
        $this->createWorkflowStep($workflow->id, 1, WorkflowStep::TYPE_JOB, $s['open']->id);
        $pause = $this->createWorkflowStep($workflow->id, 2, WorkflowStep::TYPE_PAUSE);
        $this->branch($purged, 'on_always_step_id', $pause);

        $run = $this->service()->launch($workflow, $s['admin']->id);

        $this->assertSame('The job template of this step no longer exists.', $this->stepOf($run, 0)->error_message);
        $this->assertEndedBefore($run, $pause);
        $this->assertEndedBefore($run, $this->stepAt($workflow, 1));
    }

    public function testAStepWithAPurgedTemplateDoesNotGoOnToTheNextStep(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_JOB, 987654321);
        $next = $this->createWorkflowStep($workflow->id, 1, WorkflowStep::TYPE_JOB, $s['open']->id);

        $run = $this->service()->launch($workflow, $s['admin']->id);

        $this->assertEndedBefore($run, $next);
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $s['open']->id])->count());
    }

    public function testAnApprovalStepWithoutARuleDoesNotFollowItsFailureBranch(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $approval = $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_APPROVAL);
        $this->createWorkflowStep($workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $branch = $this->createWorkflowStep($workflow->id, 2, WorkflowStep::TYPE_JOB, $s['open']->id);
        $this->branch($approval, 'on_failure_step_id', $branch);

        $run = $this->service()->launch($workflow, $s['member']->id);

        $this->assertSame('The approval rule of this step no longer exists.', $this->stepOf($run, 0)->error_message);
        $this->assertEndedBefore($run, $branch);
        $this->assertEndedBefore($run, $this->stepAt($workflow, 1));
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $s['open']->id])->count());
    }

    /**
     * Regression: a step whose type is none of job, approval and pause, as
     * add-step saved a JSON true ("1"), matched no type, so nothing ended
     * it: the step and the run stayed running until someone canceled the
     * run, and resume() found no paused step.
     */
    public function testAStepOfAnUnknownTypeFailsTheWorkflow(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        // Saved without validation, as older versions stored it.
        $unknown = $this->createWorkflowStep($workflow->id, 0, '1');
        $next = $this->createWorkflowStep($workflow->id, 1, WorkflowStep::TYPE_JOB, $s['open']->id);
        $this->branch($unknown, 'on_failure_step_id', $next);

        $run = $this->service()->launch($workflow, $s['member']->id);

        $step = $this->stepOf($run, 0);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $step->status);
        $this->assertSame('Not run: unknown step type "1".', $step->error_message);
        $this->assertNotNull($step->finished_at);
        $this->assertEndedBefore($run, $next);
        $this->assertSame((int)$unknown->id, (int)$run->current_step_id, 'the run ended at that step');
        $this->assertNotNull($run->finished_at);
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $s['open']->id])->count());
    }

    // -- Helpers ----------------------------------------------------------------

    private function swap(string $id, object $service): void
    {
        $this->swapped[] = [$id, \Yii::$app->get($id)];
        \Yii::$app->set($id, $service);
    }

    private function secondTemplateIn(JobTemplate $sibling, int $createdBy): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$sibling->project_id,
            (int)$sibling->inventory_id,
            (int)$sibling->runner_group_id,
            $createdBy
        );
    }

    private function stepAt(WorkflowTemplate $workflow, int $order): WorkflowStep
    {
        /** @var WorkflowStep|null $step */
        $step = WorkflowStep::findOne(['workflow_template_id' => $workflow->id, 'step_order' => $order]);
        $this->assertNotNull($step);
        return $step;
    }

    /**
     * Point $field (on_failure_step_id, on_always_step_id) of $from at $to.
     */
    private function branch(WorkflowStep $from, string $field, WorkflowStep $to): void
    {
        $from->setAttribute($field, $to->id);
        $from->save(false);
    }

    /**
     * The run failed, and $notRun never ran.
     */
    private function assertEndedBefore(WorkflowJob $run, WorkflowStep $notRun): void
    {
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
        $this->assertNull(
            WorkflowJobStep::findOne(['workflow_job_id' => $run->id, 'workflow_step_id' => $notRun->id]),
            "step {$notRun->step_order} never ran"
        );
    }

    private function stepOf(WorkflowJob $run, int $order): WorkflowJobStep
    {
        /** @var WorkflowStep $step */
        $step = WorkflowStep::find()->where(['workflow_template_id' => $run->workflow_template_id, 'step_order' => $order])->one();
        /** @var WorkflowJobStep|null $wjs */
        $wjs = WorkflowJobStep::findOne(['workflow_job_id' => $run->id, 'workflow_step_id' => $step->id]);
        $this->assertNotNull($wjs, "step {$order} ran");
        return $wjs;
    }

    private function firstJobOf(WorkflowJob $run): Job
    {
        /** @var Job|null $job */
        $job = Job::findOne($this->stepOf($run, 0)->job_id);
        $this->assertNotNull($job);
        return $job;
    }

    private function finishFirstJob(WorkflowJob $run): void
    {
        $job = $this->firstJobOf($run);
        $job->status = Job::STATUS_SUCCEEDED;
        $job->finished_at = time();
        $job->save(false);
        $this->service()->onChildJobCompleted($job);
    }
}
