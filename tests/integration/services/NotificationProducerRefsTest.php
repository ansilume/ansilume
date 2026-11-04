<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalDecision;
use app\models\Job;
use app\models\NotificationTemplate;
use app\models\Project;
use app\models\Schedule;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\JobCompletionService;
use app\services\ProjectService;
use app\services\ScheduleService;
use app\services\WorkflowRunEvents;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Every producer of a notification names the objects of its event, its
 * refs (NotificationRefs), which NotificationVisibility checks for each
 * template: a job event its job, an approval event its request and its
 * job, a project event its project, a schedule event its job template, a
 * workflow event its run; a job or an approval of a workflow step names
 * the step's run too. Without them a template's identity could not be
 * checked, and every event of a family would fail closed.
 */
class NotificationProducerRefsTest extends DbTestCase
{
    use TeamScopeFixtures;
    use WorkflowEngineFixtures;
    use CancellationFixtures;

    private RecordingNotificationDispatcher $notifications;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = $this->recordNotifications();
    }

    protected function tearDown(): void
    {
        $this->restoreNotifications();
        parent::tearDown();
    }

    public function testAJobLaunchNamesTheJob(): void
    {
        $user = $this->operator('refs-launch');

        $job = $this->launcher()->launch($this->openTemplate((int)$user->id), (int)$user->id);

        $this->assertSame([['job_id' => (int)$job->id]], $this->notifications->refsOf(NotificationTemplate::EVENT_JOB_LAUNCHED));
    }

    /**
     * The job of a job step is announced before the step records it: the
     * launch names the run it was launched for.
     */
    public function testTheJobOfAJobStepNamesItsRun(): void
    {
        $user = $this->operator('refs-step');
        $workflow = $this->createWorkflowWithJobSteps((int)$user->id, (int)$this->openTemplate((int)$user->id)->id);

        $run = $this->engine()->launch($workflow, (int)$user->id);

        $job = $this->onlyJobOf($run);
        $this->assertSame([['workflow_job_id' => (int)$run->id]], $this->notifications->refsOf(NotificationTemplate::EVENT_WORKFLOW_LAUNCHED));
        $this->assertSame(
            [['workflow_job_id' => (int)$run->id, 'job_id' => (int)$job->id]],
            $this->notifications->refsOf(NotificationTemplate::EVENT_JOB_LAUNCHED)
        );
    }

    /**
     * A gated launch names its request and its job; the vote that decides
     * it names them again.
     */
    public function testAGatedLaunchAndItsDecisionNameTheRequestAndTheJob(): void
    {
        $launcher = $this->operator('refs-gate-launcher');
        $approver = $this->operator('refs-gate-approver');
        $template = $this->gatedTemplate((int)$launcher->id, $this->usersRuleOf((int)$launcher->id, (int)$approver->id));

        $job = $this->launcher()->launch($template, (int)$launcher->id);
        $request = $this->requestOfJob($job);
        $this->approvalService()->recordDecision($request, (int)$approver->id, ApprovalDecision::DECISION_APPROVED);

        $refs = [['job_id' => (int)$job->id, 'approval_request_id' => (int)$request->id]];
        $this->assertSame($refs, $this->notifications->refsOf(NotificationTemplate::EVENT_APPROVAL_REQUESTED));
        $this->assertSame($refs, $this->notifications->refsOf(NotificationTemplate::EVENT_APPROVAL_APPROVED));
    }

    /**
     * The request of a gated job step is announced before the step records
     * its job: it names the run it was launched for; the rejection names it
     * too.
     */
    public function testTheRequestOfAGatedJobStepNamesItsRun(): void
    {
        $launcher = $this->operator('refs-gstep-launcher');
        $approver = $this->operator('refs-gstep-approver');
        $template = $this->gatedTemplate((int)$launcher->id, $this->usersRuleOf((int)$launcher->id, (int)$approver->id));
        $workflow = $this->createWorkflowWithJobSteps((int)$launcher->id, (int)$template->id);

        $run = $this->engine()->launch($workflow, (int)$launcher->id);
        $job = $this->onlyJobOf($run);
        $request = $this->requestOfJob($job);
        $this->approvalService()->recordDecision($request, (int)$approver->id, ApprovalDecision::DECISION_REJECTED);

        $refs = [['workflow_job_id' => (int)$run->id, 'job_id' => (int)$job->id, 'approval_request_id' => (int)$request->id]];
        $this->assertSame($refs, $this->notifications->refsOf(NotificationTemplate::EVENT_APPROVAL_REQUESTED));
        $this->assertSame($refs, $this->notifications->refsOf(NotificationTemplate::EVENT_APPROVAL_REJECTED));
    }

    /**
     * The request of an approval step names the run, its placeholder job
     * and itself.
     */
    public function testTheRequestOfAnApprovalStepNamesItsRun(): void
    {
        $launcher = $this->operator('refs-astep-launcher');
        $approver = $this->operator('refs-astep-approver');
        $workflow = $this->createWorkflowTemplate((int)$launcher->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$this->usersRuleOf((int)$launcher->id, (int)$approver->id)->id);

        $run = $this->engine()->launch($workflow, (int)$launcher->id);

        $placeholder = $this->onlyJobOf($run);
        $this->assertSame(
            [['workflow_job_id' => (int)$run->id, 'job_id' => (int)$placeholder->id, 'approval_request_id' => (int)$this->requestOfJob($placeholder)->id]],
            $this->notifications->refsOf(NotificationTemplate::EVENT_APPROVAL_REQUESTED)
        );
    }

    /**
     * A job that ends, by its runner's report or by a cancel, names itself
     * and the run of its step.
     */
    public function testAJobThatEndsNamesItselfAndTheRunOfItsStep(): void
    {
        $user = $this->operator('refs-end');
        $workflow = $this->createWorkflowWithJobSteps((int)$user->id, (int)$this->openTemplate((int)$user->id)->id);
        $run = $this->engine()->launch($workflow, (int)$user->id);
        $stepJob = $this->onlyJobOf($run);
        Job::updateAll(['status' => Job::STATUS_RUNNING], ['id' => $stepJob->id]);
        $stepJob->refresh();
        $loneJob = $this->launcher()->launch($this->openTemplate((int)$user->id), (int)$user->id);

        $this->completion()->complete($stepJob, 2);
        $this->jobCancellation()->cancel($loneJob, (int)$user->id);

        $this->assertSame(
            [['workflow_job_id' => (int)$run->id, 'job_id' => (int)$stepJob->id]],
            $this->notifications->refsOf(NotificationTemplate::EVENT_JOB_FAILED)
        );
        $this->assertSame([['job_id' => (int)$loneJob->id]], $this->notifications->refsOf(NotificationTemplate::EVENT_JOB_CANCELED));
    }

    /**
     * A workflow event names its run; a step's failure also the step's job.
     */
    public function testAWorkflowEventNamesItsRunAndAFailedStepItsJob(): void
    {
        $user = $this->operator('refs-run');
        $template = $this->openTemplate((int)$user->id);
        $workflow = $this->createWorkflowWithJobSteps((int)$user->id, (int)$template->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$user->id);
        $job = $this->createJob((int)$template->id, (int)$user->id, Job::STATUS_FAILED);
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = (int)WorkflowStep::find()->select('id')->where(['workflow_template_id' => $workflow->id])->scalar();
        $execution->job_id = (int)$job->id;
        $execution->status = WorkflowJobStep::STATUS_FAILED;
        $execution->created_at = time();
        $execution->updated_at = time();
        $execution->save(false);

        (new WorkflowRunEvents())->stepEnded($run, $execution, $job);
        (new WorkflowRunEvents())->notify(NotificationTemplate::EVENT_WORKFLOW_FAILED, $run);

        $this->assertSame(
            [['workflow_job_id' => (int)$run->id, 'job_id' => (int)$job->id]],
            $this->notifications->refsOf(NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED)
        );
        $this->assertSame([['workflow_job_id' => (int)$run->id]], $this->notifications->refsOf(NotificationTemplate::EVENT_WORKFLOW_FAILED));
    }

    public function testAProjectSyncNamesTheProject(): void
    {
        $project = $this->createProject((int)$this->createUser('refs-project')->id);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url = '';
        $project->status = Project::STATUS_SYNCING;
        $project->sync_started_at = time() - 5;
        $project->save(false);

        try {
            $this->projects()->sync($project);
            $this->fail('A git project without a repository URL cannot be synced.');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([['project_id' => (int)$project->id]], $this->notifications->refsOf(NotificationTemplate::EVENT_PROJECT_SYNC_FAILED));
    }

    /**
     * A schedule that could not launch names its job template, a deleted
     * one too.
     */
    public function testAScheduleThatDidNotLaunchNamesItsJobTemplate(): void
    {
        $user = $this->operator('refs-schedule');
        $template = $this->openTemplate((int)$user->id);
        $schedule = new Schedule();
        $schedule->name = 'refs-schedule-' . uniqid('', true);
        $schedule->job_template_id = (int)$template->id;
        $schedule->cron_expression = '0 2 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->next_run_at = time() - 60;
        $schedule->created_by = (int)$user->id;
        $schedule->save(false);
        $template->softDelete();

        $this->schedules()->runDue();

        $this->assertSame([['job_template_id' => (int)$template->id]], $this->notifications->refsOf(NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH));
    }

    // -- Helpers ----------------------------------------------------------------

    private function onlyJobOf(WorkflowJob $run): Job
    {
        $jobIds = WorkflowJobStep::find()->select('job_id')->where(['workflow_job_id' => $run->id])->column();
        $this->assertCount(1, $jobIds, 'the run started one step with a job');
        $job = Job::findOne((int)$jobIds[0]);
        $this->assertInstanceOf(Job::class, $job);

        return $job;
    }

    private function launcher(): \app\services\JobLaunchService
    {
        /** @var \app\services\JobLaunchService $launcher */
        $launcher = \Yii::$app->get('jobLaunchService');

        return $launcher;
    }

    private function completion(): JobCompletionService
    {
        /** @var JobCompletionService $completion */
        $completion = \Yii::$app->get('jobCompletionService');

        return $completion;
    }

    private function projects(): ProjectService
    {
        /** @var ProjectService $projects */
        $projects = \Yii::$app->get('projectService');

        return $projects;
    }

    private function schedules(): ScheduleService
    {
        /** @var ScheduleService $schedules */
        $schedules = \Yii::$app->get('scheduleService');

        return $schedules;
    }
}
