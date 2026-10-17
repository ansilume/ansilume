<?php

declare(strict_types=1);

namespace app\tests\integration\services\runstate;

use app\models\ApprovalRequest;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalService;
use app\services\runstate\ApprovalRequestContexts;
use app\services\runstate\RequestContext;
use app\services\runstate\Texts;
use app\services\runstate\Viewer;
use app\services\runstate\WorkflowOrigin;
use app\services\WorkflowAccessChecker;
use app\tests\integration\CountsQueries;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * What approval requests are for and where jobs come from
 * (ApprovalRequestContexts): the workflow, step and run of a workflow step's
 * job, named only to viewers who may view the workflow, else the job
 * template; batched, at a cost that does not grow with the requests.
 */
class ApprovalRequestContextsTest extends DbTestCase
{
    use TeamScopeFixtures;
    use CountsQueries;

    /** RequestContext::apiFields() of a request whose workflow, if any, the viewer may not see. */
    private const NO_WORKFLOW_FIELDS = [
        'workflow_job_id' => null,
        'workflow_job_step_id' => null,
        'workflow_template_id' => null,
        'workflow_step_name' => null,
    ];

    // -- Requests of workflow steps ---------------------------------------------------

    public function testAPlaceholderRequestNamesItsWorkflowStepAndRun(): void
    {
        $admin = $this->createUserWithRole('ctx-admin', 'admin');
        $workflow = $this->workflowNamed('Deploy prod', (int)$admin->id);
        $step = $this->approvalStep($workflow, 'Sign-off', (int)$admin->id);
        ['run' => $run, 'execution' => $execution, 'request' => $request] = $this->waitingAt($step, (int)$admin->id);

        $context = $this->contextOf($request, $admin);

        $this->assertSame((int)$request->id, $context->requestId);
        $this->assertEquals(
            new WorkflowOrigin((int)$run->id, (int)$execution->id, (int)$workflow->id, 'Deploy prod', 'Sign-off', true),
            $context->origin
        );
        $this->assertTrue($context->workflowRun);
        $this->assertNull($context->jobTemplateId, 'a placeholder has no job template');
        $this->assertNull($context->jobTemplateName);
        $this->assertFalse($context->jobTemplateDeleted, 'a placeholder has no template by design');
        $this->assertSame(sprintf(Texts::FOR_WORKFLOW_STEP, 'Deploy prod', 'Sign-off', (int)$run->id), $context->text());
        $this->assertSame(sprintf('Deploy prod › Sign-off (Workflow Job #%d)', (int)$run->id), $context->text());
        $this->assertSame(
            [
                'workflow_job_id' => (int)$run->id,
                'workflow_job_step_id' => (int)$execution->id,
                'workflow_template_id' => (int)$workflow->id,
                'workflow_step_name' => 'Sign-off',
            ],
            $context->apiFields()
        );
    }

    /**
     * A team member sees the request of a gated job step through its job
     * template's project, while another step's project hides the workflow
     * from them: they learn only the job template, an admin the workflow.
     */
    public function testAGatedJobStepRequestNamesTheWorkflowOnlyToViewersOfThatWorkflow(): void
    {
        $s = $this->teamScope();
        $gated = $this->gate($s['own'], (int)$s['admin']->id);
        $workflow = $this->workflowNamed('Mixed release', (int)$s['admin']->id);
        $step = $this->jobStep($workflow, 'Deploy', $gated);
        $other = $this->jobStep($workflow, 'Smoke test', $s['foreign']);
        ['run' => $run, 'execution' => $execution, 'request' => $request] = $this->waitingAt($step, (int)$s['admin']->id);
        $this->assertTrue($this->access()->canViewApprovalRequest((int)$s['member']->id, $request), 'the member sees the request');
        $this->assertFalse($this->access()->canViewWorkflowTemplate((int)$s['member']->id, (int)$workflow->id), 'but not the workflow');

        $forMember = $this->contextOf($request, $s['member']);

        $this->assertNull($forMember->origin);
        $this->assertFalse($forMember->workflowRun, 'not even that a run exists');
        $this->assertSame((int)$gated->id, $forMember->jobTemplateId);
        $this->assertSame((string)$gated->name, $forMember->jobTemplateName);
        $this->assertFalse($forMember->jobTemplateDeleted);
        $this->assertSame(sprintf(Texts::FOR_JOB_TEMPLATE, (string)$gated->name), $forMember->text());
        $this->assertStringNotContainsString('Mixed release', $forMember->text());
        $this->assertStringNotContainsString('Deploy', $forMember->text());
        $this->assertSame(self::NO_WORKFLOW_FIELDS, $forMember->apiFields());

        $forAdmin = $this->contextOf($request, $s['admin']);

        $this->assertEquals(
            new WorkflowOrigin((int)$run->id, (int)$execution->id, (int)$workflow->id, 'Mixed release', 'Deploy', false),
            $forAdmin->origin
        );
        $this->assertTrue($forAdmin->workflowRun);
        $this->assertSame((int)$gated->id, $forAdmin->jobTemplateId);
        $this->assertSame(sprintf(Texts::FOR_WORKFLOW_STEP, 'Mixed release', 'Deploy', (int)$run->id), $forAdmin->text());
        $this->assertSame(
            [
                'workflow_job_id' => (int)$run->id,
                'workflow_job_step_id' => (int)$execution->id,
                'workflow_template_id' => (int)$workflow->id,
                'workflow_step_name' => 'Deploy',
            ],
            $forAdmin->apiFields()
        );

        // Once every job step's project is visible to the member, so is the workflow.
        WorkflowStep::updateAll(['job_template_id' => $s['viewed']->id], ['id' => $other->id]);
        $this->assertTrue($this->access()->canViewWorkflowTemplate((int)$s['member']->id, (int)$workflow->id));
        $this->assertEquals($forAdmin->origin, $this->contextOf($request, $s['member'])->origin);
    }

    /**
     * Nobody (a guest, user ID 0) learns no workflow, also in an installation
     * where no project is restricted to a team, which restricts no user.
     */
    public function testNobodyLearnsTheWorkflow(): void
    {
        $admin = $this->createUserWithRole('ctx-nobody', 'admin');
        $step = $this->approvalStep($this->workflowNamed('Hidden', (int)$admin->id), 'Sign-off', (int)$admin->id);
        ['job' => $job, 'request' => $request] = $this->waitingAt($step, (int)$admin->id);
        $this->assertNotNull($this->contextOf($request, $admin)->origin, 'the admin learns it');

        $contexts = $this->contexts()->forRequests([$request], Viewer::of(0));

        $this->assertNull($contexts[(int)$request->id]->origin);
        $this->assertFalse($contexts[(int)$request->id]->workflowRun);
        $this->assertSame(RequestContext::UNKNOWN, $contexts[(int)$request->id]->text());
        $this->assertNull($this->contexts()->forJob($job, Viewer::of(0))->origin);
    }

    /**
     * The step's name is the one it ran with, also once the step was removed
     * and the workflow deleted; the run's history keeps naming both, and
     * marks the workflow deleted (its page answers 404).
     */
    public function testTheStepAndTheWorkflowAreNamedAlsoOnceRemovedOrDeleted(): void
    {
        $admin = $this->createUserWithRole('ctx-history', 'admin');
        $workflow = $this->workflowNamed('Old release', (int)$admin->id);
        $step = $this->approvalStep($workflow, 'Change board', (int)$admin->id);
        ['run' => $run, 'execution' => $execution, 'job' => $job, 'request' => $request] = $this->waitingAt($step, (int)$admin->id);
        $this->assertSame(1, WorkflowStep::deleteAll(['id' => $step->id]));
        $this->assertFalse($this->contextOf($request, $admin)->origin?->workflowDeleted, 'live until it is deleted');
        $this->assertTrue($workflow->softDelete());

        $context = $this->contextOf($request, $admin);

        $this->assertEquals(
            new WorkflowOrigin((int)$run->id, (int)$execution->id, (int)$workflow->id, 'Old release', 'Change board', true, true),
            $context->origin
        );
        $this->assertSame(sprintf('Old release (deleted) › Change board (Workflow Job #%d)', (int)$run->id), $context->text());
        $jobContext = $this->contexts()->forJob($job, Viewer::of((int)$admin->id));
        $this->assertTrue($jobContext->placeholder);
        $this->assertTrue($jobContext->origin?->workflowDeleted);
    }

    // -- Requests of job templates ----------------------------------------------------

    public function testAPlainGatedJobRequestNamesItsJobTemplateAlsoWhenDeleted(): void
    {
        $admin = $this->createUserWithRole('ctx-plain', 'admin');
        $template = $this->template((int)$admin->id);
        $request = $this->requestOf($this->waitingJob((int)$template->id, (int)$admin->id), (int)$admin->id);

        $live = $this->contextOf($request, $admin);

        $this->assertNull($live->origin);
        $this->assertFalse($live->workflowRun);
        $this->assertSame((int)$template->id, $live->jobTemplateId);
        $this->assertSame((string)$template->name, $live->jobTemplateName);
        $this->assertFalse($live->jobTemplateDeleted);
        $this->assertSame(sprintf(Texts::FOR_JOB_TEMPLATE, (string)$template->name), $live->text());
        $this->assertSame($template->name . ' (job template)', $live->text());
        $this->assertSame(self::NO_WORKFLOW_FIELDS, $live->apiFields());

        $this->assertTrue($template->softDelete());
        $deleted = $this->contextOf($request, $admin);

        $this->assertSame((int)$template->id, $deleted->jobTemplateId);
        $this->assertSame((string)$template->name, $deleted->jobTemplateName);
        $this->assertTrue($deleted->jobTemplateDeleted);
        $this->assertSame(sprintf(Texts::FOR_DELETED_JOB_TEMPLATE, (string)$template->name), $deleted->text());
        $this->assertSame($template->name . ' (deleted job template)', $deleted->text());
    }

    /**
     * Deleting a project purges its deleted job templates, and the foreign
     * key sets their jobs' job_template_id to NULL: a plain job's request
     * then names nothing, while a gated job step's request still names its
     * workflow run.
     */
    public function testARequestWhoseJobTemplateWasPurgedNamesOnlyItsWorkflowRun(): void
    {
        $admin = $this->createUserWithRole('ctx-purged', 'admin');
        $plainTemplate = $this->template((int)$admin->id);
        $plain = $this->requestOf($this->waitingJob((int)$plainTemplate->id, (int)$admin->id), (int)$admin->id);
        $workflow = $this->workflowNamed('Purged release', (int)$admin->id);
        $stepTemplate = $this->gate($this->template((int)$admin->id), (int)$admin->id);
        $step = $this->jobStep($workflow, 'Deploy', $stepTemplate);
        ['run' => $run, 'execution' => $execution, 'request' => $ofStep] = $this->waitingAt($step, (int)$admin->id);
        $this->purge($plainTemplate);
        $this->purge($stepTemplate);

        $contexts = $this->contexts()->forRequests([$plain, $ofStep], Viewer::of((int)$admin->id));

        $this->assertSame([(int)$plain->id, (int)$ofStep->id], array_keys($contexts));
        $this->assertNull($contexts[(int)$plain->id]->origin);
        $this->assertNull($contexts[(int)$plain->id]->jobTemplateId);
        $this->assertNull($contexts[(int)$plain->id]->jobTemplateName);
        $this->assertTrue($contexts[(int)$plain->id]->jobTemplateDeleted);
        $this->assertSame(RequestContext::UNKNOWN, $contexts[(int)$plain->id]->text());
        $this->assertSame('—', $contexts[(int)$plain->id]->text());

        $this->assertEquals(
            new WorkflowOrigin((int)$run->id, (int)$execution->id, (int)$workflow->id, 'Purged release', 'Deploy', false),
            $contexts[(int)$ofStep->id]->origin
        );
        $this->assertNull($contexts[(int)$ofStep->id]->jobTemplateId);
        $this->assertTrue($contexts[(int)$ofStep->id]->jobTemplateDeleted);
        $this->assertSame(sprintf(Texts::FOR_WORKFLOW_STEP, 'Purged release', 'Deploy', (int)$run->id), $contexts[(int)$ofStep->id]->text());
    }

    /**
     * The placeholder of an approval step whose execution 2.9.0 and older
     * deleted with the step: a job without template or execution. Nothing
     * tells it from a job whose template was purged.
     */
    public function testALegacyPlaceholderWithoutAnExecutionNamesNothing(): void
    {
        $admin = $this->createUserWithRole('ctx-legacy', 'admin');
        $job = $this->waitingJob(null, (int)$admin->id);
        $request = $this->requestOf($job, (int)$admin->id);

        $context = $this->contextOf($request, $admin);

        $this->assertNull($context->origin);
        $this->assertFalse($context->workflowRun);
        $this->assertNull($context->jobTemplateId);
        $this->assertNull($context->jobTemplateName);
        $this->assertTrue($context->jobTemplateDeleted);
        $this->assertSame(RequestContext::UNKNOWN, $context->text());

        $jobContext = $this->contexts()->forJob($job, Viewer::of((int)$admin->id));
        $this->assertNull($jobContext->origin);
        $this->assertFalse($jobContext->placeholder);
        $this->assertSame((int)$request->id, $jobContext->approval?->requestId);
    }

    /**
     * Only data written without the foreign keys has an execution without
     * its run: no workflow is named, and the request's job is still known as
     * a placeholder (no purged template).
     */
    public function testAnExecutionWithoutItsRunNamesNoWorkflow(): void
    {
        $admin = $this->createUserWithRole('ctx-orphan', 'admin');
        $step = $this->approvalStep($this->workflowNamed('Orphaned', (int)$admin->id), 'Sign-off', (int)$admin->id);
        ['run' => $run, 'job' => $job, 'request' => $request] = $this->waitingAt($step, (int)$admin->id);
        $db = \Yii::$app->db;
        $db->createCommand('SET FOREIGN_KEY_CHECKS = 0')->execute();
        try {
            $db->createCommand()->delete(WorkflowJob::tableName(), ['id' => $run->id])->execute();
        } finally {
            $db->createCommand('SET FOREIGN_KEY_CHECKS = 1')->execute();
        }

        $context = $this->contextOf($request, $admin);

        $this->assertNull($context->origin);
        $this->assertFalse($context->workflowRun);
        $this->assertFalse($context->jobTemplateDeleted, 'its execution still tells the placeholder');
        $this->assertSame(RequestContext::UNKNOWN, $context->text());
        $jobContext = $this->contexts()->forJob($job, Viewer::of((int)$admin->id));
        $this->assertNull($jobContext->origin);
        $this->assertTrue($jobContext->placeholder);
    }

    public function testARequestWhoseJobNoLongerExistsNamesNothing(): void
    {
        $admin = $this->createUserWithRole('ctx-gone', 'admin');
        $job = $this->waitingJob((int)$this->template((int)$admin->id)->id, (int)$admin->id);
        $request = $this->requestOf($job, (int)$admin->id);
        $db = \Yii::$app->db;
        $db->createCommand('SET FOREIGN_KEY_CHECKS = 0')->execute();
        try {
            $db->createCommand()->delete(Job::tableName(), ['id' => $job->id])->execute();
        } finally {
            $db->createCommand('SET FOREIGN_KEY_CHECKS = 1')->execute();
        }

        $context = $this->contextOf($request, $admin);

        $this->assertNull($context->origin);
        $this->assertNull($context->jobTemplateId);
        $this->assertNull($context->jobTemplateName);
        $this->assertFalse($context->jobTemplateDeleted);
        $this->assertSame(RequestContext::UNKNOWN, $context->text());
    }

    // -- Cost -------------------------------------------------------------------------

    public function testNoRequestsCostNoQuery(): void
    {
        $admin = $this->createUserWithRole('ctx-none', 'admin');
        $viewer = Viewer::of((int)$admin->id);

        $this->assertSame(0, $this->queriesOf(fn () => $this->assertSame([], $this->contexts()->forRequests([], $viewer))));
    }

    /**
     * Every kind of request at once (a placeholder, a gated job step, a
     * plain job, a deleted template), for an unrestricted admin and for a
     * team member whose workflows are checked: four of each cost what one
     * of each costs.
     */
    public function testForRequestsCostsTheSameForFewAndManyRequests(): void
    {
        $s = $this->teamScope();
        $few = $this->requestsOfEveryKind($s, 1);
        $many = $this->requestsOfEveryKind($s, 4);
        $this->assertCount(4, $few);
        $this->assertCount(16, $many);

        foreach ([$s['admin'], $s['member']] as $user) {
            $viewer = Viewer::of((int)$user->id);
            // The first use loads table schemas and the RBAC items.
            $this->contexts()->forRequests($few, $viewer);

            $forFew = $this->queriesOf(fn () => $this->contexts()->forRequests($few, $viewer));
            $forMany = $this->queriesOf(fn () => $this->assertCount(16, $this->contexts()->forRequests($many, $viewer)));

            $this->assertSame($forFew, $forMany, (string)$user->username);
        }
    }

    // -- Jobs -------------------------------------------------------------------------

    public function testForJobOfAPlaceholderGivesItsStepRunAndRequest(): void
    {
        $admin = $this->createUserWithRole('ctx-job', 'admin');
        $workflow = $this->workflowNamed('Nightly', (int)$admin->id);
        $step = $this->approvalStep($workflow, 'Approve nightly', (int)$admin->id);
        ['run' => $run, 'execution' => $execution, 'job' => $job, 'request' => $request] = $this->waitingAt($step, (int)$admin->id);

        $context = $this->contexts()->forJob($job, Viewer::of((int)$admin->id));

        $this->assertEquals(
            new WorkflowOrigin((int)$run->id, (int)$execution->id, (int)$workflow->id, 'Nightly', 'Approve nightly', true),
            $context->origin
        );
        $this->assertTrue($context->placeholder);
        $this->assertNotNull($context->approval);
        $this->assertSame((int)$request->id, $context->approval->requestId);
        $this->assertSame((int)$job->id, $context->approval->jobId);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $context->approval->status);
        $this->assertSame(
            ['approval_request_id' => (int)$request->id, 'workflow_job_id' => (int)$run->id, 'workflow_job_step_id' => (int)$execution->id],
            $context->apiFields()
        );
    }

    /**
     * The job of a gated job step: the member who may not view the workflow
     * sees its request, but no run; an admin sees both.
     */
    public function testForJobOfAGatedJobStepNamesTheRunOnlyToViewersOfTheWorkflow(): void
    {
        $s = $this->teamScope();
        $workflow = $this->workflowNamed('Mixed deploy', (int)$s['admin']->id);
        $step = $this->jobStep($workflow, 'Deploy', $this->gate($s['own'], (int)$s['admin']->id));
        $this->jobStep($workflow, 'Smoke test', $s['foreign']);
        ['run' => $run, 'execution' => $execution, 'job' => $job, 'request' => $request] = $this->waitingAt($step, (int)$s['admin']->id);

        $forMember = $this->contexts()->forJob($job, Viewer::of((int)$s['member']->id));

        $this->assertNull($forMember->origin);
        $this->assertFalse($forMember->placeholder);
        $this->assertSame((int)$request->id, $forMember->approval?->requestId);
        $this->assertSame(
            ['approval_request_id' => (int)$request->id, 'workflow_job_id' => null, 'workflow_job_step_id' => null],
            $forMember->apiFields()
        );

        $forAdmin = $this->contexts()->forJob($job, Viewer::of((int)$s['admin']->id));

        $this->assertEquals(
            new WorkflowOrigin((int)$run->id, (int)$execution->id, (int)$workflow->id, 'Mixed deploy', 'Deploy', false),
            $forAdmin->origin
        );
        $this->assertFalse($forAdmin->placeholder);
        $this->assertSame((int)$request->id, $forAdmin->approval?->requestId);
    }

    public function testForJobOfAJobOutsideWorkflowsAndApprovalsHasNoContext(): void
    {
        $admin = $this->createUserWithRole('ctx-plain-job', 'admin');
        $job = $this->createJob((int)$this->template((int)$admin->id)->id, (int)$admin->id);

        $context = $this->contexts()->forJob($job, Viewer::of((int)$admin->id));

        $this->assertNull($context->origin);
        $this->assertNull($context->approval);
        $this->assertFalse($context->placeholder);
        $this->assertSame(
            ['approval_request_id' => null, 'workflow_job_id' => null, 'workflow_job_step_id' => null],
            $context->apiFields()
        );
    }

    // -- Helpers ----------------------------------------------------------------------

    private function contexts(): ApprovalRequestContexts
    {
        /** @var ApprovalRequestContexts $contexts */
        $contexts = \Yii::$app->get('approvalRequestContexts');

        return $contexts;
    }

    private function access(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $access */
        $access = \Yii::$app->get('workflowAccessChecker');

        return $access;
    }

    private function contextOf(ApprovalRequest $request, User $viewer): RequestContext
    {
        $contexts = $this->contexts()->forRequests([$request], Viewer::of((int)$viewer->id));
        $this->assertSame([(int)$request->id], array_keys($contexts));

        return $contexts[(int)$request->id];
    }

    private function template(int $userId): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    /**
     * $template, now gated by an approval rule of its own.
     */
    private function gate(JobTemplate $template, int $userId): JobTemplate
    {
        JobTemplate::updateAll(['approval_rule_id' => $this->createApprovalRule($userId)->id], ['id' => $template->id]);
        $template->refresh();

        return $template;
    }

    private function workflowNamed(string $name, int $userId): WorkflowTemplate
    {
        $workflow = $this->createWorkflowTemplate($userId);
        $workflow->name = $name;
        $workflow->save(false);

        return $workflow;
    }

    private function approvalStep(WorkflowTemplate $workflow, string $name, int $userId): WorkflowStep
    {
        $step = $this->createWorkflowStep(
            (int)$workflow->id,
            (int)WorkflowStep::find()->where(['workflow_template_id' => $workflow->id])->count(),
            WorkflowStep::TYPE_APPROVAL,
            null,
            (int)$this->createApprovalRule($userId)->id
        );
        $step->name = $name;
        $step->save(false);

        return $step;
    }

    private function jobStep(WorkflowTemplate $workflow, string $name, JobTemplate $template): WorkflowStep
    {
        $step = $this->createWorkflowStep(
            (int)$workflow->id,
            (int)WorkflowStep::find()->where(['workflow_template_id' => $workflow->id])->count(),
            WorkflowStep::TYPE_JOB,
            (int)$template->id
        );
        $step->name = $name;
        $step->save(false);

        return $step;
    }

    /**
     * A running run of $step's workflow that waits at $step: its running
     * execution, the job it created (the placeholder of an approval step, or
     * the job of a job step) waiting for approval, and the job's pending
     * request. The execution's snapshot is filled from the step.
     *
     * @return array{run: WorkflowJob, execution: WorkflowJobStep, job: Job, request: ApprovalRequest}
     */
    private function waitingAt(WorkflowStep $step, int $launcherId): array
    {
        $run = $this->createWorkflowJob((int)$step->workflow_template_id, $launcherId);
        $isJob = $step->step_type === WorkflowStep::TYPE_JOB;
        $job = $this->waitingJob($isJob ? (int)$step->job_template_id : null, $launcherId);
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = (int)$step->id;
        $execution->job_id = (int)$job->id;
        $execution->status = WorkflowJobStep::STATUS_RUNNING;
        $execution->started_at = time();
        $execution->save(false);

        return ['run' => $run, 'execution' => $execution, 'job' => $job, 'request' => $this->requestOf($job, $launcherId)];
    }

    private function waitingJob(?int $templateId, int $launcherId): Job
    {
        $job = new Job();
        $job->job_template_id = $templateId;
        $job->launched_by = $launcherId;
        $job->status = Job::STATUS_PENDING_APPROVAL;
        $job->timeout_minutes = 0;
        $job->has_changes = 0;
        $job->save(false);

        return $job;
    }

    private function requestOf(Job $job, int $userId): ApprovalRequest
    {
        /** @var ApprovalService $approvals */
        $approvals = \Yii::$app->get('approvalService');

        // As they are written: a placeholder's by its approval step, a job's by its template's rule.
        $requiredBy = $job->job_template_id === null
            ? ApprovalRequest::REQUIRED_BY_APPROVAL_STEP
            : ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE;

        return $approvals->openRequest($job, $this->createApprovalRule($userId), $requiredBy);
    }

    /**
     * Purge $template as a project delete purges it: soft-deleted, then its
     * row deleted, which sets its jobs' job_template_id to NULL.
     */
    private function purge(JobTemplate $template): void
    {
        $this->assertTrue($template->softDelete());
        $this->assertSame(1, JobTemplate::deleteAll(['id' => $template->id]));
    }

    /**
     * $times requests of every kind: the placeholder of an approval step, a
     * gated job step (of a workflow the team-scope member may not view), a
     * plain job, and a job of a deleted template.
     *
     * @param array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate} $s
     * @return list<ApprovalRequest>
     */
    private function requestsOfEveryKind(array $s, int $times): array
    {
        $adminId = (int)$s['admin']->id;
        $requests = [];
        for ($i = 0; $i < $times; $i++) {
            $workflow = $this->workflowNamed('Cost ' . $i, $adminId);
            $requests[] = $this->waitingAt($this->approvalStep($workflow, 'Sign-off', $adminId), $adminId)['request'];
            $gated = $this->jobStep($workflow, 'Deploy', $this->gate($s['own'], $adminId));
            $this->jobStep($workflow, 'Smoke test', $s['foreign']);
            $requests[] = $this->waitingAt($gated, $adminId)['request'];
            $requests[] = $this->requestOf($this->waitingJob((int)$s['open']->id, $adminId), $adminId);
            $deleted = $this->template($adminId);
            $requests[] = $this->requestOf($this->waitingJob((int)$deleted->id, $adminId), $adminId);
            $this->assertTrue($deleted->softDelete());
        }

        return $requests;
    }
}
