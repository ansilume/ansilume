<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\models\ApprovalRule;
use app\models\JobTemplate;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowRouteMap;
use app\services\WorkflowStepFormView;
use app\services\WorkflowStepsTable;
use app\tests\integration\TeamScopeFixtures;

/**
 * The workflow template page on a job step whose job template has an
 * approval rule: a rejected, timed-out or canceled approval means its job
 * never ran, and then only On Failure is followed (left empty, the run ends
 * as failed) and Always is not. A job that ran and failed goes on with the
 * next step, as for any job step.
 *
 * Regression: the steps table showed "→ next step" for such a step's empty
 * On Failure, and the job-step help said only that Always overrides both.
 * Regression (open need 1 of the wave-6 check): then it showed only "end
 * workflow (failed)", which holds after a refused approval only; the label
 * names both cases. Since 2.11.0 the steps table names both by position
 * (WorkflowStepsTable), and a job step's Always takes over a failed job, but
 * not a refused approval.
 */
class WorkflowTemplateGatedStepViewTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use WorkflowStepsViews;

    public function testRegressionTheStepsTableSaysWhatAClosedGateOfAJobStepDoes(): void
    {
        $s = $this->teamScope();
        $rule = $this->createApprovalRule($s['admin']->id, ApprovalRule::APPROVER_TYPE_USERS, (string)json_encode(['user_ids' => [$s['member']->id]]));
        JobTemplate::updateAll(['approval_rule_id' => $rule->id], ['id' => [$s['own']->id, $s['viewed']->id]]);
        JobTemplate::updateAll(['deleted_at' => time()], ['id' => $s['viewed']->id]);
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $gated = $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_JOB, $s['own']->id);
        $always = $this->createWorkflowStep((int)$workflow->id, 20, WorkflowStep::TYPE_JOB, $s['own']->id);
        $always->on_always_step_id = (int)$gated->id;
        $always->save(false);
        $failure = $this->createWorkflowStep((int)$workflow->id, 30, WorkflowStep::TYPE_JOB, $s['own']->id);
        $failure->on_failure_step_id = (int)$gated->id;
        $failure->save(false);
        $open = $this->createWorkflowStep((int)$workflow->id, 40, WorkflowStep::TYPE_JOB, $s['open']->id);
        $open->on_always_step_id = (int)$gated->id;
        $open->save(false);
        $gone = $this->createWorkflowStep((int)$workflow->id, 50, WorkflowStep::TYPE_JOB, $s['viewed']->id);
        $this->loginAs($s['admin']);

        $html = $this->stepsSection($workflow);

        $unused = WorkflowStepsTable::ROUTE_UNUSED;
        $this->assertSame(
            ['→ 2. step-20 (next)', '→ 2. step-20 (next), also after a failed job; end · run fails after a refused approval', $unused],
            $this->routes($html, $gated),
            'a failed job goes on, a refused approval ends the run'
        );
        $this->assertSame(
            [WorkflowStepsTable::REFUSED_APPROVAL_TITLE],
            $this->titles($html, $gated, 'failure', 1),
            'the second part says what a refused approval is'
        );
        $this->assertSame(
            [$unused, 'end · run fails after a refused approval', '↺ 1. step-10 ' . WorkflowStepsTable::GATED_JOB_ALWAYS_NOTE],
            $this->routes($html, $always),
            'Always takes over a failed job, but not a refused approval'
        );
        $this->assertSame(['→ 4. step-40 (next)', '↺ 1. step-10', $unused], $this->routes($html, $failure), 'an explicit On Failure is followed either way');
        $this->assertSame([$unused, $unused, '↺ 1. step-10'], $this->routes($html, $open), 'no approval rule, no gate');
        $this->assertSame([$unused, $unused, $unused], $this->routes($html, $gone), 'a deleted job template runs nothing');
        $this->assertSame(
            [WorkflowStepsTable::CANNOT_RUN_TITLE],
            $this->titles($html, $gone, 'success', 0)
        );
    }

    public function testTheJobStepHelpSaysWhatAClosedGateDoes(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->loginAs($s['admin']);

        $html = $this->renderStepsPartial('_step-form', [
            'form' => WorkflowStepFormView::forAdd(WorkflowRouteMap::forTemplate($workflow), null),
            'model' => new WorkflowStep(),
            'jobTemplateOptions' => [(int)$s['own']->id => (string)$s['own']->name],
            'approvalRuleOptions' => [],
            'mayAssignRules' => true,
            'action' => ['/workflow-template/add-step', 'id' => (int)$workflow->id],
            'error' => null,
        ]);

        $help = $this->one($this->xpathOf($html), '//span[@id="ws-routing-help-job"]');
        $this->assertSame(
            'Job step: success = exit code 0. Failure = any non-zero exit, timeout, or unreachable host. Always overrides both. '
            . 'If the job template has an approval rule and the approval is rejected, times out or is canceled, the job does not run: '
            . 'then only On Failure is followed (left empty, the run ends as failed), and Always is not.',
            $this->textOf($help)
        );
    }

    // -- Helpers --------------------------------------------------------------------

    /**
     * The steps section of $workflow's page, as a viewer sees it. The
     * regression test is checked against the 2.10.1 page by overriding this.
     */
    protected function stepsSection(WorkflowTemplate $workflow): string
    {
        return $this->renderStepsPartial('_steps', [
            'model' => $workflow,
            'stepsTable' => $this->stepsTableOf($workflow, false),
            'canChangeSteps' => false,
        ]);
    }

    /**
     * What the On Success, On Failure and Always cells of $step's row show.
     *
     * @return list<string>
     */
    private function routes(string $html, WorkflowStep $step): array
    {
        $xpath = $this->xpathOf($html);
        $row = $this->one($xpath, '//tr[@data-step-id="' . $step->id . '"]');

        return array_map(
            fn (\DOMElement $cell): string => $this->textOf($cell),
            $this->all($xpath, './td[@data-route="success" or @data-route="failure" or @data-route="always"]', $row)
        );
    }

    /**
     * The title of the part $index of $step's $route cell.
     *
     * @return list<string>
     */
    private function titles(string $html, WorkflowStep $step, string $route, int $index): array
    {
        $xpath = $this->xpathOf($html);
        $parts = $this->all($xpath, '//tr[@data-step-id="' . $step->id . '"]/td[@data-route="' . $route . '"]/span');
        $this->assertArrayHasKey($index, $parts);

        return [$parts[$index]->getAttribute('title')];
    }
}
