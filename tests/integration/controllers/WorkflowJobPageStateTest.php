<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\VersionedAssetUrl;
use app\controllers\WorkflowJobController;
use app\helpers\TimeHelper;
use app\models\ApprovalRequest;
use app\models\Job;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobSearch;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\runstate\Display;
use app\services\runstate\PollPayload;
use app\services\runstate\Texts;
use app\services\StrandedJobSweep;
use app\services\WorkflowCancellationService;
use app\services\WorkflowExecutionService;
use app\tests\integration\CountsQueries;
use app\tests\integration\services\runstate\RunStateFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\data\ActiveDataProvider;
use yii\data\ArrayDataProvider;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\web\AssetManager;
use yii\web\View;

/**
 * What the run page, its poll (/workflow-job/status) and the Workflow Jobs
 * list say a run is doing: a waiting run is stored as running and shows
 * what it waits for, the run page names the approval request it waits for,
 * and the page's script gets what it needs to follow the run.
 */
class WorkflowJobPageStateTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use RunStateFixtures;
    use CountsQueries;

    protected function setUp(): void
    {
        parent::setUp();
        \Yii::$app->session->removeAllFlashes();
    }

    /**
     * regression: the status of a run waiting for approval said Running, on
     * the page and in the poll. The stored status stays running.
     */
    public function testRegressionTheStatusOfARunWaitingForApprovalSaidRunning(): void
    {
        $admin = $this->createUserWithRole('wjp-admin', 'admin');
        [$run, $execution, $request] = $this->approvalWaitRun($admin, [$admin]);
        $this->loginAs($admin);

        $payload = $this->makeController()->actionStatus((int)$run->id);

        $this->assertSame(['running', 'Running', 'primary'], [$payload['status'], $payload['status_label'], $payload['status_css']]);
        $this->assertSame('awaiting_approval', $payload['state'] ?? null);
        $this->assertSame('Awaiting Approval', $payload['state_label'] ?? null);
        $this->assertSame('info', $payload['state_css'] ?? null);
        $this->assertSame('approval', $payload['waiting_for'] ?? null);
        $row = $this->stepRow($payload, (int)$execution->id);
        $this->assertSame('Awaiting Approval', $row['state_label'] ?? null);
        $this->assertSame((int)$request->id, $row['approval']['id'] ?? null);
        $this->assertStringContainsString(
            '<span id="wj-status" class="badge text-bg-info">Awaiting Approval</span>',
            $this->renderRunPage($run)
        );
    }

    /**
     * regression: the run page named no approval request: the banner and
     * the step's row link it, and the launcher learns that another approver
     * must decide.
     */
    public function testRegressionTheRunPageNamedNoApprovalRequest(): void
    {
        $admin = $this->createUserWithRole('wjp-admin', 'admin');
        $approver = $this->createUserWithRole('wjp-approver', 'operator');
        [$run, , $request] = $this->approvalWaitRun($admin, [$admin, $approver]);
        $this->loginAs($admin);

        $html = $this->renderRunPage($run);

        $requestId = (int)$request->id;
        $this->assertStringContainsString('<div id="wj-wait-banner" class="alert alert-info" role="status" data-wait="approval">', $html);
        $this->assertStringContainsString('<div class="fw-semibold" data-banner-title>Waiting for approval at step &quot;sign &lt;off&gt;&quot;.</div>', $html);
        $this->assertStringContainsString('<li><a href="/approval/view?id=' . $requestId . '" data-banner-request>Approval Request #' . $requestId . '</a></li>', $html);
        $this->assertStringContainsString('<a href="/approval/view?id=' . $requestId . '" data-wjs-request-link>Request #' . $requestId . '</a>', $html);
        $this->assertStringContainsString('<span data-banner-personal>' . Texts::PERSONAL_LAUNCHER . '</span>', $html);
        $this->assertStringNotContainsString('wj-review-link', $html, 'the launcher never decides');
        $this->assertStringNotContainsString('data-wjs-job-link', $html, 'the placeholder is not linked as a job');
    }

    /**
     * regression: step durations were minutes only ("133m 00s" for 7980 s).
     */
    public function testRegressionStepDurationsWereMinutesOnly(): void
    {
        $admin = $this->createUserWithRole('wjp-admin', 'admin');
        $workflow = $this->createWorkflowTemplate((int)$admin->id);
        $pause = $this->namedStep($workflow, 10, 'hold', WorkflowStep::TYPE_PAUSE);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$admin->id, WorkflowJob::STATUS_SUCCEEDED);
        $execution = $this->executionOf($run, $pause, WorkflowJobStep::STATUS_SUCCEEDED, null, null, 1760000200 - 7980);
        $this->loginAs($admin);

        $row = $this->stepRow($this->makeController()->actionStatus((int)$run->id), (int)$execution->id);

        $this->assertSame(7980, $row['duration_seconds']);
        $this->assertSame('2h 13m', $row['duration_label']);
        $this->assertStringContainsString('<td data-wjs-duration-cell>2h 13m</td>', $this->renderRunPage($run));
    }

    /**
     * regression: a step that a run cancel ended read "Failed". It reads
     * Canceled; its stored status stays failed, and a step the maintenance
     * run ended for another reason still reads Failed.
     */
    public function testRegressionAStepEndedByARunCancelReadFailed(): void
    {
        $admin = $this->createUserWithRole('wjp-admin', 'admin');
        $workflow = $this->createWorkflowTemplate((int)$admin->id);
        $pause = $this->namedStep($workflow, 10, 'hold', WorkflowStep::TYPE_PAUSE);
        $other = $this->namedStep($workflow, 20, 'other', WorkflowStep::TYPE_PAUSE);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$admin->id);
        $ended = $this->executionOf($run, $other, WorkflowJobStep::STATUS_FAILED, null, StrandedJobSweep::STEP_RUN_ENDED);
        $paused = $this->executionOf($run, $pause, WorkflowJobStep::STATUS_RUNNING);
        $this->engine()->cancel($run, (int)$admin->id);
        $this->loginAs($admin);

        $payload = $this->makeController()->actionStatus((int)$run->id);

        $canceled = $this->stepRow($payload, (int)$paused->id);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $canceled['status']);
        $this->assertSame(WorkflowCancellationService::STEP_RUN_CANCELED, $canceled['error_message']);
        $this->assertSame('Canceled', $canceled['state_label'] ?? null);
        $this->assertSame('Failed', $this->stepRow($payload, (int)$ended->id)['state_label'] ?? null);
        $html = $this->renderRunPage($run);
        $this->assertStringContainsString('<span class="badge text-bg-warning">Canceled</span>', $html);
        $this->assertStringContainsString('<span class="badge text-bg-danger">Failed</span>', $html);
    }

    /**
     * regression: the Workflow Jobs list showed a paused run as Running.
     */
    public function testRegressionTheWorkflowJobsListShowedAPausedRunAsRunning(): void
    {
        $admin = $this->createUserWithRole('wjp-admin', 'admin');
        $run = $this->pausedRun($admin);
        $this->loginAs($admin);
        $this->setQueryParams(['workflow_template_id' => (string)$run->workflow_template_id]);
        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        $html = $this->renderView('index', $ctrl->capturedParams);

        $this->assertStringContainsString('<span class="badge text-bg-warning" data-run-state="paused">Paused</span>', $html);
        $this->assertStringNotContainsString('>Running</span>', $html);
    }

    /**
     * The list names each run's current step, as it was when it ran, and
     * links the request a run waits for.
     */
    public function testTheListNamesTheCurrentStepAndTheRequestARunWaitsFor(): void
    {
        $admin = $this->createUserWithRole('wjp-admin', 'admin');
        [$run, , $request] = $this->approvalWaitRun($admin, [$admin]);
        $this->loginAs($admin);
        $this->setQueryParams(['workflow_template_id' => (string)$run->workflow_template_id]);
        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        $html = $this->renderView('index', $ctrl->capturedParams);

        $this->assertStringContainsString('<th>Current Step</th>', $html);
        $this->assertMatchesRegularExpression(
            '#sign &lt;off&gt;\s*<a class="small ms-1" href="/approval/view\?id=' . $request->id . '" data-run-request>Request \#' . $request->id . '</a>#',
            $html
        );
        $this->assertStringContainsString('<span class="badge text-bg-info" data-run-state="awaiting_approval">Awaiting Approval</span>', $html);
        $this->assertStringContainsString('<div class="table-responsive">', $html);
    }

    /**
     * A finished run has no current step: the list's Current Step reads a
     * dash, next to the run's stored status.
     */
    public function testAFinishedRunHasNoCurrentStepInTheList(): void
    {
        $admin = $this->createUserWithRole('wjp-admin', 'admin');
        $workflow = $this->createWorkflowTemplate((int)$admin->id);
        $pause = $this->namedStep($workflow, 10, 'hold', WorkflowStep::TYPE_PAUSE);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$admin->id, WorkflowJob::STATUS_SUCCEEDED);
        $this->executionOf($run, $pause, WorkflowJobStep::STATUS_SUCCEEDED);
        $this->loginAs($admin);
        $this->setQueryParams(['workflow_template_id' => (string)$workflow->id]);
        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        $html = $this->renderView('index', $ctrl->capturedParams);

        $states = $ctrl->capturedParams['runStates'];
        $this->assertIsArray($states);
        $this->assertArrayHasKey((int)$run->id, $states);
        $this->assertNull($states[(int)$run->id]->current, 'no current execution');
        $this->assertMatchesRegularExpression(
            '#<span class="badge text-bg-success" data-run-state="succeeded">Succeeded</span></td>\s*<td>\s*' . PollPayload::NO_VALUE . '\s*</td>#u',
            $html
        );
        $this->assertStringNotContainsString('hold', $html, 'the step it ended with is not its current step');
    }

    /**
     * A decided approval step says how it was decided under its status, on
     * the page as the poll says it: who approved, when, and the comment.
     */
    public function testADecidedStepShowsItsDecisionOnThePage(): void
    {
        $admin = $this->createUserWithRole('wjp-admin', 'admin');
        $approver = $this->createUserWithRole('wjp-approver', 'operator');
        $rule = $this->ruleOf((int)$admin->id, [$admin, $approver]);
        $workflow = $this->createWorkflowTemplate((int)$admin->id);
        $gate = $this->namedStep($workflow, 10, 'sign off', WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$admin->id, WorkflowJob::STATUS_SUCCEEDED);
        $placeholder = $this->jobOf(null, (int)$admin->id, Job::STATUS_SUCCEEDED);
        $resolvedAt = time() - 30;
        $request = $this->requestOf($placeholder, $rule, ApprovalRequest::STATUS_APPROVED, time() - 90, null, $resolvedAt);
        $this->voteOn($request, $approver, 'approved', 'ship "it"');
        $execution = $this->executionOf($run, $gate, WorkflowJobStep::STATUS_SUCCEEDED, $placeholder);
        $this->loginAs($admin);
        $note = sprintf(Texts::DECIDED_APPROVED_BY, $approver->username, TimeHelper::stamp($resolvedAt))
            . ' ' . sprintf(Texts::DECIDED_COMMENT, 'ship "it"');

        $html = $this->renderRunPage($run);

        $this->assertSame($note, $this->stepRow($this->makeController()->actionStatus((int)$run->id), (int)$execution->id)['decision_note']);
        $this->assertStringContainsString(
            '<div class="small text-body-secondary mt-1" data-wjs-decision>' . Html::encode($note) . '</div>',
            $html
        );
    }

    /**
     * ?waiting_for= keeps the runs that wait for that; an invalid value is
     * ignored, so an old or mistyped link still opens the list.
     */
    public function testTheListFiltersByWhatTheRunsWaitFor(): void
    {
        $admin = $this->createUserWithRole('wjp-admin', 'admin');
        $this->loginAs($admin);
        $runs = [
            'paused' => $this->pausedRun($admin),
            'approval' => $this->approvalWaitRun($admin, [$admin])[0],
            'queued' => $this->queuedRun($admin),
            'finished' => $this->createWorkflowJob((int)$this->createWorkflowTemplate((int)$admin->id)->id, (int)$admin->id, WorkflowJob::STATUS_SUCCEEDED),
        ];

        foreach (
            [
                Display::WAITING_FOR_RESUME => ['paused'],
                Display::WAITING_FOR_APPROVAL => ['approval'],
                Display::WAITING_FOR_RUNNER => ['queued'],
                'nonsense' => ['paused', 'approval', 'queued', 'finished'],
                '' => ['paused', 'approval', 'queued', 'finished'],
            ] as $waitingFor => $expected
        ) {
            $this->setQueryParams(['waiting_for' => (string)$waitingFor]);
            $ctrl = $this->makeController();
            $ctrl->actionIndex();
            $listed = $this->listed($ctrl, $runs);
            $this->assertSame($expected, $listed, "waiting_for={$waitingFor}");
        }
    }

    /**
     * The filter links: All and one per wait, the active one marked; they
     * keep the list's workflow and status filters.
     */
    public function testTheFilterLinksKeepTheOtherFiltersAndMarkTheActiveOne(): void
    {
        $search = new WorkflowJobSearch();
        $search->load(['waiting_for' => 'resume', 'workflow_template_id' => '7', 'status' => 'running'], '');
        $html = $this->renderView('index', [
            'dataProvider' => new ArrayDataProvider(['allModels' => []]),
            'search' => $search,
            'runStates' => [],
        ]);

        $this->assertStringContainsString('<nav id="wj-filter"', $html);
        $this->assertStringContainsString('<a class="nav-link py-1" href="/workflow-job/index?workflow_template_id=7&amp;status=running" data-waiting-for="">All</a>', $html);
        $this->assertStringContainsString('<a class="nav-link py-1" href="/workflow-job/index?workflow_template_id=7&amp;status=running&amp;waiting_for=approval" data-waiting-for="approval">Awaiting Approval</a>', $html);
        $this->assertStringContainsString('<a class="nav-link py-1 active" href="/workflow-job/index?workflow_template_id=7&amp;status=running&amp;waiting_for=resume" data-waiting-for="resume" aria-current="page">Paused</a>', $html);
        $this->assertStringContainsString('<a class="nav-link py-1" href="/workflow-job/index?workflow_template_id=7&amp;status=running&amp;waiting_for=runner" data-waiting-for="runner">Queued</a>', $html);
        $this->assertStringContainsString(PollPayload::NO_MATCHING_RUNS, $html);
    }

    public function testAnEmptyListWithoutAFilterSaysThereAreNoRuns(): void
    {
        $html = $this->renderView('index', [
            'dataProvider' => new ArrayDataProvider(['allModels' => []]),
            'search' => new WorkflowJobSearch(),
            'runStates' => [],
        ]);

        $this->assertStringContainsString('<div class="text-muted">' . PollPayload::NO_RUNS . '</div>', $html);
        $this->assertStringContainsString('<a class="nav-link py-1 active" href="/workflow-job/index" data-waiting-for="" aria-current="page">All</a>', $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    /**
     * Review & decide is offered to an approver who may decide now, and to
     * nobody else; it leads to the request page.
     */
    public function testReviewAndDecideIsOfferedOnlyToAnApproverWhoMayDecide(): void
    {
        $admin = $this->createUserWithRole('wjp-admin', 'admin');
        $approver = $this->createUserWithRole('wjp-approver', 'operator');
        $bystander = $this->createUserWithRole('wjp-bystander', 'operator');
        [$run, , $request] = $this->approvalWaitRun($admin, [$admin, $approver]);

        $this->loginAs($approver);
        $html = $this->renderRunPage($run);
        $this->assertStringContainsString('<span data-banner-personal>' . Texts::PERSONAL_MAY_DECIDE . '</span>', $html);
        $this->assertStringContainsString(
            '<a id="wj-review-link" class="btn btn-sm btn-primary ms-2" href="/approval/view?id=' . $request->id . '">Review &amp; decide</a>',
            $html
        );

        $this->loginAs($bystander);
        $html = $this->renderRunPage($run);
        $this->assertStringContainsString('<span data-banner-personal>' . Texts::PERSONAL_NOT_APPROVER . '</span>', $html);
        $this->assertStringNotContainsString('wj-review-link', $html);
    }

    /**
     * The Resume form is rendered for whoever may resume the run, hidden
     * unless the run is paused, so the page's script can show it when the
     * run pauses later; Cancel shows until the run has finished.
     */
    public function testTheResumeFormIsHiddenUnlessTheRunIsPaused(): void
    {
        $admin = $this->createUserWithRole('wjp-admin', 'admin');
        [$waiting] = $this->approvalWaitRun($admin, [$admin]);
        $paused = $this->pausedRun($admin);
        $this->loginAs($admin);

        $html = $this->renderRunPage($waiting);
        $this->assertMatchesRegularExpression('#<form id="wj-resume-form" [^>]*onsubmit="return confirm\(\'Resume this workflow\?\'\)" hidden>#', $html);
        $this->assertMatchesRegularExpression('#<form id="wj-cancel-form" [^>]*onsubmit="return confirm\(\'Cancel this workflow\?\'\)">#', $html);

        $html = $this->renderRunPage($paused);
        $this->assertMatchesRegularExpression('#<form id="wj-resume-form" [^>]*onsubmit="return confirm\(\'Resume this workflow\?\'\)">#', $html);
        $this->assertStringContainsString('<div id="wj-wait-banner" class="alert alert-warning" role="status" data-wait="resume">', $html);
        $this->assertStringContainsString('Paused at step &quot;hold&quot; until someone resumes the run.', $html);
    }

    /**
     * While the run is unfinished, the page loads its script through a
     * versioned address and tells it where to poll; it carries the banner
     * it was rendered with, so the first poll does not draw it again.
     */
    public function testAnUnfinishedRunLoadsTheLiveScriptVersioned(): void
    {
        $admin = $this->createUserWithRole('wjp-admin', 'admin');
        [$run] = $this->approvalWaitRun($admin, [$admin]);
        $this->loginAs($admin);
        $ctrl = $this->makeController();
        $ctrl->actionView((int)$run->id);

        $html = $this->renderView('view', $ctrl->capturedParams);

        $this->assertStringContainsString('<script src="' . VersionedAssetUrl::of('/js/workflow-run-live.js') . '"></script>', $html);
        $this->assertMatchesRegularExpression('#\?v=[0-9a-f]{12}"#', VersionedAssetUrl::of('/js/workflow-run-live.js') . '"');
        if (preg_match('#<div id="wj-live-root" hidden\s+data-poll-url="([^"]*)"\s+data-job-url="([^"]*)"\s+data-banner="([^"]*)"></div>#', $html, $m) !== 1) {
            $this->fail('#wj-live-root is missing');
        }
        $this->assertSame('/workflow-job/status?id=' . $run->id, $m[1]);
        $this->assertSame('/job/view?id=JOBID', $m[2]);
        $this->assertSame($ctrl->capturedParams['payload']['banner'], Json::decode(html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5)));
        $this->assertStringContainsString('<span id="wj-live" class="badge text-bg-primary ms-2">Live</span>', $html);
        // The note the script shows in place of the Live badge once its poll is refused: the server's text.
        $this->assertStringContainsString(
            '<span id="wj-live-stopped" class="small text-body-secondary ms-2" role="status" hidden>' . Html::encode(Texts::LIVE_STOPPED_RUN) . '</span>',
            $html
        );
    }

    /**
     * The poll answers the actions as the page renders them: Resume only
     * while the run is paused, Cancel until it has finished, each only to a
     * user who may use it. It checks workflow.launch only for a paused run,
     * and nothing for a finished one.
     */
    public function testThePollOffersResumeOnlyWhilePausedAndNothingOnceFinished(): void
    {
        $admin = $this->createUserWithRole('wjp-admin', 'admin');
        [$waiting] = $this->approvalWaitRun($admin, [$admin]);
        $paused = $this->pausedRun($admin);
        $finished = $this->pausedRun($admin);
        $finished->status = WorkflowJob::STATUS_SUCCEEDED;
        $finished->save(false);
        $watcher = $this->createUser('wjp-watcher');
        $this->grantPermissions($watcher, ['workflow.view', 'workflow.cancel']);

        $this->loginAs($admin);
        $this->assertSame(['resume' => false, 'cancel' => true], $this->makeController()->actionStatus((int)$waiting->id)['actions']);
        $this->assertSame(['resume' => true, 'cancel' => true], $this->makeController()->actionStatus((int)$paused->id)['actions']);
        $this->assertSame(['resume' => false, 'cancel' => false], $this->makeController()->actionStatus((int)$finished->id)['actions']);
        $ctrl = $this->makeController();
        $ctrl->actionView((int)$finished->id);
        $this->assertSame([false, false], [$ctrl->capturedParams['mayResume'], $ctrl->capturedParams['mayCancel']]);

        // workflow.cancel, but not workflow.launch: Cancel only, also while paused.
        $this->loginAs($watcher);
        $this->assertSame(['resume' => false, 'cancel' => true], $this->makeController()->actionStatus((int)$paused->id)['actions']);
        $ctrl = $this->makeController();
        $ctrl->actionView((int)$waiting->id);
        $this->assertSame([false, true], [$ctrl->capturedParams['mayResume'], $ctrl->capturedParams['mayCancel']]);
    }

    /**
     * A finished run has nothing to follow: no script, no Live badge, no
     * actions and no banner.
     */
    public function testAFinishedRunHasNoScriptActionsOrBanner(): void
    {
        $admin = $this->createUserWithRole('wjp-admin', 'admin');
        $run = $this->createWorkflowJob((int)$this->createWorkflowTemplate((int)$admin->id)->id, (int)$admin->id, WorkflowJob::STATUS_SUCCEEDED);
        $this->loginAs($admin);

        $html = $this->renderRunPage($run);

        $this->assertStringNotContainsString('wj-live-root', $html);
        $this->assertStringNotContainsString('workflow-run-live.js', $html);
        $this->assertStringNotContainsString('id="wj-live"', $html);
        $this->assertStringNotContainsString('wj-live-stopped', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringContainsString('<div id="wj-wait-banner" class="alert" role="status" hidden></div>', $html);
        $this->assertStringContainsString('<span id="wj-status" class="badge text-bg-success">Succeeded</span>', $html);
        $this->assertStringContainsString('<div class="text-muted small mb-2" id="wj-empty-hint">', $html);
    }

    /**
     * The steps table: the order column explains itself, the table scrolls
     * on narrow screens, and a step's job and request are both linked.
     */
    public function testTheStepsTableLinksAStepsJobAndItsRequest(): void
    {
        $admin = $this->createUserWithRole('wjp-admin', 'admin');
        $rule = $this->ruleOf((int)$admin->id, [$admin]);
        $template = $this->openJobTemplate((int)$admin->id);
        $workflow = $this->createWorkflowTemplate((int)$admin->id);
        $deploy = $this->namedStep($workflow, 10, 'deploy', WorkflowStep::TYPE_JOB, (int)$template->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$admin->id);
        $job = $this->jobOf((int)$template->id, (int)$admin->id, Job::STATUS_PENDING_APPROVAL);
        $request = $this->requestOf($job, $rule);
        $this->executionOf($run, $deploy, WorkflowJobStep::STATUS_RUNNING, $job);
        $this->loginAs($admin);

        $html = $this->renderRunPage($run);

        $this->assertStringContainsString('<div class="table-responsive">', $html);
        $this->assertStringContainsString('<th title="' . PollPayload::ORDER_TITLE . '">#</th>', $html);
        $this->assertStringContainsString('<th>Job / Request</th>', $html);
        $this->assertStringContainsString(
            '<td data-wjs-job-cell><a href="/job/view?id=' . $job->id . '" data-wjs-job-link>#' . $job->id . '</a> · '
            . '<a href="/approval/view?id=' . $request->id . '" data-wjs-request-link>Request #' . $request->id . '</a></td>',
            $html
        );
        $this->assertStringContainsString(sprintf(Texts::BANNER_GATED_JOB, $job->id, 'deploy'), html_entity_decode($html));
    }

    /**
     * A user without approval.view sees the request named, unlinked, and is
     * never offered a page they may not open.
     */
    public function testWithoutApprovalViewTheRequestIsNamedButNotLinked(): void
    {
        $admin = $this->createUserWithRole('wjp-admin', 'admin');
        [$run, , $request] = $this->approvalWaitRun($admin, [$admin]);
        $watcher = $this->createUser('wjp-watcher');
        $this->grantPermissions($watcher, ['workflow.view']);
        $this->loginAs($watcher);

        $html = $this->renderRunPage($run);

        $this->assertStringContainsString('<li><span data-banner-request>Approval Request #' . $request->id . '</span></li>', $html);
        $this->assertStringContainsString('<span data-wjs-request-link>Request #' . $request->id . '</span>', $html);
        $this->assertStringNotContainsString('/approval/view', $html);
        $this->assertStringNotContainsString('data-banner-personal', $html, 'no relation without approval.view');
    }

    /**
     * Names are text: a step name or a rule name with markup is encoded in
     * the banner, the rows and the data the script reads.
     */
    public function testNamesWithMarkupAreEncodedEverywhere(): void
    {
        $admin = $this->createUserWithRole('wjp-admin', 'admin');
        [$run] = $this->approvalWaitRun($admin, [$admin], '<img src=x onerror=alert(1)> "q" \'a\'');
        $this->loginAs($admin);

        $html = $this->renderRunPage($run);

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('<td data-wjs-name-cell>&lt;img src=x onerror=alert(1)&gt; &quot;q&quot; &#039;a&#039;</td>', $html);
        $this->assertStringContainsString('Waiting for approval at step &quot;&lt;img src=x onerror=alert(1)&gt; &quot;q&quot; &#039;a&#039;&quot;.', $html);
    }

    /**
     * The poll of a run costs the same however many steps it ran (both runs
     * have decided requests, whose votes a decision note reads).
     */
    public function testThePollCostsTheSameForFewAndManySteps(): void
    {
        $admin = $this->createUserWithRole('wjp-admin', 'admin');
        $this->loginAs($admin);
        $few = $this->runWithSteps($admin, 4);
        $many = $this->runWithSteps($admin, 14);
        $this->makeController()->actionStatus((int)$few->id);

        $this->assertSame(
            $this->queriesOf(fn () => $this->makeController()->actionStatus((int)$few->id)),
            $this->queriesOf(fn () => $this->makeController()->actionStatus((int)$many->id))
        );
    }

    /**
     * The list costs the same however many runs it shows.
     */
    public function testTheListCostsTheSameForFewAndManyRuns(): void
    {
        $admin = $this->createUserWithRole('wjp-admin', 'admin');
        $this->loginAs($admin);
        $few = $this->runsOfOneWorkflow($admin, 2);
        $many = $this->runsOfOneWorkflow($admin, 12);
        $list = function (int $workflowId): void {
            $this->setQueryParams(['workflow_template_id' => (string)$workflowId]);
            $ctrl = $this->makeController();
            $ctrl->actionIndex();
            $this->renderView('index', $ctrl->capturedParams);
        };
        $list($few);

        $this->assertSame($this->queriesOf(fn () => $list($few)), $this->queriesOf(fn () => $list($many)));
    }

    // -- Fixtures -------------------------------------------------------------

    /**
     * A run waiting at its approval step, launched by $launcher; the rule's
     * approvers are $approvers.
     *
     * @param list<User> $approvers
     * @return array{0: WorkflowJob, 1: WorkflowJobStep, 2: ApprovalRequest}
     */
    private function approvalWaitRun(User $launcher, array $approvers, string $stepName = 'sign <off>'): array
    {
        $rule = $this->ruleOf((int)$launcher->id, $approvers);
        $workflow = $this->createWorkflowTemplate((int)$launcher->id);
        $gate = $this->namedStep($workflow, 10, $stepName, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$launcher->id);
        $run->current_step_id = (int)$gate->id;
        $run->save(false);
        $placeholder = $this->jobOf(null, (int)$launcher->id, Job::STATUS_PENDING_APPROVAL);
        $request = $this->requestOf($placeholder, $rule, ApprovalRequest::STATUS_PENDING, time() - 60);
        $execution = $this->executionOf($run, $gate, WorkflowJobStep::STATUS_RUNNING, $placeholder, null, time() - 70);

        return [$run, $execution, $request];
    }

    private function pausedRun(User $launcher): WorkflowJob
    {
        $workflow = $this->createWorkflowTemplate((int)$launcher->id);
        $pause = $this->namedStep($workflow, 10, 'hold', WorkflowStep::TYPE_PAUSE);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$launcher->id);
        $this->executionOf($run, $pause, WorkflowJobStep::STATUS_RUNNING);

        return $run;
    }

    private function queuedRun(User $launcher): WorkflowJob
    {
        $template = $this->openJobTemplate((int)$launcher->id);
        $workflow = $this->createWorkflowTemplate((int)$launcher->id);
        $build = $this->namedStep($workflow, 10, 'build', WorkflowStep::TYPE_JOB, (int)$template->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$launcher->id);
        $this->executionOf($run, $build, WorkflowJobStep::STATUS_RUNNING, $this->jobOf((int)$template->id, (int)$launcher->id, Job::STATUS_QUEUED, time()));

        return $run;
    }

    /**
     * A run that waits at its last of $count steps: approval steps whose
     * requests were approved, alternating with job steps.
     */
    private function runWithSteps(User $launcher, int $count): WorkflowJob
    {
        $rule = $this->ruleOf((int)$launcher->id, [$launcher]);
        $template = $this->openJobTemplate((int)$launcher->id);
        $workflow = $this->createWorkflowTemplate((int)$launcher->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$launcher->id);
        for ($i = 1; $i <= $count; $i++) {
            $last = $i === $count;
            if ($i % 2 === 0) {
                $step = $this->namedStep($workflow, $i * 10, 'gate ' . $i, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
                $job = $this->jobOf(null, (int)$launcher->id, $last ? Job::STATUS_PENDING_APPROVAL : Job::STATUS_SUCCEEDED);
                $this->requestOf($job, $rule, $last ? ApprovalRequest::STATUS_PENDING : ApprovalRequest::STATUS_APPROVED, time() - 60, null, $last ? null : time() - 30);
            } else {
                $step = $this->namedStep($workflow, $i * 10, 'job ' . $i, WorkflowStep::TYPE_JOB, (int)$template->id);
                $job = $this->jobOf((int)$template->id, (int)$launcher->id, Job::STATUS_SUCCEEDED, time() - 90);
            }
            $this->executionOf($run, $step, $last ? WorkflowJobStep::STATUS_RUNNING : WorkflowJobStep::STATUS_SUCCEEDED, $job);
        }

        return $run;
    }

    /**
     * $count runs of one new workflow, each waiting at its approval step.
     */
    private function runsOfOneWorkflow(User $launcher, int $count): int
    {
        $rule = $this->ruleOf((int)$launcher->id, [$launcher]);
        $workflow = $this->createWorkflowTemplate((int)$launcher->id);
        $gate = $this->namedStep($workflow, 10, 'gate', WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        for ($i = 0; $i < $count; $i++) {
            $run = $this->createWorkflowJob((int)$workflow->id, (int)$launcher->id);
            $placeholder = $this->jobOf(null, (int)$launcher->id, Job::STATUS_PENDING_APPROVAL);
            $this->requestOf($placeholder, $rule);
            $this->executionOf($run, $gate, WorkflowJobStep::STATUS_RUNNING, $placeholder);
        }

        return (int)$workflow->id;
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
        $role = $auth->createRole('wjp-role-' . bin2hex(random_bytes(4)));
        $auth->add($role);
        foreach ($permissions as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }
        $auth->assign($role, (string)$user->id);
    }

    // -- Helpers --------------------------------------------------------------

    private function engine(): WorkflowExecutionService
    {
        /** @var WorkflowExecutionService $engine */
        $engine = \Yii::$app->get('workflowExecutionService');

        return $engine;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function stepRow(array $payload, int $executionId): array
    {
        /** @var list<array<string, mixed>> $steps */
        $steps = $payload['steps'];
        foreach ($steps as $row) {
            if ($row['id'] === $executionId) {
                return $row;
            }
        }
        $this->fail('No row of execution #' . $executionId . ' in the status payload.');
    }

    /**
     * The IDs' keys of $among that the list shows, in the order of $among.
     *
     * @param array<string, WorkflowJob> $among
     * @return list<string>
     */
    private function listed(WorkflowJobController $ctrl, array $among): array
    {
        /** @var ActiveDataProvider $dataProvider */
        $dataProvider = $ctrl->capturedParams['dataProvider'];
        $dataProvider->pagination = false;
        $ids = array_map(static fn (WorkflowJob $run): int => (int)$run->id, $dataProvider->getModels());

        return array_keys(array_filter($among, static fn (WorkflowJob $run): bool => in_array((int)$run->id, $ids, true)));
    }

    private function renderRunPage(WorkflowJob $run): string
    {
        $ctrl = $this->makeController();
        $ctrl->actionView((int)$run->id);

        return $this->renderView('view', $ctrl->capturedParams);
    }

    /**
     * A real view of the run pages, rendered with $params, with dummy asset
     * bundles; view, asset manager and controller are restored.
     *
     * @param array<string, mixed> $params
     */
    private function renderView(string $view, array $params): string
    {
        $components = \Yii::$app->getComponents(true);
        $originals = ['view' => $components['view'] ?? null, 'assetManager' => $components['assetManager'] ?? null];
        $previousController = \Yii::$app->controller;
        \Yii::$app->set('assetManager', new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']));
        \Yii::$app->set('view', new View());
        $ctrl = new WorkflowJobController('workflow-job', \Yii::$app);
        \Yii::$app->controller = $ctrl;
        try {
            return $ctrl->renderPartial($view, $params);
        } finally {
            \Yii::$app->controller = $previousController;
            foreach ($originals as $id => $definition) {
                \Yii::$app->set($id, $definition);
            }
        }
    }

    /**
     * @return WorkflowJobController&object{capturedParams: array<string, mixed>}
     */
    private function makeController(): WorkflowJobController
    {
        return new class ('workflow-job', \Yii::$app) extends WorkflowJobController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }
        };
    }
}
