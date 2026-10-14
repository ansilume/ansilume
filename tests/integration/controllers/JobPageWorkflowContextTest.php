<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\JobController;
use app\models\ApprovalRequest;
use app\models\Job;
use app\models\TeamProject;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\runstate\Banner;
use app\services\runstate\JobContext;
use app\services\runstate\Texts;
use app\tests\integration\CountsQueries;
use app\tests\integration\services\runstate\RunStateFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Module;
use yii\helpers\Html;

/**
 * The job page says where a job comes from and what it waits for: the
 * approval request it waits or waited for, and the workflow run and step it
 * was created for, as far as the viewer may see them. The placeholder of an
 * approval step explains itself instead of showing an empty log, and the
 * jobs list names it.
 */
class JobPageWorkflowContextTest extends WebControllerTestCase
{
    use RendersRealViews;
    use TeamScopeFixtures;
    use RunStateFixtures;
    use CountsQueries;

    /**
     * regression: the placeholder job page was a dead end: an empty live
     * log, the template "—", and no link to its request or its run.
     */
    public function testRegressionThePlaceholderJobPageWasADeadEnd(): void
    {
        $admin = $this->createUserWithRole('jpc-admin', 'admin');
        [$run, $placeholder, $request] = $this->approvalStepWait($admin, 'sign <off>');
        $this->loginAs($admin);

        $html = $this->renderJobPage($placeholder);

        $this->assertStringContainsString(
            '<div class="alert alert-secondary" id="job-placeholder-note">'
            . "\n    " . Html::encode(sprintf(Texts::PLACEHOLDER_NOTE, 'sign <off>', $run->id)),
            $html
        );
        $this->assertStringNotContainsString('id="job-log"', $html, 'a placeholder never runs: no Output card');
        $this->assertStringContainsString(Html::encode(sprintf(Texts::PLACEHOLDER_SUBTITLE, 'sign <off>', $run->id)), $html);
        $this->assertMatchesRegularExpression('#<dt class="col-5">Template</dt>\s*<dd class="col-7">\s*' . preg_quote(Html::encode(Texts::PLACEHOLDER_TEMPLATE), '#') . '\s*</dd>#', $html);
        $this->assertMatchesRegularExpression(
            '#<dd class="col-7" id="detail-approval">\s*<a href="/approval/view\?id=' . $request->id . '">Request \#' . $request->id . '</a> · Pending\s*</dd>#',
            $html
        );
        $this->assertMatchesRegularExpression(
            '#<dd class="col-7" id="detail-workflow-job">\s*<a href="/workflow-job/view\?id=' . $run->id . '">\#' . $run->id . ', step &quot;sign &lt;off&gt;&quot;</a>\s*</dd>#',
            $html
        );
        $this->assertStringContainsString('<div class="alert alert-info" id="job-approval-note" role="status">', $html);
        $this->assertStringContainsString('<div class="fw-semibold">' . sprintf(Texts::JOB_APPROVAL_NOTE, $request->id) . '</div>', $html);
        $this->assertStringContainsString(
            '<a id="job-approval-review" class="btn btn-sm btn-primary mt-2" href="/approval/view?id=' . $request->id . '">' . Banner::REVIEW_LINK . '</a>',
            $html
        );
    }

    /**
     * A gated job waits for its request: the note says so with the request's
     * facts; the job keeps its Output card and links its run and request.
     */
    public function testAGatedJobPageNamesItsRequestAndItsRun(): void
    {
        $admin = $this->createUserWithRole('jpc-admin', 'admin');
        [$run, $job, $request, $rule] = $this->gatedJobStepWait($admin);
        $this->loginAs($admin);

        $html = $this->renderJobPage($job);

        $this->assertStringContainsString('id="job-log"', $html);
        $this->assertStringNotContainsString('id="job-placeholder-note"', $html);
        $this->assertStringContainsString('<li>' . Html::encode(sprintf(Texts::FACT_RULE, $rule->name)) . '</li>', $html);
        $this->assertStringContainsString('<li>0 of 1 approval(s)</li>', $html);
        $this->assertStringContainsString('<li>' . Texts::FACT_NEVER_EXPIRES . '</li>', $html);
        $this->assertStringContainsString('id="detail-approval"', $html);
        $this->assertMatchesRegularExpression('#id="detail-workflow-job">\s*<a href="/workflow-job/view\?id=' . $run->id . '">#', $html);
        $this->assertStringContainsString('id="job-approval-review"', $html);
        $this->assertStringContainsString('<span class="badge text-bg-info ms-2 fs-6" id="status-badge">', $html);
        $this->assertSame((int)$request->id, $this->context($job, $admin)->approval?->requestId);
    }

    /**
     * Once the job no longer waits, the note goes; the request stays linked
     * with its outcome.
     */
    public function testAnApprovedJobHasNoApprovalNoteButNamesItsRequest(): void
    {
        $admin = $this->createUserWithRole('jpc-admin', 'admin');
        [, $job, $request] = $this->gatedJobStepWait($admin);
        $job->status = Job::STATUS_QUEUED;
        $job->save(false);
        $request->status = ApprovalRequest::STATUS_APPROVED;
        $request->resolved_at = time();
        $request->save(false);
        $this->loginAs($admin);

        $html = $this->renderJobPage($job);

        $this->assertStringNotContainsString('id="job-approval-note"', $html);
        $this->assertMatchesRegularExpression('#id="detail-approval">\s*<a href="/approval/view\?id=' . $request->id . '">Request \#' . $request->id . '</a> · Approved#', $html);
    }

    /**
     * Without approval.view the request is named but not linked, and there is
     * no Review link; without workflow.view the run is named but not linked.
     */
    public function testLinksNeedThePermissionToOpenTheirPage(): void
    {
        $admin = $this->createUserWithRole('jpc-admin', 'admin');
        [$run, $placeholder, $request] = $this->approvalStepWait($admin, 'gate');
        $jobsOnly = $this->createUser('jpc-jobs-only');
        $this->grantPermissions($jobsOnly, ['job.view']);
        $this->loginAs($jobsOnly);

        $html = $this->renderJobPage($placeholder);

        $this->assertMatchesRegularExpression('#id="detail-approval">\s*Request \#' . $request->id . ' · Pending\s*</dd>#', $html);
        $this->assertStringNotContainsString('/approval/view', $html);
        $this->assertStringNotContainsString('id="job-approval-review"', $html);
        $this->assertStringContainsString('id="job-approval-note"', $html, 'the wait is told, without the link');
        $this->assertMatchesRegularExpression('#id="detail-workflow-job">\s*\#' . $run->id . ', step &quot;gate&quot;\s*</dd>#', $html);
        $this->assertStringNotContainsString('/workflow-job/view', $html);
    }

    /**
     * A user who may see a gated job through its template's project, but not
     * the workflow it belongs to (team scoping), learns nothing of the run.
     */
    public function testAMemberWhoMayNotViewTheWorkflowLearnsNothingOfTheRun(): void
    {
        $s = $this->teamScope();
        $rule = $this->ruleOf((int)$s['admin']->id, [$s['admin']]);
        $workflow = $this->createWorkflowWithJobSteps((int)$s['admin']->id, (int)$s['own']->id, (int)$s['foreign']->id);
        $step = WorkflowStep::findOne(['workflow_template_id' => $workflow->id, 'job_template_id' => $s['own']->id]);
        $this->assertNotNull($step);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$s['admin']->id);
        $job = $this->jobOf((int)$s['own']->id, (int)$s['admin']->id, Job::STATUS_PENDING_APPROVAL);
        $this->requestOf($job, $rule);
        $this->executionOf($run, $step, WorkflowJobStep::STATUS_RUNNING, $job);
        $this->loginAs($s['member']);

        $html = $this->renderJobPage($job);

        $this->assertStringNotContainsString('id="detail-workflow-job"', $html);
        $this->assertStringNotContainsString((string)$workflow->name, $html);
        $this->assertStringContainsString('id="detail-approval"', $html, 'the request is visible through the template');
        $this->assertNull($this->context($job, $s['member'])->origin);
    }

    public function testAJobOutsideWorkflowsAndApprovalsHasNoContext(): void
    {
        $admin = $this->createUserWithRole('jpc-admin', 'admin');
        $job = $this->createJob((int)$this->openJobTemplate((int)$admin->id)->id, (int)$admin->id, Job::STATUS_RUNNING);
        $this->loginAs($admin);

        $html = $this->renderJobPage($job);

        foreach (['detail-approval', 'detail-workflow-job', 'job-approval-note', 'job-placeholder-note'] as $id) {
            $this->assertStringNotContainsString('id="' . $id . '"', $html, $id);
        }
        $this->assertStringContainsString('id="job-log"', $html);
    }

    /**
     * The jobs list names a placeholder by its step and run, and costs the
     * same however many it lists.
     */
    public function testTheJobsListNamesPlaceholdersByTheirStepAndRun(): void
    {
        $admin = $this->createUserWithRole('jpc-admin', 'admin');
        $this->loginAs($admin);
        $few = $this->createUser('jpc-few');
        $many = $this->createUser('jpc-many');
        [$run, $placeholder] = $this->approvalStepWait($few, 'sign <off>');
        for ($i = 0; $i < 11; $i++) {
            $this->approvalStepWait($many, 'gate ' . $i);
        }
        $this->approvalStepWait($few, 'second');
        $list = fn (User $launcher): string => $this->renderJobList((int)$launcher->id);
        $html = $list($few);

        $this->assertStringContainsString(Html::encode(sprintf(Texts::PLACEHOLDER_LABEL, 'sign <off>', $run->id)), $html);
        $this->assertStringContainsString('<a href="/job/view?id=' . $placeholder->id . '">#' . $placeholder->id . '</a>', $html);
        $this->assertSame($this->queriesOf(fn () => $list($few)), $this->queriesOf(fn () => $list($many)));
    }

    /**
     * Next to the placeholders, the jobs list names a job's template, and
     * marks one that was deleted since, muted and without a link.
     */
    public function testTheJobsListNamesAJobsTemplateAndMarksADeletedOne(): void
    {
        $admin = $this->createUserWithRole('jpc-admin', 'admin');
        $this->loginAs($admin);
        $launcher = $this->createUser('jpc-templates');
        $live = $this->openJobTemplate((int)$admin->id);
        $gone = $this->openJobTemplate((int)$admin->id);
        $gone->name = 'Old <b>deploy</b>';
        $gone->save(false);
        $liveJob = $this->createJob((int)$live->id, (int)$launcher->id, Job::STATUS_SUCCEEDED);
        $goneJob = $this->createJob((int)$gone->id, (int)$launcher->id, Job::STATUS_SUCCEEDED);
        $this->assertTrue($gone->softDelete());

        $html = $this->renderJobList((int)$launcher->id);

        $this->assertSame(Html::encode((string)$live->name), $this->templateCellOf($html, $liveJob));
        $this->assertSame('Old &lt;b&gt;deploy&lt;/b&gt; <span class="text-muted">(deleted)</span>', $this->templateCellOf($html, $goneJob));
    }

    // -- Fixtures -------------------------------------------------------------

    /**
     * A run launched by $launcher that waits at its approval step named
     * $stepName.
     *
     * @return array{0: WorkflowJob, 1: Job, 2: ApprovalRequest}
     */
    private function approvalStepWait(User $launcher, string $stepName): array
    {
        $rule = $this->ruleOf((int)$launcher->id, [$launcher]);
        $workflow = $this->createWorkflowTemplate((int)$launcher->id);
        $gate = $this->namedStep($workflow, 10, $stepName, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$launcher->id);
        $placeholder = $this->jobOf(null, (int)$launcher->id, Job::STATUS_PENDING_APPROVAL);
        $request = $this->requestOf($placeholder, $rule);
        $this->executionOf($run, $gate, WorkflowJobStep::STATUS_RUNNING, $placeholder);

        return [$run, $placeholder, $request];
    }

    /**
     * A run whose gated job step's job waits for its request.
     *
     * @return array{0: WorkflowJob, 1: Job, 2: ApprovalRequest, 3: \app\models\ApprovalRule}
     */
    private function gatedJobStepWait(User $launcher): array
    {
        $rule = $this->ruleOf((int)$launcher->id, [$launcher]);
        $template = $this->openJobTemplate((int)$launcher->id);
        $workflow = $this->createWorkflowTemplate((int)$launcher->id);
        $deploy = $this->namedStep($workflow, 10, 'deploy', WorkflowStep::TYPE_JOB, (int)$template->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$launcher->id);
        $job = $this->jobOf((int)$template->id, (int)$launcher->id, Job::STATUS_PENDING_APPROVAL);
        $request = $this->requestOf($job, $rule);
        $this->executionOf($run, $deploy, WorkflowJobStep::STATUS_RUNNING, $job);

        return [$run, $job, $request, $rule];
    }

    /**
     * A role of its own with exactly $permissions, assigned to $user.
     *
     * @param list<string> $permissions
     */
    private function grantPermissions(User $user, array $permissions): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('jpc-role-' . bin2hex(random_bytes(4)));
        $auth->add($role);
        foreach ($permissions as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }
        $auth->assign($role, (string)$user->id);
    }

    // -- Helpers --------------------------------------------------------------

    private function context(Job $job, User $viewer): JobContext
    {
        /** @var \app\services\runstate\ApprovalRequestContexts $contexts */
        $contexts = \Yii::$app->get('approvalRequestContexts');

        return $contexts->forJob($job, \app\services\runstate\Viewer::of((int)$viewer->id));
    }

    private function renderJobPage(Job $job): string
    {
        $ctrl = $this->controller();

        return $this->withRealViews($ctrl, '/job/view?id=' . $job->id, static fn (): string => $ctrl->actionView((int)$job->id));
    }

    private function renderJobList(int $launcherId): string
    {
        $ctrl = $this->controller();
        $this->setQueryParams(['launched_by' => (string)$launcherId]);

        return $this->withRealViews($ctrl, '/job/index?launched_by=' . $launcherId, static fn (): string => $ctrl->actionIndex());
    }

    /**
     * The markup of the Template cell (the third) of $job's row in the jobs
     * list $html, its whitespace runs as one space.
     */
    private function templateCellOf(string $html, Job $job): string
    {
        $pattern = '#<tr>\s*<td><a href="/job/view\?id=' . $job->id . '">\#' . $job->id . '</a></td>\s*<td>.*?</td>\s*<td>(.*?)</td>#s';
        $this->assertSame(1, preg_match($pattern, $html, $match), 'the row of job #' . $job->id);

        return trim((string)preg_replace('/\s+/', ' ', $match[1]));
    }

    private function controller(): JobController
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);

        return new class ('job', $app) extends JobController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };
    }
}
