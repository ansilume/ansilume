<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\BaseController;
use app\controllers\InventoryController;
use app\models\AuditLog;
use app\models\Inventory;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\GatedInventoryLock;
use app\services\InventoryRuleAssignment;
use app\services\InventoryUsageService;
use app\services\InventoryWriteService;
use app\tests\integration\services\InventoryWriteFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\helpers\Html;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The inventory pages' create, update and delete, through
 * InventoryWriteService: the flashes, what each refusal answers (403 with
 * its message, the team scope in the page's own words, 404, and a danger
 * flash for an inventory in use), and the form shown again after a failed
 * save.
 */
class InventoryControllerGateTest extends WebControllerTestCase
{
    use InventoryWriteFixtures;
    use RendersRealViews;
    use TeamScopeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        \Yii::$app->session->removeAllFlashes();
    }

    protected function tearDown(): void
    {
        $this->detachInventoryHandlers();
        \Yii::$app->session->removeAllFlashes();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: bool}> whether the job template that uses the inventory is soft-deleted
     */
    public static function usingTemplateProvider(): array
    {
        return ['a live job template' => [false], 'only a soft-deleted job template' => [true]];
    }

    /**
     * regression: deleting an inventory that a job template uses, also one
     * that only a soft-deleted template still refers to, failed on the
     * foreign key with a server error. It is refused, and the inventory's
     * page says why.
     *
     * @dataProvider usingTemplateProvider
     */
    public function testRegressionDeletingAnInventoryInUseAnswered500(bool $softDeleted): void
    {
        $admin = $this->inventoryAdmin();
        $this->loginAs($admin);
        $inventory = $this->staticInventory((int)$admin->id);
        $template = $this->templateOn((int)$inventory->id, (int)$admin->id);
        if ($softDeleted) {
            $template->deleted_at = time();
            $template->save(false);
        }
        $ctrl = $this->makeController();

        $ctrl->actionDelete((int)$inventory->id);

        $this->assertSame(['view', 'id' => (int)$inventory->id], $ctrl->capturedRedirect, 'back to the inventory, no server error');
        $this->assertNotNull(Inventory::findOne($inventory->id));
        $usage = (new InventoryUsageService())->forInventory($inventory, (int)$admin->id);
        $this->assertSame(['danger' => sprintf(InventoryWriteService::DELETE_IN_USE, $usage->summary())], \Yii::$app->session->getAllFlashes());
        $this->assertStringContainsString($softDeleted ? '1 deleted job template(s)' : 'in use by 1 job template(s).', $usage->summary());
        $this->assertFalse($this->hasInventoryAudit((int)$inventory->id));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function postedCreatorProvider(): array
    {
        return ['another user' => ['other'], 'a user that does not exist' => ['missing']];
    }

    /**
     * regression: the edit form loaded created_by from the request, so a
     * crafted form made another user, or one that does not exist (a server
     * error on the foreign key), the inventory's creator.
     *
     * @dataProvider postedCreatorProvider
     */
    public function testRegressionTheEditFormMassAssignedCreatedBy(string $creator): void
    {
        $owner = $this->createUser('inv-gate-owner');
        $operator = $this->inventoryOperator();
        $this->loginAs($operator);
        $inventory = $this->staticInventory((int)$owner->id);
        $posted = $creator === 'other' ? (int)$this->createUser('inv-gate-other')->id : 987654321;
        $this->setPost(['Inventory' => ['name' => 'inv-gate-renamed', 'created_by' => (string)$posted]]);
        $ctrl = $this->makeController();

        $ctrl->actionUpdate((int)$inventory->id);

        $this->assertSame(['view', 'id' => (int)$inventory->id], $ctrl->capturedRedirect, 'saved');
        $stored = Inventory::findOne($inventory->id);
        $this->assertNotNull($stored);
        $this->assertSame('inv-gate-renamed', $stored->name);
        $this->assertSame((int)$owner->id, (int)$stored->created_by, 'the creator stays');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}> the action, the field, its input's ID
     */
    public static function postedListProvider(): array
    {
        $cases = [];
        foreach (['create', 'update'] as $action) {
            foreach (['name', 'description', 'content', 'source_path'] as $field) {
                $cases["{$action}: {$field}"] = [$action, $field, 'inventory-' . $field];
            }
        }

        return $cases;
    }

    /**
     * regression: a list that a crafted form posted for a text field
     * (Inventory[name][]=x) was refused by the rules, but showing the form
     * again failed with "Array to string conversion" (500). The form is
     * shown with the field's error, and nothing is written.
     *
     * @dataProvider postedListProvider
     */
    public function testRegressionAPostedListMadeTheInventoryFormFail(string $action, string $field, string $inputId): void
    {
        $admin = $this->inventoryAdmin();
        $this->loginAs($admin);
        $inventory = $this->staticInventory((int)$admin->id);
        $stored = $this->storedRow((int)$inventory->id);
        $count = (int)Inventory::find()->count();
        $fields = $action === 'create'
            ? ['name' => 'inv-gate-list', 'inventory_type' => Inventory::TYPE_STATIC, 'content' => "all:\n  hosts:\n    web1.example.com:\n"]
            : [];
        $fields[$field] = ['x'];
        $this->setPost(['Inventory' => $fields]);
        $ctrl = $this->makeRealViewController();
        $url = $action === 'create' ? '/inventory/create' : '/inventory/update?id=' . $inventory->id;

        $html = $this->withRealViews($ctrl, $url, fn (): Response|string => $action === 'create'
            ? $ctrl->actionCreate()
            : $ctrl->actionUpdate((int)$inventory->id));

        $this->assertIsString($html, 'the form is shown again');
        $this->assertSame(1, preg_match_all('/<div class="[^"]*field-([\w-]+)[^"]*has-error/', $html, $failed), 'one field is marked as failed');
        $this->assertSame([$inputId], $failed[1]);
        $label = (new Inventory())->getAttributeLabel($field);
        $this->assertStringContainsString(Html::encode($label . ' must be a string.'), $html);
        $this->assertSame($count, (int)Inventory::find()->count());
        $this->assertSame($stored, $this->storedRow((int)$inventory->id));
        $this->assertFalse(AuditLog::find()->where(['object_type' => 'inventory', 'user_id' => $admin->id])->exists());
    }

    /**
     * A list for a select is shown without a selection, with its error.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function postedListSelectProvider(): array
    {
        return ['the type' => ['inventory_type', 'inventory-type'], 'the project' => ['project_id', 'inventory-project_id']];
    }

    /**
     * @dataProvider postedListSelectProvider
     */
    public function testAPostedListForASelectShowsTheFormWithItsError(string $field, string $inputId): void
    {
        $admin = $this->inventoryAdmin();
        $this->loginAs($admin);
        $inventory = $this->staticInventory((int)$admin->id);
        $stored = $this->storedRow((int)$inventory->id);
        $this->setPost(['Inventory' => [$field => ['x']]]);
        $ctrl = $this->makeRealViewController();

        $html = $this->withRealViews($ctrl, '/inventory/update?id=' . $inventory->id, fn (): Response|string => $ctrl->actionUpdate((int)$inventory->id));

        $this->assertIsString($html);
        $this->assertSame(1, preg_match_all('/<div class="[^"]*field-([\w-]+)[^"]*has-error/', $html, $failed));
        $this->assertSame([$inputId], $failed[1]);
        $this->assertSame($stored, $this->storedRow((int)$inventory->id));
    }

    /**
     * Without a submitted form, New Inventory shows the form: static, in
     * the project of the link it came from.
     */
    public function testCreateShowsTheFormWithoutASubmission(): void
    {
        $this->loginAs($this->inventoryOperator());
        $ctrl = $this->makeController();

        $this->assertSame('rendered:form', $ctrl->actionCreate(42));

        $model = $ctrl->capturedParams['model'] ?? null;
        $this->assertInstanceOf(Inventory::class, $model);
        $this->assertTrue($model->isNewRecord);
        $this->assertSame(Inventory::TYPE_STATIC, $model->inventory_type);
        $this->assertSame(42, $model->project_id);
        $this->assertSame(
            ['model', 'projects', 'canAssignRule', 'approvalRuleOptions', 'changeLocked', 'repositoryHostsNote'],
            array_keys($ctrl->capturedParams)
        );
        $this->assertSame(
            [false, [], false, null],
            [$ctrl->capturedParams['canAssignRule'], $ctrl->capturedParams['approvalRuleOptions'], $ctrl->capturedParams['changeLocked'], $ctrl->capturedParams['repositoryHostsNote']],
            'an operator: the rule as text, an unlocked form, no note'
        );
    }

    public function testTheFlashesNameTheInventory(): void
    {
        $operator = $this->createUserWithRole('inv-gate-flash', 'operator');
        $this->loginAs($operator);
        $this->setPost(['Inventory' => ['name' => 'inv-gate-flash', 'inventory_type' => Inventory::TYPE_STATIC, 'content' => "all:\n  hosts:\n    web1.example.com:\n"]]);
        $ctrl = $this->makeController();

        $ctrl->actionCreate();

        $created = Inventory::findOne(['name' => 'inv-gate-flash']);
        $this->assertNotNull($created);
        $this->assertSame(['view', 'id' => (int)$created->id], $ctrl->capturedRedirect);
        $this->assertSame(['success' => 'Inventory "inv-gate-flash" created.'], \Yii::$app->session->getAllFlashes(true));
        $this->assertSame((int)$operator->id, (int)$created->created_by);

        $this->setPost(['Inventory' => ['name' => 'inv-gate-flash-renamed']]);
        $ctrl->actionUpdate((int)$created->id);
        $this->assertSame(['success' => sprintf(InventoryController::FLASH_UPDATED, 'inv-gate-flash-renamed')], \Yii::$app->session->getAllFlashes(true));

        $this->loginAs($this->inventoryAdmin());
        $ctrl->actionDelete((int)$created->id);
        $this->assertSame(['index'], $ctrl->capturedRedirect);
        $this->assertSame(['success' => sprintf(InventoryController::FLASH_DELETED, 'inv-gate-flash-renamed')], \Yii::$app->session->getAllFlashes(true));
        $this->assertNull(Inventory::findOne($created->id));
        $this->assertSame(sprintf(InventoryController::FLASH_CREATED, 'x'), 'Inventory "x" created.');
    }

    public function testAnAdminCreatesAnInventoryWithARule(): void
    {
        $admin = $this->inventoryAdmin();
        $this->loginAs($admin);
        $rule = $this->createApprovalRule((int)$admin->id);
        $this->setPost(['Inventory' => [
            'name' => 'inv-gate-web-gated',
            'inventory_type' => Inventory::TYPE_STATIC,
            'content' => "all:\n  hosts:\n    web1.example.com:\n",
            InventoryRuleAssignment::FIELD => (string)$rule->id,
        ]]);

        $this->makeController()->actionCreate();

        $created = Inventory::findOne(['name' => 'inv-gate-web-gated']);
        $this->assertNotNull($created);
        $this->assertSame((int)$rule->id, $created->getAttribute('approval_rule_id'));
        $this->assertSame('web', $this->inventoryAudits(AuditLog::ACTION_INVENTORY_CREATED, (int)$created->id)[0]['source'] ?? null);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusalProvider(): array
    {
        return [
            'a rule on a new inventory' => ['create-rule'],
            'a rule on an inventory' => ['update-rule'],
            'the hosts of a gated inventory' => ['update-hosts'],
            'deleting a gated inventory' => ['delete'],
        ];
    }

    /**
     * Every refusal of the write service is a 403 with its message, and
     * nothing is written.
     *
     * @dataProvider refusalProvider
     */
    public function testARefusalIsA403WithItsMessage(string $case): void
    {
        $user = $this->inventoryDeleter();
        $this->loginAs($user);
        $rule = $this->createApprovalRule((int)$user->id);
        $inventory = $this->staticInventory((int)$user->id);
        if ($case === 'update-hosts' || $case === 'delete') {
            $inventory = $this->gate($inventory, $rule);
        }
        $stored = $this->storedRow((int)$inventory->id);
        $count = (int)Inventory::find()->count();
        $ctrl = $this->makeController();

        try {
            match ($case) {
                'create-rule' => $this->post(['name' => 'inv-gate-refused', 'inventory_type' => Inventory::TYPE_STATIC, 'content' => "all:\n", InventoryRuleAssignment::FIELD => (string)$rule->id], fn () => $ctrl->actionCreate()),
                'update-rule' => $this->post([InventoryRuleAssignment::FIELD => (string)$rule->id], fn () => $ctrl->actionUpdate((int)$inventory->id)),
                'update-hosts' => $this->post(['content' => "all:\n  hosts:\n    db1.example.com:\n"], fn () => $ctrl->actionUpdate((int)$inventory->id)),
                default => $ctrl->actionDelete((int)$inventory->id),
            };
            $this->fail('Expected a ForbiddenHttpException.');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame(
                match ($case) {
                    'create-rule', 'update-rule' => ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED,
                    'update-hosts' => GatedInventoryLock::CHANGE_REFUSED,
                    default => GatedInventoryLock::DELETE_REFUSED,
                },
                $e->getMessage()
            );
        }

        $this->assertSame($stored, $this->storedRow((int)$inventory->id));
        $this->assertSame($count, (int)Inventory::find()->count());
        $this->assertSame([], \Yii::$app->session->getAllFlashes());
    }

    /**
     * The team scope that the write service checks again under the row lock
     * is answered in the words of the page's own check.
     *
     * @return array<string, array{0: string}>
     */
    public static function writeProvider(): array
    {
        return ['an update' => ['update'], 'a delete' => ['delete']];
    }

    /**
     * @dataProvider writeProvider
     */
    public function testAnInventoryMovedMeanwhileOutOfReachAnswersTheModifyForbiddenText(string $write): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $inventory = $this->staticInventory((int)$scope['admin']->id, (int)$scope['own']->project_id);
        $this->meanwhile(static function () use ($inventory, $scope): void {
            Inventory::updateAll(['project_id' => (int)$scope['foreign']->project_id], ['id' => $inventory->id]);
        });
        $this->setPost(['Inventory' => ['name' => 'inv-gate-moved']]);
        $ctrl = $this->makeController();

        try {
            $write === 'update' ? $ctrl->actionUpdate((int)$inventory->id) : $ctrl->actionDelete((int)$inventory->id);
            $this->fail('Expected a ForbiddenHttpException.');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame(BaseController::MODIFY_FORBIDDEN, $e->getMessage());
        }

        $this->assertNotNull(Inventory::findOne($inventory->id));
        $this->assertNotSame('inv-gate-moved', Inventory::findOne($inventory->id)?->name);
    }

    /**
     * @dataProvider writeProvider
     */
    public function testAnInventoryDeletedMeanwhileIsNotFound(string $write): void
    {
        $admin = $this->inventoryAdmin();
        $this->loginAs($admin);
        $inventory = $this->staticInventory((int)$admin->id);
        $this->meanwhile(static function () use ($inventory): void {
            Inventory::deleteAll(['id' => $inventory->id]);
        });
        $this->setPost(['Inventory' => ['name' => 'inv-gate-gone']]);
        $ctrl = $this->makeController();

        try {
            $write === 'update' ? $ctrl->actionUpdate((int)$inventory->id) : $ctrl->actionDelete((int)$inventory->id);
            $this->fail('Expected a NotFoundHttpException.');
        } catch (NotFoundHttpException $e) {
            $this->assertSame(sprintf(InventoryWriteService::NOT_FOUND, (int)$inventory->id), $e->getMessage());
        }

        $this->assertFalse($this->hasInventoryAudit((int)$inventory->id));
    }

    // -- helpers -----------------------------------------------------------------

    /**
     * Run $action with $fields posted as the form's Inventory fields.
     *
     * @param array<string, mixed> $fields
     */
    private function post(array $fields, callable $action): mixed
    {
        $this->setPost(['Inventory' => $fields]);

        return $action();
    }

    /**
     * Run $change right before the write service writes: a change that
     * another request commits after this one loaded the inventory.
     */
    private function meanwhile(callable $change): void
    {
        $writer = new class () extends InventoryWriteService {
            /** @var (callable(): void)|null */
            public $change = null;

            public function update(Inventory $inventory, #[\SensitiveParameter] array $submitted, int $userId, string $source): bool
            {
                ($this->change)();

                return parent::update($inventory, $submitted, $userId, $source);
            }

            public function delete(Inventory $inventory, int $userId, string $source): void
            {
                ($this->change)();
                parent::delete($inventory, $userId, $source);
            }
        };
        $writer->change = $change;
        $this->replaceComponent('inventoryWriteService', $writer);
    }

    private function makeController(): InventoryController
    {
        return new class ('inventory', \Yii::$app) extends InventoryController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];
            /** @var mixed the route passed to redirect() */
            public mixed $capturedRedirect = null;

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): \yii\web\Response
            {
                $this->capturedRedirect = $url;
                return new \yii\web\Response();
            }
        };
    }

    /**
     * The controller with the real form view, without the layout.
     */
    private function makeRealViewController(): InventoryController
    {
        return new class ('inventory', \Yii::$app) extends InventoryController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };
    }
}
