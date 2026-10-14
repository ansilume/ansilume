<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ApprovalController;
use app\controllers\SiteController;
use app\helpers\TimeHelper;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\models\WorkflowJob;
use app\services\runstate\Display;
use app\services\runstate\RequestContext;
use app\services\runstate\RunState;
use app\services\runstate\Texts;
use app\services\runstate\Viewer;
use app\tests\integration\CountsQueries;
use app\tests\integration\services\runstate\RunStateFixtures;
use app\tests\integration\TeamScopeFixtures;

/**
 * The dashboard says what running workflows wait for and what pending
 * approval requests are for, as the run state (WorkflowRunStates) and the
 * request contexts (ApprovalRequestContexts) derive them, with the real
 * services. Durations read in two units, never as a clock time. A workflow
 * is named only to a user who may view it, and the dashboard reads all of
 * this in a number of queries that does not grow with the runs.
 */
class DashboardWorkflowWaitsTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use RunStateFixtures;
    use WaitingRunFixtures;
    use CountsQueries;
    use RendersRealViews;

    /**
     * regression: the dashboard flagged pauses but not approval waits: a run
     * waiting at an approval step, or at a job step whose job waits for its
     * approval, looked like any running run, without a way to the request.
     */
    public function testRegressionTheDashboardFlaggedPausesButNotApprovalWaits(): void
    {
        $admin = $this->createUserWithRole('dash-admin', 'admin');
        $rule = $this->ruleOf((int)$admin->id, [$admin]);
        $waits = [
            $this->approvalWait($admin, 'Dash approval step', 'Sign-off', $rule),
            $this->approvalWait($admin, 'Dash gated step', 'Deploy', $rule, $this->openJobTemplate((int)$admin->id)),
        ];

        $xpath = $this->xpathOf($this->dashboardPage($admin));

        foreach ($waits as $wait) {
            $row = self::rowLinkingTo($xpath, '/workflow-job/view?id=' . $wait['run']->id);
            $this->assertNotNull($row, 'the run is listed');
            $this->assertStringContainsString($wait['step']->name . ' Awaiting Approval', self::textOf($row));
            $review = $xpath->query('.//a[@data-run-review]', $row)->item(0);
            $this->assertInstanceOf(\DOMElement::class, $review, 'a link to the request');
            $this->assertSame('/approval/view?id=' . $wait['request']->id, $review->getAttribute('href'));
            $this->assertSame('Review', self::textOf($review));
        }
    }

    /**
     * Each waiting state is flagged with its label and colour, and says
     * since when the run waits; a run whose step runs is not flagged.
     */
    public function testEveryWaitIsFlaggedAndARunningRunIsNot(): void
    {
        $admin = $this->createUserWithRole('dash-states', 'admin');
        $rule = $this->ruleOf((int)$admin->id, [$admin]);
        $template = $this->openJobTemplate((int)$admin->id);
        $runs = [
            Display::STATE_AWAITING_APPROVAL => $this->approvalWait($admin, 'States approval', 'Sign-off', $rule)['run'],
            Display::STATE_PAUSED => $this->runAtStep($admin, 'States pause', 'Hold')['run'],
            Display::STATE_QUEUED => $this->runAtStep($admin, 'States queued', 'Build', $template, Job::STATUS_QUEUED)['run'],
            Display::STATE_RUNNING => $this->runAtStep($admin, 'States running', 'Test', $template, Job::STATUS_RUNNING)['run'],
        ];

        $params = $this->dashboardFor($admin);
        $xpath = $this->xpathOf($this->render($params));

        foreach ($runs as $state => $run) {
            $runState = $params['runStates'][(int)$run->id] ?? null;
            $this->assertInstanceOf(RunState::class, $runState);
            $this->assertSame($state, $runState->state);
            $badges = $xpath->query(sprintf('//tr[@data-run-id="%d"]//*[@data-run-state]', $run->id));
            if ($state === Display::STATE_RUNNING) {
                $this->assertSame(0, $badges->length, 'a running run is not flagged');
                continue;
            }
            $badge = $badges->item(0);
            $this->assertInstanceOf(\DOMElement::class, $badge, $state);
            $this->assertSame($state, $badge->getAttribute('data-run-state'));
            $this->assertSame(Display::label($state), self::textOf($badge));
            $this->assertStringContainsString('text-bg-' . Display::css($state), $badge->getAttribute('class'));
            $waitingSince = $runState->current?->waitingSince;
            $this->assertNotNull($waitingSince, $state);
            $this->assertSame(sprintf(Texts::FACT_SINCE, TimeHelper::stamp($waitingSince)), $badge->getAttribute('title'));
        }
        $this->assertSame(['Awaiting Approval', 'Paused', 'Queued'], [
            Display::label(Display::STATE_AWAITING_APPROVAL),
            Display::label(Display::STATE_PAUSED),
            Display::label(Display::STATE_QUEUED),
        ]);
    }

    /**
     * Resume follows the derived state, not the run's live step: a paused
     * run offers it to a user who may resume it, a run waiting for approval
     * does not, although its current step was a pause step once.
     */
    public function testResumeIsOfferedForAPausedRunOnly(): void
    {
        $admin = $this->createUserWithRole('dash-resume', 'admin');
        $rule = $this->ruleOf((int)$admin->id, [$admin]);
        $paused = $this->runAtStep($admin, 'Resume pause', 'Hold')['run'];
        $waiting = $this->approvalWait($admin, 'Resume approval', 'Sign-off', $rule);
        $waiting['run']->current_step_id = $this->namedStep($waiting['workflow'], 9, 'Old pause', 'pause')->id;
        $waiting['run']->save(false);

        $xpath = $this->xpathOf($this->dashboardPage($admin));

        $this->assertSame(1, $this->resumeForms($xpath, $paused));
        $this->assertSame(0, $this->resumeForms($xpath, $waiting['run']));
        $this->assertSame(0, $xpath->query(sprintf('//tr[@data-run-id="%d"]//a[@data-run-review]', $paused->id))->length);
    }

    /**
     * The Review link needs approval.view and a request the user may see:
     * without approval.view the run is flagged, without a link.
     */
    public function testTheReviewLinkNeedsApprovalView(): void
    {
        $admin = $this->createUserWithRole('dash-review', 'admin');
        $rule = $this->ruleOf((int)$admin->id, [$admin]);
        $wait = $this->approvalWait($admin, 'Review rights', 'Sign-off', $rule);
        $watcher = $this->userWithPermissions('dash-watcher', ['workflow.view', 'workflow-template.view', 'job.view']);

        $xpath = $this->xpathOf($this->dashboardPage($watcher));

        $row = sprintf('//tr[@data-run-id="%d"]', $wait['run']->id);
        $this->assertSame(1, $xpath->query($row . '//*[@data-run-state="awaiting_approval"]')->length);
        $this->assertSame(0, $xpath->query($row . '//a[@data-run-review]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="dashboard-pending-approvals"]')->length, 'no approval.view: no pending approvals');
    }

    /**
     * regression: "Running for" wrapped after 24 hours: a job or run started
     * 25 hours ago read "01:00:00".
     */
    public function testRegressionRunningForWrappedAfter24Hours(): void
    {
        $admin = $this->createUserWithRole('dash-long', 'admin');
        $template = $this->openJobTemplate((int)$admin->id);
        $job = $this->createJob((int)$template->id, (int)$admin->id, Job::STATUS_RUNNING);
        $job->started_at = time() - 90000;
        $job->save(false);
        $run = $this->runAtStep($admin, 'Long pause', 'Hold')['run'];
        $run->started_at = time() - 3 * 86400 - 4 * 3600;
        $run->save(false);

        $xpath = $this->xpathOf($this->dashboardPage($admin));

        $jobCells = self::cellsOf(self::rowLinkingTo($xpath, '/job/view?id=' . $job->id));
        $this->assertSame('1d 1h', $jobCells[5] ?? null, 'Running for of the job');
        $runCells = self::cellsOf(self::rowLinkingTo($xpath, '/workflow-job/view?id=' . $run->id));
        $this->assertSame('3d 4h', $runCells[5] ?? null, 'Running for of the run');
        $this->assertSame(date('Y-m-d H:i:s', (int)$run->started_at), $runCells[4] ?? null, 'a start on another day shows its date');
    }

    /**
     * Every other duration reads in two units too: failed and recent jobs,
     * and the time until a schedule runs.
     */
    public function testDurationsReadInTwoUnits(): void
    {
        $admin = $this->createUserWithRole('dash-durations', 'admin');
        $template = $this->openJobTemplate((int)$admin->id);
        $failed = $this->createJob((int)$template->id, (int)$admin->id, Job::STATUS_FAILED);
        $failed->started_at = time() - 7980;
        $failed->finished_at = time();
        $failed->save(false);
        $schedule = $this->scheduleIn($template, 3 * 86400 + 4 * 3600 + 30);
        $due = $this->scheduleIn($template, -60);

        $html = $this->dashboardPage($admin);
        $xpath = $this->xpathOf($html);

        $failedRows = $xpath->query(sprintf('//tbody/tr[.//a[@href="/job/view?id=%d"]]', $failed->id));
        $this->assertSame(2, $failedRows->length, 'Failed Jobs and Recent Jobs');
        $this->assertSame('2h 13m', self::cellsOf($failedRows->item(0) instanceof \DOMElement ? $failedRows->item(0) : null)[4] ?? null);
        $this->assertSame('2h 13m', self::cellsOf($failedRows->item(1) instanceof \DOMElement ? $failedRows->item(1) : null)[6] ?? null);
        $this->assertSame('3d 4h', self::cellsOf(self::rowLinkingTo($xpath, '/schedule/view?id=' . $schedule))[4] ?? null);
        $this->assertSame('due now', self::cellsOf(self::rowLinkingTo($xpath, '/schedule/view?id=' . $due))[4] ?? null);
    }

    public function testPendingApprovalsSayWhatEachRequestIsForAndLinkToTheLists(): void
    {
        $admin = $this->createUserWithRole('dash-pending', 'admin');
        $rule = $this->ruleOf((int)$admin->id, [$admin]);
        $wait = $this->approvalWait($admin, 'Pending workflow', 'Sign-off', $rule);
        $template = $this->openJobTemplate((int)$admin->id);
        $plain = $this->requestOf($this->jobOf((int)$template->id, (int)$admin->id, Job::STATUS_PENDING_APPROVAL), $rule, 'pending', time());

        $params = $this->dashboardFor($admin);
        $xpath = $this->xpathOf($this->render($params));

        $this->assertInstanceOf(RequestContext::class, $params['approvalContexts'][(int)$plain->id] ?? null);
        $card = '//div[@id="dashboard-pending-approvals"]';
        $headers = [];
        foreach ($xpath->query($card . '//thead//th') ?: [] as $th) {
            $headers[] = self::textOf($th);
        }
        $this->assertSame(['Rule', 'For', 'Job', 'Requested', 'Expires', ''], $headers);
        $this->assertSame(
            sprintf(Texts::FOR_WORKFLOW_STEP, $wait['workflow']->name, $wait['step']->name, $wait['run']->id),
            self::textOf($xpath->query(sprintf('%s//tr[@data-request-id="%d"]/td[@data-approval-for]', $card, $wait['request']->id))->item(0))
        );
        $plainCells = self::cellsOf($this->elementAt($xpath, sprintf('%s//tr[@data-request-id="%d"]', $card, $plain->id)));
        $this->assertSame([$rule->name, sprintf(Texts::FOR_JOB_TEMPLATE, $template->name), '#' . $plain->job_id], array_slice($plainCells, 0, 3));
        $this->assertSame('Review', $plainCells[5] ?? null);
        $this->assertSame(
            ['All pending' => '/approval/index?status=pending'],
            $this->linksIn($xpath, $card . '/div[contains(@class, "card-header")]//a')
        );
        $this->assertSame(
            ['All Workflow Jobs' => '/workflow-job/index'],
            $this->linksIn($xpath, '//div[@id="dashboard-running-workflows"]/div[contains(@class, "card-header")]//a')
        );
        $this->assertSame(['All pending', 'Review', 'All Workflow Jobs'], [ApprovalController::ALL_PENDING, ApprovalController::REVIEW, SiteController::ALL_WORKFLOW_JOBS]);
    }

    /**
     * A team member sees the request of a gated job step through its job
     * template's project, but not the workflow, which has a job step of
     * another team: the dashboard names only the job template, and neither
     * the workflow nor its steps. An admin sees the workflow.
     */
    public function testATeamMemberNeverSeesTheNameOfAWorkflowTheyMayNotView(): void
    {
        $s = $this->teamScope();
        $rule = $this->ruleOf((int)$s['admin']->id, [$s['member'], $s['admin']]);
        $hidden = $this->approvalWait($s['admin'], 'Secret rollout', 'Hidden gate', $rule, $s['own'], [$s['foreign']]);
        $card = sprintf('//div[@id="dashboard-pending-approvals"]//tr[@data-request-id="%d"]/td[@data-approval-for]', $hidden['request']->id);

        $memberHtml = $this->dashboardPage($s['member']);

        $this->assertSame(sprintf(Texts::FOR_JOB_TEMPLATE, $s['own']->name), self::textOf($this->xpathOf($memberHtml)->query($card)->item(0)));
        $this->assertStringNotContainsString('Secret rollout', $memberHtml);
        $this->assertStringNotContainsString('Hidden gate', $memberHtml);
        $this->assertStringNotContainsString('later-0', $memberHtml);

        $adminXpath = $this->xpathOf($this->dashboardPage($s['admin']));
        $this->assertSame(
            sprintf(Texts::FOR_WORKFLOW_STEP, 'Secret rollout', 'Hidden gate', $hidden['run']->id),
            self::textOf($adminXpath->query($card)->item(0))
        );
    }

    /**
     * What the runs wait for and what the requests are for are read for all
     * of them at once: the dashboard sends as many queries for 12 waiting
     * runs as for 2, for an admin and for a team member.
     */
    public function testTheDashboardReadsWaitsInAConstantNumberOfQueries(): void
    {
        $s = $this->teamScope();
        $rule = $this->ruleOf((int)$s['admin']->id, [$s['member'], $s['admin']]);
        $this->waitsOfEveryKind($s, $rule, 1);
        $before = [];
        foreach (['admin', 'member'] as $key) {
            $this->dashboardPage($s[$key]);
            $before[$key] = $this->queriesOf(fn (): string => $this->dashboardPage($s[$key]));
        }

        $this->waitsOfEveryKind($s, $rule, 5);

        foreach (['admin', 'member'] as $key) {
            $this->assertSame($before[$key], $this->queriesOf(fn (): string => $this->dashboardPage($s[$key])), $key);
        }
    }

    /**
     * Resume is offered for the workflows the user may operate, read in one
     * query for all of them: as canOperateWorkflowTemplate() decides it.
     */
    public function testResumeFollowsOperatorAccessToEveryJobStep(): void
    {
        $s = $this->teamScope();
        $runs = [];
        foreach (['own', 'viewed', 'foreign', 'open'] as $key) {
            $run = $this->runAtStep($s['admin'], 'Operate ' . $key, 'Hold')['run'];
            $this->namedStep($this->workflowOf($run), 1, 'job', 'job', (int)$s[$key]->id);
            $runs[$key] = $run;
        }
        $access = \Yii::$app->get('workflowAccessChecker');
        $this->assertInstanceOf(\app\services\WorkflowAccessChecker::class, $access);

        foreach (['member', 'admin', 'outsider'] as $who) {
            $params = $this->dashboardFor($s[$who]);
            $expected = [];
            foreach ($params['runningWorkflows'] as $run) {
                $this->assertInstanceOf(WorkflowJob::class, $run);
                if ($access->canOperateWorkflowTemplate((int)$s[$who]->id, (int)$run->workflow_template_id)) {
                    $expected[] = (int)$run->workflow_template_id;
                }
            }
            $this->assertEqualsCanonicalizing($expected, $params['resumableWorkflowTemplateIds'], $who);
        }
        $member = $this->dashboardFor($s['member'])['resumableWorkflowTemplateIds'];
        $this->assertEqualsCanonicalizing(
            [(int)$runs['own']->workflow_template_id, (int)$runs['open']->workflow_template_id],
            $member
        );
    }

    /**
     * Recent Jobs names the approval step a placeholder job holds the
     * request of, instead of a template it does not have.
     */
    public function testRecentJobsNameThePlaceholderOfAnApprovalStep(): void
    {
        $admin = $this->createUserWithRole('dash-placeholder', 'admin');
        $wait = $this->approvalWait($admin, 'Placeholder workflow', 'Sign-off', $this->ruleOf((int)$admin->id, [$admin]));
        $purged = $this->jobOf(null, (int)$admin->id, Job::STATUS_FAILED);

        $xpath = $this->xpathOf($this->dashboardPage($admin));

        $recent = '//div[contains(@class, "card") and .//strong[text()="Recent Jobs"]]';
        $placeholderRow = $this->elementAt($xpath, sprintf('%s//tbody/tr[.//a[@href="/job/view?id=%d"]]', $recent, $wait['job']->id));
        $this->assertSame(sprintf(Texts::PLACEHOLDER_LABEL, 'Sign-off', $wait['run']->id), self::cellsOf($placeholderRow)[1] ?? null);
        $purgedRow = $this->elementAt($xpath, sprintf('%s//tbody/tr[.//a[@href="/job/view?id=%d"]]', $recent, $purged->id));
        $this->assertSame('—', self::cellsOf($purgedRow)[1] ?? null);
    }

    public function testTheSidebarNamesTheRunListWorkflowJobs(): void
    {
        $admin = $this->createUserWithRole('dash-sidebar', 'admin');
        $this->loginAs($admin);
        $ctrl = new SiteController('site', \Yii::$app);

        $html = $this->withRealViews($ctrl, '/site/index', fn (): string => \Yii::$app->view->renderFile('@app/views/layouts/main.php', ['content' => '']));

        $link = $this->elementAt($this->xpathOf($html), '//div[@id="sidebar"]//a[@href="/workflow-job/index"]');
        $this->assertSame('Workflow Jobs', self::textOf($link));
        $this->assertStringNotContainsString('>Executions<', $html);
    }

    public function testTheDashboardHandsTheViewTheViewerOfThePage(): void
    {
        $admin = $this->createUserWithRole('dash-viewer', 'admin');

        $params = $this->dashboardFor($admin);

        $this->assertInstanceOf(Viewer::class, $params['viewer']);
        $this->assertSame((int)$admin->id, $params['viewer']->userId);
    }

    // -- Helpers ----------------------------------------------------------------------

    /**
     * $rounds times: a run of a workflow the member may view at an approval
     * step, one at a gated job step of a workflow they may not view (its
     * request they see), and one paused run they may view.
     *
     * @param array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate} $s
     */
    private function waitsOfEveryKind(array $s, ApprovalRule $rule, int $rounds): void
    {
        for ($i = 0; $i < $rounds; $i++) {
            $this->approvalWait($s['admin'], 'Visible ' . $i, 'Gate', $rule, null, [$s['own']]);
            $this->approvalWait($s['admin'], 'Hidden ' . $i, 'Deploy', $rule, $s['own'], [$s['foreign']]);
            $paused = $this->runAtStep($s['admin'], 'Paused ' . $i, 'Hold')['run'];
            $this->namedStep($this->workflowOf($paused), 1, 'job', 'job', (int)$s['own']->id);
        }
    }

    private function workflowOf(WorkflowJob $run): \app\models\WorkflowTemplate
    {
        $workflow = $run->workflowTemplate;
        $this->assertNotNull($workflow);

        return $workflow;
    }

    private function scheduleIn(JobTemplate $template, int $seconds): int
    {
        $schedule = new Schedule();
        $schedule->name = 'dash-schedule-' . uniqid('', true);
        $schedule->job_template_id = $template->id;
        $schedule->cron_expression = '0 3 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->next_run_at = time() + $seconds;
        $schedule->created_by = $template->created_by;
        $schedule->created_at = time();
        $schedule->updated_at = time();
        $schedule->save(false);

        return (int)$schedule->id;
    }

    private function resumeForms(\DOMXPath $xpath, WorkflowJob $run): int
    {
        return $xpath->query(sprintf('//tbody/tr[.//a[@href="/workflow-job/view?id=%d"]]//form[@action="/workflow-job/resume?id=%d"]', $run->id, $run->id))->length;
    }

    private function elementAt(\DOMXPath $xpath, string $path): ?\DOMElement
    {
        $node = $xpath->query($path)->item(0);

        return $node instanceof \DOMElement ? $node : null;
    }

    /**
     * @return array<string, string> link text => href
     */
    private function linksIn(\DOMXPath $xpath, string $path): array
    {
        $links = [];
        foreach ($xpath->query($path) ?: [] as $link) {
            $links[self::textOf($link)] = $link instanceof \DOMElement ? $link->getAttribute('href') : '';
        }

        return $links;
    }

    /**
     * The parameters the dashboard hands its view, for $user.
     *
     * @return array<string, mixed>
     */
    private function dashboardFor(User $user): array
    {
        $this->loginAs($user);
        $ctrl = new class ('site', \Yii::$app) extends SiteController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }
        };
        $ctrl->actionIndex();

        return $ctrl->capturedParams;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function render(array $params): string
    {
        return \Yii::$app->view->renderFile('@app/views/site/index.php', $params);
    }

    /**
     * The dashboard as $user sees it: the action, then its view.
     */
    private function dashboardPage(User $user): string
    {
        return $this->render($this->dashboardFor($user));
    }
}
