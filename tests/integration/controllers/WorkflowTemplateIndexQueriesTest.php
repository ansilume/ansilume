<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\WorkflowTemplateController;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\tests\integration\CountsQueries;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Module;

/**
 * The list of workflow templates counts the steps of the listed workflows
 * with one aggregate query.
 *
 * regression: the template list loaded the steps of every listed workflow
 * with a query of its own (count($model->steps) in each row), so the page
 * sent one more query per workflow.
 */
class WorkflowTemplateIndexQueriesTest extends WebControllerTestCase
{
    use CountsQueries;
    use RendersRealViews;
    use TeamScopeFixtures;

    /**
     * regression: the template list loaded the steps once per row.
     */
    public function testRegressionTheListSendsAsManyQueriesForTwelveWorkflowsAsForTwo(): void
    {
        $s = $this->teamScope();
        $this->loginAs($s['admin']);
        $this->workflows($s['admin']->id, $s['own']->id, 2);
        $this->renderList();

        $two = $this->queriesOf(fn (): string => $this->renderList());
        $this->workflows($s['admin']->id, $s['own']->id, 10);
        $twelve = $this->queriesOf(fn (): string => $this->renderList());

        $this->assertSame($two, $twelve, 'the queries do not grow with the listed workflows');
    }

    /**
     * Each row shows the number of its workflow's steps; a workflow without
     * steps shows 0. A team-scoped member's list counts the same way.
     */
    public function testEachRowShowsTheNumberOfItsSteps(): void
    {
        $s = $this->teamScope();
        $three = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['own']->id, $s['open']->id);
        $none = $this->createWorkflowTemplate($s['admin']->id);
        $this->createWorkflowStep((int)$three->id, 99, WorkflowStep::TYPE_PAUSE);
        $this->loginAs($s['member']);

        $html = $this->renderList();

        $this->assertSame('4', $this->stepCount($html, $three));
        $this->assertSame('0', $this->stepCount($html, $none));
    }

    // -- Helpers ------------------------------------------------------------------------

    /**
     * $count workflows of three job steps each.
     */
    private function workflows(int $createdBy, int $jobTemplateId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->createWorkflowWithJobSteps($createdBy, $jobTemplateId, $jobTemplateId, $jobTemplateId);
        }
    }

    /**
     * The Steps cell of $workflow's row.
     */
    private function stepCount(string $html, WorkflowTemplate $workflow): string
    {
        $link = preg_quote('/workflow-template/view?id=' . $workflow->id . '"', '#');
        $this->assertSame(1, preg_match('#<tr>\s*<td><a href="' . $link . '>[^<]*</a></td>\s*<td>([^<]*)</td>#', $html, $cell));

        return $cell[1];
    }

    /**
     * The list page as the signed-in user sees it.
     */
    private function renderList(): string
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);
        $ctrl = new class ('workflow-template', $app) extends WorkflowTemplateController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };

        return $this->withRealViews($ctrl, '/workflow-template/index', static fn (): string => $ctrl->actionIndex());
    }
}
