<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\Inventory;
use app\tests\integration\DbTestCase;

class InventoryTest extends DbTestCase
{
    // -- tableName / behaviors ---------------------------------------------------

    public function testTableName(): void
    {
        $this->assertSame('{{%inventory}}', Inventory::tableName());
    }

    public function testTimestampBehaviorIsRegistered(): void
    {
        $inv = new Inventory();
        $behaviors = $inv->behaviors();
        $this->assertContains(\yii\behaviors\TimestampBehavior::class, $behaviors);
    }

    // -- validation: required fields --------------------------------------------

    public function testValidationRequiresNameAndType(): void
    {
        $inv = new Inventory();
        $this->assertFalse($inv->validate());
        $this->assertArrayHasKey('name', $inv->getErrors());
        $this->assertArrayHasKey('inventory_type', $inv->getErrors());
    }

    public function testValidationPassesWithMinimalStaticInventory(): void
    {
        $user = $this->createUser();
        $inv = new Inventory();
        $inv->name = 'test';
        $inv->inventory_type = Inventory::TYPE_STATIC;
        $inv->content = "all:\n  hosts:\n    server1:\n";
        $inv->created_by = $user->id;
        $this->assertTrue($inv->validate());
    }

    // -- validation: inventory type ---------------------------------------------

    public function testValidationRejectsInvalidType(): void
    {
        $inv = new Inventory();
        $inv->name = 'test';
        $inv->inventory_type = 'magic';
        $this->assertFalse($inv->validate(['inventory_type']));
    }

    public function testValidationAcceptsStaticType(): void
    {
        $inv = new Inventory();
        $inv->inventory_type = Inventory::TYPE_STATIC;
        $this->assertTrue($inv->validate(['inventory_type']));
    }

    public function testValidationAcceptsDynamicType(): void
    {
        $inv = new Inventory();
        $inv->inventory_type = Inventory::TYPE_DYNAMIC;
        $this->assertTrue($inv->validate(['inventory_type']));
    }

    public function testValidationAcceptsFileType(): void
    {
        $inv = new Inventory();
        $inv->inventory_type = Inventory::TYPE_FILE;
        $this->assertTrue($inv->validate(['inventory_type']));
    }

    // -- validation: conditional content required for static --------------------

    public function testStaticInventoryRequiresContent(): void
    {
        $inv = new Inventory();
        $inv->name = 'test';
        $inv->inventory_type = Inventory::TYPE_STATIC;
        $inv->content = null;
        $this->assertFalse($inv->validate());
        $this->assertArrayHasKey('content', $inv->getErrors());
    }

    public function testDynamicInventoryDoesNotRequireContent(): void
    {
        $user = $this->createUser();
        $inv = new Inventory();
        $inv->name = 'test';
        $inv->inventory_type = Inventory::TYPE_DYNAMIC;
        $inv->content = null;
        $inv->created_by = $user->id;
        $this->assertTrue($inv->validate(['content']));
    }

    // -- validation: conditional source_path + project_id required for file -----

    public function testFileInventoryRequiresSourcePathAndProjectId(): void
    {
        $inv = new Inventory();
        $inv->name = 'test';
        $inv->inventory_type = Inventory::TYPE_FILE;
        $inv->source_path = null;
        $inv->project_id = null;
        $this->assertFalse($inv->validate());
        $this->assertArrayHasKey('source_path', $inv->getErrors());
        $this->assertArrayHasKey('project_id', $inv->getErrors());
    }

    public function testFileInventoryPassesWithSourcePathAndProjectId(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inv = new Inventory();
        $inv->name = 'test';
        $inv->inventory_type = Inventory::TYPE_FILE;
        $inv->source_path = 'inventories/prod.yml';
        $inv->project_id = $project->id;
        $inv->created_by = $user->id;
        $this->assertTrue($inv->validate());
    }

    public function testStaticInventoryDoesNotRequireSourcePath(): void
    {
        $inv = new Inventory();
        $inv->inventory_type = Inventory::TYPE_STATIC;
        $inv->source_path = null;
        $inv->project_id = null;
        $this->assertTrue($inv->validate(['source_path', 'project_id']));
    }

    // -- validateYaml -----------------------------------------------------------

    public function testValidateYamlPassesWithValidYaml(): void
    {
        $inv = new Inventory();
        $inv->inventory_type = Inventory::TYPE_STATIC;
        $inv->content = "all:\n  hosts:\n    server1:\n";
        $this->assertTrue($inv->validate(['content']));
    }

    public function testValidateYamlFailsWithInvalidYaml(): void
    {
        $inv = new Inventory();
        $inv->inventory_type = Inventory::TYPE_STATIC;
        $inv->content = "all:\n  hosts:\n    - bad:\nindent";
        $this->assertFalse($inv->validate(['content']));
        $this->assertArrayHasKey('content', $inv->getErrors());
        $this->assertStringContainsString('Invalid YAML', $inv->getErrors()['content'][0]);
    }

    public function testValidateYamlFailsWithScalarYaml(): void
    {
        $inv = new Inventory();
        $inv->inventory_type = Inventory::TYPE_STATIC;
        $inv->content = 'just a string';
        $this->assertFalse($inv->validate(['content']));
        $this->assertStringContainsString('YAML mapping', $inv->getErrors()['content'][0]);
    }

    public function testValidateYamlSkippedForNonStaticType(): void
    {
        $inv = new Inventory();
        $inv->inventory_type = Inventory::TYPE_DYNAMIC;
        $inv->content = '{bad yaml';
        $this->assertTrue($inv->validate(['content']));
    }

    public function testValidateYamlSkippedWhenContentIsEmpty(): void
    {
        $inv = new Inventory();
        $inv->inventory_type = Inventory::TYPE_STATIC;
        $inv->content = '';
        // The 'required' rule will fail, but validateYaml must NOT add its own error.
        $inv->validate(['content']);
        /** @var array<int, string> $errors */
        $errors = $inv->getErrors('content');
        foreach ($errors as $msg) {
            $this->assertStringNotContainsString('Invalid YAML', $msg);
            $this->assertStringNotContainsString('YAML mapping', $msg);
        }
    }

    // -- targetsLocalhost -------------------------------------------------------

    public function testTargetsLocalhostDetectsLocalhost(): void
    {
        $user = $this->createUser();
        $inv = $this->createInventory($user->id);
        $inv->content = "all:\n  hosts:\n    localhost:\n";
        $this->assertTrue($inv->targetsLocalhost());
    }

    public function testTargetsLocalhostDetects127001(): void
    {
        $user = $this->createUser();
        $inv = $this->createInventory($user->id);
        $inv->content = "all:\n  hosts:\n    127.0.0.1:\n";
        $this->assertTrue($inv->targetsLocalhost());
    }

    public function testTargetsLocalhostDetectsIpv6Loopback(): void
    {
        $user = $this->createUser();
        $inv = $this->createInventory($user->id);
        $inv->content = "all:\n  hosts:\n    ::1:\n";
        $this->assertTrue($inv->targetsLocalhost());
    }

    public function testTargetsLocalhostDetectsAnsibleConnectionLocal(): void
    {
        $user = $this->createUser();
        $inv = $this->createInventory($user->id);
        $inv->content = "all:\n  hosts:\n    myhost:\n      ansible_connection=local\n";
        $this->assertTrue($inv->targetsLocalhost());
    }

    public function testTargetsLocalhostReturnsFalseForRemoteHost(): void
    {
        $user = $this->createUser();
        $inv = $this->createInventory($user->id);
        $inv->content = "all:\n  hosts:\n    server1.example.com:\n";
        $this->assertFalse($inv->targetsLocalhost());
    }

    public function testTargetsLocalhostReturnsFalseForNonStaticType(): void
    {
        $inv = new Inventory();
        $inv->inventory_type = Inventory::TYPE_FILE;
        $inv->content = 'localhost';
        $this->assertFalse($inv->targetsLocalhost());
    }

    public function testTargetsLocalhostReturnsFalseForNullContent(): void
    {
        $inv = new Inventory();
        $inv->inventory_type = Inventory::TYPE_STATIC;
        $inv->content = null;
        $this->assertFalse($inv->targetsLocalhost());
    }

    public function testTargetsLocalhostReturnsFalseForEmptyContent(): void
    {
        $inv = new Inventory();
        $inv->inventory_type = Inventory::TYPE_STATIC;
        $inv->content = '';
        $this->assertFalse($inv->targetsLocalhost());
    }

    public function testTargetsLocalhostDoesNotMatchSubstring(): void
    {
        $user = $this->createUser();
        $inv = $this->createInventory($user->id);
        $inv->content = "all:\n  hosts:\n    my-localhost-dev.example.com:\n";
        $this->assertFalse($inv->targetsLocalhost());
    }

    public function testTargetsLocalhostDoesNotMatchLargerIp(): void
    {
        $user = $this->createUser();
        $inv = $this->createInventory($user->id);
        $inv->content = "all:\n  hosts:\n    127.0.0.15:\n";
        $this->assertFalse($inv->targetsLocalhost());
    }

    public function testTargetsLocalhostReturnsFalseForDynamicType(): void
    {
        $inv = new Inventory();
        $inv->inventory_type = Inventory::TYPE_DYNAMIC;
        $inv->content = 'localhost';
        $this->assertFalse($inv->targetsLocalhost());
    }

    // -- relations --------------------------------------------------------------

    public function testCreatorRelationReturnsUser(): void
    {
        $user = $this->createUser();
        $inv = $this->createInventory($user->id);
        $creator = $inv->creator;
        $this->assertNotNull($creator);
        $this->assertSame($user->id, $creator->id);
    }

    public function testProjectRelationReturnsProjectWhenSet(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inv = new Inventory();
        $inv->name = 'file-inv-' . uniqid('', true);
        $inv->inventory_type = Inventory::TYPE_FILE;
        $inv->source_path = 'inv.yml';
        $inv->project_id = $project->id;
        $inv->created_by = $user->id;
        $inv->created_at = time();
        $inv->updated_at = time();
        $inv->save(false);

        $this->assertNotNull($inv->project);
        $this->assertSame($project->id, $inv->project->id);
    }

    public function testProjectRelationReturnsNullWhenNotSet(): void
    {
        $user = $this->createUser();
        $inv = $this->createInventory($user->id);
        $this->assertNull($inv->project);
    }

    // -- persistence round-trip ------------------------------------------------

    public function testSaveAndReloadPreservesAllFields(): void
    {
        $user = $this->createUser();
        $inv = $this->createInventory($user->id);
        $inv->refresh();
        $this->assertSame(Inventory::TYPE_STATIC, $inv->inventory_type);
        $this->assertNotNull($inv->created_at);
        $this->assertNotNull($inv->updated_at);
    }
}
