<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ApprovalController;
use app\controllers\SiteController;
use app\controllers\WorkflowJobController;
use app\models\ApprovalRequest;
use app\models\User;
use app\services\runstate\Texts;
use app\services\runstate\Viewer;
use app\tests\integration\CountsQueries;
use app\tests\integration\services\runstate\RunStateFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\web\Controller;

/**
 * A workflow template that was deleted is named "<name> (deleted)", without
 * a link, wherever a run, an approval request or a job names it: the For
 * cell of the approval list, the request page and the dashboard's Pending
 * Approvals, the run page, the run list and the dashboard's Running
 * Workflows. Its page answers 404, so a link would lead nowhere. The marker
 * is muted, as the jobs list marks a deleted job template. Whether a
 * workflow was deleted comes with the rows' workflow, so it costs no query
 * per row.
 */
class DeletedWorkflowNamesTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use RunStateFixtures;
    use WaitingRunFixtures;
    use CountsQueries;

    /** A workflow name with markup: it is text everywhere. */
    private const MARKUP_NAME = 'Old <b>release</b> "q"';

    /**
     * regression: the For cell linked a deleted workflow, whose page answers
     * 404, and did not say it was deleted. The approval list and the request
     * page name it "<name> (deleted)" without a link and keep the run's
     * link; a live workflow stays linked.
     */
    public function testRegressionTheForCellLinkedADeletedWorkflow(): void
    {
        $admin = $this->createUserWithRole('dwn-admin', 'admin');
        $rule = $this->ruleOf((int)$admin->id, [$admin]);
        $live = $this->approvalWait($admin, 'Live release', 'Approve', $rule);
        $gone = $this->approvalWait($admin, self::MARKUP_NAME, 'Sign off', $rule);
        $this->assertTrue($gone['workflow']->softDelete());
        $this->loginAs($admin);
        $expected = sprintf(Texts::FOR_WORKFLOW_STEP, self::MARKUP_NAME . ' ' . Texts::WORKFLOW_DELETED, 'Sign off', $gone['run']->id);

        $list = $this->xpathOf($this->approvalPage('index'));
        $page = $this->xpathOf($this->approvalPage('view', $gone['request']));

        $cells = [
            'list' => $this->only($list, sprintf('//tr[@data-request-id="%d"]/td[@data-approval-for]', $gone['request']->id)),
            'request page' => $this->only($page, '//td[@id="approval-for"]'),
        ];
        foreach ($cells as $where => $cell) {
            $this->assertSame($expected, self::textOf($cell), $where);
            $this->assertSame(sprintf('Old &lt;b&gt;release&lt;/b&gt; "q" <span class="text-muted">(deleted)</span> › Sign off (<a href="/workflow-job/view?id=%1$d" data-for-run="%1$d">Workflow Job #%1$d</a>)', $gone['run']->id), trim(self::innerHtmlOf($cell)), $where);
            $this->assertStringNotContainsString('/workflow-template/view', self::innerHtmlOf($cell), $where);
        }
        $this->assertSame($expected, $this->contextOf($gone['request'], $admin), 'the text the cell reads');
        $liveCell = $this->only($list, sprintf('//tr[@data-request-id="%d"]/td[@data-approval-for]', $live['request']->id));
        $link = $this->only($list, sprintf('a[@data-for-workflow="%d"]', $live['workflow']->id), $liveCell);
        $this->assertSame(['/workflow-template/view?id=' . $live['workflow']->id, 'Live release'], [$link->getAttribute('href'), self::textOf($link)]);
        $this->assertStringNotContainsString(Texts::WORKFLOW_DELETED, self::textOf($liveCell));
    }

    /**
     * regression: the run page linked a deleted workflow. It names it
     * "<name> (deleted)" without a link; a live one stays linked.
     */
    public function testRegressionTheRunPageLinkedADeletedWorkflow(): void
    {
        $admin = $this->createUserWithRole('dwn-admin', 'admin');
        $live = $this->runAtStep($admin, 'Nightly', 'hold');
        $gone = $this->runAtStep($admin, self::MARKUP_NAME, 'hold');
        $this->assertTrue($gone['workflow']->softDelete());
        $this->loginAs($admin);

        $goneCell = $this->only($this->xpathOf($this->runPage((int)$gone['run']->id)), '//tr[th="Workflow"]/td');
        $liveCell = $this->only($this->xpathOf($this->runPage((int)$live['run']->id)), '//tr[th="Workflow"]/td');

        $this->assertSame(self::MARKUP_NAME . ' (deleted)', self::textOf($goneCell));
        $this->assertSame('Old &lt;b&gt;release&lt;/b&gt; "q" <span class="text-muted">(deleted)</span>', self::innerHtmlOf($goneCell));
        $this->assertSame(sprintf('<a href="/workflow-template/view?id=%d">Nightly</a>', $live['workflow']->id), self::innerHtmlOf($liveCell));
        $this->assertSame(['wj-workflow', 'wj-workflow'], [$goneCell->getAttribute('id'), $liveCell->getAttribute('id')]);
    }

    /**
     * regression: the run list did not say a run's workflow was deleted. It
     * reads "<name> (deleted)", as the jobs list reads a deleted job
     * template.
     */
    public function testRegressionTheRunListDidNotSayAWorkflowWasDeleted(): void
    {
        $admin = $this->createUserWithRole('dwn-admin', 'admin');
        $live = $this->runAtStep($admin, 'Nightly', 'hold');
        $gone = $this->runAtStep($admin, self::MARKUP_NAME, 'hold');
        $this->assertTrue($gone['workflow']->softDelete());
        $this->loginAs($admin);

        $xpath = $this->xpathOf($this->runList());

        $goneRow = self::rowLinkingTo($xpath, '/workflow-job/view?id=' . $gone['run']->id);
        $this->assertSame(self::MARKUP_NAME . ' (deleted)', self::cellsOf($goneRow)[1] ?? null);
        $this->assertSame('Nightly', self::cellsOf(self::rowLinkingTo($xpath, '/workflow-job/view?id=' . $live['run']->id))[1] ?? null);
        $this->assertSame(
            'Old &lt;b&gt;release&lt;/b&gt; "q" <span class="text-muted">(deleted)</span>',
            self::innerHtmlOf($this->only($xpath, sprintf('//tr[td[1]//a[@href="/workflow-job/view?id=%d"]]/td[@data-run-workflow]', $gone['run']->id)))
        );
    }

    /**
     * regression: the dashboard did not say a workflow was deleted. A run
     * of a deleted workflow that its cancel left running (the maintenance
     * run retries it) reads "<name> (deleted)" under Running Workflows; its
     * pending request's For cell names the workflow without a link.
     */
    public function testRegressionTheDashboardDidNotSayAWorkflowWasDeleted(): void
    {
        $admin = $this->createUserWithRole('dwn-admin', 'admin');
        $gone = $this->approvalWait($admin, self::MARKUP_NAME, 'Sign off', $this->ruleOf((int)$admin->id, [$admin]));
        $this->assertTrue($gone['workflow']->softDelete());

        $xpath = $this->xpathOf($this->dashboard($admin));

        $running = $this->only($xpath, sprintf('//div[@id="dashboard-running-workflows"]//tr[@data-run-id="%d"]/td[2]', $gone['run']->id));
        $this->assertSame(self::MARKUP_NAME . ' (deleted)', self::textOf($running));
        $this->assertSame('Old &lt;b&gt;release&lt;/b&gt; "q" <span class="text-muted">(deleted)</span>', trim(self::innerHtmlOf($running)));
        $pending = $this->only($xpath, sprintf('//div[@id="dashboard-pending-approvals"]//tr[@data-request-id="%d"]/td[@data-approval-for]', $gone['request']->id));
        $this->assertSame(
            sprintf(Texts::FOR_WORKFLOW_STEP, self::MARKUP_NAME . ' ' . Texts::WORKFLOW_DELETED, 'Sign off', $gone['run']->id),
            self::textOf($pending)
        );
        $this->assertStringNotContainsString('/workflow-template/view', self::innerHtmlOf($pending));
    }

    /**
     * Whether a workflow was deleted costs no query per row: the approval
     * list and the run list cost the same for 2 and for 12 rows of deleted
     * workflows.
     */
    public function testTheListsReadWhetherAWorkflowWasDeletedWithoutAQueryPerRow(): void
    {
        $admin = $this->createUserWithRole('dwn-admin', 'admin');
        $rule = $this->ruleOf((int)$admin->id, [$admin]);
        $this->loginAs($admin);
        $this->deletedWaits($admin, $rule, 2);
        $this->approvalPage('index');
        $this->runList();
        $two = [$this->queriesOf(fn () => $this->approvalPage('index')), $this->queriesOf(fn () => $this->runList())];
        $this->deletedWaits($admin, $rule, 10);
        $twelve = [$this->queriesOf(fn () => $this->approvalPage('index')), $this->queriesOf(fn () => $this->runList())];

        $this->assertSame($two, $twelve, 'the approval list and the run list, 2 and 12 rows');
        $this->assertSame(12, substr_count($this->runList(), '<span class="text-muted">' . Texts::WORKFLOW_DELETED . '</span>'));
    }

    /**
     * The partial that names a run's workflow: a link to a live one when
     * asked, the name alone otherwise, a deleted one marked and never
     * linked, and a dash without a name. Every name is text.
     */
    public function testTheWorkflowNameReadsAsItsStateSays(): void
    {
        $name = '<img src=x onerror=alert(1)> & "q"';
        $encoded = '&lt;img src=x onerror=alert(1)&gt; &amp; &quot;q&quot;';
        $cases = [
            'live, linked' => [['deleted' => false, 'link' => true, 'linkOptions' => ['data-x' => 5]], '<a href="/workflow-template/view?id=5" data-x="5">' . $encoded . '</a>'],
            'live, not linked' => [['deleted' => false, 'link' => false], $encoded],
            'deleted' => [['deleted' => true, 'link' => true], $encoded . ' <span class="text-muted">(deleted)</span>'],
            'no name' => [['name' => null, 'deleted' => false, 'link' => true], '—'],
        ];

        foreach ($cases as $case => [$params, $expected]) {
            $html = \Yii::$app->view->renderFile($this->viewFile('workflow-job/_workflow-name'), $params + ['name' => $name, 'id' => 5]);
            $this->assertSame($expected, $html, $case);
        }
    }

    // -- Fixtures -------------------------------------------------------------

    /**
     * $count runs of new workflows that wait for an approval, each workflow
     * deleted since.
     */
    private function deletedWaits(User $launcher, \app\models\ApprovalRule $rule, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->assertTrue($this->approvalWait($launcher, 'Gone ' . $i, 'Gate', $rule)['workflow']->softDelete());
        }
    }

    // -- Pages ----------------------------------------------------------------

    /**
     * The file of $view (folder/name) of the pages under test; a check
     * against older views overrides it.
     */
    protected function viewFile(string $view): string
    {
        return '@app/views/' . $view . '.php';
    }

    /**
     * The approval list ('index') or the page of $request ('view'), as the
     * signed-in user sees it.
     */
    private function approvalPage(string $view, ?ApprovalRequest $request = null): string
    {
        $ctrl = new class ('approval', \Yii::$app) extends ApprovalController {
            use CapturesParams;
        };
        if ($request === null) {
            $ctrl->actionIndex();
        } else {
            $ctrl->actionView((int)$request->id);
        }

        return $this->page($ctrl, 'approval/' . $view);
    }

    private function runPage(int $runId): string
    {
        $ctrl = $this->runController();
        $ctrl->actionView($runId);

        return $this->page($ctrl, 'workflow-job/view');
    }

    private function runList(): string
    {
        $ctrl = $this->runController();
        $ctrl->actionIndex();

        return $this->page($ctrl, 'workflow-job/index');
    }

    private function dashboard(User $user): string
    {
        $this->loginAs($user);
        $ctrl = new class ('site', \Yii::$app) extends SiteController {
            use CapturesParams;
        };
        $ctrl->actionIndex();

        return $this->page($ctrl, 'site/index');
    }

    /**
     * @return WorkflowJobController&object{capturedParams: array<string, mixed>}
     */
    private function runController(): WorkflowJobController
    {
        return new class ('workflow-job', \Yii::$app) extends WorkflowJobController {
            use CapturesParams;
        };
    }

    /**
     * $view rendered with the parameters $ctrl's action passed, while $ctrl
     * is the current controller (the pages link to routes relative to it).
     *
     * @param Controller&object{capturedParams: array<string, mixed>} $ctrl
     */
    private function page(Controller $ctrl, string $view): string
    {
        $previous = \Yii::$app->controller;
        \Yii::$app->controller = $ctrl;
        try {
            return \Yii::$app->view->renderFile($this->viewFile($view), $ctrl->capturedParams);
        } finally {
            \Yii::$app->controller = $previous;
        }
    }

    // -- Helpers --------------------------------------------------------------

    private function contextOf(ApprovalRequest $request, User $viewer): string
    {
        /** @var \app\services\runstate\ApprovalRequestContexts $contexts */
        $contexts = \Yii::$app->get('approvalRequestContexts');

        return $contexts->forRequests([$request], Viewer::of((int)$viewer->id))[(int)$request->id]->text();
    }

    /**
     * The one element at $path (in $context, when given).
     */
    private function only(\DOMXPath $xpath, string $path, ?\DOMNode $context = null): \DOMElement
    {
        $found = $xpath->query($path, $context);
        $this->assertNotFalse($found);
        $this->assertSame(1, $found->length, $path);
        $element = $found->item(0);
        $this->assertInstanceOf(\DOMElement::class, $element);

        return $element;
    }
}
