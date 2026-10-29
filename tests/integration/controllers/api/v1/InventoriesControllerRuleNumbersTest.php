<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\InventoriesController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\Inventory;
use app\models\User;
use app\services\ApprovalRuleAssignmentPolicy;
use app\tests\integration\controllers\SendsJsonNumbers;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The approval_rule_id of POST and PUT /api/v1/inventories, a JSON number
 * beyond the int range. An admin's answers 422 with the model's error
 * ("Approval rule must be an integer."), anyone else's 403 as a rule change
 * they may not make (ASSIGN_REFUSED), as before; nothing is written, audited
 * or logged as a failure. A rule ID, also as digits, is assigned as before.
 *
 * Regression: an admin's PUT answered 500 on PHP 8.5. The write service
 * asks the gated inventory lock which attributes the request changes, and
 * its comparison cast the rule with the column's type cast ("The float
 * 1.0E+30 is not representable as an int, cast occurred" in
 * yii\db\ColumnSchema, from GatedInventoryLock::sameValue()).
 */
class InventoriesControllerRuleNumbersTest extends WebControllerTestCase
{
    use SendsJsonNumbers;
    use TeamScopeFixtures;

    private const NOT_A_RULE_ID = 'Approval rule must be an integer.';

    private const CONTENT = "all:\n  hosts:\n    web1.example.com:\n";

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testAnAdminsUpdateWithARuleBeyondTheIntRangeIs422(string $number): void
    {
        $this->authenticate($this->createUserWithRole('rule-numbers-admin', 'admin'));
        $inventory = $this->inventory();
        $before = $this->storedRow($inventory);
        $auditBefore = $this->lastAuditId();
        $body = $this->sendJsonText('{"name": "rule-numbers-renamed", "approval_rule_id": ' . $number . '}', 'PUT');
        $this->assertIsFloat($body['approval_rule_id'], 'the parser gives a float');

        [$result, $problems] = $this->logged(fn (): mixed => $this->api()->runAction('update', ['id' => $inventory->id]));

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::NOT_A_RULE_ID]], $result);
        $this->assertSame($before, $this->storedRow($inventory), 'nothing was written');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testAnAdminsCreateWithARuleBeyondTheIntRangeIs422(string $number): void
    {
        $this->authenticate($this->createUserWithRole('rule-numbers-creator', 'admin'));
        $auditBefore = $this->lastAuditId();
        $this->sendJsonText('{"name": "rule-numbers-created", "inventory_type": "static", "content": '
            . (string)json_encode(self::CONTENT) . ', "approval_rule_id": ' . $number . '}');

        [$result, $problems] = $this->logged(fn (): mixed => $this->api()->runAction('create'));

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::NOT_A_RULE_ID]], $result);
        $this->assertFalse(Inventory::find()->where(['name' => 'rule-numbers-created'])->exists(), 'nothing was created');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testAnOperatorsRuleBeyondTheIntRangeIsARuleChangeTheyMayNotMake(string $number): void
    {
        $this->authenticate($this->createUserWithRole('rule-numbers-operator', 'operator'));
        $inventory = $this->inventory();
        $before = $this->storedRow($inventory);
        $this->sendJsonText('{"approval_rule_id": ' . $number . '}', 'PUT');

        $result = $this->api()->runAction('update', ['id' => $inventory->id]);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED]], $result);
        $this->assertSame($before, $this->storedRow($inventory));
    }

    /**
     * A rule ID keeps its answer, as an int and as its digits.
     *
     * @return array<string, array{0: string}>
     */
    public static function ruleIdProvider(): array
    {
        return ['the ID' => ['%d'], 'the ID as digits' => ['"%d"']];
    }

    /**
     * @dataProvider ruleIdProvider
     */
    public function testAnAdminAssignsARuleIdAsBefore(string $form): void
    {
        $admin = $this->createUserWithRole('rule-numbers-assigner', 'admin');
        $this->authenticate($admin);
        $ruleId = (int)$this->createApprovalRule((int)$admin->id)->id;
        $inventory = $this->inventory();
        $this->sendJsonText('{"approval_rule_id": ' . sprintf($form, $ruleId) . '}', 'PUT');

        $result = $this->api()->runAction('update', ['id' => $inventory->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertSame($ruleId, Inventory::findOne($inventory->id)?->approval_rule_id);
    }

    // -- helpers -------------------------------------------------------------------

    private function inventory(): Inventory
    {
        $inventory = $this->createInventory((int)$this->createUser('rule-numbers-owner')->id);
        $inventory->content = self::CONTENT;
        $inventory->save(false);

        return $inventory;
    }

    /**
     * @return array<string, mixed>
     */
    private function storedRow(Inventory $inventory): array
    {
        $row = (new \yii\db\Query())->from(Inventory::tableName())->where(['id' => $inventory->id])->one();
        $this->assertIsArray($row);

        return $row;
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'rule-numbers-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
    }

    private function api(): InventoriesController
    {
        $app = \Yii::$app;
        $this->assertNotNull($app, 'the test bootstrap creates the application');

        return new InventoriesController('api/v1/inventories', $app);
    }

    private function lastAuditId(): int
    {
        return (int)AuditLog::find()->max('id');
    }
}
