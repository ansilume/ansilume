<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowStepFormView;
use app\services\WorkflowTemplatePage;
use app\tests\integration\TeamScopeFixtures;

/**
 * "Steps that route here" on the page that edits a step
 * (views/workflow-template/edit-step.php), rendered with real views: every
 * step that keeps the step from being removed, by position, and under a
 * step whose branch here no run takes, why.
 *
 * regression: the card listed only branches that runs take. A step whose
 * removal was refused for an On Success that the Always branch overrides,
 * or for a pause step's On Failure, read "No other step routes here.".
 */
class WorkflowTemplateRoutedByViewTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use WorkflowStepsViews;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('routed-by-view', 'admin');
        $this->loginAs($this->admin);
    }

    public function testRegressionTheCardListsEveryStepThatBlocksTheRemovalWithWhyNoRunTakesItsBranch(): void
    {
        $template = $this->createJobTemplate(
            (int)$this->createProject($this->admin->id)->id,
            (int)$this->createInventory($this->admin->id)->id,
            (int)$this->createRunnerGroup($this->admin->id)->id,
            $this->admin->id
        );
        $workflow = $this->createWorkflowTemplate($this->admin->id);
        $build = $this->namedStep($workflow, 'Build <b>', 10, WorkflowStep::TYPE_JOB, (int)$template->id);
        $deploy = $this->namedStep($workflow, 'Deploy', 20, WorkflowStep::TYPE_JOB, (int)$template->id);
        $wait = $this->namedStep($workflow, 'Wait', 30, WorkflowStep::TYPE_PAUSE);
        $this->route($build, ['success' => (int)$deploy->id, 'always' => 0]);
        $this->route($wait, ['failure' => (int)$deploy->id]);

        $xpath = $this->xpathOf($this->editPage($workflow, $deploy));

        $card = $this->one($xpath, '//div[@id="edit-step-routed-by"]');
        $this->assertSame(WorkflowStepFormView::ROUTED_BY_TITLE, $this->textOf($this->one($xpath, './div[@class="card-header"]', $card)));
        $alwaysWins = 'Not used: its Always branch is taken instead.';
        $pauseFailure = 'Not used: a pause step does not fail. Saving that step clears its On Failure.';
        $this->assertSame(
            ['1. Build <b> ' . $alwaysWins, '3. Wait ' . $pauseFailure],
            array_map(fn (\DOMElement $item): string => $this->textOf($item), $this->all($xpath, './/li', $card))
        );
        $this->assertSame(
            [$alwaysWins, $pauseFailure],
            array_map(fn (\DOMElement $note): string => $this->textOf($note), $this->all($xpath, './/li/span[@data-routed-by-note]', $card))
        );
        $this->assertSame([], $this->all($xpath, './/span[@class="text-muted"]', $card), 'no "No other step routes here."');
    }

    public function testAStepWhoseBranchRunsTakeHasNoNote(): void
    {
        $workflow = $this->createWorkflowTemplate($this->admin->id);
        $wait = $this->namedStep($workflow, 'Wait', 10, WorkflowStep::TYPE_PAUSE);
        $end = $this->namedStep($workflow, 'End', 20, WorkflowStep::TYPE_PAUSE);
        $this->route($wait, ['success' => (int)$end->id]);

        $xpath = $this->xpathOf($this->editPage($workflow, $end));

        $item = $this->one($xpath, '//div[@id="edit-step-routed-by"]//li');
        $this->assertSame('1. Wait', $this->textOf($item));
        $this->assertSame([], $this->all($xpath, './span', $item));
    }

    /**
     * The page that edits $step of $workflow, as the admin sees it.
     */
    private function editPage(WorkflowTemplate $workflow, WorkflowStep $step): string
    {
        return $this->renderStepsPartial('edit-step', (new WorkflowTemplatePage((int)$this->admin->id))->editParams($workflow, $step, null, null));
    }
}
