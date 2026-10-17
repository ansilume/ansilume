<?php

declare(strict_types=1);

namespace app\tests\integration\api;

use app\controllers\api\v1\InventoriesController;
use app\models\Inventory;
use app\models\User;
use app\tests\integration\controllers\WebControllerTestCase;

class InventoriesApiTest extends WebControllerTestCase
{
    /**
     * GET /api/v1/inventories: each item without content and source_path,
     * with the inventory's approval rule.
     */
    public function testInventorySerializationShape(): void
    {
        $user = $this->apiUser();
        $inv = $this->createInventory($user->id);

        $serialized = $this->listItemOf((int)$inv->id);

        $this->assertSame([
            'id' => (int)$inv->id,
            'name' => $inv->name,
            'description' => null,
            'inventory_type' => Inventory::TYPE_STATIC,
            'project_id' => null,
            'approval_rule_id' => null,
            'approval_rule_name' => null,
            'created_at' => (int)$inv->created_at,
            'updated_at' => (int)$inv->updated_at,
        ], $serialized);
        $this->assertArrayNotHasKey('content', $serialized);
        $this->assertArrayNotHasKey('source_path', $serialized);
    }

    public function testInventoryFindOneReturnsCorrectRecord(): void
    {
        $user = $this->createUser();
        $inv = $this->createInventory($user->id);

        $found = Inventory::findOne($inv->id);

        $this->assertNotNull($found);
        $this->assertSame($inv->id, $found->id);
        $this->assertSame($inv->name, $found->name);
    }

    public function testInventoryFindOneReturnsNullForMissingId(): void
    {
        $this->assertNull(Inventory::findOne(999999));
    }

    public function testInventoryListReturnsMultiple(): void
    {
        $user = $this->createUser();
        $i1 = $this->createInventory($user->id);
        $i2 = $this->createInventory($user->id);

        $results = Inventory::find()
            ->where(['id' => [$i1->id, $i2->id]])
            ->orderBy(['id' => SORT_DESC])
            ->all();

        $this->assertCount(2, $results);
    }

    /**
     * The list leaves out content and source_path; GET /{id}, for the
     * audience of the inventory's page, answers them.
     */
    public function testInventoryContentNotExposedInApiShape(): void
    {
        $user = $this->apiUser();
        $inv = $this->createInventory($user->id);

        $this->assertArrayNotHasKey('content', $this->listItemOf((int)$inv->id));
        $detail = (new InventoriesController('api/v1/inventories', \Yii::$app))->actionView((int)$inv->id);
        $this->assertSame("localhost\n", $detail['data']['content'] ?? null);
        $this->assertArrayHasKey('source_path', $detail['data']);
        $this->assertNull($detail['data']['source_path']);
    }

    public function testCreateStaticInventoryWithYamlContent(): void
    {
        $user = $this->createUser();

        $inv = new Inventory();
        $inv->name = 'zabbix-hq-' . uniqid('', true);
        $inv->inventory_type = Inventory::TYPE_STATIC;
        $inv->content = "---\nall:\n  hosts:\n    zabbix-hq:\n      ansible_host: 10.1.42.108\n";
        $inv->created_by = $user->id;

        $this->assertTrue($inv->save(), 'Static inventory with YAML content must save');
        $this->assertNotNull($inv->id);
        $this->assertSame(Inventory::TYPE_STATIC, $inv->inventory_type);
    }

    public function testUpdateInventory(): void
    {
        $user = $this->createUser();
        $inv = $this->createInventory($user->id);

        $inv->name = 'renamed-' . uniqid('', true);
        $inv->description = 'updated description';
        $inv->content = "all:\n  hosts:\n    localhost: {}\n";
        $this->assertTrue($inv->save());

        $inv->refresh();
        $this->assertStringStartsWith('renamed-', $inv->name);
        $this->assertSame('updated description', $inv->description);
    }

    public function testDeleteInventory(): void
    {
        $user = $this->createUser();
        $inv = $this->createInventory($user->id);
        $id = $inv->id;

        $this->assertSame(1, $inv->delete());
        $this->assertNull(Inventory::findOne($id));
    }

    public function testValidationRequiresName(): void
    {
        $inv = new Inventory();
        $inv->inventory_type = Inventory::TYPE_STATIC;
        $inv->content = "localhost\n";
        $inv->created_by = 1;

        $this->assertFalse($inv->validate());
        $this->assertTrue($inv->hasErrors('name'));
    }

    public function testValidationRequiresInventoryType(): void
    {
        $inv = new Inventory();
        $inv->name = 'test';
        $inv->created_by = 1;

        $this->assertFalse($inv->validate());
        $this->assertTrue($inv->hasErrors('inventory_type'));
    }

    public function testValidationRejectsInvalidYamlContent(): void
    {
        $inv = new Inventory();
        $inv->name = 'bad-yaml';
        $inv->inventory_type = Inventory::TYPE_STATIC;
        $inv->content = "  foo: [this: is: not: valid";
        $inv->created_by = 1;

        $this->assertFalse($inv->validate());
        $this->assertTrue($inv->hasErrors('content'));
    }

    /**
     * A signed-in admin, who sees every inventory.
     */
    private function apiUser(): User
    {
        $user = $this->createUser('api-inventories');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->getRole('admin');
        $this->assertNotNull($role);
        $auth->assign($role, (string)$user->id);
        $this->loginAs($user);

        return $user;
    }

    /**
     * The item of inventory #$id in GET /api/v1/inventories.
     *
     * @return array<string, mixed>
     */
    private function listItemOf(int $id): array
    {
        $ctrl = new InventoriesController('api/v1/inventories', \Yii::$app);
        $page = 1;
        do {
            $this->setQueryParams(['page' => $page]);
            $answer = $ctrl->actionIndex();
            foreach ($answer['data'] as $item) {
                if (is_array($item) && ($item['id'] ?? null) === $id) {
                    /** @var array<string, mixed> $item */
                    return $item;
                }
            }
            $page++;
        } while ($page <= $answer['meta']['pages']);
        $this->fail("Inventory #{$id} is not listed.");
    }
}
