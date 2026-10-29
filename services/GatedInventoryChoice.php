<?php

declare(strict_types=1);

namespace app\services;

use app\components\SubmittedId;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;

/**
 * The inventory side of GatedJobTemplateLock: what gates a stored job
 * template (storedSource(): its own rule first, else its inventory's, as
 * JobTemplateGate says), and whether a user may give a job template an
 * inventory with an approval rule (refusal()). Such an inventory gates every
 * job template that uses it, so giving a template one is for admins
 * (approval-rule.update), on a new template too.
 *
 * It reads no inventory row itself: the rules come from
 * JobTemplateGate::readInventoryRules(), which the lock calls once per
 * question, in the lock order of the save (C16: the template row FOR UPDATE,
 * then the inventory rows LOCK IN SHARE MODE in one statement). Its own
 * reads are plain ones: whether the rule a template names exists, whether
 * the user may assign rules, and whether the user may see the inventory.
 *
 * No existence oracle: an inventory the user may not see (the filter of
 * JobTemplate::restrictInventories()) is never refused here. The save goes
 * on to the template's validation, which answers "The selected inventory
 * does not exist." for it, as for an inventory that does not exist.
 */
final class GatedInventoryChoice
{
    /**
     * What gates the stored template: JobTemplateGate::SOURCE_JOB_TEMPLATE
     * when its stored rule ($storedRuleId) exists, also when its inventory
     * has the same one; else JobTemplateGate::SOURCE_INVENTORY when its
     * stored inventory has a rule in $inventoryRules (as
     * JobTemplateGate::readInventoryRules() read them); else null.
     *
     * @param array<int, int|null> $inventoryRules
     * @return JobTemplateGate::SOURCE_*|null
     */
    public static function storedSource(mixed $storedRuleId, int $storedInventoryId, array $inventoryRules): ?string
    {
        if (self::ruleExists($storedRuleId)) {
            return JobTemplateGate::SOURCE_JOB_TEMPLATE;
        }

        return self::hasRule($inventoryRules, $storedInventoryId) ? JobTemplateGate::SOURCE_INVENTORY : null;
    }

    /**
     * Why $userId may not give $template the inventory its inventory_id
     * names now, or null when they may: GatedJobTemplateLock::GATED_INVENTORY_REFUSED
     * when that inventory is another one than the stored one
     * ($storedInventoryId; null for a new template), has an approval rule in
     * $inventoryRules, the user may not assign rules, and the user may see
     * the inventory. An inventory the user may not see is left to the
     * template's validation, so the answer does not tell whether it exists
     * or has a rule.
     *
     * @param array<int, int|null> $inventoryRules
     */
    public static function refusal(int $userId, JobTemplate $template, ?int $storedInventoryId, array $inventoryRules): ?string
    {
        $inventoryId = self::positiveId($template->getAttribute('inventory_id'));
        if ($inventoryId === null || $inventoryId === $storedInventoryId || !self::hasRule($inventoryRules, $inventoryId)) {
            return null;
        }

        return self::policy()->mayAssign($userId) || !self::visible($userId, $inventoryId)
            ? null
            : GatedJobTemplateLock::GATED_INVENTORY_REFUSED;
    }

    /**
     * Whether $ruleId names an existing approval rule.
     */
    private static function ruleExists(mixed $ruleId): bool
    {
        $id = self::positiveId($ruleId);

        return $id !== null && ApprovalRule::find()->where(['id' => $id])->exists();
    }

    /**
     * Whether inventory #$inventoryId has an approval rule in $inventoryRules:
     * a positive rule ID counts as a rule without a lookup, as the foreign
     * key restricts the delete of a rule that an inventory uses.
     *
     * @param array<int, int|null> $inventoryRules
     */
    private static function hasRule(array $inventoryRules, int $inventoryId): bool
    {
        return ($inventoryRules[$inventoryId] ?? 0) > 0;
    }

    /**
     * Whether $userId may see inventory #$inventoryId: the team filter of
     * JobTemplate::restrictInventories(), applied only when there is one (no
     * team-restricted project, or an unrestricted user: every inventory).
     */
    private static function visible(int $userId, int $inventoryId): bool
    {
        $query = Inventory::find()->where(['inventory.id' => $inventoryId]);
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');
        $filter = $checker->buildChildResourceFilter($userId, 'inventory.project_id');
        if ($filter !== null) {
            $query->andWhere($filter);
        }

        return $query->exists();
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

    private static function policy(): ApprovalRuleAssignmentPolicy
    {
        /** @var ApprovalRuleAssignmentPolicy $policy */
        $policy = \Yii::$app->get('approvalRuleAssignmentPolicy');

        return $policy;
    }
}
