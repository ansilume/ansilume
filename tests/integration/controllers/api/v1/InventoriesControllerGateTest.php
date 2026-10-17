<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\ApiBodyValues;
use app\controllers\api\v1\InventoriesController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\Inventory;
use app\models\User;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\GatedInventoryLock;
use app\services\InventoryRuleAssignment;
use app\services\InventoryUsageService;
use app\services\InventoryWriteService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\CountsQueries;
use app\tests\integration\services\InventoryWriteFixtures;
use app\tests\integration\TeamScopeFixtures;

/**
 * /api/v1/inventories through InventoryWriteService: the list and the
 * detail, the approval rule (admins only), the lock of a gated inventory,
 * the delete of an inventory in use (409 with used_by), and request bodies
 * whose fields hold a list or an object (422).
 */
class InventoriesControllerGateTest extends WebControllerTestCase
{
    use CountsQueries;
    use InventoryWriteFixtures;
    use TeamScopeFixtures;

    private const LIST_KEYS = [
        'id',
        'name',
        'description',
        'inventory_type',
        'project_id',
        'approval_rule_id',
        'approval_rule_name',
        'created_at',
        'updated_at',
    ];

    private InventoriesController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new InventoriesController('api/v1/inventories', \Yii::$app);
    }

    protected function tearDown(): void
    {
        $this->detachInventoryHandlers();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function usingTemplateProvider(): array
    {
        return ['a live job template' => [false], 'only a soft-deleted job template' => [true]];
    }

    /**
     * regression: DELETE of an inventory that a job template uses, also one
     * that only a soft-deleted template still refers to, failed on the
     * foreign key with a server error. It answers 409 with what uses it.
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

        $result = $this->ctrl->actionDelete((int)$inventory->id);

        $this->assertSame(409, \Yii::$app->response->statusCode);
        $this->assertNotNull(Inventory::findOne($inventory->id));
        $usage = (new InventoryUsageService())->forInventory($inventory, (int)$admin->id);
        $this->assertSame(
            ['error' => ['message' => sprintf(InventoryWriteService::DELETE_IN_USE, $usage->summary()), 'used_by' => $usage->toArray()]],
            $result
        );
        $this->assertSame(
            ['in_use', 'job_templates', 'hidden_job_template_count', 'deleted_job_template_count'],
            array_keys($usage->toArray())
        );
        $this->assertFalse($this->hasInventoryAudit((int)$inventory->id));
    }

    /** The body fields, as InventoriesController::BODY_FIELDS names them (a literal: the old controller has none). */
    private const BODY_FIELDS = ['name', 'description', 'inventory_type', 'content', 'source_path', 'project_id', 'approval_rule_id'];

    public function testTheListValueCasesCoverEveryBodyField(): void
    {
        $this->assertSame(InventoriesController::BODY_FIELDS, self::BODY_FIELDS);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: mixed}> the verb, the field, its value
     */
    public static function listValueProvider(): array
    {
        $cases = [];
        foreach (['POST', 'PUT'] as $verb) {
            foreach (self::BODY_FIELDS as $field) {
                $cases["{$verb}: {$field} as a list"] = [$verb, $field, ['x']];
            }
            $cases["{$verb}: project_id [7]"] = [$verb, 'project_id', [7]];
            $cases["{$verb}: name as an object"] = [$verb, 'name', ['first' => 'web']];
        }

        return $cases;
    }

    /**
     * regression: a field of the JSON body that held a list or an object
     * was cast: a list became "Array" with a PHP error (500); a list for the
     * project became project #1 (saved there when it exists, else refused
     * as an unknown project); a list for the rule was ignored and the
     * inventory saved. It answers 422 naming the field, and writes nothing.
     *
     * @dataProvider listValueProvider
     */
    public function testRegressionAListInAJsonBodyAnswered500(string $verb, string $field, mixed $value): void
    {
        $admin = $this->inventoryAdmin();
        $this->loginAs($admin);
        $inventory = $this->staticInventory((int)$admin->id);
        $stored = $this->storedRow((int)$inventory->id);
        $count = (int)Inventory::find()->count();
        $body = $verb === 'POST' ? ['name' => 'inv-gate-api-list', 'inventory_type' => Inventory::TYPE_STATIC, 'content' => "all:\n"] : [];
        $body[$field] = $value;
        $this->setBody($body);

        $result = $verb === 'POST' ? $this->ctrl->actionCreate() : $this->ctrl->actionUpdate((int)$inventory->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame($count, (int)Inventory::find()->count());
        $this->assertSame($stored, $this->storedRow((int)$inventory->id));
        $this->assertSame(['error' => ['message' => sprintf(ApiBodyValues::NOT_A_VALUE, $field)]], $result);
    }

    // -- shapes ------------------------------------------------------------------

    /**
     * The list leaves out content and source_path; each item names its
     * approval rule.
     */
    public function testTheListItemNamesTheRuleWithoutContentOrSourcePath(): void
    {
        $admin = $this->inventoryAdmin();
        $this->loginAs($admin);
        $rule = $this->createApprovalRule((int)$admin->id);
        $gated = $this->gate($this->staticInventory((int)$admin->id, null, 'inv-gate-api-gated'), $rule);
        $plain = $this->staticInventory((int)$admin->id, null, 'inv-gate-api-plain');

        $items = $this->itemsById($this->ctrl->actionIndex()['data']);

        $this->assertSame([
            'id' => (int)$gated->id,
            'name' => 'inv-gate-api-gated',
            'description' => null,
            'inventory_type' => Inventory::TYPE_STATIC,
            'project_id' => null,
            'approval_rule_id' => (int)$rule->id,
            'approval_rule_name' => $rule->name,
            'created_at' => (int)$gated->created_at,
            'updated_at' => (int)$gated->updated_at,
        ], $items[(int)$gated->id] ?? null);
        $plainItem = $items[(int)$plain->id] ?? [];
        $this->assertSame(self::LIST_KEYS, array_keys($plainItem));
        $this->assertNull($plainItem['approval_rule_id']);
        $this->assertNull($plainItem['approval_rule_name']);
    }

    /**
     * The list loads the rules with the inventories: 2 or 12 gated
     * inventories cost the same.
     */
    public function testTheListCostsTheSameForMoreGatedInventories(): void
    {
        $admin = $this->inventoryAdmin();
        $this->loginAs($admin);
        $this->ctrl->actionIndex();
        $this->addGatedInventories((int)$admin->id, 2);
        $two = $this->queriesOf(fn () => $this->ctrl->actionIndex());
        $this->addGatedInventories((int)$admin->id, 10);
        $twelve = $this->queriesOf(fn () => $this->ctrl->actionIndex());

        $this->assertSame($two, $twelve);
    }

    /**
     * GET /{id}, the 201 of POST and the 200 of PUT answer the detail: the
     * list item with content and source_path.
     */
    public function testTheDetailAddsContentAndSourcePath(): void
    {
        $admin = $this->inventoryAdmin();
        $this->loginAs($admin);
        $rule = $this->createApprovalRule((int)$admin->id);
        $this->setBody([
            'name' => 'inv-gate-api-detail',
            'inventory_type' => Inventory::TYPE_STATIC,
            'content' => "all:\n  hosts:\n    web1.example.com:\n",
            InventoryRuleAssignment::FIELD => (int)$rule->id,
        ]);

        $created = $this->ctrl->actionCreate();

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $data = $created['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertSame(array_merge(self::LIST_KEYS, ['content', 'source_path']), array_keys($data));
        $this->assertSame((int)$rule->id, $data['approval_rule_id']);
        $this->assertSame($rule->name, $data['approval_rule_name']);
        $this->assertSame("all:\n  hosts:\n    web1.example.com:\n", $data['content']);
        $this->assertNull($data['source_path']);
        $id = (int)$data['id'];

        $this->assertSame(
            ['data' => $data + ['used_by' => ['in_use' => false, 'job_templates' => [], 'hidden_job_template_count' => 0, 'deleted_job_template_count' => 0]]],
            $this->ctrl->actionView($id),
            'GET /{id} answers the same detail, and the job templates that use the inventory'
        );

        $this->setBody([InventoryRuleAssignment::FIELD => null, 'description' => 'No rule now']);
        $updated = $this->ctrl->actionUpdate($id);
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $updatedData = $updated['data'] ?? [];
        $this->assertIsArray($updatedData);
        $this->assertSame(array_merge(self::LIST_KEYS, ['content', 'source_path']), array_keys($updatedData));
        $this->assertNull($updatedData['approval_rule_id']);
        $this->assertNull($updatedData['approval_rule_name']);
        $this->assertSame('No rule now', $updatedData['description']);
        $this->assertNull($this->reloaded($id)->getAttribute('approval_rule_id'));
    }

    /**
     * Regression (2.12.0 review, API-2): the Job templates card of the
     * inventory's page had no API counterpart. GET /{id} answers used_by as
     * the card shows it (InventoryUsageService::forInventory() for the
     * caller): the live job templates the caller may see, each with its own
     * rule, and the hidden and the deleted ones counted.
     */
    public function testRegressionTheJobTemplatesCardHadNoApiAnswer(): void
    {
        $scope = $this->teamScope();
        $adminId = (int)$scope['admin']->id;
        $inventory = $this->staticInventory($adminId, null, 'inv-gate-api-used');
        $rule = $this->createApprovalRule($adminId);
        $visible = $this->templateOn((int)$inventory->id, $adminId, $rule, 'inv-gate-api-visible');
        $hidden = $this->templateOn((int)$inventory->id, $adminId, null, 'inv-gate-api-hidden');
        $hidden->project_id = (int)$scope['foreign']->project_id;
        $hidden->save(false);
        $deleted = $this->templateOn((int)$inventory->id, $adminId, null, 'inv-gate-api-deleted');
        $deleted->deleted_at = time();
        $deleted->save(false);
        $this->loginAs($scope['member']);

        $data = $this->ctrl->actionView((int)$inventory->id)['data'] ?? null;

        $this->assertIsArray($data);
        $this->assertSame(
            [
                'in_use' => true,
                'job_templates' => [[
                    'id' => (int)$visible->id,
                    'name' => 'inv-gate-api-visible',
                    'project_id' => (int)$visible->project_id,
                    'project_name' => $visible->project?->name,
                    'approval_rule_id' => (int)$rule->id,
                    'approval_rule_name' => $rule->name,
                ]],
                'hidden_job_template_count' => 1,
                'deleted_job_template_count' => 1,
            ],
            $data['used_by'] ?? null,
            'the card: the template the member sees with its own rule, the hidden one and the deleted one counted'
        );
        $this->assertSame((new InventoryUsageService())->forInventory($inventory, (int)$scope['member']->id)->toArray(), $data['used_by']);
        $this->assertSame(array_merge(self::LIST_KEYS, ['content', 'source_path', 'used_by']), array_keys($data));
    }

    /**
     * Regression (2.12.0 review, API-1): a project_id of "" was cast to 0,
     * although null and "" are the same, as the PUT description says: on a
     * gated inventory without a project, an operator's rename that sent
     * "project_id": "" answered 403 CHANGE_REFUSED, and an admin's create or
     * update with it 422 'The selected project does not exist.'. "" is no
     * project now, as null is, for every field that may be empty.
     */
    public function testRegressionAnEmptyProjectIdWasAChange(): void
    {
        $operator = $this->inventoryOperator();
        $gated = $this->gate($this->staticInventory((int)$operator->id), $this->createApprovalRule((int)$operator->id));
        $this->loginAs($operator);
        $this->setBody(['name' => 'inv-gate-api-empty-project', 'project_id' => '', 'description' => '', 'source_path' => '']);

        $result = $this->ctrl->actionUpdate((int)$gated->id);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($result));
        $stored = $this->reloaded((int)$gated->id);
        $this->assertSame(['inv-gate-api-empty-project', null, null, null], [$stored->name, $stored->project_id, $stored->description, $stored->source_path]);
        $this->assertSame(['name'], $this->inventoryAudits(AuditLog::ACTION_INVENTORY_UPDATED, (int)$gated->id)[0]['changed_fields'] ?? null, 'only the name changed');

        $admin = $this->inventoryAdmin();
        $this->loginAs($admin);
        $plain = $this->staticInventory((int)$admin->id);
        $this->setBody(['project_id' => '']);
        $result = $this->ctrl->actionUpdate((int)$plain->id);
        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertNull($this->reloaded((int)$plain->id)->project_id);

        $this->setBody(['name' => 'inv-gate-api-empty-project-new', 'inventory_type' => Inventory::TYPE_STATIC, 'content' => "all:\n", 'project_id' => '']);
        $created = $this->ctrl->actionCreate();
        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($created));
        $this->assertNull($this->reloaded((int)($created['data']['id'] ?? 0))->project_id);
    }

    /**
     * An empty project_id still moves an inventory out of its project: ""
     * is no project, as null is. On a gated inventory that is a change of
     * its hosts, refused for an operator.
     */
    public function testAnEmptyProjectIdTakesTheInventoryOutOfItsProject(): void
    {
        $operator = $this->inventoryOperator();
        $project = (int)$this->createProject((int)$operator->id)->id;
        $this->loginAs($operator);
        $plain = $this->staticInventory((int)$operator->id, $project);
        $this->setBody(['project_id' => '']);
        $this->ctrl->actionUpdate((int)$plain->id);
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertNull($this->reloaded((int)$plain->id)->project_id);

        $gated = $this->gate($this->staticInventory((int)$operator->id, $project), $this->createApprovalRule((int)$operator->id));
        $stored = $this->storedRow((int)$gated->id);
        $result = $this->ctrl->actionUpdate((int)$gated->id);
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => GatedInventoryLock::CHANGE_REFUSED]], $result);
        $this->assertSame($stored, $this->storedRow((int)$gated->id));
    }

    /**
     * created_by is the caller, never a value of the body.
     */
    public function testTheBodyNeverChoosesTheCreator(): void
    {
        $operator = $this->inventoryOperator();
        $this->loginAs($operator);
        $other = (int)$this->createUser('inv-gate-api-other')->id;
        $this->setBody(['name' => 'inv-gate-api-creator', 'inventory_type' => Inventory::TYPE_STATIC, 'content' => "all:\n", 'created_by' => $other]);

        $created = $this->ctrl->actionCreate();

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $id = (int)($created['data']['id'] ?? 0);
        $this->assertSame((int)$operator->id, (int)$this->reloaded($id)->created_by);

        $this->setBody(['created_by' => $other, 'name' => 'inv-gate-api-creator-2']);
        $this->ctrl->actionUpdate($id);
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame((int)$operator->id, (int)$this->reloaded($id)->created_by);
    }

    /**
     * The body of a GET sent back with another name answers 200, also for
     * a user the lock of a gated inventory applies to: what it sends back
     * unchanged is no change.
     */
    public function testAGetBodySentBackWithANewNameIsSaved(): void
    {
        $operator = $this->inventoryOperator();
        $this->loginAs($operator);
        $inventory = $this->gate($this->staticInventory((int)$operator->id), $this->createApprovalRule((int)$operator->id));
        $body = $this->ctrl->actionView((int)$inventory->id)['data'] ?? null;
        $this->assertIsArray($body);
        $body['name'] = 'inv-gate-api-sent-back';
        $this->setBody($body);

        $result = $this->ctrl->actionUpdate((int)$inventory->id);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertSame('inv-gate-api-sent-back', $this->reloaded((int)$inventory->id)->name);
    }

    // -- refusals ----------------------------------------------------------------

    /**
     * Each action checks its permission first, then team scope: POST into a
     * project the caller may not operate is refused before anything is
     * written, and so are POST and DELETE without their permission.
     */
    public function testCreateAndDeleteCheckThePermissionAndTheTargetProject(): void
    {
        $scope = $this->teamScope();
        $this->loginAs($this->createUserWithRole('inv-gate-api-viewer', 'viewer'));
        $count = (int)Inventory::find()->count();
        $inventory = $this->staticInventory((int)$scope['admin']->id);

        $this->setBody(['name' => 'inv-gate-api-viewer', 'inventory_type' => Inventory::TYPE_STATIC, 'content' => "all:\n"]);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $this->ctrl->actionCreate());
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $this->ctrl->actionDelete((int)$inventory->id));
        $this->assertSame(403, \Yii::$app->response->statusCode);

        $this->loginAs($scope['member']);
        foreach (['foreign', 'viewed'] as $target) {
            $this->setBody(['name' => 'inv-gate-api-foreign', 'inventory_type' => Inventory::TYPE_STATIC, 'content' => "all:\n", 'project_id' => (int)$scope[$target]->project_id]);
            $this->assertSame(['error' => ['message' => 'Forbidden.']], $this->ctrl->actionCreate(), $target);
            $this->assertSame(403, \Yii::$app->response->statusCode);
        }
        $this->assertSame($count + 1, (int)Inventory::find()->count(), 'only the fixture was written');
        $this->assertNotNull(Inventory::findOne($inventory->id));

        // A user who may delete inventories, of a team that only views the inventory's project.
        $deleter = $this->inventoryDeleter();
        $team = (int)$this->createTeam((int)$scope['admin']->id)->id;
        $this->addTeamMember($team, (int)$deleter->id);
        $this->createTeamProject($team, (int)$scope['viewed']->project_id, \app\models\TeamProject::ROLE_VIEWER);
        $viewed = $this->staticInventory((int)$scope['admin']->id, (int)$scope['viewed']->project_id);
        $this->loginAs($deleter);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $this->ctrl->actionDelete((int)$viewed->id));
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertNotNull(Inventory::findOne($viewed->id));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function actionOnAMissingInventoryProvider(): array
    {
        return ['GET' => ['view'], 'PUT' => ['update'], 'DELETE' => ['delete']];
    }

    /**
     * @dataProvider actionOnAMissingInventoryProvider
     */
    public function testAMissingInventoryIsNotFound(string $action): void
    {
        $this->loginAs($this->inventoryAdmin());
        $this->setBody(['name' => 'inv-gate-api-missing']);

        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->expectExceptionMessage('Inventory #999999999 not found.');

        match ($action) {
            'view' => $this->ctrl->actionView(999999999),
            'update' => $this->ctrl->actionUpdate(999999999),
            default => $this->ctrl->actionDelete(999999999),
        };
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusalProvider(): array
    {
        return [
            'POST with a rule' => ['create-rule'],
            'PUT with a rule' => ['update-rule'],
            'PUT of a gated inventory\'s hosts' => ['update-hosts'],
            'DELETE of a gated inventory' => ['delete'],
        ];
    }

    /**
     * @dataProvider refusalProvider
     */
    public function testARefusalAnswers403WithItsMessageAndWritesNothing(string $case): void
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

        $result = match ($case) {
            'create-rule' => $this->withBody(['name' => 'inv-gate-api-refused', 'inventory_type' => Inventory::TYPE_STATIC, 'content' => "all:\n", InventoryRuleAssignment::FIELD => (int)$rule->id], fn () => $this->ctrl->actionCreate()),
            'update-rule' => $this->withBody([InventoryRuleAssignment::FIELD => (int)$rule->id], fn () => $this->ctrl->actionUpdate((int)$inventory->id)),
            'update-hosts' => $this->withBody(['content' => "all:\n  hosts:\n    db1.example.com:\n"], fn () => $this->ctrl->actionUpdate((int)$inventory->id)),
            default => $this->ctrl->actionDelete((int)$inventory->id),
        };

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame($stored, $this->storedRow((int)$inventory->id));
        $this->assertSame($count, (int)Inventory::find()->count());
        $this->assertSame(['error' => ['message' => match ($case) {
            'create-rule', 'update-rule' => ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED,
            'update-hosts' => GatedInventoryLock::CHANGE_REFUSED,
            default => GatedInventoryLock::DELETE_REFUSED,
        }]], $result);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function writeProvider(): array
    {
        return ['PUT' => ['update'], 'DELETE' => ['delete']];
    }

    /**
     * @dataProvider writeProvider
     */
    public function testAnInventoryDeletedMeanwhileAnswers404(string $write): void
    {
        $admin = $this->inventoryAdmin();
        $this->loginAs($admin);
        $inventory = $this->staticInventory((int)$admin->id);
        $this->meanwhile(static function () use ($inventory): void {
            Inventory::deleteAll(['id' => $inventory->id]);
        });
        $this->setBody(['name' => 'inv-gate-api-gone']);

        $result = $write === 'update' ? $this->ctrl->actionUpdate((int)$inventory->id) : $this->ctrl->actionDelete((int)$inventory->id);

        $this->assertSame(404, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => sprintf(InventoryWriteService::NOT_FOUND, (int)$inventory->id)]], $result);
    }

    /**
     * @dataProvider writeProvider
     */
    public function testAnInventoryMovedMeanwhileOutOfReachAnswers403(string $write): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $inventory = $this->staticInventory((int)$scope['admin']->id, (int)$scope['own']->project_id, 'inv-gate-api-moving');
        $this->meanwhile(static function () use ($inventory, $scope): void {
            Inventory::updateAll(['project_id' => (int)$scope['foreign']->project_id], ['id' => $inventory->id]);
        });
        $this->setBody(['name' => 'inv-gate-api-moved']);

        $result = $write === 'update' ? $this->ctrl->actionUpdate((int)$inventory->id) : $this->ctrl->actionDelete((int)$inventory->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => InventoryWriteService::TEAM_SCOPE_REFUSED]], $result);
        $this->assertSame('inv-gate-api-moving', $this->reloaded((int)$inventory->id)->name);
    }

    /**
     * @return array<string, array{0: string, 1: string}> the case, and its error
     */
    public static function invalidRuleProvider(): array
    {
        return [
            'a rule that does not exist' => ['unknown', Inventory::APPROVAL_RULE_NOT_FOUND],
            'no rule ID' => ['text', 'Approval rule must be an integer.'],
            'a dynamic inventory without a source path' => ['dynamic', Inventory::SOURCE_PATH_REQUIRED_WHEN_GATED],
            'a job template with another rule of its own' => ['differ', 'differ'],
        ];
    }

    /**
     * @dataProvider invalidRuleProvider
     */
    public function testAnInvalidRuleAnswers422(string $case, string $error): void
    {
        $admin = $this->inventoryAdmin();
        $this->loginAs($admin);
        $rule = $this->createApprovalRule((int)$admin->id);
        $inventory = $this->staticInventory((int)$admin->id);
        $body = [InventoryRuleAssignment::FIELD => match ($case) {
            'unknown' => 999999999,
            'text' => 'abc',
            default => (int)$rule->id,
        }];
        if ($case === 'dynamic') {
            $body += ['inventory_type' => Inventory::TYPE_DYNAMIC, 'content' => null];
        }
        if ($case === 'differ') {
            $this->templateOn((int)$inventory->id, (int)$admin->id, $this->createApprovalRule((int)$admin->id), 'inv-gate-api-differ');
            $error = sprintf(InventoryRuleAssignment::RULES_DIFFER, '"inv-gate-api-differ"');
        }
        $stored = $this->storedRow((int)$inventory->id);
        $this->setBody($body);

        $result = $this->ctrl->actionUpdate((int)$inventory->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $error]], $result);
        $this->assertSame($stored, $this->storedRow((int)$inventory->id));
    }

    /**
     * A superadmin without RBAC roles is an admin of approval rules: it
     * assigns a rule, changes the hosts of a gated inventory and deletes it,
     * through the gate of every action.
     */
    public function testARolelessSuperadminChangesGatedInventories(): void
    {
        $superadmin = $this->createUser('inv-gate-api-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $rule = $this->createApprovalRule((int)$superadmin->id);

        $created = $this->callAs($superadmin, 'create', 'POST', [], [
            'name' => 'inv-gate-api-superadmin',
            'inventory_type' => Inventory::TYPE_STATIC,
            'content' => "all:\n",
            InventoryRuleAssignment::FIELD => (int)$rule->id,
        ]);
        $this->assertSame(201, $created['status']);
        $id = (int)($created['response']['data']['id'] ?? 0);
        $this->assertSame((int)$rule->id, $this->reloaded($id)->getAttribute('approval_rule_id'));

        $updated = $this->callAs($superadmin, 'update', 'PUT', ['id' => $id], ['content' => "all:\n  hosts:\n    db1.example.com:\n"]);
        $this->assertSame(200, $updated['status']);
        $this->assertSame("all:\n  hosts:\n    db1.example.com:\n", $this->reloaded($id)->content);

        $deleted = $this->callAs($superadmin, 'delete', 'DELETE', ['id' => $id]);
        $this->assertSame(200, $deleted['status']);
        $this->assertSame(['data' => ['deleted' => true]], $deleted['response']);
        $this->assertNull(Inventory::findOne($id));
        $this->assertSame(['api'], array_column($this->inventoryAudits(AuditLog::ACTION_INVENTORY_DELETED, $id), 'source'));
    }

    // -- helpers -----------------------------------------------------------------

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    private function itemsById(array $items): array
    {
        $byId = [];
        foreach ($items as $item) {
            $this->assertIsArray($item);
            /** @var array<string, mixed> $item */
            $byId[(int)$item['id']] = $item;
        }

        return $byId;
    }

    private function addGatedInventories(int $userId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->gate($this->staticInventory($userId), $this->createApprovalRule($userId));
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function withBody(array $body, callable $action): mixed
    {
        $this->setBody($body);

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

    /**
     * Run $action as $user through runAction() with a Bearer token, the
     * gate of beforeAction() included.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $body
     * @return array{status: int, response: array<string, mixed>}
     */
    private function callAs(User $user, string $action, string $verb, array $params = [], array $body = []): array
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'inventory-gate-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        $this->setBody($body);
        $_SERVER['REQUEST_METHOD'] = $verb;
        \Yii::$app->response->statusCode = 200;
        \Yii::$app->response->data = null;

        $result = (new InventoriesController('api/v1/inventories', \Yii::$app))->runAction($action, $params);
        $response = is_array($result) ? $result : \Yii::$app->response->data;
        $this->assertIsArray($response);
        /** @var array<string, mixed> $response */

        return ['status' => \Yii::$app->response->statusCode, 'response' => $response];
    }
}
