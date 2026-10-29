<?php

declare(strict_types=1);

namespace app\services;

use app\components\SubmittedId;
use app\components\SurveyDefinition;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\Project;

/**
 * The inventories the job template form offers, and its two hints on
 * inventories with an approval rule. Such an inventory gates every job
 * template that uses it (JobTemplateGate), so giving a job template one is
 * for admins (approval-rule.update); GatedJobTemplateLock refuses it to
 * everyone else (GATED_INVENTORY_REFUSED). So the form gives:
 * - admins every inventory, the gated ones marked (GATED_OPTION_SUFFIX), and,
 *   when the template's inventory has a rule, a hint at the rule select
 *   (JobTemplateRuleAssignment::INVENTORY_RULE_HINT): a launch waits for one
 *   rule only;
 * - everyone else the inventories without a rule, plus the template's stored
 *   inventory, which a locked form keeps showing (marked too); an unlocked
 *   form says that gated ones were left out (GATED_INVENTORIES_HINT).
 *
 * An inventory counts as gated as the template lock counts it
 * (GatedInventoryChoice): its approval_rule_id is a positive ID. The form
 * passes only the inventories the user may see (its team filter), so an
 * inventory the user may not see is neither listed nor counted.
 */
final class JobTemplateInventoryOptions
{
    /** Appended to the label of an inventory with an approval rule; sprintf(): the rule's name. */
    public const GATED_OPTION_SUFFIX = ' · needs approval: %s';

    /** Below the inventory select of a user who may not assign rules, when inventories with a rule were left out. */
    public const GATED_INVENTORIES_HINT = 'Inventories with an approval rule are not listed: only admins may give a '
        . 'job template such an inventory.';

    /** The label of a project-bound inventory; sprintf(): its name, its type, its project's name (or NO_PROJECT). */
    private const PROJECT_BOUND_LABEL = '%s (%s, %s)';

    /** The project part of PROJECT_BOUND_LABEL for an inventory without a project. */
    private const NO_PROJECT = 'no project';

    /** The rule's name in GATED_OPTION_SUFFIX when its row is gone; sprintf(): its ID. */
    private const RULE_GONE = 'rule #%d';

    /**
     * The options of the inventory select, by inventory ID, in the order of
     * $inventories: the label, with GATED_OPTION_SUFFIX for an inventory with
     * an approval rule. Unless $mayAssign, inventories with a rule are left
     * out, except $keepId (the template's stored inventory; null for a new
     * template); gated_omitted tells whether any was.
     *
     * @param list<Inventory> $inventories
     * @return array{options: array<int, string>, gated_omitted: bool}
     */
    public static function of(array $inventories, bool $mayAssign, ?int $keepId): array
    {
        $options = [];
        $omitted = false;
        foreach ($inventories as $inventory) {
            $id = (int)$inventory->id;
            $ruleId = self::positiveId($inventory->getAttribute('approval_rule_id'));
            if ($ruleId === null) {
                $options[$id] = self::label($inventory);
            } elseif ($mayAssign || $id === $keepId) {
                $options[$id] = self::label($inventory) . sprintf(self::GATED_OPTION_SUFFIX, self::ruleName($inventory, $ruleId));
            } else {
                $omitted = true;
            }
        }

        return ['options' => $options, 'gated_omitted' => $omitted];
    }

    /**
     * The form's inventory parameters for $model: the options (of(), keeping
     * the stored inventory of a saved template); inventoryHint,
     * GATED_INVENTORIES_HINT for a user who may not assign rules on a form
     * that is not locked ($changeLocked) when inventories with a rule were
     * left out; inventoryRuleHint, for a user who may assign rules,
     * JobTemplateRuleAssignment::INVENTORY_RULE_HINT when the template's
     * inventory (JobTemplateGate::of(), as $model names it) has a rule.
     *
     * @param list<Inventory> $inventories the inventories the user may see
     * @return array{inventoryOptions: array<int, string>, inventoryHint: string|null, inventoryRuleHint: string|null}
     */
    public static function formParams(JobTemplate $model, array $inventories, bool $mayAssign, bool $changeLocked): array
    {
        $keepId = $model->isNewRecord ? null : self::positiveId($model->getOldAttribute('inventory_id'));
        $listed = self::of($inventories, $mayAssign, $keepId);
        $omittedHint = !$mayAssign && !$changeLocked && $listed['gated_omitted'];

        return [
            'inventoryOptions' => $listed['options'],
            'inventoryHint' => $omittedHint ? self::GATED_INVENTORIES_HINT : null,
            'inventoryRuleHint' => $mayAssign ? self::inventoryRuleHint($model) : null,
        ];
    }

    /**
     * INVENTORY_RULE_HINT for the inventory $model names, filled as
     * RULES_DIFFER is: the inventory's name and the rule's name, each as
     * SurveyDefinition::quote() gives it. Null when that inventory has no
     * rule, or its rule's row is gone.
     */
    private static function inventoryRuleHint(JobTemplate $model): ?string
    {
        $gate = JobTemplateGate::of($model);
        $rule = $gate->inventoryRule;
        if ($rule === null) {
            return null;
        }

        return sprintf(
            JobTemplateRuleAssignment::INVENTORY_RULE_HINT,
            SurveyDefinition::quote((string)$gate->inventoryName),
            SurveyDefinition::quote((string)$rule->name)
        );
    }

    /**
     * An inventory as the select names it: a file or dynamic one with its
     * type and project, as only the template's project may use it.
     */
    private static function label(Inventory $inventory): string
    {
        if (!$inventory->isProjectBound()) {
            return (string)$inventory->name;
        }
        /** @var Project|null $project */
        $project = $inventory->project;

        return sprintf(self::PROJECT_BOUND_LABEL, $inventory->name, $inventory->inventory_type, $project?->name ?? self::NO_PROJECT);
    }

    /**
     * The name of the approval rule #$ruleId of $inventory; RULE_GONE when
     * its row is gone (the foreign key restricts that).
     */
    private static function ruleName(Inventory $inventory, int $ruleId): string
    {
        /** @var ApprovalRule|null $rule */
        $rule = $inventory->approvalRule;

        return $rule === null ? sprintf(self::RULE_GONE, $ruleId) : (string)$rule->name;
    }

    /**
     * $number as an ID: a positive whole number as the integer rule reads it
     * (SubmittedId), as the integer columns store it; "", 0 and anything the
     * rule refuses (a number beyond the int range included) are none.
     */
    private static function positiveId(mixed $number): ?int
    {
        $id = SubmittedId::of($number) ?? 0;

        return $id > 0 ? $id : null;
    }
}
