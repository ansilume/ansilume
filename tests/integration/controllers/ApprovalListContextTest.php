<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ApprovalController;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\services\ApprovalDecisionRefusedException;
use app\services\runstate\ApprovalRequestContexts;
use app\services\runstate\RequestContext;
use app\services\runstate\Texts;
use app\services\runstate\Viewer;
use app\tests\integration\CountsQueries;
use app\tests\integration\services\runstate\RunStateFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\data\ActiveDataProvider;
use yii\db\Query;
use yii\helpers\Html;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The approval list (/approval/index) and the request page say what each
 * request is for: the workflow, step and run of its job, or its job
 * template. A workflow is named only to a user who may view it (team
 * scoping), and links only for one with the page's permission. The list
 * filters by status and reads what its requests are for in a number of
 * queries that does not grow with them. Real services throughout.
 *
 * Since 2.12.0 the list's Rule cell names the inventory of a request that
 * the inventory of its job required, and the request page names the
 * inventory the job was launched with (#approval-inventory) and what
 * required the request (#approval-required-by).
 */
class ApprovalListContextTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use RunStateFixtures;
    use WaitingRunFixtures;
    use CountsQueries;
    use InventoryRequestFixtures;

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

    // -- What required a request, and the inventory of its job (2.12.0) ---------------

    /**
     * The Rule cell names the inventory of a request that the inventory of
     * its job required, by the inventory's name at launch: not that of a
     * request its job template's own rule required, although that job was
     * launched with an inventory too, nor that of an approval step's
     * request. A rule that no longer exists stays a dash. The cell stays
     * the fourth column.
     */
    public function testTheRuleCellNamesTheInventoryOnlyOfARequestThatTheInventoryRequired(): void
    {
        $admin = $this->createUserWithRole('rule-cell-admin', 'admin');
        $launcher = $this->createUserWithRole('rule-cell-launcher', 'operator');
        $rule = $this->namedRule($admin, 'Change board');
        $byInventory = $this->inventoryGatedRequest($launcher, $rule, 'web-prod');
        $byTemplate = $this->templateGatedRequest($launcher, $rule, 'db-prod');
        $byStep = $this->requiredByApprovalStep($this->approvalWait($admin, 'Rule cell workflow', 'Sign-off', $rule)['request']);
        $ruleGone = $this->inventoryGatedRequest($launcher, $this->namedRule($admin, 'Gone board'), 'app-prod')['request'];
        ApprovalRequest::updateAll(['approval_rule_id' => null], ['id' => $ruleGone->id]);
        $byInventory['inventory']->name = 'web-prod-renamed';
        $byInventory['inventory']->save(false);
        $this->loginAs($admin);

        $xpath = $this->xpathOf($this->listPage());

        $this->assertSame('Change board (of inventory web-prod)', $this->ruleCellOf($xpath, $byInventory['request']));
        $this->assertSame(sprintf(Texts::RULE_OF_INVENTORY, 'Change board', 'web-prod'), $this->ruleCellOf($xpath, $byInventory['request']));
        $this->assertSame('Change board', $this->ruleCellOf($xpath, $byTemplate['request']), 'the job template required it');
        $this->assertSame('Change board', $this->ruleCellOf($xpath, $byStep), 'an approval step required it');
        $this->assertSame(RequestContext::UNKNOWN, $this->ruleCellOf($xpath, $ruleGone), 'its rule no longer exists');
        $cells = self::cellsOf(self::rowLinkingTo($xpath, '/approval/view?id=' . $byInventory['request']->id));
        $this->assertSame(
            ['#' . $byInventory['job']->id, sprintf(Texts::FOR_JOB_TEMPLATE, $byInventory['template']->name), 'Change board (of inventory web-prod)'],
            array_slice($cells, 1, 3),
            'Job, For and Rule keep their columns'
        );
    }

    /**
     * The request page names the inventory the job was launched with, right
     * after the job, and what required the request, right after the rule:
     * the inventory, the job template or a workflow approval step.
     */
    public function testTheRequestPageNamesTheInventoryAndWhatRequiredTheRequest(): void
    {
        $admin = $this->createUserWithRole('required-admin', 'admin');
        $launcher = $this->createUserWithRole('required-launcher', 'operator');
        $rule = $this->namedRule($admin, 'Change board');
        $byInventory = $this->inventoryGatedRequest($launcher, $rule, 'web-prod');
        $byTemplate = $this->templateGatedRequest($launcher, $rule, 'db-prod');
        $byStep = $this->requiredByApprovalStep($this->approvalWait($admin, 'Required workflow', 'Sign-off', $rule)['request']);
        $this->loginAs($admin);

        $cases = [
            'inventory' => [$byInventory['request'], 'Inventory', 'web-prod', ['/inventory/view?id=' . $byInventory['inventory']->id]],
            'job template' => [$byTemplate['request'], 'Job template', 'db-prod', ['/inventory/view?id=' . $byTemplate['inventory']->id]],
            'approval step' => [$byStep, 'Workflow approval step', '—', []],
        ];
        foreach ($cases as $case => [$request, $requiredBy, $inventory, $links]) {
            $xpath = $this->xpathOf($this->requestPage($request));

            $this->assertSame(
                ['Status', 'For', 'Job', 'Inventory', 'Launched by', 'Rule', 'Required by', 'Approvals', 'Rejections', 'Requested', 'Expires', 'Resolved'],
                $this->requestPageHeaders($xpath),
                $case
            );
            $this->assertSame($requiredBy, self::textOf($this->elementById($xpath, 'approval-required-by')), $case);
            $inventoryCell = $this->elementById($xpath, 'approval-inventory');
            $this->assertSame($inventory, self::textOf($inventoryCell), $case);
            $this->assertSame($links, $this->hrefsIn($inventoryCell), $case);
        }
        $this->assertSame(
            [Texts::INVENTORY_LABEL, Texts::REQUIRED_BY_LABEL],
            ['Inventory', 'Required by']
        );
        $this->assertSame(
            ['Job template', 'Inventory', 'Workflow approval step'],
            array_map([ApprovalRequest::class, 'requiredByLabel'], ApprovalRequest::REQUIRED_BY_VALUES)
        );
    }

    /**
     * The inventory as launched: renamed since, it keeps its name at launch;
     * once deleted, it is named and marked deleted, without a link.
     */
    public function testTheRequestPageNamesTheInventoryAsLaunched(): void
    {
        $admin = $this->createUserWithRole('launched-admin', 'admin');
        $launcher = $this->createUserWithRole('launched-launcher', 'operator');
        $wait = $this->inventoryGatedRequest($launcher, $this->namedRule($admin, 'Change board'), 'web-prod');
        $wait['inventory']->name = 'web-prod-renamed';
        $wait['inventory']->save(false);
        $this->loginAs($admin);

        $renamed = $this->elementById($this->xpathOf($this->requestPage($wait['request'])), 'approval-inventory');
        $this->assertSame('web-prod', self::textOf($renamed));
        $link = $renamed?->getElementsByTagName('a')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $link);
        $this->assertSame((string)$wait['inventory']->id, $link->getAttribute('data-inventory-id'));

        $this->moveTemplateTo($wait['template'], $this->inventoryNamed((int)$admin->id, 'db-prod'));
        $this->assertSame(1, Inventory::deleteAll(['id' => $wait['inventory']->id]));
        $deleted = $this->elementById($this->xpathOf($this->requestPage($wait['request'])), 'approval-inventory');

        $this->assertSame('web-prod (deleted)', self::textOf($deleted));
        $this->assertSame('web-prod <span class="text-muted">(deleted)</span>', trim(self::innerHtmlOf($deleted)));
        $this->assertSame([], $this->hrefsIn($deleted));
    }

    /**
     * Before m000088 (or with a schema cached from before it) a request has
     * no required_by: the page says nothing required it, and still names
     * the inventory. A page rendered without a context takes what required
     * the request from the request itself, and names no inventory.
     */
    public function testWithoutRequiredByOrAContextTheRequestPageStillRenders(): void
    {
        $admin = $this->createUserWithRole('legacy-admin', 'admin');
        $launcher = $this->createUserWithRole('legacy-launcher', 'operator');
        $wait = $this->inventoryGatedRequest($launcher, $this->namedRule($admin, 'Change board'), 'web-prod');
        $this->loginAs($admin);
        $ctrl = $this->makeController();
        $ctrl->actionView((int)$wait['request']->id);
        $old = new class () extends ApprovalRequest {
            public function attributes(): array
            {
                return array_values(array_diff(parent::attributes(), ['required_by']));
            }
        };
        $row = (new Query())->from(ApprovalRequest::tableName())->where(['id' => $wait['request']->id])->one();
        $this->assertIsArray($row);
        $old::populateRecord($old, $row);
        /** @var ApprovalRequestContexts $contexts */
        $contexts = \Yii::$app->get('approvalRequestContexts');
        $ctrl->capturedParams['model'] = $old;
        $ctrl->capturedParams['context'] = $contexts->forRequests([$old], Viewer::current())[(int)$old->id] ?? null;

        $before = $this->xpathOf($this->render($ctrl, 'view'));

        $this->assertSame('—', self::textOf($this->elementById($before, 'approval-required-by')));
        $this->assertSame('web-prod', self::textOf($this->elementById($before, 'approval-inventory')));

        $ctrl->capturedParams['model'] = $wait['request'];
        unset($ctrl->capturedParams['context']);
        $withoutContext = $this->xpathOf($this->render($ctrl, 'view'));

        $this->assertSame('Inventory', self::textOf($this->elementById($withoutContext, 'approval-required-by')));
        $this->assertSame('—', self::textOf($this->elementById($withoutContext, 'approval-inventory')));
        $this->assertSame(RequestContext::UNKNOWN, self::textOf($this->elementById($withoutContext, 'approval-for')));
    }

    public function testInventoryAndRuleNamesAreEncodedOnTheListAndTheRequestPage(): void
    {
        $evil = '<img src=x onerror=alert(1)> "q" \'a\'';
        $admin = $this->createUserWithRole('enc-admin', 'admin');
        $launcher = $this->createUserWithRole('enc-launcher', 'operator');
        $wait = $this->inventoryGatedRequest($launcher, $this->namedRule($admin, $evil . ' rule'), $evil);
        $this->loginAs($admin);

        $list = $this->listPage();
        $page = $this->requestPage($wait['request']);

        foreach (['the list' => $list, 'the request page' => $page] as $case => $html) {
            $this->assertStringNotContainsString('<img src=x', $html, $case);
        }
        $listXpath = $this->xpathOf($list);
        $this->assertSame(sprintf(Texts::RULE_OF_INVENTORY, $evil . ' rule', $evil), $this->ruleCellOf($listXpath, $wait['request']));
        $this->assertStringContainsString(
            '<td data-approval-rule>' . Html::encode(sprintf(Texts::RULE_OF_INVENTORY, $evil . ' rule', $evil)) . '</td>',
            $list
        );
        $this->assertSame($evil, self::textOf($this->elementById($this->xpathOf($page), 'approval-inventory')));
        $this->assertStringContainsString('data-inventory-id="' . $wait['inventory']->id . '">' . Html::encode($evil) . '</a>', $page);
    }

    /**
     * The inventories of the listed requests are read for the whole page at
     * once: the list costs as many queries with 12 requests whose jobs were
     * launched with 12 inventories as with 2, for an admin and for a team
     * member.
     */
    public function testTheListReadsTheInventoriesOfItsRequestsInAConstantNumberOfQueries(): void
    {
        $s = $this->teamScope();
        $rule = $this->ruleOf((int)$s['admin']->id, [$s['member'], $s['admin']]);
        $this->inventoryAndTemplateRequests($s['admin'], $rule, (int)$s['own']->project_id, 1);
        $before = [];
        foreach (['admin', 'member'] as $key) {
            $this->loginAs($s[$key]);
            $this->listPage();
            $before[$key] = $this->queriesOf(fn (): string => $this->listPage());
        }

        $this->inventoryAndTemplateRequests($s['admin'], $rule, (int)$s['own']->project_id, 5);

        foreach (['admin', 'member'] as $key) {
            $this->loginAs($s[$key]);
            $html = '';
            $this->assertSame($before[$key], $this->queriesOf(function () use (&$html): void {
                $html = $this->listPage();
            }), $key);
            $this->assertSame(6, substr_count($html, ' (of inventory cost-'), $key . ': every inventory request names its inventory');
        }
    }

    // -- Helpers ----------------------------------------------------------------------

    private function namedRule(User $admin, string $name): ApprovalRule
    {
        $rule = $this->ruleOf((int)$admin->id, [$admin]);
        $rule->name = $name;
        $rule->save(false);

        return $rule;
    }

    private function ruleCellOf(\DOMXPath $xpath, ApprovalRequest $request): string
    {
        return self::textOf($xpath->query(sprintf('//tr[@data-request-id="%d"]/td[@data-approval-rule]', $request->id))->item(0));
    }

    private function elementById(\DOMXPath $xpath, string $id): ?\DOMElement
    {
        $nodes = $xpath->query(sprintf('//*[@id="%s"]', $id));
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, '#' . $id);
        $node = $nodes->item(0);

        return $node instanceof \DOMElement ? $node : null;
    }

    /**
     * @return list<string> the row headers of the request page's details table, in order
     */
    private function requestPageHeaders(\DOMXPath $xpath): array
    {
        $headers = [];
        foreach ($xpath->query('(//table)[1]//tr/th') ?: [] as $th) {
            $headers[] = self::textOf($th);
        }

        return $headers;
    }

    /**
     * @return list<string> the targets of the links in $element
     */
    private function hrefsIn(?\DOMElement $element): array
    {
        $hrefs = [];
        foreach ($element?->getElementsByTagName('a') ?? [] as $link) {
            $hrefs[] = $link->getAttribute('href');
        }

        return $hrefs;
    }

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
