<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\WorkflowTemplateController;
use app\models\ApprovalRule;
use app\models\JobTemplate;
use app\models\WorkflowStep;
use app\services\WorkflowTemplatePage;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Module;
use yii\web\AssetManager;
use yii\web\Response;
use yii\web\View;

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
 * names both cases.
 */
class WorkflowTemplateGatedStepViewTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

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

        $html = $this->renderPage($this->viewParams((int)$workflow->id));

        $next = WorkflowTemplatePage::ROUTE_NEXT_STEP;
        $this->assertSame(
            [$next, WorkflowTemplatePage::GATED_JOB_FAILURE_ROUTE, '—'],
            $this->routes($html, $gated),
            'a failed job goes on, a refused approval ends the run'
        );
        $this->assertStringContainsString('title="' . WorkflowTemplatePage::GATED_JOB_FAILURE_TITLE . '"', $this->row($html, $gated));
        $this->assertSame(
            [$next, WorkflowTemplatePage::GATED_JOB_FAILURE_ROUTE, '#10 step-10 ' . WorkflowTemplatePage::GATED_JOB_ALWAYS_NOTE],
            $this->routes($html, $always)
        );
        $this->assertSame([$next, '#10 step-10', '—'], $this->routes($html, $failure), 'an explicit On Failure is followed');
        $this->assertSame([$next, $next, '#10 step-10'], $this->routes($html, $open), 'no approval rule, no gate');
        $this->assertSame([$next, $next, '—'], $this->routes($html, $gone), 'a deleted job template runs nothing');
    }

    public function testTheJobStepHelpSaysWhatAClosedGateDoes(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->loginAs($s['admin']);

        $html = $this->renderPage($this->viewParams((int)$workflow->id));

        $this->assertMatchesRegularExpression('#<span id="ws-routing-help-job" class="d-none">(.*?)</span>#s', $html);
        preg_match('#<span id="ws-routing-help-job" class="d-none">(.*?)</span>\s*<span id="ws-routing-help-approval"#s', $html, $help);
        $text = (string)preg_replace('/\s+/', ' ', trim(strip_tags($help[1] ?? '')));
        $this->assertSame(
            'Job step: success = exit code 0. Failure = any non-zero exit, timeout, or unreachable host. Always overrides both. '
            . 'If the job template has an approval rule and the approval is rejected, times out or is canceled, the job does not run: '
            . 'then only On Failure is followed (left empty, the run ends as failed), and Always is not.',
            $text
        );
    }

    // -- Helpers --------------------------------------------------------------------

    /**
     * The HTML of $step's row.
     */
    private function row(string $html, WorkflowStep $step): string
    {
        $found = preg_match('#<tr data-step-id="' . $step->id . '">(.*?)</tr>#s', $html, $row);
        $this->assertSame(1, $found, "row of step #{$step->id}");

        return $row[1];
    }

    /**
     * What the On Success, On Failure and Always cells of $step's row show.
     *
     * @return list<string>
     */
    private function routes(string $html, WorkflowStep $step): array
    {
        preg_match_all('#<td data-route="(?:success|failure|always)">(.*?)</td>#s', $this->row($html, $step), $cells);

        return array_map(
            static fn (string $cell): string => (string)preg_replace('/\s+/', ' ', trim(html_entity_decode(strip_tags($cell)))),
            $cells[1]
        );
    }

    /**
     * @return array<string, mixed> what actionView passes to the view
     */
    private function viewParams(int $workflowId): array
    {
        $ctrl = new class ('workflow-template', $this->app()) extends WorkflowTemplateController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            /**
             * @param array<int|string, mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };
        $ctrl->actionView($workflowId);

        return $ctrl->capturedParams;
    }

    private function app(): Module
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);

        return $app;
    }

    /**
     * The real page, rendered with $params, with dummy asset bundles; view,
     * asset manager and controller are restored.
     *
     * @param array<string, mixed> $params
     */
    private function renderPage(array $params): string
    {
        $components = \Yii::$app->getComponents(true);
        $originals = ['view' => $components['view'] ?? null, 'assetManager' => $components['assetManager'] ?? null];
        $previousController = \Yii::$app->controller;
        \Yii::$app->set('assetManager', new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']));
        \Yii::$app->set('view', new View());
        $ctrl = new WorkflowTemplateController('workflow-template', $this->app());
        \Yii::$app->controller = $ctrl;
        try {
            return $ctrl->renderPartial('view', $params);
        } finally {
            \Yii::$app->controller = $previousController;
            foreach ($originals as $id => $definition) {
                \Yii::$app->set($id, $definition);
            }
        }
    }
}
