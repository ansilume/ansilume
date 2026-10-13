<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ApprovalController;
use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\models\WorkflowStep;
use app\services\ScheduleService;
use app\tests\integration\services\ApprovalVoteFixtures;
use app\tests\integration\services\CancellationFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\web\Response;

/**
 * approval.requested names the user who launched the job: the launcher
 * asks for the approval, also when the launch ran in another user's request
 * (a workflow step started by an approver's vote) or in no request at all
 * (a schedule), with the real services.
 */
class ApprovalRequestedAuditTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;
    use ApprovalVoteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordNotifications();
    }

    protected function tearDown(): void
    {
        $this->restoreNotifications();
        parent::tearDown();
    }

    /**
     * Regression: approval.requested of a workflow step named the approver
     * of the previous step. The audit entry took the signed-in user: when
     * approver A approved the run's approval step in the web UI, the engine
     * started the next step, a job step whose template is gated, inside A's
     * request, and its approval.requested named A, although L launched the
     * run. If A then approved that request too, the log showed A both asking
     * for and granting the approval.
     */
    public function testRegressionApprovalRequestedOfAWorkflowStepNamedTheApproverOfThePreviousStep(): void
    {
        $launcher = $this->approverUser('requested-launcher');
        $approver = $this->approverUser('requested-approver');
        $second = $this->approverUser('requested-second');
        $template = $this->voteTemplate((int)$launcher->id);
        $template->approval_rule_id = (int)$this->listRule((int)$launcher->id, [$approver, $second])->id;
        $template->save(false);
        $workflow = $this->createWorkflowTemplate((int)$launcher->id);
        $gate = $this->createWorkflowStep(
            (int)$workflow->id,
            0,
            WorkflowStep::TYPE_APPROVAL,
            null,
            (int)$this->listRule((int)$launcher->id, [$approver])->id
        );
        $gatedJob = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$template->id);
        $gate->on_success_step_id = (int)$gatedJob->id;
        $gate->save(false);
        $run = $this->workflowEngine()->launch($workflow, (int)$launcher->id);
        $gateRequest = $this->requestOfStep($run, $gate);

        $this->loginAs($approver);
        $this->webController()->actionApprove((int)$gateRequest->id);

        $this->assertSame('Approval recorded.', \Yii::$app->session->getFlash('success'));
        $jobRequest = $this->requestOfStep($run, $gatedJob);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $jobRequest->status, 'the gated job step waits for its own approval');
        $requested = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_REQUESTED, 'approval_request', (int)$jobRequest->id);
        $this->assertSame((int)$launcher->id, (int)$requested->user_id, 'the launcher asks, not the approver whose vote started the step');
        $decided = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_DECIDED, 'approval_request', (int)$gateRequest->id);
        $this->assertSame((int)$approver->id, (int)$decided->user_id);
    }

    /**
     * A scheduled launch has no session: approval.requested names the
     * user the schedule runs as (it named nobody).
     */
    public function testAScheduledLaunchWithoutASessionRecordsTheUserTheScheduleRunsAs(): void
    {
        $owner = $this->approverUser('requested-owner');
        $template = $this->voteTemplate((int)$owner->id);
        $template->approval_rule_id = (int)$this->listRule((int)$owner->id, [$this->approverUser('requested-schedule-approver')])->id;
        $template->save(false);
        $this->dueSchedule($template, $owner);
        $this->assertTrue(\Yii::$app->user->isGuest, 'no session');

        /** @var ScheduleService $schedules */
        $schedules = \Yii::$app->get('scheduleService');
        $this->assertSame(1, $schedules->runDue());

        $job = Job::findOne(['job_template_id' => $template->id]);
        $this->assertNotNull($job);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $job->status);
        $request = ApprovalRequest::findOne(['job_id' => $job->id]);
        $this->assertNotNull($request);
        $requested = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_REQUESTED, 'approval_request', (int)$request->id);
        $this->assertSame((int)$owner->id, (int)$requested->user_id);
    }

    /**
     * Every request a launch opens is attributed that way, whoever is
     * signed in.
     */
    public function testARequestOpenedWhileAnotherUserIsSignedInNamesTheLauncher(): void
    {
        $this->loginAs($this->approverUser('requested-other'));

        ['request' => $request, 'launcher' => $launcher] = $this->waitingRequest([$this->approverUser('requested-plain-approver')]);

        $requested = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_REQUESTED, 'approval_request', (int)$request->id);
        $this->assertSame((int)$launcher->id, (int)$requested->user_id, 'not the signed-in user');
    }

    // -- Helpers ----------------------------------------------------------------------

    private function requestOfStep(\app\models\WorkflowJob $run, WorkflowStep $step): ApprovalRequest
    {
        $execution = $this->executionOf($run, $step);
        $this->assertNotNull($execution, "step {$step->name} ran");
        $request = ApprovalRequest::findOne(['job_id' => $execution->job_id]);
        $this->assertNotNull($request, "step {$step->name} has its approval request");

        return $request;
    }

    private function dueSchedule(JobTemplate $template, User $owner): Schedule
    {
        $schedule = new Schedule();
        $schedule->name = 'requested-audit-schedule';
        $schedule->job_template_id = (int)$template->id;
        $schedule->cron_expression = '* * * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->next_run_at = time() - 60;
        $schedule->created_by = (int)$owner->id;
        $schedule->created_at = time();
        $schedule->updated_at = time();
        $this->assertTrue($schedule->save(false));

        return $schedule;
    }

    private function webController(): ApprovalController
    {
        $app = \Yii::$app;
        $this->assertNotNull($app);

        return new class ('approval', $app) extends ApprovalController {
            /**
             * @param array<int|string, mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };
    }
}
