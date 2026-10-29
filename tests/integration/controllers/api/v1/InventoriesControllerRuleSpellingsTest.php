<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\InventoriesController;
use app\models\ApiToken;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Inventory;
use app\models\User;
use app\services\ApprovalRuleAssignmentPolicy;
use app\tests\integration\controllers\RuleIdSpellings;
use app\tests\integration\controllers\SendsJsonNumbers;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\InventoryWriteFixtures;
use app\tests\integration\TeamScopeFixtures;

/**
 * Regression: PUT /api/v1/inventories/{id} of a gated inventory by a user
 * without approval-rule.update, with a new name and the stored rule written
 * as 5.0, 5e0, "+5" or "5\n" (RuleIdSpellings), answered 403 ASSIGN_REFUSED:
 * the check of who may assign a rule, which the inventory shares with job
 * templates, read such a spelling as no rule ID, although the integer rule
 * reads it as the stored rule. Sending the stored rule again is allowed for
 * everyone, so the rename answers 200 now; another rule, in any spelling,
 * is still refused. An admin's rule was always recorded as the rule it
 * stands for (InventoryWriteService reads it so), and still is.
 */
class InventoriesControllerRuleSpellingsTest extends WebControllerTestCase
{
    use InventoryWriteFixtures;
    use RuleIdSpellings;
    use SendsJsonNumbers;
    use TeamScopeFixtures;

    private User $admin;
    private ApprovalRule $rule;
    private ApprovalRule $otherRule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->inventoryAdmin();
        $this->rule = $this->createApprovalRule((int)$this->admin->id);
        $this->otherRule = $this->createApprovalRule((int)$this->admin->id);
    }

    protected function tearDown(): void
    {
        $this->detachInventoryHandlers();
        parent::tearDown();
    }

    /**
     * @dataProvider jsonSpellingProvider
     */
    public function testRegressionAnOperatorMayRenameAGatedInventoryWithItsStoredRuleInEverySpelling(string $spelling): void
    {
        $inventory = $this->gate($this->staticInventory((int)$this->admin->id), $this->rule);
        $this->authenticate($this->inventoryOperator());
        $newName = 'rule-spelling-inv-' . uniqid('', true);
        $this->sendJsonText($this->jsonWithRule(['name' => $newName], sprintf($spelling, (int)$this->rule->id)), 'PUT');

        $result = $this->api()->runAction('update', ['id' => $inventory->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($result));
        $stored = $this->reloaded((int)$inventory->id);
        $this->assertSame($newName, $stored->name);
        $this->assertSame((int)$this->rule->id, $stored->approval_rule_id, 'the gate stays');
        $context = $this->newestAuditContext(AuditLog::ACTION_INVENTORY_UPDATED, 'inventory', (int)$inventory->id);
        $this->assertSame(['name'], $context['changed_fields'] ?? null);
        $this->assertArrayNotHasKey('approval_rule', $context, 'no rule change');
    }

    /**
     * Any other rule, in any spelling, stays a change that only admins make,
     * on a gated inventory and on an ungated one.
     *
     * @dataProvider jsonSpellingProvider
     */
    public function testAnOperatorsOtherRuleIsRefusedInEverySpelling(string $spelling): void
    {
        $gated = $this->gate($this->staticInventory((int)$this->admin->id), $this->rule);
        $ungated = $this->staticInventory((int)$this->admin->id);
        $rows = [$this->storedRow((int)$gated->id), $this->storedRow((int)$ungated->id)];
        $auditBefore = $this->newestAuditId();
        $this->authenticate($this->inventoryOperator());

        foreach ([$gated, $ungated] as $inventory) {
            $this->sendJsonText($this->jsonWithRule(['name' => 'rule-spelling-inv-refused'], sprintf($spelling, (int)$this->otherRule->id)), 'PUT');
            $result = $this->api()->runAction('update', ['id' => $inventory->id]);
            $this->assertSame(403, \Yii::$app->response->statusCode, (string)json_encode($result));
            $this->assertSame(['error' => ['message' => ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED]], $result);
        }

        $this->assertSame($rows, [$this->storedRow((int)$gated->id), $this->storedRow((int)$ungated->id)], 'nothing was written');
        $this->assertSame($auditBefore, $this->newestAuditId(), 'nothing was audited');
    }

    /**
     * An admin's rule in every spelling is assigned, changed and sent again
     * as the rule it stands for, and audited so, as before.
     *
     * @dataProvider jsonSpellingProvider
     */
    public function testAnAdminsRuleIsTheRuleItStandsForInEverySpelling(string $spelling): void
    {
        $inventory = $this->staticInventory((int)$this->admin->id);
        $this->authenticate($this->admin);
        $audits = [];
        foreach ([$this->rule, $this->otherRule, $this->otherRule] as $rule) {
            $this->sendJsonText('{"approval_rule_id": ' . sprintf($spelling, (int)$rule->id) . '}', 'PUT');
            $result = $this->api()->runAction('update', ['id' => $inventory->id]);
            $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($result));
            $this->assertSame((int)$rule->id, $this->reloaded((int)$inventory->id)->approval_rule_id);
            $audits[] = $this->newestAuditContext(AuditLog::ACTION_INVENTORY_UPDATED, 'inventory', (int)$inventory->id)['approval_rule'] ?? null;
        }

        $this->assertSame(
            [
                ['from' => null, 'to' => $this->ruleEntry($this->rule)],
                ['from' => $this->ruleEntry($this->rule), 'to' => $this->ruleEntry($this->otherRule)],
                null,
            ],
            $audits,
            'assigned, changed, then sent again: no change'
        );
    }

    /**
     * The spec states what these tests show: the spellings it names are the
     * rule, and the stored rule sent again in any of them is allowed for
     * everyone and no change.
     */
    public function testTheSpecSaysEverySpellingOfTheStoredRuleIsNoChange(): void
    {
        $text = $this->ruleFieldSpecText('InventoryInput');

        $this->assertStringContainsString('`3`, `"3"`, `"+3"`, `"03"` and `3.0` are rule 3.', $text);
        $this->assertStringContainsString(
            'sending the stored value again, in any of these forms, or leaving the field out, is allowed for everyone and'
            . ' changes no rule.',
            $text
        );
    }

    // -- helpers -------------------------------------------------------------------

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'rule-spelling-inv-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        \Yii::$app->response->statusCode = 200;
    }

    private function api(): InventoriesController
    {
        $app = \Yii::$app;
        $this->assertNotNull($app, 'the test bootstrap creates the application');

        return new InventoriesController('api/v1/inventories', $app);
    }
}
