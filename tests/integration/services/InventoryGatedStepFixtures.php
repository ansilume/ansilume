<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Inventory;
use app\models\JobTemplate;

/**
 * Fixtures of the tests of job steps gated through the inventory of their
 * job template (GatedJobSteps, ApprovalRuleAssignmentPolicy, the route map,
 * the steps table and the step form); use in DbTestCase subclasses.
 *
 * Rules are written with updateAll(): the model never mass-assigns an
 * inventory's approval_rule_id, and the tests change rules behind the back
 * of loaded models on purpose, as another user's save would.
 */
trait InventoryGatedStepFixtures
{
    /**
     * A job template of a project, an inventory and a runner group of its
     * own (no team restricts the project), whose inventory has the approval
     * rule $inventoryRuleId (null: none) and which has the rule $ownRuleId
     * of its own (null: none).
     */
    protected function templateOnGatedInventory(int $userId, ?int $inventoryRuleId, ?int $ownRuleId = null): JobTemplate
    {
        $template = $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
        $this->giveInventoryRule((int)$template->inventory_id, $inventoryRuleId);
        JobTemplate::updateAll(['approval_rule_id' => $ownRuleId], ['id' => $template->id]);
        $template->refresh();

        return $template;
    }

    /**
     * Give inventory #$inventoryId the approval rule $ruleId (null: none),
     * as an admin's save stores it.
     */
    protected function giveInventoryRule(int $inventoryId, ?int $ruleId): void
    {
        Inventory::updateAll(['approval_rule_id' => $ruleId], ['id' => $inventoryId]);
        $stored = Inventory::find()->select('approval_rule_id')->where(['id' => $inventoryId])->scalar();
        $this->assertSame($ruleId, $stored === null ? null : (int)$stored, "inventory #{$inventoryId} has the rule");
    }

    /**
     * A new inventory, of no project, with the approval rule $ruleId.
     */
    protected function gatedInventory(int $userId, int $ruleId): Inventory
    {
        $inventory = $this->createInventory($userId);
        $this->giveInventoryRule((int)$inventory->id, $ruleId);
        $inventory->refresh();

        return $inventory;
    }

    /**
     * Give job template #$templateId the inventory #$inventoryId, as an
     * admin's save stores it.
     */
    protected function moveToInventory(int $templateId, int $inventoryId): void
    {
        $this->assertSame(1, JobTemplate::updateAll(['inventory_id' => $inventoryId], ['id' => $templateId]));
    }
}
