<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\InventoryController;
use app\models\ApprovalRule;
use app\models\User;
use app\services\ApprovalRuleAssignmentPolicy;
use app\tests\integration\services\InventoryWriteFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Regression: the inventory form of a gated inventory, posted by a user
 * without approval-rule.update with a new name and Inventory[approval_rule_id]
 * as "+5" or "5\n" (url-encoded), or as 5.0 by a form posted as JSON
 * (RuleIdSpellings), was refused with 403 ASSIGN_REFUSED: the check of who
 * may assign a rule read the spelling as no rule ID, although the integer
 * rule reads it as the stored rule. Now the rename is saved, and another
 * rule, in any spelling, is still refused.
 */
class InventoryControllerRuleSpellingsTest extends WebControllerTestCase
{
    use InventoryWriteFixtures;
    use RuleIdSpellings;
    use TeamScopeFixtures;

    private User $admin;
    private ApprovalRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->inventoryAdmin();
        $this->rule = $this->createApprovalRule((int)$this->admin->id);
    }

    protected function tearDown(): void
    {
        $this->detachInventoryHandlers();
        \Yii::$app->session->removeAllFlashes();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: mixed}> the rule's ID as posted: sprintf() of a form text, or a float as JSON gives it
     */
    public static function postedSpellingProvider(): array
    {
        $cases = self::formSpellingProvider();
        $cases['a whole float, as a form posted as JSON gives it'] = [5.0];

        return $cases;
    }

    /**
     * @dataProvider postedSpellingProvider
     */
    public function testRegressionAnOperatorMayRenameAGatedInventoryWithItsStoredRuleInEverySpelling(mixed $spelling): void
    {
        $inventory = $this->gate($this->staticInventory((int)$this->admin->id), $this->rule);
        $this->loginAs($this->inventoryOperator());
        $newName = 'form-spelling-inv-' . uniqid('', true);
        $this->setPost(['Inventory' => ['name' => $newName, 'approval_rule_id' => $this->spelled($spelling, $this->rule)]]);

        $result = $this->makeController()->actionUpdate((int)$inventory->id);

        $this->assertInstanceOf(Response::class, $result, is_string($result) ? $result : 'saved');
        $stored = $this->reloaded((int)$inventory->id);
        $this->assertSame($newName, $stored->name);
        $this->assertSame((int)$this->rule->id, $stored->approval_rule_id, 'the gate stays');
    }

    /**
     * @dataProvider postedSpellingProvider
     */
    public function testAnOperatorsOtherRuleIsRefusedInEverySpelling(mixed $spelling): void
    {
        $inventory = $this->gate($this->staticInventory((int)$this->admin->id), $this->rule);
        $row = $this->storedRow((int)$inventory->id);
        $this->loginAs($this->inventoryOperator());
        $otherRule = $this->createApprovalRule((int)$this->admin->id);
        $this->setPost(['Inventory' => ['name' => 'form-spelling-inv-refused', 'approval_rule_id' => $this->spelled($spelling, $otherRule)]]);

        try {
            $this->makeController()->actionUpdate((int)$inventory->id);
            $this->fail('Expected ForbiddenHttpException');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame(ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED, $e->getMessage());
        }

        $this->assertSame($row, $this->storedRow((int)$inventory->id), 'nothing is saved');
    }

    // -- helpers -------------------------------------------------------------------

    /**
     * $rule's ID in $spelling: a form text's sprintf(), or a float.
     */
    private function spelled(mixed $spelling, ApprovalRule $rule): mixed
    {
        return is_string($spelling) ? sprintf($spelling, (int)$rule->id) : (float)$rule->id;
    }

    private function makeController(): InventoryController
    {
        $app = \Yii::$app;
        $this->assertNotNull($app, 'the test bootstrap creates the application');

        return new class ('inventory', $app) extends InventoryController {
            public function render($view, $params = []): string
            {
                $model = $params['model'] ?? null;

                return 'rendered:' . $view . ' ' . (string)json_encode($model instanceof \app\models\Inventory ? $model->getErrors() : null);
            }

            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };
    }
}
