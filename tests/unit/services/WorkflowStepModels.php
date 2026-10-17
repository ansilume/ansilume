<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\WorkflowStep;
use app\services\WorkflowRouteMap;

/**
 * Workflow steps built in memory, with their job templates (with their
 * approval rules, inventories and the inventories' approval rules) and
 * approval rules loaded, so that the route map and the presenters of the
 * template page read nothing from the database.
 */
trait WorkflowStepModels
{
    /** The workflow every step belongs to. */
    private static int $workflowId = 77;

    /**
     * A job step whose job template (ID 100 + $id) exists, on an inventory
     * (ID 400 + $id) that exists; gated by the approval rule $gatedRule of
     * the job template (ID 300 + $id) when it is given, and by the rule
     * $inventoryRule of the inventory (ID 500 + $id) when it is given.
     *
     * @param array{success?: int|null, failure?: int|null, always?: int|null} $routes
     */
    private static function job(
        int $id,
        string $name,
        array $routes = [],
        ?string $gatedRule = null,
        ?string $inventoryRule = null
    ): WorkflowStep {
        $template = new JobTemplate();
        $template->id = 100 + $id;
        $template->name = 'template-' . $name;
        $template->inventory_id = 400 + $id;
        $template->approval_rule_id = $gatedRule === null ? null : 300 + $id;
        $template->populateRelation('approvalRule', $gatedRule === null ? null : self::rule(300 + $id, $gatedRule));
        $template->populateRelation('inventory', self::inventory(400 + $id, $inventoryRule === null ? null : self::rule(500 + $id, $inventoryRule)));

        return self::stepModel($id, $name, WorkflowStep::TYPE_JOB, $routes, $template, null, $template->id, null);
    }

    /**
     * An inventory with the ID $id, gated by $rule when it is given, with
     * its approval rule loaded.
     */
    private static function inventory(int $id, ?ApprovalRule $rule): Inventory
    {
        $inventory = new Inventory();
        $inventory->id = $id;
        $inventory->name = 'inventory-' . $id;
        $inventory->setAttribute('approval_rule_id', $rule?->id);
        $inventory->populateRelation('approvalRule', $rule);

        return $inventory;
    }

    /**
     * An approval step whose approval rule (ID 200 + $id) exists.
     *
     * @param array{success?: int|null, failure?: int|null, always?: int|null} $routes
     */
    private static function approval(int $id, string $name, array $routes = []): WorkflowStep
    {
        return self::stepModel($id, $name, WorkflowStep::TYPE_APPROVAL, $routes, null, self::rule(200 + $id, 'rule-' . $name), null, 200 + $id);
    }

    /**
     * @param array{success?: int|null, failure?: int|null, always?: int|null} $routes
     */
    private static function pause(int $id, string $name, array $routes = []): WorkflowStep
    {
        return self::stepModel($id, $name, WorkflowStep::TYPE_PAUSE, $routes, null, null, null, null);
    }

    /**
     * A step with the relations given: $template and $rule are what the
     * step's job_template_id ($templateId) and approval_rule_id ($ruleId)
     * find, null for one that does not exist (or was deleted). A $template
     * needs its approval rule and its inventory loaded, as job() loads them.
     *
     * @param array{success?: int|null, failure?: int|null, always?: int|null} $routes
     */
    private static function stepModel(
        int $id,
        string $name,
        string $type,
        array $routes = [],
        ?JobTemplate $template = null,
        ?ApprovalRule $rule = null,
        ?int $templateId = null,
        ?int $ruleId = null
    ): WorkflowStep {
        $step = new WorkflowStep();
        $step->id = $id;
        $step->workflow_template_id = self::$workflowId;
        $step->name = $name;
        $step->step_type = $type;
        $step->step_order = $id * 10;
        $step->job_template_id = $templateId;
        $step->approval_rule_id = $ruleId;
        $step->on_success_step_id = $routes['success'] ?? null;
        $step->on_failure_step_id = $routes['failure'] ?? null;
        $step->on_always_step_id = $routes['always'] ?? null;
        $step->populateRelation('jobTemplate', $template);
        $step->populateRelation('approvalRule', $rule);

        return $step;
    }

    private static function rule(int $id, string $name): ApprovalRule
    {
        $rule = new ApprovalRule();
        $rule->id = $id;
        $rule->name = $name;

        return $rule;
    }

    /**
     * The route map of $models, given in run order.
     *
     * @param list<WorkflowStep> $models
     */
    private static function mapOf(array $models): WorkflowRouteMap
    {
        return WorkflowRouteMap::fromModels($models);
    }
}
