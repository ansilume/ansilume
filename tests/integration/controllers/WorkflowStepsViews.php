<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowRouteMap;
use app\services\WorkflowStepsTable;
use yii\base\Module;
use yii\web\Controller;

/**
 * Renders the steps partials of the workflow template page
 * (views/workflow-template/_steps.php and _step-form.php) with real views,
 * as the template page and the edit page render them, and reads the HTML
 * with XPath. Use in WebControllerTestCase subclasses.
 */
trait WorkflowStepsViews
{
    use RendersRealViews;

    /**
     * The partial $view of views/workflow-template, rendered with $params
     * on a request for the template page.
     *
     * @param array<string, mixed> $params
     */
    protected function renderStepsPartial(string $view, array $params): string
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);
        $ctrl = new Controller('workflow-template', $app);

        return $this->withRealViews($ctrl, '/workflow-template/view', static fn (): string => $ctrl->renderPartial($view, $params));
    }

    /**
     * The steps table of $workflow as the template page builds it.
     *
     * @param array<int, int> $waitingSteps
     */
    protected function stepsTableOf(WorkflowTemplate $workflow, bool $withActions, array $waitingSteps = []): WorkflowStepsTable
    {
        $models = WorkflowRouteMap::stepsOf($workflow);

        return new WorkflowStepsTable(WorkflowRouteMap::fromModels($models), $models, $withActions, $waitingSteps);
    }

    /**
     * A step of $workflow named $name at $order, with its branches.
     *
     * @param array{success?: int|null, failure?: int|null, always?: int|null} $routes
     */
    protected function namedStep(
        WorkflowTemplate $workflow,
        string $name,
        int $order,
        string $type = WorkflowStep::TYPE_JOB,
        ?int $jobTemplateId = null,
        ?int $approvalRuleId = null,
        array $routes = []
    ): WorkflowStep {
        $step = new WorkflowStep();
        $step->workflow_template_id = (int)$workflow->id;
        $step->name = $name;
        $step->step_order = $order;
        $step->step_type = $type;
        $step->job_template_id = $jobTemplateId;
        $step->approval_rule_id = $approvalRuleId;
        $step->on_success_step_id = $routes['success'] ?? null;
        $step->on_failure_step_id = $routes['failure'] ?? null;
        $step->on_always_step_id = $routes['always'] ?? null;
        $step->created_at = time();
        $step->updated_at = time();
        $step->save(false);

        return $step;
    }

    /**
     * Set $step's branches and save them.
     *
     * @param array{success?: int|null, failure?: int|null, always?: int|null} $routes
     */
    protected function route(WorkflowStep $step, array $routes): void
    {
        foreach (['success' => 'on_success_step_id', 'failure' => 'on_failure_step_id', 'always' => 'on_always_step_id'] as $key => $field) {
            if (array_key_exists($key, $routes)) {
                $step->setAttribute($field, $routes[$key]);
            }
        }
        $step->save(false);
    }

    protected function xpathOf(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    /**
     * The one node $query finds in $xpath.
     */
    protected function one(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): \DOMElement
    {
        $nodes = $xpath->query($query, $context);
        $this->assertNotFalse($nodes, $query);
        $this->assertSame(1, $nodes->length, "exactly one {$query}");
        $node = $nodes->item(0);
        $this->assertInstanceOf(\DOMElement::class, $node);

        return $node;
    }

    /**
     * Every node $query finds in $xpath.
     *
     * @return list<\DOMElement>
     */
    protected function all(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): array
    {
        $nodes = $xpath->query($query, $context);
        $this->assertNotFalse($nodes, $query);
        $elements = [];
        foreach ($nodes as $node) {
            if ($node instanceof \DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    /**
     * The text of $node as a browser shows it: white space collapsed.
     */
    protected function textOf(\DOMNode $node): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', (string)$node->textContent));
    }
}
