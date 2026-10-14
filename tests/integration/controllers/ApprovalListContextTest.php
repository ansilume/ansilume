<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ApprovalController;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\services\ApprovalDecisionRefusedException;
use app\services\runstate\RequestContext;
use app\services\runstate\Texts;
use app\services\runstate\Viewer;
use app\tests\integration\CountsQueries;
use app\tests\integration\services\runstate\RunStateFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\data\ActiveDataProvider;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The approval list (/approval/index) and the request page say what each
 * request is for: the workflow, step and run of its job, or its job
 * template. A workflow is named only to a user who may view it (team
 * scoping), and links only for one with the page's permission. The list
 * filters by status and reads what its requests are for in a number of
 * queries that does not grow with them. Real services throughout.
 */
class ApprovalListContextTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use RunStateFixtures;
    use WaitingRunFixtures;
    use CountsQueries;

    /**
     * regression: the approvals list named only "Job #N" for a workflow step:
     * neither the workflow, nor the step, nor the run.
     */
    public function testRegressionTheListNamedOnlyTheJobOfAWorkflowStep(): void
    {
        $admin = $this->createUserWithRole('list-admin', 'admin');
        $rule = $this->ruleOf((int)$admin->id, [$admin]);
        $approvalStep = $this->approvalWait($admin, 'Ship release', 'Approve prod', $rule);
        $gatedStep = $this->approvalWait($admin, 'Build and test', 'Deploy', $rule, $this->openJobTemplate((int)$admin->id));
        $this->loginAs($admin);

        $xpath = $this->xpathOf($this->listPage());

        foreach ([$approvalStep, $gatedStep] as $wait) {
            $cells = self::cellsOf(self::rowLinkingTo($xpath, '/approval/view?id=' . $wait['request']->id));
            $this->assertSame('#' . $wait['job']->id, $cells[1] ?? null, 'the Job column stays the second one');
            $this->assertSame(
                sprintf('%s › %s (Workflow Job #%d)', $wait['workflow']->name, $wait['step']->name, $wait['run']->id),
                $cells[2] ?? null,
                'the For column names the workflow, the step and the run'
            );
        }
    }

    public function testTheListHasItsTitleColumnsAndStatusFilter(): void
    {
        $admin = $this->createUserWithRole('list-columns', 'admin');
        $this->approvalWait($admin, 'Columns', 'Gate', $this->ruleOf((int)$admin->id, [$admin]));
        $this->loginAs($admin);

        $html = $this->listPage();
        $xpath = $this->xpathOf($html);

        $this->assertStringContainsString('<h2>Approval Requests</h2>', $html);
        $this->assertSame(ApprovalController::LIST_TITLE, \Yii::$app->view->title);
        $headers = [];
        foreach ($xpath->query('//div[contains(@class, "table-responsive")]/table[@id="approval-requests-table"]/thead//th') ?: [] as $th) {
            $headers[] = self::textOf($th);
        }
        $this->assertSame(['#', 'Job', 'For', 'Rule', 'Status', 'Requested', 'Expires', 'Resolved'], $headers);
        $this->assertSame(ApprovalController::LIST_COLUMNS, $headers);
        $filters = [];
        foreach ($xpath->query('//nav[@id="approval-status-filter"]/a[@data-status]') ?: [] as $link) {
            $this->assertInstanceOf(\DOMElement::class, $link);
            $filters[$link->getAttribute('data-status')] = [self::textOf($link), $link->getAttribute('href')];
        }
        $this->assertSame([
            '' => ['All', '/approval/index'],
            'pending' => ['Pending', '/approval/index?status=pending'],
            'approved' => ['Approved', '/approval/index?status=approved'],
            'rejected' => ['Rejected', '/approval/index?status=rejected'],
            'timed_out' => ['Timed Out', '/approval/index?status=timed_out'],
            'canceled' => ['Canceled', '/approval/index?status=canceled'],
        ], $filters);
        $this->assertSame(['All'], $this->activeFilters($xpath));
    }

    public function testTheStatusFilterListsOnlyRequestsOfThatStatusAndMarksItsLink(): void
    {
        $admin = $this->createUserWithRole('list-filter', 'admin');
        $rule = $this->ruleOf((int)$admin->id, [$admin]);
        $pending = $this->approvalWait($admin, 'Filter pending', 'Gate', $rule)['request'];
        $approved = $this->approvalWait($admin, 'Filter approved', 'Gate', $rule)['request'];
        ApprovalRequest::updateAll(['status' => ApprovalRequest::STATUS_APPROVED, 'resolved_at' => time()], ['id' => $approved->id]);
        $this->loginAs($admin);

        $this->setQueryParams(['status' => 'pending']);
        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        $this->assertSame('pending', $ctrl->capturedParams['activeStatus']);
        $ids = $this->listedIds($ctrl);
        $this->assertContains((int)$pending->id, $ids);
        $this->assertNotContains((int)$approved->id, $ids);
        $this->assertSame(['Pending'], $this->activeFilters($this->xpathOf($this->render($ctrl, 'index'))));
    }

    /**
     * The web list ignores a status it does not know, so an old or mistyped
     * link still opens: every request, with All marked.
     */
    public function testAnUnknownStatusListsEveryRequest(): void
    {
        $admin = $this->createUserWithRole('list-unknown', 'admin');
        $rule = $this->ruleOf((int)$admin->id, [$admin]);
        $pending = $this->approvalWait($admin, 'Unknown filter', 'Gate', $rule)['request'];
        $this->loginAs($admin);

        foreach (['bogus', ['pending'], 'PENDING'] as $status) {
            $this->setQueryParams(['status' => $status]);
            $ctrl = $this->makeController();
            $ctrl->actionIndex();

            $this->assertNull($ctrl->capturedParams['activeStatus']);
            $this->assertContains((int)$pending->id, $this->listedIds($ctrl));
            $this->assertSame(['All'], $this->activeFilters($this->xpathOf($this->render($ctrl, 'index'))));
        }
    }

    public function testAnEmptyListSaysSoForItsStatus(): void
    {
        $ctrl = $this->makeController();
        $empty = new ActiveDataProvider(['query' => ApprovalRequest::find()->where('0 = 1')]);

        foreach ([[null, ApprovalController::NO_REQUESTS], ['canceled', 'No approval requests with the status Canceled.']] as [$status, $text]) {
            $ctrl->capturedParams = ['dataProvider' => $empty, 'contexts' => [], 'activeStatus' => $status, 'viewer' => Viewer::current()];
            $node = $this->xpathOf($this->render($ctrl, 'index'))->query('//*[@id="approval-requests-empty"]');
            $this->assertSame($text, self::textOf($node === false ? null : $node->item(0)));
        }
    }

    /**
     * Expires: when a pending request times out, Never without a timeout,
     * and nothing once the request is resolved.
     */
    public function testExpiresSaysWhenAPendingRequestTimesOut(): void
    {
        $admin = $this->createUserWithRole('list-expires', 'admin');
        $rule = $this->ruleOf((int)$admin->id, [$admin]);
        $never = $this->approvalWait($admin, 'Expires never', 'Gate', $rule)['request'];
        $soon = $this->approvalWait($admin, 'Expires soon', 'Gate', $rule)['request'];
        $resolved = $this->approvalWait($admin, 'Expires resolved', 'Gate', $rule)['request'];
        // Two and a half hours: "in 2 hours" also when the clock moves on before the page is rendered.
        ApprovalRequest::updateAll(['expires_at' => time() + 9000], ['id' => [$soon->id, $resolved->id]]);
        ApprovalRequest::updateAll(['status' => ApprovalRequest::STATUS_REJECTED, 'resolved_at' => time()], ['id' => $resolved->id]);
        $this->loginAs($admin);

        $xpath = $this->xpathOf($this->listPage());

        $this->assertSame('Never', $this->expiresOf($xpath, $never));
        $this->assertSame('in 2 hours', $this->expiresOf($xpath, $soon));
        $this->assertSame('—', $this->expiresOf($xpath, $resolved));
    }

    /**
     * A team member may see the request of a gated job step through its job
     * template's project, although they may not view the workflow, which has
     * a job step of another team: they learn only the job template, on the
     * list and on the request page. An admin sees the workflow.
     */
    public function testATeamMemberNeverSeesTheNameOfAWorkflowTheyMayNotView(): void
    {
        $s = $this->teamScope();
        $rule = $this->ruleOf((int)$s['admin']->id, [$s['member'], $s['admin']]);
        $hidden = $this->approvalWait($s['admin'], 'Secret rollout', 'Hidden gate', $rule, $s['own'], [$s['foreign']]);
        $visible = $this->approvalWait($s['admin'], 'Team rollout', 'Team gate', $rule, null, [$s['own']]);
        $own = sprintf(Texts::FOR_JOB_TEMPLATE, $s['own']->name);

        $this->loginAs($s['member']);
        $html = $this->listPage();
        $xpath = $this->xpathOf($html);

        $this->assertSame($own, $this->forOf($xpath, $hidden['request']));
        $this->assertSame($this->forText($visible), $this->forOf($xpath, $visible['request']));
        $this->assertStringNotContainsString('Secret rollout', $html);
        $this->assertStringNotContainsString('Hidden gate', $html);
        $page = $this->requestPage($hidden['request']);
        $this->assertSame($own, self::textOf($this->xpathOf($page)->query('//td[@id="approval-for"]')->item(0)));
        $this->assertStringNotContainsString('Secret rollout', $page);
        $this->assertStringNotContainsString('Hidden gate', $page);

        $this->loginAs($s['admin']);
        $this->assertSame($this->forText($hidden), $this->forOf($this->xpathOf($this->listPage()), $hidden['request']));
        $this->assertSame(
            $this->forText($hidden),
            self::textOf($this->xpathOf($this->requestPage($hidden['request']))->query('//td[@id="approval-for"]')->item(0))
        );
    }

    /**
     * The request page names what the request is for: a workflow step, a
     * job template, a deleted job template, or nothing (a purged template).
     */
    public function testTheRequestPageSaysWhatTheRequestIsFor(): void
    {
        $admin = $this->createUserWithRole('page-admin', 'admin');
        $rule = $this->ruleOf((int)$admin->id, [$admin]);
        $step = $this->approvalWait($admin, 'Page workflow', 'Page gate', $rule);
        $template = $this->openJobTemplate((int)$admin->id);
        $plain = $this->requestOf($this->jobOf((int)$template->id, (int)$admin->id, Job::STATUS_PENDING_APPROVAL), $rule);
        $deletedTemplate = $this->openJobTemplate((int)$admin->id);
        $deleted = $this->requestOf($this->jobOf((int)$deletedTemplate->id, (int)$admin->id, Job::STATUS_PENDING_APPROVAL), $rule);
        JobTemplate::updateAll(['deleted_at' => time()], ['id' => $deletedTemplate->id]);
        $purged = $this->requestOf($this->jobOf(null, (int)$admin->id, Job::STATUS_PENDING_APPROVAL), $rule);
        $this->loginAs($admin);

        $expected = [
            [$step['request'], $this->forText($step)],
            [$plain, sprintf(Texts::FOR_JOB_TEMPLATE, $template->name)],
            [$deleted, sprintf(Texts::FOR_DELETED_JOB_TEMPLATE, $deletedTemplate->name)],
            [$purged, RequestContext::UNKNOWN],
        ];
        foreach ($expected as [$request, $text]) {
            $ctrl = $this->makeController();
            $ctrl->actionView((int)$request->id);
            $context = $ctrl->capturedParams['context'];
            $this->assertInstanceOf(RequestContext::class, $context);
            $this->assertSame($text, $context->text());
            $cell = $this->xpathOf($this->render($ctrl, 'view'))->query('//td[@id="approval-for"]')->item(0);
            $this->assertSame($text, self::textOf($cell));
        }
    }

    /**
     * The workflow's name links to the workflow only with
     * workflow-template.view, the run to the run page only with
     * workflow.view; the text stays the same.
     */
    public function testTheForCellLinksOnlyThePagesTheUserMayOpen(): void
    {
        $admin = $this->createUserWithRole('links-admin', 'admin');
        $rule = $this->ruleOf((int)$admin->id, [$admin]);
        $wait = $this->approvalWait($admin, 'Linked workflow', 'Linked gate', $rule);
        $template = '/workflow-template/view?id=' . $wait['workflow']->id;
        $run = '/workflow-job/view?id=' . $wait['run']->id;
        $cases = [
            'both' => [['approval.view', 'workflow.view', 'workflow-template.view'], [$template, $run]],
            'the run only' => [['approval.view', 'workflow.view'], [$run]],
            'the workflow only' => [['approval.view', 'workflow-template.view'], [$template]],
            'neither' => [['approval.view'], []],
        ];

        foreach ($cases as $case => [$permissions, $links]) {
            $this->loginAs($this->userWithPermissions('links-' . str_replace(' ', '-', $case), $permissions));
            $cell = $this->forCellOf($this->xpathOf($this->listPage()), $wait['request']);
            $this->assertSame($this->forText($wait), self::textOf($cell), $case);
            $hrefs = [];
            foreach ($cell?->getElementsByTagName('a') ?? [] as $link) {
                $hrefs[] = $link->getAttribute('href');
            }
            $this->assertSame($links, $hrefs, $case);
        }
    }

    public function testNamesInTheForCellAreEncoded(): void
    {
        $admin = $this->createUserWithRole('xss-admin', 'admin');
        $rule = $this->ruleOf((int)$admin->id, [$admin]);
        $evil = '<img src=x onerror=alert(1)> "q" \'a\' & Workflow Job #1';
        $wait = $this->approvalWait($admin, $evil, $evil, $rule);
        $this->loginAs($admin);

        $html = $this->listPage();
        $cell = $this->forCellOf($this->xpathOf($html), $wait['request']);

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', self::innerHtmlOf($cell));
        $this->assertSame($this->forText($wait), self::textOf($cell), 'a name holding the run label stays as it is');
        $this->assertSame(2, $cell?->getElementsByTagName('a')->length);
    }

    /**
     * The list reads what its requests are for, and which workflows the
     * user may view, for the whole page at once: the number of queries does
     * not grow with the requests, for an admin and for a team member.
     */
    public function testTheListReadsWhatRequestsAreForInAConstantNumberOfQueries(): void
    {
        $s = $this->teamScope();
        $rule = $this->ruleOf((int)$s['admin']->id, [$s['member'], $s['admin']]);
        $this->requestsOfEveryKind($s, $rule, 1);
        $before = [];
        foreach (['admin', 'member'] as $key) {
            $this->loginAs($s[$key]);
            $this->listPage();
            $before[$key] = $this->queriesOf(fn (): string => $this->listPage());
        }

        $this->requestsOfEveryKind($s, $rule, 5);

        foreach (['admin', 'member'] as $key) {
            $this->loginAs($s[$key]);
            $this->assertSame($before[$key], $this->queriesOf(fn (): string => $this->listPage()), $key);
        }
    }

    /**
     * The launcher of a workflow run is told that they launched the run,
     * not "this job", on the request page and when they vote anyway: for
     * the placeholder of an approval step and for a gated job step.
     */
    public function testTheLauncherOfAWorkflowRunIsToldTheyLaunchedTheRun(): void
    {
        $launcher = $this->createUserWithRole('run-launcher', 'operator');
        $other = $this->createUserWithRole('run-approver', 'operator');
        $rule = $this->ruleOf((int)$launcher->id, [$launcher, $other]);
        $waits = [
            $this->approvalWait($launcher, 'Launcher run', 'Approve', $rule),
            $this->approvalWait($launcher, 'Launcher gated run', 'Deploy', $rule, $this->openJobTemplate((int)$launcher->id)),
        ];
        $this->loginAs($launcher);

        foreach ($waits as $wait) {
            $ctrl = $this->makeController();
            $ctrl->actionView((int)$wait['request']->id);
            $this->assertFalse($ctrl->capturedParams['canDecide']);
            $this->assertSame(ApprovalDecisionRefusedException::LAUNCHER_REFUSED_RUN, $ctrl->capturedParams['decisionNote']);
            $note = $this->xpathOf($this->render($ctrl, 'view'))->query('//*[@id="approval-decision-note"]')->item(0);
            $this->assertSame('You launched this workflow run; another approver must decide.', self::textOf($note));

            try {
                $this->makeController()->actionApprove((int)$wait['request']->id);
                $this->fail('Expected 403');
            } catch (ForbiddenHttpException $e) {
                $this->assertSame(ApprovalDecisionRefusedException::LAUNCHER_REFUSED_RUN, $e->getMessage());
            }
        }
    }

    // -- Helpers ----------------------------------------------------------------------

    /**
     * $rounds times: a request of an approval step of a workflow the member
     * may view, one of a gated job step of a workflow they may not, and one
     * of a gated job template outside workflows.
     *
     * @param array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate} $s
     */
    private function requestsOfEveryKind(array $s, ApprovalRule $rule, int $rounds): void
    {
        for ($i = 0; $i < $rounds; $i++) {
            $this->approvalWait($s['admin'], 'Visible ' . $i, 'Gate', $rule, null, [$s['own']]);
            $this->approvalWait($s['admin'], 'Hidden ' . $i, 'Deploy', $rule, $s['own'], [$s['foreign']]);
            $this->requestOf($this->jobOf((int)$s['own']->id, (int)$s['admin']->id, Job::STATUS_PENDING_APPROVAL), $rule);
        }
    }

    /**
     * Texts::FOR_WORKFLOW_STEP of $wait.
     *
     * @param array{workflow: \app\models\WorkflowTemplate, step: \app\models\WorkflowStep, run: \app\models\WorkflowJob} $wait
     */
    private function forText(array $wait): string
    {
        return sprintf(Texts::FOR_WORKFLOW_STEP, $wait['workflow']->name, $wait['step']->name, $wait['run']->id);
    }

    private function forCellOf(\DOMXPath $xpath, ApprovalRequest $request): ?\DOMElement
    {
        $cell = $xpath->query(sprintf('//tr[@data-request-id="%d"]/td[@data-approval-for]', $request->id))->item(0);

        return $cell instanceof \DOMElement ? $cell : null;
    }

    private function forOf(\DOMXPath $xpath, ApprovalRequest $request): string
    {
        return self::textOf($this->forCellOf($xpath, $request));
    }

    private function expiresOf(\DOMXPath $xpath, ApprovalRequest $request): string
    {
        return self::textOf($xpath->query(sprintf('//tr[@data-request-id="%d"]/td[@data-approval-expires]', $request->id))->item(0));
    }

    /**
     * @return list<string>
     */
    private function activeFilters(\DOMXPath $xpath): array
    {
        $active = [];
        foreach ($xpath->query('//nav[@id="approval-status-filter"]/a[@aria-current="page"]') ?: [] as $link) {
            $this->assertStringContainsString('active', $link instanceof \DOMElement ? $link->getAttribute('class') : '');
            $active[] = self::textOf($link);
        }

        return $active;
    }

    /**
     * @return list<int>
     */
    private function listedIds(ApprovalController $ctrl): array
    {
        $dataProvider = $ctrl->capturedParams['dataProvider'];
        $this->assertInstanceOf(ActiveDataProvider::class, $dataProvider);

        return array_map(static fn (ApprovalRequest $r): int => (int)$r->id, $dataProvider->getModels());
    }

    /**
     * The list as the signed-in user sees it: the action, then its view.
     */
    private function listPage(): string
    {
        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        return $this->render($ctrl, 'index');
    }

    private function requestPage(ApprovalRequest $request): string
    {
        $ctrl = $this->makeController();
        $ctrl->actionView((int)$request->id);

        return $this->render($ctrl, 'view');
    }

    /**
     * $view of the approval pages with the parameters $ctrl's action
     * passed, while $ctrl is the current controller (the pages link to
     * routes relative to it).
     */
    private function render(ApprovalController $ctrl, string $view): string
    {
        $previous = \Yii::$app->controller;
        \Yii::$app->controller = $ctrl;
        try {
            return \Yii::$app->view->renderFile('@app/views/approval/' . $view . '.php', $ctrl->capturedParams);
        } finally {
            \Yii::$app->controller = $previous;
        }
    }

    private function makeController(): ApprovalController
    {
        return new class ('approval', \Yii::$app) extends ApprovalController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): Response
            {
                $r = new Response();
                $r->content = 'redirected';
                return $r;
            }
        };
    }
}
