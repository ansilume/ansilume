<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Inventory;
use app\models\User;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\GatedInventoryLock;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Who may change the hosts of an inventory with an approval rule, and delete
 * it: only users who may assign approval rules. Everyone else keeps its name
 * and description. The change is read against the stored state, deny by
 * default.
 */
class GatedInventoryLockTest extends DbTestCase
{
    use InventoryWriteFixtures;
    use TeamScopeFixtures;

    private GatedInventoryLock $lock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lock = new GatedInventoryLock();
    }

    /**
     * Gated is what is stored: a rule set or removed only in memory does not
     * count, and neither does a new inventory.
     */
    public function testAnInventoryIsGatedByItsStoredRule(): void
    {
        $admin = $this->inventoryAdmin();
        $rule = $this->createApprovalRule((int)$admin->id);
        $gated = $this->gate($this->staticInventory((int)$admin->id), $rule);
        $plain = $this->staticInventory((int)$admin->id);
        $fresh = new Inventory();
        $fresh->setAttribute('approval_rule_id', (int)$rule->id);

        $this->assertTrue(GatedInventoryLock::isGated($gated));
        $this->assertFalse(GatedInventoryLock::isGated($plain));
        $this->assertFalse(GatedInventoryLock::isGated($fresh));
        $gated->setAttribute('approval_rule_id', null);
        $plain->setAttribute('approval_rule_id', (int)$rule->id);
        $this->assertTrue(GatedInventoryLock::isGated($gated), 'removed only in memory');
        $this->assertFalse(GatedInventoryLock::isGated($plain), 'set only in memory');
    }

    /**
     * @return array<string, array{0: string, 1: bool}> the user, and whether the lock applies to them
     */
    public static function userProvider(): array
    {
        return [
            'an admin' => ['admin', false],
            'a superadmin without roles' => ['superadmin', false],
            'a custom role with approval-rule.update' => ['assigner', false],
            'an operator' => ['operator', true],
            'a custom role that may delete inventories' => ['deleter', true],
            'an inactive admin' => ['inactive-admin', true],
        ];
    }

    /**
     * @dataProvider userProvider
     */
    public function testTheLockAppliesToUsersWhoMayNotAssignRules(string $kind, bool $applies): void
    {
        $user = $this->userOf($kind);
        $owner = (int)$this->inventoryAdmin()->id;
        $gated = $this->gate($this->staticInventory($owner), $this->createApprovalRule($owner));

        $this->assertSame($applies, $this->lock->appliesTo((int)$user->id, $gated));
        $this->assertFalse($this->lock->appliesTo((int)$user->id, $this->staticInventory($owner)), 'an inventory without a rule');
        $this->assertSame($applies ? GatedInventoryLock::DELETE_REFUSED : null, $this->lock->deleteRefusal((int)$user->id, $gated));
        $gated->content = "all:\n  hosts:\n    db1.example.com:\n";
        $this->assertSame($applies ? GatedInventoryLock::CHANGE_REFUSED : null, $this->lock->changeRefusal((int)$user->id, $gated));
    }

    /**
     * Name and description stay open to everyone who may change the
     * inventory, also both at once.
     */
    public function testTheNameAndTheDescriptionStayOpen(): void
    {
        $operator = $this->inventoryOperator();
        $gated = $this->gate($this->staticInventory((int)$operator->id), $this->createApprovalRule((int)$operator->id));

        $this->assertNull($this->lock->changeRefusal((int)$operator->id, $gated), 'no change at all');
        $gated->name = 'renamed-gated';
        $gated->description = 'A new description';
        $this->assertNull($this->lock->changeRefusal((int)$operator->id, $gated));
    }

    public function testNothingIsRefusedForAnInventoryWithoutARule(): void
    {
        $operator = $this->inventoryOperator();
        $inventory = $this->staticInventory((int)$operator->id);
        $inventory->content = "all:\n  hosts:\n    db1.example.com:\n";
        $inventory->inventory_type = Inventory::TYPE_DYNAMIC;

        $this->assertNull($this->lock->changeRefusal((int)$operator->id, $inventory));
        $this->assertNull($this->lock->deleteRefusal((int)$operator->id, $inventory));
    }

    /**
     * @return array<string, array{0: string, 1: mixed, 2: mixed, 3: bool}> the column, the value, the stored
     *     value, and whether they are the same
     */
    public static function comparisonProvider(): array
    {
        return [
            'null and an empty text' => ['source_path', '', null, true],
            'an empty text and null' => ['source_path', null, '', true],
            'an ID as text' => ['project_id', '7', 7, true],
            'an ID with a leading zero' => ['project_id', '07', 7, true],
            'another ID' => ['project_id', 8, 7, false],
            'an empty form value for no project' => ['project_id', '', null, true],
            'the same text' => ['content', "all:\n", "all:\n", true],
            'a text with another line ending' => ['content', "all:\r\n", "all:\n", false],
            'a trailing space' => ['content', "all:\n ", "all:\n", false],
            'a list for an ID' => ['project_id', [7], 7, false],
            'a list for a text' => ['content', ['x'], 'x', false],
            'a list stored' => ['content', 'x', ['x'], false],
            'a list of a value and a PDO type' => ['project_id', [7, \PDO::PARAM_INT], 7, false],
            'an object' => ['content', new \ArrayObject(['x']), 'x', false],
            'an integer for a text' => ['inventory_type', 5, '5', true],
        ];
    }

    /**
     * The comparison rule: null and '' are the same, a list or an object
     * always differs and is never cast, anything else is compared after the
     * column's type cast.
     *
     * @dataProvider comparisonProvider
     */
    public function testTheChangeIsReadAgainstTheStoredState(string $column, mixed $value, mixed $stored, bool $same): void
    {
        $inventory = $this->staticInventory((int)$this->inventoryAdmin()->id);
        $old = $inventory->getOldAttributes();
        $old[$column] = $stored;
        $inventory->setAttribute($column, $value);

        $this->assertSame($same ? [] : [$column], GatedInventoryLock::changedAttributes($inventory, $old));
    }

    /**
     * Deny by default: an attribute the stored state lacks counts as
     * changed, and so does every attribute of a new inventory.
     */
    public function testAnAttributeTheStoredStateLacksCountsAsChanged(): void
    {
        $inventory = $this->staticInventory((int)$this->inventoryAdmin()->id);
        $old = $inventory->getOldAttributes();
        unset($old['parsed_at']);

        $this->assertSame(['parsed_at'], GatedInventoryLock::changedAttributes($inventory, $old));
        $this->assertSame($inventory->attributes(), GatedInventoryLock::changedAttributes(new Inventory(), []));
    }

    /**
     * A name that is no column of the table (an attribute a subclass adds)
     * is compared exactly, without a cast.
     */
    public function testAnAttributeThatIsNoColumnIsComparedExactly(): void
    {
        $inventory = new class () extends Inventory {
            public function attributes(): array
            {
                return array_merge(parent::attributes(), ['extra']);
            }
        };
        $inventory->setAttribute('extra', '5');

        $this->assertSame(['extra'], GatedInventoryLock::changedAttributes($inventory, ['extra' => 5] + array_fill_keys(Inventory::getTableSchema()->getColumnNames(), null)));
        $this->assertSame([], GatedInventoryLock::changedAttributes($inventory, ['extra' => '5'] + array_fill_keys(Inventory::getTableSchema()->getColumnNames(), null)));
    }

    private function userOf(string $kind): User
    {
        return match ($kind) {
            'admin' => $this->inventoryAdmin(),
            'superadmin' => $this->superadmin(),
            'assigner' => $this->assigner(),
            'operator' => $this->inventoryOperator(),
            'deleter' => $this->inventoryDeleter(),
            default => $this->inactiveAdmin(),
        };
    }

    private function superadmin(): User
    {
        $user = $this->createUser('inv-lock-superadmin');
        $user->is_superadmin = true;
        $user->save(false);

        return $user;
    }

    private function assigner(): User
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('inv-lock-assigners-' . bin2hex(random_bytes(4)));
        $auth->add($role);
        $permission = $auth->getPermission(ApprovalRuleAssignmentPolicy::PERMISSION);
        $this->assertNotNull($permission);
        $auth->addChild($role, $permission);
        $user = $this->createUser('inv-lock-assigner');
        $auth->assign($role, (string)$user->id);

        return $user;
    }

    private function inactiveAdmin(): User
    {
        $user = $this->inventoryAdmin();
        $user->status = User::STATUS_INACTIVE;
        $user->save(false);

        return $user;
    }
}
