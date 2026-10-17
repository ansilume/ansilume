<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\User;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\InventoryWriteRefusedException;
use app\services\InventoryWriteService;
use yii\base\Event;

/**
 * Fixtures of the tests of the inventory write path (InventoryWriteService,
 * GatedInventoryLock, InventoryRuleAssignment and the inventory
 * controllers); use in DbTestCase subclasses together with
 * TeamScopeFixtures.
 *
 * Inventory rules and "meanwhile" changes are written with updateAll(): the
 * model never mass-assigns approval_rule_id, and the tests change rows
 * behind the back of loaded models on purpose.
 */
trait InventoryWriteFixtures
{
    /** @var list<array{0: class-string, 1: string, 2: callable}> class-level event handlers to detach */
    private array $inventoryHandlers = [];

    /** @var array<string, mixed> the definitions of the components replaceComponent() replaced */
    private array $replacedComponents = [];

    /**
     * Detach the event handlers of on() and restore the components that
     * replaceComponent() replaced; call from tearDown().
     */
    protected function detachInventoryHandlers(): void
    {
        foreach ($this->inventoryHandlers as [$class, $name, $handler]) {
            Event::off($class, $name, $handler);
        }
        $this->inventoryHandlers = [];
        foreach ($this->replacedComponents as $id => $definition) {
            \Yii::$app->set($id, $definition);
        }
        $this->replacedComponents = [];
    }

    /**
     * Use $instance as the application component $id until
     * detachInventoryHandlers().
     */
    protected function replaceComponent(string $id, object $instance): void
    {
        if (!array_key_exists($id, $this->replacedComponents)) {
            $this->replacedComponents[$id] = \Yii::$app->getComponents(true)[$id] ?? null;
        }
        \Yii::$app->set($id, $instance);
    }

    /**
     * A class-level event handler that detachInventoryHandlers() removes.
     *
     * @param class-string $class
     */
    protected function on(string $class, string $name, callable $handler): void
    {
        Event::on($class, $name, $handler);
        $this->inventoryHandlers[] = [$class, $name, $handler];
    }

    protected function inventoryAdmin(): User
    {
        return $this->createUserWithRole('inv-gate-admin', 'admin');
    }

    protected function inventoryOperator(): User
    {
        return $this->createUserWithRole('inv-gate-operator', 'operator');
    }

    /**
     * A user of a custom role that may view, create, change and delete
     * inventories, but holds no approval-rule.update: the lock of gated
     * inventories applies to them, delete included.
     */
    protected function inventoryDeleter(): User
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('inv-gate-deleters-' . bin2hex(random_bytes(4)));
        $auth->add($role);
        foreach (['inventory.view', 'inventory.create', 'inventory.update', 'inventory.delete'] as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }
        $user = $this->createUser('inv-gate-deleter');
        $auth->assign($role, (string)$user->id);
        $this->assertFalse($auth->checkAccess((string)$user->id, ApprovalRuleAssignmentPolicy::PERMISSION));

        return $user;
    }

    /**
     * A static inventory, stored, as a loaded model.
     */
    protected function staticInventory(int $createdBy, ?int $projectId = null, string $name = ''): Inventory
    {
        $inventory = $this->createInventory($createdBy);
        $inventory->name = $name !== '' ? $name : 'inv-gate-' . uniqid('', true);
        $inventory->content = "all:\n  hosts:\n    web1.example.com:\n";
        $inventory->project_id = $projectId;
        $inventory->save(false);

        return $this->reloaded((int)$inventory->id);
    }

    /**
     * $inventory with the stored rule $rule (null: none), loaded again.
     */
    protected function gate(Inventory $inventory, ?ApprovalRule $rule): Inventory
    {
        $this->setStoredRule((int)$inventory->id, $rule === null ? null : (int)$rule->id);

        return $this->reloaded((int)$inventory->id);
    }

    /**
     * A change "meanwhile": the stored rule of inventory #$id, behind the
     * back of every loaded model.
     */
    protected function setStoredRule(int $id, ?int $ruleId): void
    {
        $this->assertSame(1, Inventory::updateAll(['approval_rule_id' => $ruleId], ['id' => $id]));
    }

    protected function reloaded(int $id): Inventory
    {
        $inventory = Inventory::findOne($id);
        $this->assertInstanceOf(Inventory::class, $inventory, "inventory #{$id}");

        return $inventory;
    }

    /**
     * The inventory's row as stored, for "nothing was written".
     *
     * @return array<string, mixed>
     */
    protected function storedRow(int $id): array
    {
        $row = (new \yii\db\Query())->from(Inventory::tableName())->where(['id' => $id])->one();
        $this->assertIsArray($row, "inventory #{$id}");

        return $row;
    }

    /**
     * A live job template of a new project on inventory #$inventoryId, with
     * the rule $rule of its own (null: none).
     */
    protected function templateOn(int $inventoryId, int $createdBy, ?ApprovalRule $rule = null, string $name = ''): JobTemplate
    {
        $template = $this->createJobTemplate(
            (int)$this->createProject($createdBy)->id,
            $inventoryId,
            (int)$this->createRunnerGroup($createdBy)->id,
            $createdBy
        );
        if ($name !== '') {
            $template->name = $name;
        }
        $template->approval_rule_id = $rule === null ? null : (int)$rule->id;
        $template->save(false);

        return $template;
    }

    /**
     * The contexts of the audit entries $action wrote for inventory
     * #$inventoryId, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    protected function inventoryAudits(string $action, int $inventoryId): array
    {
        $contexts = [];
        $entries = AuditLog::find()
            ->where(['action' => $action, 'object_type' => 'inventory', 'object_id' => $inventoryId])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        foreach ($entries as $entry) {
            $this->assertInstanceOf(AuditLog::class, $entry);
            $context = json_decode((string)$entry->metadata, true);
            $this->assertIsArray($context);
            /** @var array<string, mixed> $context */
            $contexts[] = $context;
        }

        return $contexts;
    }

    /**
     * Whether any inventory audit entry names inventory #$inventoryId.
     */
    protected function hasInventoryAudit(int $inventoryId): bool
    {
        return AuditLog::find()->where(['object_type' => 'inventory', 'object_id' => $inventoryId])->exists();
    }

    protected function inventoryWriter(): InventoryWriteService
    {
        $writer = \Yii::$app->get('inventoryWriteService');
        $this->assertInstanceOf(InventoryWriteService::class, $writer);

        return $writer;
    }

    /**
     * The refusal $write throws; fails when it throws none.
     */
    protected function refusalOf(callable $write): InventoryWriteRefusedException
    {
        try {
            $write();
        } catch (InventoryWriteRefusedException $e) {
            return $e;
        }
        $this->fail('Expected an InventoryWriteRefusedException.');
    }
}
