<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\controllers\api\v1\InventoriesController;
use app\controllers\InventoryController;
use app\models\AuditLog;
use app\models\Inventory;
use app\services\InventoryRuleAssignment;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\InventoryWriteFixtures;
use app\tests\integration\TeamScopeFixtures;

/**
 * The YAML check of a static inventory's content (Inventory::validateYaml())
 * runs for content a request brings: a new inventory, a change of the type,
 * or content that differs from the stored one in more than its line endings
 * (CRLF, CR and LF count as the same: a browser posts a textarea's lines
 * with CRLF). Content stored before the check, such as the INI that m000020
 * and m000035 seed for the selftest and demo inventories, is kept while the
 * name, the description or the approval rule change, through the model, the
 * API and the web form; content that changes must be a YAML mapping.
 *
 * Regression (2.12.0 review, API-5): the check ran on every save, so a
 * static inventory whose stored content is INI could not be given an
 * approval rule, renamed or described, through the form or the API, until
 * an admin rewrote its content as YAML.
 */
class InventoryIniContentTest extends WebControllerTestCase
{
    use InventoryWriteFixtures;
    use TeamScopeFixtures;

    /** The content m000020 and m000035 seed for the selftest and demo inventories. */
    private const INI = "[local]\nlocalhost ansible_connection=local\n";

    /** The YAML check's error of INI content. */
    private const INVALID_YAML = 'Invalid YAML: Unable to parse at line 2 (near "localhost ansible_connection=local").';

    protected function tearDown(): void
    {
        $this->detachInventoryHandlers();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: callable(Inventory): void}>
     */
    public static function unchangedContentProvider(): array
    {
        return [
            'a new name' => [static function (Inventory $inventory): void {
                $inventory->name = 'inv-ini-renamed';
            }],
            'a new description' => [static function (Inventory $inventory): void {
                $inventory->description = 'The runner itself';
            }],
            'an approval rule' => [static function (Inventory $inventory): void {
                $inventory->setAttribute('approval_rule_id', 1);
            }],
            'the content sent back with CRLF line endings, as a browser posts it' => [static function (Inventory $inventory): void {
                $inventory->content = str_replace("\n", "\r\n", self::INI);
            }],
            'the content sent back with CR line endings' => [static function (Inventory $inventory): void {
                $inventory->content = str_replace("\n", "\r", self::INI);
            }],
        ];
    }

    /**
     * Regression (API-5): a stored INI inventory is valid as long as its
     * content stays what is stored.
     *
     * @dataProvider unchangedContentProvider
     * @param callable(Inventory): void $change
     */
    public function testRegressionAStoredIniInventoryWasInvalidWhateverChanged(callable $change): void
    {
        $inventory = $this->iniInventory();
        $change($inventory);

        $this->assertTrue($inventory->validate(['content']), (string)json_encode($inventory->getErrors()));
    }

    /**
     * @return array<string, array{0: callable(Inventory): void, 1: string}> the change, and the content's error
     */
    public static function newContentProvider(): array
    {
        return [
            'other INI content' => [static function (Inventory $inventory): void {
                $inventory->content = "[local]\nlocalhost ansible_connection=local\nother\n";
            }, 'Invalid YAML: Unable to parse at line 2 (near "localhost ansible_connection=local").'],
            'the content without its last line break' => [static function (Inventory $inventory): void {
                $inventory->content = rtrim(self::INI);
            }, 'Invalid YAML: Unable to parse at line 2 (near "localhost ansible_connection=local").'],
            'a YAML scalar' => [static function (Inventory $inventory): void {
                $inventory->content = "localhost\n";
            }, 'Inventory must be a YAML mapping (not a scalar).'],
            'a static inventory that was dynamic' => [static function (Inventory $inventory): void {
                $inventory->setOldAttribute('inventory_type', Inventory::TYPE_DYNAMIC);
            }, self::INVALID_YAML],
            'content for a static inventory stored without any' => [static function (Inventory $inventory): void {
                $inventory->setOldAttribute('content', null);
            }, self::INVALID_YAML],
        ];
    }

    /**
     * Content a request brings must still be a YAML mapping, and so must
     * the content of an inventory that becomes static.
     *
     * @dataProvider newContentProvider
     * @param callable(Inventory): void $change
     */
    public function testNewOrChangedContentMustBeAYamlMapping(callable $change, string $error): void
    {
        $inventory = $this->iniInventory();
        $change($inventory);

        $this->assertFalse($inventory->validate(['content']));
        $this->assertSame([$error], $inventory->getErrors('content'));
    }

    /**
     * A new inventory is checked, INI or not.
     */
    public function testANewInventoryMustHoldAYamlMapping(): void
    {
        $inventory = new Inventory();
        $inventory->name = 'inv-ini-new';
        $inventory->inventory_type = Inventory::TYPE_STATIC;
        $inventory->content = self::INI;

        $this->assertFalse($inventory->validate(['content']));
        $this->assertSame([self::INVALID_YAML], $inventory->getErrors('content'));
        $this->assertSame([self::INVALID_YAML], $this->failedCreate(self::INI));
    }

    /**
     * Stored YAML changed to INI is checked: the check follows the change,
     * not the stored format.
     */
    public function testStoredYamlChangedToIniIsRefused(): void
    {
        $inventory = $this->staticInventory((int)$this->inventoryAdmin()->id);
        $inventory->content = self::INI;

        $this->assertFalse($inventory->validate(['content']));
        $this->assertSame([self::INVALID_YAML], $inventory->getErrors('content'));
    }

    /**
     * Regression (API-5): an admin gives a stored INI inventory an approval
     * rule through the API, and takes it away again; it answered 422
     * 'Invalid YAML: …'. The content stays as stored.
     */
    public function testRegressionAnIniInventoryCouldNotBeGivenARuleThroughTheApi(): void
    {
        $admin = $this->inventoryAdmin();
        $this->loginAs($admin);
        $inventory = $this->iniInventory((int)$admin->id);
        $rule = $this->createApprovalRule((int)$admin->id);

        $given = $this->put((int)$inventory->id, [InventoryRuleAssignment::FIELD => (int)$rule->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($given));
        $this->assertSame((int)$rule->id, $this->reloaded((int)$inventory->id)->getAttribute('approval_rule_id'));
        $this->put((int)$inventory->id, [InventoryRuleAssignment::FIELD => null, 'content' => self::INI]);
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $stored = $this->reloaded((int)$inventory->id);
        $this->assertNull($stored->getAttribute('approval_rule_id'));
        $this->assertSame(self::INI, $stored->content, 'the content as stored');
    }

    /**
     * Regression (API-5): an operator renames a stored INI inventory through
     * the API, with its content sent back as GET answers it.
     */
    public function testRegressionAnIniInventoryCouldNotBeRenamedThroughTheApi(): void
    {
        $operator = $this->inventoryOperator();
        $this->loginAs($operator);
        $inventory = $this->iniInventory((int)$operator->id);
        $body = (new InventoriesController('api/v1/inventories', \Yii::$app))->actionView((int)$inventory->id)['data'] ?? null;
        $this->assertIsArray($body);
        $body['name'] = 'inv-ini-renamed-api';

        $renamed = $this->put((int)$inventory->id, $body);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($renamed));
        $stored = $this->reloaded((int)$inventory->id);
        $this->assertSame(['inv-ini-renamed-api', self::INI], [$stored->name, $stored->content]);
        $this->assertSame(['name'], $this->inventoryAudits(AuditLog::ACTION_INVENTORY_UPDATED, (int)$inventory->id)[0]['changed_fields'] ?? null);
    }

    /**
     * A gated INI inventory: e2e-operator's rename is saved with the content
     * as stored, and a change of the content is still the lock's to refuse
     * (403 CHANGE_REFUSED, before any validation), with nothing written.
     */
    public function testAnOperatorRenamesAGatedIniInventoryButCannotChangeItsContent(): void
    {
        $operator = $this->inventoryOperator();
        $this->loginAs($operator);
        $inventory = $this->gate($this->iniInventory((int)$operator->id), $this->createApprovalRule((int)$operator->id));

        $renamed = $this->put((int)$inventory->id, ['name' => 'inv-ini-gated-renamed']);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($renamed));
        $this->assertSame(['inv-ini-gated-renamed', self::INI], [$this->reloaded((int)$inventory->id)->name, $this->reloaded((int)$inventory->id)->content]);
        $stored = $this->storedRow((int)$inventory->id);
        $refused = $this->put((int)$inventory->id, ['content' => "all:\n  hosts:\n    db1.example.com:\n"]);
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => \app\services\GatedInventoryLock::CHANGE_REFUSED]], $refused);
        $this->assertSame($stored, $this->storedRow((int)$inventory->id));
    }

    /**
     * Changed content is checked through the API too: 422, nothing written.
     */
    public function testChangedIniContentAnswers422ThroughTheApi(): void
    {
        $admin = $this->inventoryAdmin();
        $this->loginAs($admin);
        $inventory = $this->iniInventory((int)$admin->id);
        $stored = $this->storedRow((int)$inventory->id);

        $result = $this->put((int)$inventory->id, ['content' => "[web]\nweb1.example.com\n"]);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Invalid YAML: Unable to parse at line 2 (near "web1.example.com").']], $result);
        $this->assertSame($stored, $this->storedRow((int)$inventory->id));
    }

    /**
     * Regression (API-5): the edit form posts the content back with CRLF
     * line endings; an admin who gives a stored INI inventory a rule there,
     * and an operator who renames it, get it saved, not the form again with
     * 'Invalid YAML: …'.
     */
    public function testRegressionAnIniInventoryCouldNotBeSavedThroughTheEditForm(): void
    {
        $admin = $this->inventoryAdmin();
        $inventory = $this->iniInventory((int)$admin->id);
        $rule = $this->createApprovalRule((int)$admin->id);
        $form = static fn (string $name, string $rule): array => ['Inventory' => [
            'name' => $name,
            'description' => '',
            'inventory_type' => Inventory::TYPE_STATIC,
            'content' => str_replace("\n", "\r\n", self::INI),
            'project_id' => '',
            'source_path' => '',
            InventoryRuleAssignment::FIELD => $rule,
        ]];

        $this->loginAs($admin);
        $this->setPost($form((string)$inventory->name, (string)$rule->id));
        $ctrl = $this->webController();
        $ctrl->actionUpdate((int)$inventory->id);

        $this->assertSame(['view', 'id' => (int)$inventory->id], $ctrl->redirectedTo, 'saved: ' . (string)json_encode($ctrl->rendered));
        $this->assertSame((int)$rule->id, $this->reloaded((int)$inventory->id)->getAttribute('approval_rule_id'));

        $this->setPost($form((string)$inventory->name, ''));
        $ctrl = $this->webController();
        $ctrl->actionUpdate((int)$inventory->id);
        $this->assertSame(['view', 'id' => (int)$inventory->id], $ctrl->redirectedTo, 'the rule taken away: ' . (string)json_encode($ctrl->rendered));
        $this->assertNull($this->reloaded((int)$inventory->id)->getAttribute('approval_rule_id'));

        $this->loginAs($this->inventoryOperator());
        $posted = $form('inv-ini-renamed-web', '');
        unset($posted['Inventory'][InventoryRuleAssignment::FIELD]);
        $this->setPost($posted);
        $ctrl = $this->webController();
        $ctrl->actionUpdate((int)$inventory->id);
        $this->assertSame(['view', 'id' => (int)$inventory->id], $ctrl->redirectedTo, 'renamed: ' . (string)json_encode($ctrl->rendered));
        $this->assertSame('inv-ini-renamed-web', $this->reloaded((int)$inventory->id)->name);
    }

    // -- helpers -----------------------------------------------------------------

    /**
     * A stored static inventory with the INI content of the seeded selftest
     * inventory, loaded.
     */
    private function iniInventory(?int $createdBy = null): Inventory
    {
        $inventory = $this->staticInventory($createdBy ?? (int)$this->createUser('inv-ini-owner')->id);
        $this->assertSame(1, Inventory::updateAll(['content' => self::INI], ['id' => $inventory->id]));

        return $this->reloaded((int)$inventory->id);
    }

    /**
     * The content errors of a create through the API with $content.
     *
     * @return list<string>
     */
    private function failedCreate(string $content): array
    {
        $this->loginAs($this->inventoryAdmin());
        $this->setBodyParams(['name' => 'inv-ini-created', 'inventory_type' => Inventory::TYPE_STATIC, 'content' => $content]);
        $result = (new InventoriesController('api/v1/inventories', \Yii::$app))->actionCreate();
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertFalse(Inventory::find()->where(['name' => 'inv-ini-created'])->exists());

        return [(string)($result['error']['message'] ?? '')];
    }

    /**
     * PUT /api/v1/inventories/{id} with $body: the answer.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function put(int $id, array $body): array
    {
        $this->setBodyParams($body);

        return (new InventoriesController('api/v1/inventories', \Yii::$app))->actionUpdate($id);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBodyParams(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
    }

    /**
     * The inventory controller, with the view it renders and the route it
     * redirects to captured.
     */
    private function webController(): InventoryController
    {
        return new class ('inventory', \Yii::$app) extends InventoryController {
            /** @var array<string, mixed> the errors of the form it rendered */
            public array $rendered = [];
            /** @var mixed the route passed to redirect() */
            public mixed $redirectedTo = null;

            public function render($view, $params = []): string
            {
                $model = $params['model'] ?? null;
                $this->rendered = $model instanceof Inventory ? $model->getErrors() : [];

                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): \yii\web\Response
            {
                $this->redirectedTo = $url;

                return new \yii\web\Response();
            }
        };
    }
}
