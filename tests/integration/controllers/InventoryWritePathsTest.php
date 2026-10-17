<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\api\v1\InventoriesController;
use app\controllers\InventoryController;
use app\models\Inventory;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\GatedInventoryLock;
use app\services\InventoryRuleAssignment;
use app\tests\integration\services\InventoryWriteFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\web\ForbiddenHttpException;

/**
 * Durable guard: every write path of inventories, web and API, refuses a
 * user who may change inventories but not assign approval rules, with
 * nothing written, when the write would give an inventory a rule or touch a
 * gated inventory's hosts or delete it. The completeness check reads the
 * public actions of both controllers: a new action fails here until it is
 * a known read, or a write path with its case below.
 */
class InventoryWritePathsTest extends WebControllerTestCase
{
    use InventoryWriteFixtures;
    use TeamScopeFixtures;

    /** The actions that read, or write only the parse cache (parsing stays open, GatedInventoryLock::NOTICE). */
    private const READS = [
        InventoryController::class => ['index', 'view', 'parse-hosts'],
        InventoriesController::class => ['index', 'view'],
    ];

    /** The write actions, each with its cases in pathProvider(). */
    private const WRITES = [
        InventoryController::class => ['create', 'update', 'delete'],
        InventoriesController::class => ['create', 'update', 'delete'],
    ];

    protected function tearDown(): void
    {
        $this->detachInventoryHandlers();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: class-string, 1: string, 2: string}> the controller, the action, the case
     */
    public static function pathProvider(): array
    {
        return [
            'web: create with a rule' => [InventoryController::class, 'create', 'rule'],
            'web: update with a rule' => [InventoryController::class, 'update', 'rule'],
            'web: update of a gated inventory\'s hosts' => [InventoryController::class, 'update', 'hosts'],
            'web: delete of a gated inventory' => [InventoryController::class, 'delete', 'delete'],
            'api: create with a rule' => [InventoriesController::class, 'create', 'rule'],
            'api: update with a rule' => [InventoriesController::class, 'update', 'rule'],
            'api: update of a gated inventory\'s hosts' => [InventoriesController::class, 'update', 'hosts'],
            'api: delete of a gated inventory' => [InventoriesController::class, 'delete', 'delete'],
        ];
    }

    /**
     * @dataProvider pathProvider
     * @param class-string $controller
     */
    public function testTheWritePathRefusesAUserWhoMayNotAssignRules(string $controller, string $action, string $case): void
    {
        // An operator, or for a delete (which operators may not do at all) a custom role that may delete inventories.
        $user = $case === 'delete' ? $this->inventoryDeleter() : $this->inventoryOperator();
        $this->loginAs($user);
        $rule = $this->createApprovalRule((int)$user->id);
        $inventory = $this->staticInventory((int)$user->id);
        if ($case !== 'rule') {
            $inventory = $this->gate($inventory, $rule);
        }
        $stored = $this->storedRow((int)$inventory->id);
        $count = (int)Inventory::find()->count();
        $fields = match ($case) {
            'rule' => ['name' => 'inv-paths-refused', 'inventory_type' => Inventory::TYPE_STATIC, 'content' => "all:\n", InventoryRuleAssignment::FIELD => (string)$rule->id],
            'hosts' => ['content' => "all:\n  hosts:\n    db1.example.com:\n"],
            default => [],
        };

        $refusal = $controller === InventoryController::class
            ? $this->webRefusal($action, (int)$inventory->id, $fields)
            : $this->apiRefusal($action, (int)$inventory->id, $fields);

        $this->assertSame(match ($case) {
            'rule' => ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED,
            'hosts' => GatedInventoryLock::CHANGE_REFUSED,
            default => GatedInventoryLock::DELETE_REFUSED,
        }, $refusal);
        $this->assertSame($stored, $this->storedRow((int)$inventory->id), 'nothing written');
        $this->assertSame($count, (int)Inventory::find()->count(), 'nothing created or deleted');
        $this->assertFalse($this->hasInventoryAudit((int)$inventory->id));
    }

    /**
     * Every public action of both controllers is a known read or a write
     * path with its cases.
     */
    public function testEveryActionIsAKnownReadOrAGuardedWritePath(): void
    {
        $covered = [];
        foreach (self::pathProvider() as [$controller, $action]) {
            $covered[$controller][] = $action;
        }
        foreach ([InventoryController::class, InventoriesController::class] as $controller) {
            $this->assertSame(
                self::sorted(array_merge(self::READS[$controller], self::WRITES[$controller])),
                self::sorted(self::actionsOf($controller)),
                "{$controller}: a new action is a read or a write path with its case"
            );
            $this->assertSame(self::sorted(self::WRITES[$controller]), self::sorted(array_values(array_unique($covered[$controller] ?? []))));
        }
    }

    /**
     * The 403 message of $action of the web controller with $fields posted.
     *
     * @param array<string, mixed> $fields
     */
    private function webRefusal(string $action, int $id, array $fields): string
    {
        $this->setPost(['Inventory' => $fields]);
        $ctrl = new class ('inventory', \Yii::$app) extends InventoryController {
            public function render($view, $params = []): string
            {
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): \yii\web\Response
            {
                return new \yii\web\Response();
            }
        };
        try {
            match ($action) {
                'create' => $ctrl->actionCreate(),
                'update' => $ctrl->actionUpdate($id),
                default => $ctrl->actionDelete($id),
            };
        } catch (ForbiddenHttpException $e) {
            return $e->getMessage();
        }
        $this->fail("web {$action}: expected a ForbiddenHttpException.");
    }

    /**
     * The 403 message of $action of the API controller with $fields as the
     * body.
     *
     * @param array<string, mixed> $fields
     */
    private function apiRefusal(string $action, int $id, array $fields): string
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($fields);
        $ctrl = new InventoriesController('api/v1/inventories', \Yii::$app);
        $result = match ($action) {
            'create' => $ctrl->actionCreate(),
            'update' => $ctrl->actionUpdate($id),
            default => $ctrl->actionDelete($id),
        };
        $this->assertSame(403, \Yii::$app->response->statusCode, "api {$action}");
        $message = $result['error']['message'] ?? null;
        $this->assertIsString($message);

        return $message;
    }

    /**
     * The action IDs of $controller's public action methods.
     *
     * @param class-string $controller
     * @return list<string>
     */
    private static function actionsOf(string $controller): array
    {
        $actions = [];
        foreach ((new \ReflectionClass($controller))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (preg_match('/^action([A-Z]\w*)$/', $method->getName(), $match) === 1) {
                $actions[] = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '-$0', $match[1]));
            }
        }

        return $actions;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values, SORT_STRING);

        return $values;
    }
}
