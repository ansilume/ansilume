<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowStepsTable;
use app\services\WorkflowTemplatePage;
use app\tests\integration\TeamScopeFixtures;
use yii\web\Controller;
use yii\web\Request;

/**
 * The size of the workflow template page for a user who may change the
 * steps, rendered with real views as the page renders it
 * (WorkflowTemplatePage::viewParams(), views/workflow-template/view.php).
 *
 * regression: every row of the steps table carried a Move to… menu that
 * listed every other place with its hints, worked out from a route map per
 * place. The page grew with the square of the steps, and its rendering
 * about with the cube: 1.2 MB for 50 steps (2,451 move buttons), 4.3 MB and
 * 0.4 s for 100, 16 MB and 2.9 s for 200. Above
 * WorkflowStepsTable::MOVE_MENU_LIMIT steps, Move to… links to the step's
 * edit page instead.
 */
class WorkflowTemplatePageSizeTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use RendersRealViews;

    /** The most HTML the page of a workflow of 50 job steps may have (it had 1.2 MB). */
    private const MAX_BYTES_FOR_50_STEPS = 400000;

    private User $admin;
    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('page-size', 'admin');
        $this->template = $this->createJobTemplate(
            (int)$this->createProject($this->admin->id)->id,
            (int)$this->createInventory($this->admin->id)->id,
            (int)$this->createRunnerGroup($this->admin->id)->id,
            $this->admin->id
        );
        $this->loginAs($this->admin);
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->enableCsrfValidation = true;
    }

    public function testRegressionThePageOfFiftyStepsStaysSmallAndHasNoMoveMenus(): void
    {
        $html = $this->pageOf($this->workflowOf(50));

        $this->assertLessThan(self::MAX_BYTES_FOR_50_STEPS, strlen($html));
        $this->assertSame(0, preg_match_all('#<details[^>]*data-action="move-to"#', $html), 'no Move to… menus');
        $this->assertSame(50, preg_match_all('#<a [^>]*data-action="move-to"#', $html), 'a Move to… link per row');
        $this->assertSame(1, substr_count($html, 'name="position"'), 'only the Position of the Add Step form');
    }

    public function testATableUpToTheLimitKeepsItsMoveMenus(): void
    {
        $this->assertSame(20, WorkflowStepsTable::MOVE_MENU_LIMIT);

        $html = $this->pageOf($this->workflowOf(10));

        $this->assertSame(10, preg_match_all('#<details[^>]*data-action="move-to"#', $html), 'a Move to… menu per row');
        $this->assertSame(0, preg_match_all('#<a [^>]*data-action="move-to"#', $html));
        $this->assertGreaterThan(10, substr_count($html, 'name="position"'));
    }

    /**
     * A workflow of $count job steps on the test's job template, with empty
     * branches.
     */
    private function workflowOf(int $count): WorkflowTemplate
    {
        $workflow = $this->createWorkflowTemplate($this->admin->id);
        for ($n = 1; $n <= $count; $n++) {
            $step = $this->createWorkflowStep((int)$workflow->id, $n * 10, WorkflowStep::TYPE_JOB, (int)$this->template->id);
            $step->name = 'Step ' . $n;
            $step->save(false);
        }

        return $workflow;
    }

    /**
     * The template page of $workflow for the admin.
     */
    private function pageOf(WorkflowTemplate $workflow): string
    {
        $ctrl = new Controller('workflow-template', \Yii::$app);
        $params = (new WorkflowTemplatePage((int)$this->admin->id))->viewParams($workflow);
        $this->assertTrue($params['canChangeSteps'], 'the step actions are shown');

        return $this->withRealViews(
            $ctrl,
            '/workflow-template/view?id=' . $workflow->id,
            static fn (): string => $ctrl->renderPartial('view', $params)
        );
    }
}
