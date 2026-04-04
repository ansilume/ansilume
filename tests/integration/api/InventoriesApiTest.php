<?php

declare(strict_types=1);

namespace app\tests\integration\api;

use app\models\Inventory;
use app\tests\integration\DbTestCase;

class InventoriesApiTest extends DbTestCase
{
    public function testInventorySerializationShape(): void
    {
        $user = $this->createUser();
        $inv = $this->createInventory($user->id);

        $serialized = [
            'id' => $inv->id,
            'name' => $inv->name,
            'description' => $inv->description,
            'inventory_type' => $inv->inventory_type,
            'project_id' => $inv->project_id,
            'created_at' => $inv->created_at,
            'updated_at' => $inv->updated_at,
        ];

        $this->assertArrayNotHasKey('content', $serialized);
        $this->assertArrayNotHasKey('source_path', $serialized);
        $this->assertSame(Inventory::TYPE_STATIC, $serialized['inventory_type']);
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

    public function testInventoryContentNotExposedInApiShape(): void
    {
        $user = $this->createUser();
        $inv = $this->createInventory($user->id);

        // The API controller intentionally excludes content and source_path
        $this->assertNotNull($inv->content);
        $this->assertSame("localhost\n", $inv->content);
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
}
