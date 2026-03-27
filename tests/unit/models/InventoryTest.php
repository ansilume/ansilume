<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\Inventory;
use PHPUnit\Framework\TestCase;

class InventoryTest extends TestCase
{
    public function testTableName(): void
    {
        $this->assertSame('{{%inventory}}', Inventory::tableName());
    }

    public function testTypeConstants(): void
    {
        $this->assertSame('static', Inventory::TYPE_STATIC);
        $this->assertSame('dynamic', Inventory::TYPE_DYNAMIC);
        $this->assertSame('file', Inventory::TYPE_FILE);
    }

    public function testRulesRequireNameAndType(): void
    {
        $inv = new Inventory();
        $inv->validate();
        $this->assertArrayHasKey('name', $inv->errors);
        $this->assertArrayHasKey('inventory_type', $inv->errors);
    }

    public function testInvalidTypeIsRejected(): void
    {
        $inv = new Inventory();
        $inv->name           = 'Test';
        $inv->inventory_type = 'nonexistent_type';
        $inv->validate(['inventory_type']);
        $this->assertArrayHasKey('inventory_type', $inv->errors);
    }

    public function testStaticTypeIsValid(): void
    {
        $inv = new Inventory();
        $inv->name           = 'Test';
        $inv->inventory_type = Inventory::TYPE_STATIC;
        $inv->content        = "[web]\nlocalhost";
        $inv->validate(['inventory_type', 'content']);
        $this->assertArrayNotHasKey('inventory_type', $inv->errors);
        $this->assertArrayNotHasKey('content', $inv->errors);
    }

    public function testDynamicTypeIsValid(): void
    {
        $inv = new Inventory();
        $inv->name           = 'Test';
        $inv->inventory_type = Inventory::TYPE_DYNAMIC;
        $inv->validate(['inventory_type']);
        $this->assertArrayNotHasKey('inventory_type', $inv->errors);
    }

    public function testStaticTypeRequiresContent(): void
    {
        $inv = new Inventory();
        $inv->name           = 'Test';
        $inv->inventory_type = Inventory::TYPE_STATIC;
        $inv->content        = null;
        $inv->validate(['content']);
        $this->assertArrayHasKey('content', $inv->errors);
    }

    public function testFileTypeRequiresSourcePathAndProjectId(): void
    {
        $inv = new Inventory();
        $inv->name           = 'Test';
        $inv->inventory_type = Inventory::TYPE_FILE;
        $inv->source_path    = null;
        $inv->project_id     = null;
        $inv->validate(['source_path', 'project_id']);
        $this->assertArrayHasKey('source_path', $inv->errors);
        $this->assertArrayHasKey('project_id', $inv->errors);
    }

    public function testSourcePathMaxLength512(): void
    {
        $inv = new Inventory();
        $inv->name           = 'Test';
        $inv->inventory_type = Inventory::TYPE_FILE;
        $inv->source_path    = str_repeat('a', 513);
        $inv->project_id     = 1;
        $inv->validate(['source_path']);
        $this->assertArrayHasKey('source_path', $inv->errors);
    }
}
