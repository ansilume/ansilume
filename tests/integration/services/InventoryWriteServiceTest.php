<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\InventoryUsage;
use app\models\AuditLog;
use app\models\Inventory;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\GatedInventoryLock;
use app\services\InventoryRuleAssignment;
use app\services\InventoryUsageService;
use app\services\InventoryWriteRefusedException;
use app\services\InventoryWriteService;
use app\services\VaultCheckService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\db\BaseActiveRecord;
use yii\base\ModelEvent;

/**
 * InventoryWriteService: who creates, changes and deletes an inventory, what
 * the audit records, and what is refused before anything is written: a rule
 * change by a user who may not assign rules, a change of a gated inventory's
 * hosts and its delete by such a user, every column but name and
 * description counting (deny by default), and the delete of an inventory in
 * use.
 */
class InventoryWriteServiceTest extends DbTestCase
{
    use InventoryWriteFixtures;
    use TeamScopeFixtures;

    private InventoryWriteService $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = new InventoryWriteService();
    }

    protected function tearDown(): void
    {
        $this->detachInventoryHandlers();
        parent::tearDown();
    }

    /**
     * config/services.php registers it for the web and console apps; the
     * tests' application, built from config/test.php, has it too.
     */
    public function testIsRegisteredAsAnApplicationComponentEverywhere(): void
    {
        /** @var array<string, array<string, mixed>> $services */
        $services = require dirname(__DIR__, 3) . '/config/services.php';

        $this->assertSame(InventoryWriteService::class, $services['inventoryWriteService']['class'] ?? null);
        $this->assertInstanceOf(InventoryWriteService::class, \Yii::$app->get('inventoryWriteService'));
    }

    // -- create() ----------------------------------------------------------------

    /**
     * created_by is the user who creates the inventory, whatever the model
     * held; the audit names the fields that have a value, never a value.
     */
    public function testCreateRecordsTheCreatorAndAuditsTheFieldsWithAValue(): void
    {
        $operator = $this->inventoryOperator();
        $other = $this->createUser('inv-write-other');
        $inventory = $this->newInventory('inv-write-created');
        $inventory->description = 'Web servers';
        $inventory->project_id = null;
        $inventory->created_by = (int)$other->id;

        $this->assertTrue($this->writer->create($inventory, [], (int)$operator->id, InventoryWriteService::SOURCE_API));

        $stored = $this->reloaded((int)$inventory->id);
        $this->assertSame((int)$operator->id, $stored->created_by);
        $this->assertNull($stored->getAttribute('approval_rule_id'));
        $this->assertSame([[
            'name' => 'inv-write-created',
            'type' => Inventory::TYPE_STATIC,
            'project_id' => null,
            'changed_fields' => ['name', 'description', 'inventory_type', 'content'],
            'source' => 'api',
        ]], $this->inventoryAudits(AuditLog::ACTION_INVENTORY_CREATED, (int)$inventory->id));
        $this->assertSame((int)$operator->id, $this->auditUserOf(AuditLog::ACTION_INVENTORY_CREATED, (int)$inventory->id));
    }

    public function testCreateAuditsTheProjectOfAFileInventory(): void
    {
        $operator = $this->inventoryOperator();
        $project = $this->createProject((int)$operator->id);
        $inventory = new Inventory();
        $inventory->name = 'inv-write-file';
        $inventory->inventory_type = Inventory::TYPE_FILE;
        $inventory->source_path = 'inventories/hosts.yml';
        $inventory->project_id = (string)$project->id;

        $this->assertTrue($this->writer->create($inventory, [], (int)$operator->id, InventoryWriteService::SOURCE_WEB));

        $this->assertSame([[
            'name' => 'inv-write-file',
            'type' => Inventory::TYPE_FILE,
            'project_id' => (int)$project->id,
            'changed_fields' => ['name', 'inventory_type', 'source_path', 'project_id'],
            'source' => 'web',
        ]], $this->inventoryAudits(AuditLog::ACTION_INVENTORY_CREATED, (int)$inventory->id));
    }

    public function testAnAdminCreatesAnInventoryWithARule(): void
    {
        $admin = $this->inventoryAdmin();
        $rule = $this->createApprovalRule((int)$admin->id);
        $inventory = $this->newInventory('inv-write-gated');

        $this->assertTrue($this->writer->create(
            $inventory,
            [InventoryRuleAssignment::FIELD => (string)$rule->id, 'created_by' => 1],
            (int)$admin->id,
            InventoryWriteService::SOURCE_WEB
        ));

        $this->assertSame((int)$rule->id, $this->reloaded((int)$inventory->id)->getAttribute('approval_rule_id'));
        $this->assertSame([[
            'name' => 'inv-write-gated',
            'type' => Inventory::TYPE_STATIC,
            'project_id' => null,
            'changed_fields' => ['name', 'inventory_type', 'content'],
            'approval_rule' => ['from' => null, 'to' => ['id' => (int)$rule->id, 'name' => $rule->name]],
            'source' => 'web',
        ]], $this->inventoryAudits(AuditLog::ACTION_INVENTORY_CREATED, (int)$inventory->id));
    }

    public function testAnOperatorIsRefusedARuleOnANewInventoryAndNothingIsWritten(): void
    {
        $operator = $this->inventoryOperator();
        $rule = $this->createApprovalRule((int)$operator->id);
        $inventory = $this->newInventory('inv-write-refused');

        $refusal = $this->refusalOf(fn () => $this->writer->create(
            $inventory,
            [InventoryRuleAssignment::FIELD => (int)$rule->id],
            (int)$operator->id,
            InventoryWriteService::SOURCE_API
        ));

        $this->assertSame(InventoryWriteRefusedException::FORBIDDEN, $refusal->status);
        $this->assertSame(ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED, $refusal->getMessage());
        $this->assertSame([], $refusal->details);
        $this->assertFalse(Inventory::find()->where(['name' => 'inv-write-refused'])->exists());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}> the case, the attribute, its error
     */
    public static function invalidCreateProvider(): array
    {
        return [
            'no name' => ['no-name', 'name', 'Name cannot be blank.'],
            'a rule that does not exist' => ['unknown-rule', 'approval_rule_id', Inventory::APPROVAL_RULE_NOT_FOUND],
            'a dynamic inventory with a rule but no source path' => [
                'dynamic-without-source',
                'source_path',
                Inventory::SOURCE_PATH_REQUIRED_WHEN_GATED,
            ],
        ];
    }

    /**
     * @dataProvider invalidCreateProvider
     */
    public function testAnInvalidCreateWritesNothing(string $case, string $attribute, string $error): void
    {
        $admin = $this->inventoryAdmin();
        $inventory = $this->newInventory('inv-write-invalid');
        $submitted = [];
        if ($case === 'no-name') {
            $inventory->name = '';
        } elseif ($case === 'unknown-rule') {
            $submitted = [InventoryRuleAssignment::FIELD => 999999999];
        } else {
            $inventory->inventory_type = Inventory::TYPE_DYNAMIC;
            $inventory->content = null;
            $submitted = [InventoryRuleAssignment::FIELD => (int)$this->createApprovalRule((int)$admin->id)->id];
        }
        $before = (int)Inventory::find()->count();

        $this->assertFalse($this->writer->create($inventory, $submitted, (int)$admin->id, InventoryWriteService::SOURCE_WEB));

        $this->assertSame([$error], $inventory->getErrors($attribute));
        $this->assertSame($before, (int)Inventory::find()->count());
        $this->assertFalse(AuditLog::find()->where(['action' => AuditLog::ACTION_INVENTORY_CREATED, 'user_id' => $admin->id])->exists());
    }

    // -- update() ----------------------------------------------------------------

    public function testAnUpdateAuditsTheFieldsItChangedButNoValues(): void
    {
        $operator = $this->inventoryOperator();
        $inventory = $this->staticInventory((int)$operator->id);
        $inventory->name = 'inv-write-renamed';
        $inventory->content = "all:\n  hosts:\n    db1.example.com:\n";
        $inventory->source_path = '';

        $this->assertTrue($this->writer->update($inventory, $this->bodyOf($inventory), (int)$operator->id, InventoryWriteService::SOURCE_API));

        $stored = $this->reloaded((int)$inventory->id);
        $this->assertSame('inv-write-renamed', $stored->name);
        $this->assertSame("all:\n  hosts:\n    db1.example.com:\n", $stored->content);
        $this->assertSame([[
            'name' => 'inv-write-renamed',
            'changed_fields' => ['name', 'content'],
            'source' => 'api',
        ]], $this->inventoryAudits(AuditLog::ACTION_INVENTORY_UPDATED, (int)$inventory->id));
        $this->assertSame((int)$operator->id, $this->auditUserOf(AuditLog::ACTION_INVENTORY_UPDATED, (int)$inventory->id));
    }

    /**
     * A successful update always writes one entry, also when it changed
     * nothing.
     */
    public function testAnUpdateThatChangesNothingStillWritesItsEntry(): void
    {
        $operator = $this->inventoryOperator();
        $inventory = $this->staticInventory((int)$operator->id, null, 'inv-write-unchanged');

        $this->assertTrue($this->writer->update($inventory, $this->bodyOf($inventory), (int)$operator->id, InventoryWriteService::SOURCE_WEB));

        $this->assertSame(
            [['name' => 'inv-write-unchanged', 'changed_fields' => [], 'source' => 'web']],
            $this->inventoryAudits(AuditLog::ACTION_INVENTORY_UPDATED, (int)$inventory->id)
        );
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null}> the rule before and after, by key
     */
    public static function ruleChangeProvider(): array
    {
        return [
            'a rule given' => [null, 'first'],
            'the rule removed' => ['first', null],
            'another rule' => ['first', 'second'],
        ];
    }

    /**
     * An admin gives, removes or replaces the rule; the audit names it
     * before and after, and the inventory's approvalRule is read anew.
     *
     * @dataProvider ruleChangeProvider
     */
    public function testAnAdminChangesTheRule(?string $from, ?string $to): void
    {
        $admin = $this->inventoryAdmin();
        $rules = ['first' => $this->createApprovalRule((int)$admin->id), 'second' => $this->createApprovalRule((int)$admin->id)];
        $inventory = $this->staticInventory((int)$admin->id, null, 'inv-write-rule');
        if ($from !== null) {
            $inventory = $this->gate($inventory, $rules[$from]);
        }
        $this->assertSame($from === null ? null : (int)$rules[$from]->id, $inventory->approvalRule?->id, 'loaded before the save');
        $submitted = [InventoryRuleAssignment::FIELD => $to === null ? '' : (string)$rules[$to]->id];

        $this->assertTrue($this->writer->update($inventory, $submitted, (int)$admin->id, InventoryWriteService::SOURCE_WEB));

        $expected = $to === null ? null : (int)$rules[$to]->id;
        $this->assertSame($expected, $this->reloaded((int)$inventory->id)->getAttribute('approval_rule_id'));
        $this->assertSame($expected, $inventory->approvalRule?->id, 'the relation is read anew');
        $this->assertSame([[
            'name' => 'inv-write-rule',
            'changed_fields' => [],
            'approval_rule' => [
                'from' => $from === null ? null : ['id' => (int)$rules[$from]->id, 'name' => $rules[$from]->name],
                'to' => $to === null ? null : ['id' => (int)$rules[$to]->id, 'name' => $rules[$to]->name],
            ],
            'source' => 'web',
        ]], $this->inventoryAudits(AuditLog::ACTION_INVENTORY_UPDATED, (int)$inventory->id));
    }

    /**
     * A user who may not assign rules keeps changing the name and the
     * description of a gated inventory.
     */
    public function testAnOperatorRenamesAGatedInventory(): void
    {
        $operator = $this->inventoryOperator();
        $rule = $this->createApprovalRule((int)$operator->id);
        $inventory = $this->gate($this->staticInventory((int)$operator->id), $rule);
        $inventory->name = 'inv-write-gated-renamed';
        $inventory->description = 'Renamed while gated';

        $this->assertTrue($this->writer->update($inventory, $this->bodyOf($inventory), (int)$operator->id, InventoryWriteService::SOURCE_WEB));

        $stored = $this->reloaded((int)$inventory->id);
        $this->assertSame('inv-write-gated-renamed', $stored->name);
        $this->assertSame('Renamed while gated', $stored->description);
        $this->assertSame((int)$rule->id, $stored->getAttribute('approval_rule_id'));
    }

    /**
     * @return array<string, array{0: string}> the change of the hosts
     */
    public static function hostChangeProvider(): array
    {
        return [
            'the content' => ['content'],
            'the type' => ['type'],
            'the source path' => ['source_path'],
            'the project' => ['project'],
        ];
    }

    /**
     * @dataProvider hostChangeProvider
     */
    public function testAnOperatorIsRefusedAChangeOfAGatedInventorysHostsAndNothingIsWritten(string $change): void
    {
        $operator = $this->inventoryOperator();
        $inventory = $this->gate($this->staticInventory((int)$operator->id), $this->createApprovalRule((int)$operator->id));
        $stored = $this->storedRow((int)$inventory->id);
        $inventory->name = 'inv-write-not-renamed';
        match ($change) {
            'content' => $inventory->content = "all:\n  hosts:\n    db1.example.com:\n",
            'type' => $inventory->inventory_type = Inventory::TYPE_DYNAMIC,
            'source_path' => $inventory->source_path = 'inventories/other.yml',
            default => $inventory->project_id = (int)$this->createProject((int)$operator->id)->id,
        };

        $refusal = $this->refusalOf(fn () => $this->writer->update($inventory, $this->bodyOf($inventory), (int)$operator->id, InventoryWriteService::SOURCE_API));

        $this->assertSame(InventoryWriteRefusedException::FORBIDDEN, $refusal->status);
        $this->assertSame(GatedInventoryLock::CHANGE_REFUSED, $refusal->getMessage());
        $this->assertSame($stored, $this->storedRow((int)$inventory->id));
        $this->assertFalse($this->hasInventoryAudit((int)$inventory->id));
    }

    /**
     * Deny by default: every column but the ID and the open ones (name,
     * description) counts, also a column added later.
     *
     * @return array<string, array{0: string}>
     */
    public static function lockedColumnProvider(): array
    {
        $columns = [];
        foreach (Inventory::getTableSchema()->getColumnNames() as $name) {
            if (!in_array($name, array_merge(['id'], GatedInventoryLock::OPEN_ATTRIBUTES), true)) {
                $columns[$name] = [$name];
            }
        }

        return $columns;
    }

    /**
     * @dataProvider lockedColumnProvider
     */
    public function testEveryColumnButTheOpenOnesIsLocked(string $column): void
    {
        $operator = $this->inventoryOperator();
        $inventory = $this->gate($this->staticInventory((int)$operator->id), $this->createApprovalRule((int)$operator->id));
        $stored = $this->storedRow((int)$inventory->id);
        $inventory->setAttribute($column, $this->otherValueOf($column, $inventory->getAttribute($column)));

        $refusal = $this->refusalOf(fn () => $this->writer->update($inventory, [], (int)$operator->id, InventoryWriteService::SOURCE_WEB));

        $this->assertSame(GatedInventoryLock::CHANGE_REFUSED, $refusal->getMessage());
        $this->assertSame($stored, $this->storedRow((int)$inventory->id));
    }

    public function testTheProviderCoversTheColumnsThatSayWhereJobsRun(): void
    {
        $this->assertSame(
            ['inventory_type', 'content', 'source_path', 'project_id', 'approval_rule_id'],
            array_values(array_intersect(
                ['inventory_type', 'content', 'source_path', 'project_id', 'approval_rule_id'],
                array_keys(self::lockedColumnProvider())
            ))
        );
    }

    public function testAnAdminChangesTheHostsOfAGatedInventory(): void
    {
        $admin = $this->inventoryAdmin();
        $rule = $this->createApprovalRule((int)$admin->id);
        $inventory = $this->gate($this->staticInventory((int)$admin->id), $rule);
        $inventory->content = "all:\n  hosts:\n    db1.example.com:\n";

        $this->assertTrue($this->writer->update($inventory, $this->bodyOf($inventory), (int)$admin->id, InventoryWriteService::SOURCE_API));

        $stored = $this->reloaded((int)$inventory->id);
        $this->assertSame("all:\n  hosts:\n    db1.example.com:\n", $stored->content);
        $this->assertSame((int)$rule->id, $stored->getAttribute('approval_rule_id'));
    }

    public function testAnOperatorIsRefusedARuleChangeBeforeAnythingIsRead(): void
    {
        $operator = $this->inventoryOperator();
        $inventory = $this->staticInventory((int)$operator->id);
        $stored = $this->storedRow((int)$inventory->id);

        $refusal = $this->refusalOf(fn () => $this->writer->update(
            $inventory,
            [InventoryRuleAssignment::FIELD => (int)$this->createApprovalRule((int)$operator->id)->id],
            (int)$operator->id,
            InventoryWriteService::SOURCE_WEB
        ));

        $this->assertSame(ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED, $refusal->getMessage());
        $this->assertSame($stored, $this->storedRow((int)$inventory->id));
        $this->assertNull($inventory->getAttribute('approval_rule_id'));
    }

    public function testAnInvalidUpdateWritesNothing(): void
    {
        $operator = $this->inventoryOperator();
        $inventory = $this->staticInventory((int)$operator->id);
        $stored = $this->storedRow((int)$inventory->id);
        $inventory->name = '';

        $this->assertFalse($this->writer->update($inventory, [], (int)$operator->id, InventoryWriteService::SOURCE_WEB));

        $this->assertSame(['Name cannot be blank.'], $inventory->getErrors('name'));
        $this->assertSame($stored, $this->storedRow((int)$inventory->id));
        $this->assertFalse($this->hasInventoryAudit((int)$inventory->id));
    }

    /**
     * The vault check of the job templates that use the inventory is redone
     * after a save, and only after one.
     */
    public function testTheVaultCheckOfItsTemplatesIsRedoneAfterASave(): void
    {
        $operator = $this->inventoryOperator();
        $inventory = $this->staticInventory((int)$operator->id);
        $first = $this->templateOn((int)$inventory->id, (int)$operator->id);
        $second = $this->templateOn((int)$inventory->id, (int)$operator->id);
        $this->templateOn((int)$this->staticInventory((int)$operator->id)->id, (int)$operator->id);
        $checks = new class () extends VaultCheckService {
            /** @var list<list<int>> */
            public array $checked = [];

            public function checkTemplateIds(array $ids): void
            {
                $this->checked[] = $ids;
            }
        };
        $this->replaceComponent('vaultCheckService', $checks);
        $inventory->name = '';
        $this->assertFalse($this->writer->update($inventory, [], (int)$operator->id, InventoryWriteService::SOURCE_WEB));
        $this->assertSame([], $checks->checked, 'not after a refused save');

        $inventory->name = 'inv-write-vault';
        $this->assertTrue($this->writer->update($inventory, [], (int)$operator->id, InventoryWriteService::SOURCE_WEB));

        $ids = $checks->checked[0] ?? [];
        sort($ids);
        $this->assertCount(1, $checks->checked);
        $this->assertSame([(int)$first->id, (int)$second->id], $ids);
    }

    // -- delete() ----------------------------------------------------------------

    public function testDeleteAuditsTheDeletedInventory(): void
    {
        $operator = $this->inventoryDeleter();
        $inventory = $this->staticInventory((int)$operator->id, null, 'inv-write-deleted');

        $this->writer->delete($inventory, (int)$operator->id, InventoryWriteService::SOURCE_WEB);

        $this->assertNull(Inventory::findOne($inventory->id));
        $this->assertSame(
            [['name' => 'inv-write-deleted', 'type' => Inventory::TYPE_STATIC, 'source' => 'web']],
            $this->inventoryAudits(AuditLog::ACTION_INVENTORY_DELETED, (int)$inventory->id)
        );
        $this->assertSame((int)$operator->id, $this->auditUserOf(AuditLog::ACTION_INVENTORY_DELETED, (int)$inventory->id));
    }

    public function testAnAdminDeletesAnUnusedGatedInventory(): void
    {
        $admin = $this->inventoryAdmin();
        $rule = $this->createApprovalRule((int)$admin->id);
        $inventory = $this->gate($this->staticInventory((int)$admin->id, null, 'inv-write-gated-deleted'), $rule);

        $this->writer->delete($inventory, (int)$admin->id, InventoryWriteService::SOURCE_API);

        $this->assertNull(Inventory::findOne($inventory->id));
        $this->assertSame([[
            'name' => 'inv-write-gated-deleted',
            'type' => Inventory::TYPE_STATIC,
            'approval_rule' => ['from' => ['id' => (int)$rule->id, 'name' => $rule->name], 'to' => null],
            'source' => 'api',
        ]], $this->inventoryAudits(AuditLog::ACTION_INVENTORY_DELETED, (int)$inventory->id));
    }

    /**
     * An inventory that a live or a soft-deleted job template uses is not
     * deleted: 409 with the usage, as far as the user may see it.
     */
    public function testAnInventoryInUseIsNotDeleted(): void
    {
        $scope = $this->teamScope();
        $inventory = $this->reloaded((int)$scope['own']->inventory_id);
        $deleted = $this->templateOn((int)$inventory->id, (int)$scope['admin']->id);
        $deleted->deleted_at = time();
        $deleted->save(false);
        $member = (int)$scope['member']->id;
        $usage = (new InventoryUsageService())->forInventory($inventory, $member);
        $this->assertSame(4, $usage->jobTemplateTotal);
        $this->assertSame(1, $usage->hiddenJobTemplateCount());

        $refusal = $this->refusalOf(fn () => $this->writer->delete($inventory, $member, InventoryWriteService::SOURCE_WEB));

        $this->assertSame(InventoryWriteRefusedException::CONFLICT, $refusal->status);
        $this->assertSame(
            sprintf(
                'Inventory %s is in use by 4 job template(s) and 1 deleted job template(s). Point its job templates at '
                    . 'another inventory first. A deleted job template keeps its inventory for the history of its jobs '
                    . 'until its project is deleted, so deleting the template does not free it.',
                '"' . $inventory->name . '"'
            ),
            $refusal->getMessage()
        );
        $this->assertSame(sprintf(InventoryWriteService::DELETE_IN_USE, $usage->summary()), $refusal->getMessage());
        $this->assertSame(['used_by' => $usage->toArray()], $refusal->details);
        $this->assertNotNull(Inventory::findOne($inventory->id));
        $this->assertFalse($this->hasInventoryAudit((int)$inventory->id));
    }

    /**
     * The lock refuses the delete of a gated inventory to a user who may
     * delete inventories but not assign rules, before the usage is read.
     */
    public function testAGatedInventoryIsNotDeletedByAUserWhoMayNotAssignRules(): void
    {
        $deleter = $this->inventoryDeleter();
        $inventory = $this->gate($this->staticInventory((int)$deleter->id), $this->createApprovalRule((int)$deleter->id));
        $this->templateOn((int)$inventory->id, (int)$deleter->id);
        $usage = new class () extends InventoryUsageService {
            public int $calls = 0;

            public function forInventory(Inventory $i, ?int $viewerId): InventoryUsage
            {
                $this->calls++;

                return parent::forInventory($i, $viewerId);
            }
        };
        $this->replaceComponent('inventoryUsageService', $usage);

        $refusal = $this->refusalOf(fn () => $this->writer->delete($inventory, (int)$deleter->id, InventoryWriteService::SOURCE_WEB));

        $this->assertSame(InventoryWriteRefusedException::FORBIDDEN, $refusal->status);
        $this->assertSame(GatedInventoryLock::DELETE_REFUSED, $refusal->getMessage());
        $this->assertSame(0, $usage->calls, 'refused before any usage read');
        $this->assertNotNull(Inventory::findOne($inventory->id));
    }

    /**
     * A reference the usage did not count: the foreign key refuses the
     * delete, and the usage, counted again after the rollback, answers it.
     */
    public function testAForeignKeyRefusalIsCountedAgainAndAnsweredAsInUse(): void
    {
        $admin = $this->inventoryAdmin();
        $inventory = $this->staticInventory((int)$admin->id);
        $this->templateOn((int)$inventory->id, (int)$admin->id);
        $usage = new class () extends InventoryUsageService {
            public int $calls = 0;

            public function forInventory(Inventory $i, ?int $viewerId): InventoryUsage
            {
                $this->calls++;

                return $this->calls === 1 ? new InventoryUsage((string)$i->name, [], 0, 0) : parent::forInventory($i, $viewerId);
            }
        };
        $this->replaceComponent('inventoryUsageService', $usage);

        $refusal = $this->refusalOf(fn () => $this->writer->delete($inventory, (int)$admin->id, InventoryWriteService::SOURCE_WEB));

        $this->assertSame(InventoryWriteRefusedException::CONFLICT, $refusal->status);
        $this->assertSame(2, $usage->calls);
        $recounted = (new InventoryUsageService())->forInventory($inventory, (int)$admin->id);
        $this->assertSame(sprintf(InventoryWriteService::DELETE_IN_USE, $recounted->summary()), $refusal->getMessage());
        $this->assertSame(['used_by' => $recounted->toArray()], $refusal->details);
        $this->assertTrue($recounted->isInUse());
        $this->assertNotNull(Inventory::findOne($inventory->id));
        $this->assertFalse($this->hasInventoryAudit((int)$inventory->id));
    }

    public function testADeleteThatAModelEventRefusesFailsWithoutAnEntry(): void
    {
        $admin = $this->inventoryAdmin();
        $inventory = $this->staticInventory((int)$admin->id);
        $this->on(Inventory::class, BaseActiveRecord::EVENT_BEFORE_DELETE, static function (ModelEvent $event): void {
            $event->isValid = false;
        });

        try {
            $this->writer->delete($inventory, (int)$admin->id, InventoryWriteService::SOURCE_WEB);
            $this->fail('Expected a RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(InventoryWriteRefusedException::class, $e);
            $this->assertSame(sprintf('Deleting inventory #%d was refused.', (int)$inventory->id), $e->getMessage());
        }

        $this->assertNotNull(Inventory::findOne($inventory->id));
        $this->assertFalse($this->hasInventoryAudit((int)$inventory->id));
    }

    // -- helpers -----------------------------------------------------------------

    private function newInventory(string $name): Inventory
    {
        $inventory = new Inventory();
        $inventory->name = $name;
        $inventory->inventory_type = Inventory::TYPE_STATIC;
        $inventory->content = "all:\n  hosts:\n    web1.example.com:\n";

        return $inventory;
    }

    /**
     * A body as a form or an API client sends it back: the inventory's
     * fields, the stored rule among them.
     *
     * @return array<string, mixed>
     */
    private function bodyOf(Inventory $inventory): array
    {
        return [
            'name' => $inventory->name,
            InventoryRuleAssignment::FIELD => InventoryRuleAssignment::storedRuleId($inventory),
        ];
    }

    /**
     * Another value of $column than $value, of its type.
     */
    private function otherValueOf(string $column, mixed $value): mixed
    {
        $schema = Inventory::getTableSchema()->getColumn($column);
        $this->assertNotNull($schema, $column);
        if ($column === 'approval_rule_id') {
            return (int)$this->createApprovalRule((int)$this->createUser('inv-write-other-rule')->id)->id;
        }
        if ($column === 'project_id') {
            // A project of no team, which everyone may operate: the lock refuses it, not the team scope.
            return (int)$this->createProject((int)$this->createUser('inv-write-other-project')->id)->id;
        }
        if ($schema->phpType === 'integer') {
            return (int)$value + 1;
        }

        return (string)$value . '-changed';
    }

    private function auditUserOf(string $action, int $inventoryId): ?int
    {
        $entry = AuditLog::find()->where(['action' => $action, 'object_type' => 'inventory', 'object_id' => $inventoryId])->one();
        $this->assertInstanceOf(AuditLog::class, $entry);

        return $entry->user_id === null ? null : (int)$entry->user_id;
    }
}
