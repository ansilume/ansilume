<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\ApiBodyValues;
use app\components\SurveyDefinition;
use app\components\WorkflowRouteStep;
use app\controllers\api\v1\ApprovalsController;
use app\controllers\api\v1\InventoriesController;
use app\controllers\api\v1\JobsController;
use app\controllers\api\v1\JobTemplatesController;
use app\controllers\api\v1\WorkflowTemplatesController;
use app\controllers\TriggerController;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\GatedInventoryLock;
use app\services\GatedJobTemplateLock;
use app\services\InventoryRuleAssignment;
use app\services\InventoryWriteService;
use app\services\JobClaimRefusedException;
use app\services\JobTemplateGate;
use app\services\JobTemplateRuleAssignment;
use app\tests\integration\controllers\PresentsTriggerTokens;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\InventoryWriteFixtures;
use app\tests\integration\services\RecordingNotificationDispatcher;
use app\tests\integration\TeamScopeFixtures;
use yii\web\NotFoundHttpException;
use yii\web\Request;
use yii\web\Response;

/**
 * What web/openapi.yaml says about approval rules on inventories (2.12.0) is
 * what the API answers: each test runs the endpoints and compares their
 * answers with the spec's schemas, examples and descriptions, and every
 * quoted text with the code's constant.
 *
 * - /api/v1/inventories: the list item (Inventory) and the detail
 *   (InventoryDetail) with the approval rule, the request examples, the 403
 *   of the lock and of the rule assignment, the 422 of the body and of the
 *   validation, and the 404 of an inventory that is gone;
 * - job templates: approval_gate, and the 403 and 422 of an inventory with
 *   a rule;
 * - the launch of a job template gated through its inventory, the approval
 *   request that records it, and the job trigger's status;
 * - job steps gated through their job template's inventory in the routes;
 * - the tags of inventories, jobs, approval rules and approvals.
 *
 * Regressions, each fails on the spec of 2.11.1 (3007a3b): a list or an
 * object in an inventory body answered 500 and the spec documented no 422
 * for it; the job trigger did not say that its job waits for approval.
 */
class OpenApiInventoryApprovalContractTest extends WebControllerTestCase
{
    use OpenApiSchemaAssertions;
    use OpenApiWorkflowAssertions;
    use InventoryWriteFixtures;
    use TeamScopeFixtures;
    use PresentsTriggerTokens;

    private const INVENTORIES = '/api/v1/inventories';
    private const INVENTORY = '/api/v1/inventories/{id}';
    private const JOB_TEMPLATES = '/api/v1/job-templates';
    private const JOB_TEMPLATE = '/api/v1/job-templates/{id}';

    /** The inventory, rule and job template names of the spec's examples. */
    private const EXAMPLE_INVENTORY = 'web-prod';
    private const EXAMPLE_RULE = 'deploy-prod';
    private const EXAMPLE_TEMPLATE = 'deploy-web';

    /** The hosts of a static inventory, a YAML mapping as the model requires. */
    private const CONTENT = "all:\n  hosts:\n    web1.example.com:\n";
    private const OTHER_CONTENT = "all:\n  hosts:\n    other.example.com:\n";

    private User $admin;

    private InventoriesController $inventories;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->userWithRole('inv-contract-admin', 'admin');
        $this->loginAs($this->admin);
        $this->inventories = new InventoriesController('api/v1/inventories', $this->app());
        $this->replaceComponent('notificationDispatcher', new RecordingNotificationDispatcher());
    }

    protected function tearDown(): void
    {
        $this->detachInventoryHandlers();
        parent::tearDown();
    }

    /**
     * The list answers Inventory, GET /{id} InventoryView: the list item
     * with content and source_path (InventoryDetail, which POST and PUT
     * answer) and used_by, each field in the documented order, and the
     * approval rule by ID and name.
     */
    public function testTheInventoryAnswersAreTheDocumentedSchemas(): void
    {
        $rule = $this->createApprovalRule($this->adminId());
        $gated = $this->gate($this->staticInventory($this->adminId()), $rule);
        $open = $this->staticInventory($this->adminId());

        $list = $this->inventories->actionIndex();
        $gatedItem = $this->listItem($list, (int)$gated->id);
        $openItem = $this->listItem($list, (int)$open->id);
        foreach ([$gatedItem, $openItem] as $item) {
            $this->assertMatchesSchema('Inventory', $item);
            $this->assertSame(array_keys($item), $this->required('Inventory'), 'Inventory documents the fields in the order of the answer.');
        }
        $this->assertSame([(int)$rule->id, $rule->name], [$gatedItem['approval_rule_id'], $gatedItem['approval_rule_name']]);
        $this->assertSame([null, null], [$openItem['approval_rule_id'], $openItem['approval_rule_name']]);
        $this->assertArrayNotHasKey('content', $gatedItem, 'The list leaves the hosts out.');

        $view = $this->data($this->inventories->actionView((int)$gated->id));
        $this->assertMatchesSchema('InventoryView', $view);
        $this->assertSame(array_keys($view), $this->required('InventoryView'), 'InventoryView documents the fields in the order of the answer.');
        $this->assertSame(array_merge($this->required('InventoryDetail'), ['used_by']), $this->required('InventoryView'), 'InventoryView is InventoryDetail and used_by.');
        $this->assertSame($gatedItem, array_diff_key($view, ['content' => true, 'source_path' => true, 'used_by' => true]), 'The detail is the list item and its hosts.');
        $this->assertSame([$gated->content, null], [$view['content'], $view['source_path']]);
        $this->assertIsArray($view['used_by']);
        $this->assertMatchesSchema('InventoryUsage', $view['used_by']);
        $this->assertSame(array_keys($view['used_by']), $this->required('InventoryUsage'));
        $this->assertSame(self::SCHEMA_REF_PREFIX . 'InventoryUsage', $this->specAt(['components', 'schemas', 'InventoryView', 'allOf', '1', 'properties', 'used_by', '$ref']));

        $this->assertDocumentedData(self::INVENTORY, 'get', '200', 'InventoryView');
        $this->assertDocumentedData(self::INVENTORIES, 'post', '201', 'InventoryDetail');
        $this->assertDocumentedData(self::INVENTORY, 'put', '200', 'InventoryDetail');
        $this->assertStringContainsString('The answers of `POST` and `PUT` leave `used_by` out.', $this->specText(['paths', self::INVENTORY, 'get', 'description']));
        $this->assertSame(self::SCHEMA_REF_PREFIX . 'Inventory', $this->specAt(['components', 'schemas', 'InventoryList', 'properties', 'data', 'items', '$ref']));
    }

    /**
     * The request examples are bodies the API takes, approval rule
     * included, and every answer is the documented detail.
     */
    public function testTheRequestExamplesAreBodiesTheApiTakes(): void
    {
        $rule = $this->createApprovalRule($this->adminId());
        $gated = $this->requestExample(self::INVENTORIES, 'post', 'gated');
        $this->assertSame(3, $gated['approval_rule_id'] ?? null, 'The example names rule 3, as the descriptions do.');
        $this->body(['approval_rule_id' => (int)$rule->id] + $gated);
        $created = $this->data($this->inventories->actionCreate());
        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertMatchesSchema('InventoryDetail', $created);
        $this->assertSame([self::EXAMPLE_INVENTORY, (int)$rule->id], [$created['name'], $created['approval_rule_id']]);
        $id = (int)$created['id'];

        foreach (['removeRule' => null, 'assignRule' => (int)$rule->id] as $name => $ruleId) {
            $example = $this->requestExample(self::INVENTORY, 'put', $name);
            $this->assertArrayHasKey('approval_rule_id', $example);
            $this->body(['approval_rule_id' => $ruleId] + $example);
            $updated = $this->data($this->inventories->actionUpdate($id));
            $this->assertSame(200, \Yii::$app->response->statusCode, $name);
            $this->assertMatchesSchema('InventoryDetail', $updated);
            $this->assertSame($ruleId, $updated['approval_rule_id'], $name);
        }
        $this->assertNull($this->requestExample(self::INVENTORY, 'put', 'removeRule')['approval_rule_id'], 'null removes the rule.');
    }

    /**
     * The 403s quote the code: the rule assignment (first), the lock of an
     * inventory with a rule, its delete, and a missing permission; nothing
     * is written, and the body of GET sent back with a new name is a rename.
     * The answers come in the documented order: a list in the body before
     * the rule assignment, the lock of a delete before the 409 of an
     * inventory in use.
     */
    public function testTheInventoryRefusalsAnswerAsTheSpecSays(): void
    {
        $rule = $this->createApprovalRule($this->adminId());
        $gated = $this->gate($this->staticInventory($this->adminId()), $rule);
        $id = (int)$gated->id;
        $this->templateOn($id, $this->adminId());
        $stored = $this->storedRow($id);
        $this->loginAs($this->inventoryOperator());

        $forged = ['name' => 'inv-contract-forged', 'inventory_type' => 'static', 'content' => self::CONTENT, 'approval_rule_id' => (int)$rule->id];
        $this->body(['project_id' => [7]] + $forged);
        $this->assertAnswer(422, $this->responseExample(self::INVENTORIES, 'post', '422', 'notAValue'), $this->inventories->actionCreate());
        $this->body($forged);
        $this->assertAnswer(403, $this->responseExample(self::INVENTORIES, 'post', '403', 'approvalRule'), $this->inventories->actionCreate());
        $this->assertFalse(Inventory::find()->where(['name' => 'inv-contract-forged'])->exists(), 'Nothing was written.');
        $this->body(['content' => self::OTHER_CONTENT]);
        $this->assertAnswer(403, $this->responseExample(self::INVENTORY, 'put', '403', 'gatedInventory'), $this->inventories->actionUpdate($id));
        $this->body(['approval_rule_id' => null, 'content' => self::OTHER_CONTENT]);
        $this->assertAnswer(403, $this->responseExample(self::INVENTORY, 'put', '403', 'approvalRule'), $this->inventories->actionUpdate($id));
        $this->assertSame($stored, $this->storedRow($id), 'Nothing was written.');

        $this->body(['name' => 'inv-contract-renamed'] + $this->data($this->inventories->actionView($id)));
        $renamed = $this->data($this->inventories->actionUpdate($id));
        $this->assertSame(200, \Yii::$app->response->statusCode, 'The GET body sent back with a new name is a rename.');
        $this->assertSame(['inv-contract-renamed', (int)$rule->id], [$renamed['name'], $renamed['approval_rule_id']]);

        $this->loginAs($this->inventoryDeleter());
        $this->assertAnswer(403, $this->responseExample(self::INVENTORY, 'delete', '403', 'gatedInventory'), $this->inventories->actionDelete($id));
        $this->assertNotNull(Inventory::findOne($id), 'Nothing was deleted; the 403 comes before the 409 of an inventory in use.');

        $this->loginAs($this->userWithRole('inv-contract-viewer', 'viewer'));
        $this->body(['name' => 'inv-contract-viewer', 'inventory_type' => 'static', 'content' => self::CONTENT]);
        $this->assertAnswer(403, $this->responseExample(self::INVENTORIES, 'post', '403', 'forbidden'), $this->inventories->actionCreate());
        $this->assertAnswer(403, $this->responseExample(self::INVENTORY, 'put', '403', 'forbidden'), $this->inventories->actionUpdate($id));
        $this->assertAnswer(403, $this->responseExample(self::INVENTORY, 'delete', '403', 'forbidden'), $this->inventories->actionDelete($id));
        $this->assertSame('Forbidden.', InventoryWriteService::TEAM_SCOPE_REFUSED, 'The 403 under the row lock reads as the others.');

        $put = $this->specText(['paths', self::INVENTORY, 'put', 'description']);
        foreach ([ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED, GatedInventoryLock::CHANGE_REFUSED] as $quote) {
            $this->assertStringContainsString('`' . $quote . '`', $put);
        }
        $this->assertStringContainsString('so the body of `GET` sent back with a new `name` is accepted', $put);
        $this->assertStringContainsString('`' . GatedInventoryLock::DELETE_REFUSED . '`', $this->specText(['paths', self::INVENTORY, 'delete', 'description']));
        $this->assertStringContainsString('`' . ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED . '`', $this->specText(['paths', self::INVENTORIES, 'post', 'description']));
        $this->assertSame(GatedInventoryLock::OPEN_ATTRIBUTES, ['name', 'description'], 'The texts name the fields everyone may still change.');
    }

    /**
     * Only admins hold inventory.delete among the built-in roles, as the
     * delete's description says.
     */
    public function testOnlyAdminsDeleteInventoriesAsTheSpecSays(): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        foreach (['viewer' => false, 'operator' => false, 'admin' => true] as $role => $holds) {
            $this->assertSame($holds, in_array('inventory.delete', array_keys($auth->getPermissionsByRole($role)), true), $role);
        }
        $this->assertStringContainsString(
            'Requires `inventory.delete`, which of the built-in roles only admin holds',
            $this->specText(['paths', self::INVENTORY, 'delete', 'description'])
        );
    }

    /**
     * regression: a list or an object in a field of an inventory body
     * answered 500 (a cast or a typed check failed), and the spec documented
     * no answer for it. POST and PUT answer 422 with the spec's example,
     * before anything is written.
     */
    public function testRegressionAListInAnInventoryBodyAnswers422AsTheSpecSays(): void
    {
        $inventory = $this->staticInventory($this->adminId());
        $stored = $this->storedRow((int)$inventory->id);
        $expected = $this->responseExample(self::INVENTORIES, 'post', '422', 'notAValue');

        $this->body(['name' => 'inv-contract-list', 'inventory_type' => 'static', 'content' => self::CONTENT, 'project_id' => [7]]);
        $this->assertAnswer(422, $expected, $this->inventories->actionCreate());
        $this->body(['project_id' => ['id' => 7]]);
        $this->assertAnswer(422, $expected, $this->inventories->actionUpdate((int)$inventory->id));

        $this->assertFalse(Inventory::find()->where(['name' => 'inv-contract-list'])->exists(), 'Nothing was written.');
        $this->assertSame($stored, $this->storedRow((int)$inventory->id), 'Nothing was written.');
        $this->assertSame(['error' => ['message' => sprintf(ApiBodyValues::NOT_A_VALUE, 'project_id')]], $expected);
        $this->assertSame(self::RESPONSE_REF_PREFIX . 'InventoryRejected', $this->specAt(['paths', self::INVENTORY, 'put', 'responses', '422', '$ref']));
        $this->assertStringContainsString(
            '`' . sprintf(ApiBodyValues::NOT_A_VALUE, '<field>') . '`',
            $this->specText(['components', 'responses', 'InventoryRejected', 'description'])
        );
    }

    /**
     * The 422s of the inventory's validation quote the code: a rule that
     * does not exist, a dynamic inventory with a rule but no source path,
     * job templates on it with another rule of their own (an update), and a
     * rule that is no whole number.
     */
    public function testTheInventoryValidationErrorsAnswerAsTheSpecSays(): void
    {
        $rule = $this->createApprovalRule($this->adminId());
        $unknown = (int)ApprovalRule::find()->max('id') + 1000;

        $this->body(['name' => 'inv-contract-unknown', 'inventory_type' => 'static', 'content' => self::CONTENT, 'approval_rule_id' => $unknown]);
        $this->assertAnswer(422, $this->responseExample(self::INVENTORIES, 'post', '422', 'approvalRuleNotFound'), $this->inventories->actionCreate());
        $this->body(['name' => 'inv-contract-dynamic', 'inventory_type' => 'dynamic', 'approval_rule_id' => (int)$rule->id]);
        $this->assertAnswer(422, $this->responseExample(self::INVENTORIES, 'post', '422', 'sourcePathRequired'), $this->inventories->actionCreate());
        $this->assertFalse(Inventory::find()->where(['name' => ['inv-contract-unknown', 'inv-contract-dynamic']])->exists(), 'Nothing was written.');

        $inventory = $this->staticInventory($this->adminId());
        $this->templateOn((int)$inventory->id, $this->adminId(), $this->createApprovalRule($this->adminId()), self::EXAMPLE_TEMPLATE);
        $this->body(['approval_rule_id' => (int)$rule->id]);
        $this->assertAnswer(422, $this->responseExample(self::INVENTORY, 'put', '422', 'rulesDiffer'), $this->inventories->actionUpdate((int)$inventory->id));
        $this->assertNull($this->reloaded((int)$inventory->id)->getAttribute('approval_rule_id'), 'Nothing was written.');

        $this->body(['approval_rule_id' => 'abc']);
        $notANumber = $this->inventories->actionUpdate((int)$inventory->id);
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $message = $notANumber['error']['message'] ?? null;
        $this->assertIsString($message);
        $field = $this->specText(['components', 'schemas', 'InventoryInput', 'properties', 'approval_rule_id', 'description']);
        $this->assertStringContainsString('`' . $message . '`', $field);
        $this->assertSame(
            [Inventory::APPROVAL_RULE_NOT_FOUND, Inventory::SOURCE_PATH_REQUIRED_WHEN_GATED, sprintf(InventoryRuleAssignment::RULES_DIFFER, SurveyDefinition::quote(self::EXAMPLE_TEMPLATE))],
            [
                $this->exampleMessage(self::INVENTORIES, 'post', '422', 'approvalRuleNotFound'),
                $this->exampleMessage(self::INVENTORIES, 'post', '422', 'sourcePathRequired'),
                $this->exampleMessage(self::INVENTORY, 'put', '422', 'rulesDiffer'),
            ]
        );
        foreach ([Inventory::APPROVAL_RULE_NOT_FOUND, sprintf(InventoryRuleAssignment::RULES_DIFFER, SurveyDefinition::quote(self::EXAMPLE_TEMPLATE)), ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED] as $quote) {
            $this->assertStringContainsString('`' . $quote . '`', $field);
        }
        $hidden = sprintf(InventoryRuleAssignment::RULES_DIFFER_HIDDEN, 2);
        $this->assertStringContainsString('`' . SurveyDefinition::quote(self::EXAMPLE_TEMPLATE) . ' and ' . $hidden . '`', $this->specText(['paths', self::INVENTORY, 'put', 'description']));
        $this->assertStringContainsString('`' . Inventory::SOURCE_PATH_REQUIRED_WHEN_GATED . '`', $this->specText(['components', 'schemas', 'InventoryInput', 'properties', 'source_path', 'description']));
    }

    /**
     * A save whose approval rule or project another request deletes at the
     * same moment is made once more (InventoryWriteService): POST and PUT
     * say so, with the validation errors that the second attempt answers,
     * as the model words them.
     */
    public function testTheRetryOfASaveWhoseRuleOrProjectIsDeletedMeanwhileIsDocumented(): void
    {
        $inventory = new Inventory();
        $inventory->project_id = (int)\app\models\Project::find()->max('id') + 1000;
        $inventory->setAttribute('approval_rule_id', (int)ApprovalRule::find()->max('id') + 1000);
        $this->assertFalse($inventory->validate(['project_id', 'approval_rule_id']));
        $errors = [$inventory->getFirstError('approval_rule_id'), $inventory->getFirstError('project_id')];
        $this->assertSame([Inventory::APPROVAL_RULE_NOT_FOUND, 'The selected project does not exist.'], $errors);

        foreach (['post' => self::INVENTORIES, 'put' => self::INVENTORY] as $method => $path) {
            $text = $this->specText(['paths', $path, $method, 'description']);
            $this->assertStringContainsString('(a deadlock or a lock wait timeout) is made once more, and so is a save whose approval rule or', $text, $method);
            $this->assertStringContainsString('the second attempt answers 422 `' . $errors[0] . '` or `' . $errors[1] . '`.', $text, $method);
            $this->assertStringContainsString('A second collision or refusal in a row answers 500. Nothing is written in either case.', $text, $method);
        }
    }

    /**
     * The YAML check runs for content the request brings, as InventoryInput
     * says: a body that sends the stored INI content back, with a browser's
     * CRLF line endings, saves; changed content that is no YAML mapping
     * answers 422 as InventoryRejected says.
     */
    public function testTheContentCheckIsTheDocumentedOne(): void
    {
        $content = $this->specText(['components', 'schemas', 'InventoryInput', 'properties', 'content', 'description']);
        $this->assertStringContainsString('Content the request brings is checked: on a create, when the type changes, and when it differs from the stored content in more than its line endings (CRLF, CR and LF count as the same).', $content);
        $this->assertStringContainsString('static content that the inventory brings (new, changed, or of an inventory that becomes static) and that is no YAML mapping (`Invalid YAML: …`, `Inventory must be a YAML mapping (not a scalar).`)', $this->specText(['components', 'responses', 'InventoryRejected', 'description']));
        $inventory = $this->staticInventory($this->adminId());
        Inventory::updateAll(['content' => "[local]\nlocalhost ansible_connection=local\n"], ['id' => $inventory->id]);

        $this->body(['name' => 'inv-contract-ini', 'content' => "[local]\r\nlocalhost ansible_connection=local\r\n"]);
        $saved = $this->data($this->inventories->actionUpdate((int)$inventory->id));
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame('inv-contract-ini', $saved['name']);

        $this->body(['content' => "[web]\nweb1.example.com\n"]);
        $refused = $this->inventories->actionUpdate((int)$inventory->id);
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertStringStartsWith('Invalid YAML: ', (string)($refused['error']['message'] ?? ''));
        $this->body(['content' => "web1.example.com\n"]);
        $this->assertAnswer(422, ['error' => ['message' => 'Inventory must be a YAML mapping (not a scalar).']], $this->inventories->actionUpdate((int)$inventory->id));
    }

    /**
     * An unknown inventory answers 404 in the framework's shape; one that
     * another request deleted while the change was checked, under the row
     * lock, as ErrorResponse: both with the message of the spec's examples.
     */
    public function testAMissingInventoryAnswersAsTheSpecSays(): void
    {
        foreach (['put', 'delete'] as $method) {
            $this->assertSame(self::RESPONSE_REF_PREFIX . 'InventoryNotFound', $this->specAt(['paths', self::INVENTORY, $method, 'responses', '404', '$ref']), $method);
        }
        $examples = $this->exampleBodies($this->responseOf(self::INVENTORY, 'put', '404'));
        $this->assertSame(
            [
                ['name' => 'Not Found', 'message' => sprintf(InventoryWriteService::NOT_FOUND, 7), 'code' => 0, 'status' => 404],
                ['error' => ['message' => sprintf(InventoryWriteService::NOT_FOUND, 7)]],
            ],
            $examples
        );

        $unknown = (int)Inventory::find()->max('id') + 1000;
        $missing = null;
        try {
            $this->inventories->actionUpdate($unknown);
        } catch (NotFoundHttpException $e) {
            $missing = $e;
        }
        $this->assertNotNull($missing, 'An unknown inventory answers 404.');
        $this->assertSame(sprintf(InventoryWriteService::NOT_FOUND, $unknown), $missing->getMessage());

        $this->replaceComponent('inventoryWriteService', self::writerThatLosesTheRow());
        $inventory = $this->staticInventory($this->adminId());
        $this->body(['name' => 'inv-contract-gone']);
        $this->assertAnswer(404, ['error' => ['message' => sprintf(InventoryWriteService::NOT_FOUND, (int)$inventory->id)]], $this->inventories->actionUpdate((int)$inventory->id));
        $other = $this->staticInventory($this->adminId());
        $this->assertAnswer(404, ['error' => ['message' => sprintf(InventoryWriteService::NOT_FOUND, (int)$other->id)]], $this->inventories->actionDelete((int)$other->id));
    }

    /**
     * approval_gate names the rule every launch of a job template waits
     * for, by JobTemplateGate: its own (job_template, also when the
     * inventory has the same), else its inventory's (inventory), else null;
     * approval_rule_id stays the template's own rule.
     */
    public function testTheApprovalGateOfAJobTemplateIsDocumentedAsTheApiAnswersIt(): void
    {
        $ownRule = $this->createApprovalRule($this->adminId());
        $inventoryRule = $this->createApprovalRule($this->adminId());
        $cases = [
            'own rule' => [$this->templateOn((int)$this->staticInventory($this->adminId())->id, $this->adminId(), $ownRule), $ownRule, JobTemplateGate::SOURCE_JOB_TEMPLATE, (int)$ownRule->id],
            'inventory rule' => [$this->onGatedInventory($inventoryRule), $inventoryRule, JobTemplateGate::SOURCE_INVENTORY, null],
            'the same rule' => [$this->onGatedInventory($ownRule, $ownRule), $ownRule, JobTemplateGate::SOURCE_JOB_TEMPLATE, (int)$ownRule->id],
            'no rule' => [$this->templateOn((int)$this->staticInventory($this->adminId())->id, $this->adminId()), null, null, null],
        ];
        $ctrl = new JobTemplatesController('api/v1/job-templates', $this->app());

        foreach ($cases as $case => [$template, $rule, $source, $ownRuleId]) {
            $data = $this->data($ctrl->actionView((int)$template->id));
            $this->assertMatchesSchema('JobTemplate', $data);
            $this->assertMatchesNested('JobTemplate', 'approval_gate', $data['approval_gate']);
            $expected = $rule === null ? null : ['approval_rule_id' => (int)$rule->id, 'approval_rule_name' => $rule->name, 'required_by' => $source];
            $this->assertSame($expected, $data['approval_gate'], $case);
            $this->assertSame($ownRuleId, $data['approval_rule_id'], "{$case}: approval_rule_id stays the template's own rule.");
        }
        $byInventory = $cases['inventory rule'][0];
        $this->assertSame(
            ['approval_rule_id' => (int)$inventoryRule->id, 'approval_rule_name' => $inventoryRule->name, 'required_by' => JobTemplateGate::SOURCE_INVENTORY],
            $this->listItem($ctrl->actionIndex(), (int)$byInventory->id)['approval_gate'],
            'The list names it too.'
        );
        $this->assertSame([JobTemplateGate::SOURCE_JOB_TEMPLATE, JobTemplateGate::SOURCE_INVENTORY], $this->enumOf('JobTemplateApprovalGate', 'required_by'));
        $this->assertSame(['approval_rule_id', 'approval_rule_name', 'required_by'], $this->required('JobTemplateApprovalGate'));
    }

    /**
     * A job template on an inventory with a rule: the 403s of the lock and
     * of a gated inventory, and the 422 of two different rules, as the
     * spec's examples quote them; nothing is written.
     */
    public function testAJobTemplateOnAGatedInventoryAnswersAsTheSpecSays(): void
    {
        $rule = $this->createApprovalRule($this->adminId());
        $gatedInventory = $this->gate($this->staticInventory($this->adminId(), null, self::EXAMPLE_INVENTORY), $rule);
        $template = $this->templateOn((int)$gatedInventory->id, $this->adminId());
        $ungated = $this->templateOn((int)$this->staticInventory($this->adminId())->id, $this->adminId());
        $ctrl = new JobTemplatesController('api/v1/job-templates', $this->app());
        $this->loginAs($this->userWithRole('jt-contract-operator', 'operator'));

        $create = $this->templateBody('jt-contract-new', (int)$ungated->project_id, (int)$gatedInventory->id);
        $this->body($create);
        $this->assertAnswer(403, $this->responseExample(self::JOB_TEMPLATES, 'post', '403', 'gatedInventory'), $ctrl->actionCreate());
        $this->body(['approval_rule_id' => (int)$rule->id] + $create);
        $this->assertAnswer(403, $this->responseExample(self::JOB_TEMPLATES, 'post', '403', 'approvalRule'), $ctrl->actionCreate());
        $this->assertFalse(JobTemplate::find()->where(['name' => 'jt-contract-new'])->exists(), 'Nothing was written.');
        $this->body(['playbook' => 'other.yml']);
        $this->assertAnswer(403, $this->responseExample(self::JOB_TEMPLATE, 'put', '403', 'inventoryGatedTemplate'), $ctrl->actionUpdate((int)$template->id));
        $this->body(['inventory_id' => (int)$gatedInventory->id]);
        $this->assertAnswer(403, $this->responseExample(self::JOB_TEMPLATE, 'put', '403', 'gatedInventory'), $ctrl->actionUpdate((int)$ungated->id));
        $this->assertSame(['site.yml', (int)$ungated->inventory_id], [$this->stored($template)->playbook, (int)$this->stored($ungated)->inventory_id], 'Nothing was written.');

        $this->loginAs($this->userWithRole('jt-contract-viewer', 'viewer'));
        $this->body($create);
        $this->assertAnswer(403, $this->responseExample(self::JOB_TEMPLATES, 'post', '403', 'forbidden'), $ctrl->actionCreate());

        $this->loginAs($this->admin);
        $rule->name = self::EXAMPLE_RULE;
        $this->assertTrue($rule->save(false));
        $this->body(['approval_rule_id' => (int)$this->createApprovalRule($this->adminId())->id]);
        $this->assertAnswer(422, $this->responseExample(self::JOB_TEMPLATE, 'put', '422', 'rulesDiffer'), $ctrl->actionUpdate((int)$template->id));
        $this->assertNull($this->stored($template)->approval_rule_id, 'Nothing was written.');
        $this->body(['inventory_id' => (int)Inventory::find()->max('id') + 1000] + $create);
        $this->assertAnswer(422, $this->responseExample(self::JOB_TEMPLATES, 'post', '422', 'inventoryNotFound'), $ctrl->actionCreate());

        $this->assertSame(
            [
                GatedJobTemplateLock::GATED_INVENTORY_REFUSED,
                GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY,
                sprintf(JobTemplateRuleAssignment::RULES_DIFFER, SurveyDefinition::quote(self::EXAMPLE_INVENTORY), SurveyDefinition::quote(self::EXAMPLE_RULE)),
            ],
            [
                $this->exampleMessage(self::JOB_TEMPLATE, 'put', '403', 'gatedInventory'),
                $this->exampleMessage(self::JOB_TEMPLATE, 'put', '403', 'inventoryGatedTemplate'),
                $this->exampleMessage(self::JOB_TEMPLATE, 'put', '422', 'rulesDiffer'),
            ]
        );
        $post = $this->specText(['paths', self::JOB_TEMPLATES, 'post', 'description']);
        $put = $this->specText(['paths', self::JOB_TEMPLATE, 'put', 'description']);
        $this->assertStringContainsString('`' . GatedJobTemplateLock::GATED_INVENTORY_REFUSED . '`', $post);
        $this->assertStringContainsString('`' . GatedJobTemplateLock::GATED_INVENTORY_REFUSED . '`', $put);
        $this->assertStringContainsString('`' . GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY . '`', $put);
        $rulesDiffer = '`' . sprintf(JobTemplateRuleAssignment::RULES_DIFFER, SurveyDefinition::quote(self::EXAMPLE_INVENTORY), SurveyDefinition::quote(self::EXAMPLE_RULE)) . '`';
        $this->assertStringContainsString($rulesDiffer, $post);
        $this->assertStringContainsString($rulesDiffer, $put);
        $this->assertStringContainsString($rulesDiffer, $this->specText(['components', 'schemas', 'JobTemplateInput', 'properties', 'approval_rule_id', 'description']));
    }

    /**
     * A launch of a job template gated through its inventory answers the
     * documented JobDetail: pending_approval, the inventory it was launched
     * with, and its approval request, which records that the inventory
     * required it.
     */
    public function testAnInventoryGatedLaunchAnswersAsTheSpecSays(): void
    {
        $inventory = $this->gate($this->staticInventory($this->adminId(), null, self::EXAMPLE_INVENTORY), $this->adminApprovedRule());
        $template = $this->templateOn((int)$inventory->id, $this->adminId());
        $this->loginAs($this->userWithRole('launch-contract-operator', 'operator'));
        $this->body(['job_template_id' => (int)$template->id]);

        $launched = $this->data((new JobsController('api/v1/jobs', $this->app()))->actionCreate());
        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertMatchesSchema('JobDetail', $launched);
        $this->assertSame(
            [Job::STATUS_PENDING_APPROVAL, (int)$inventory->id, self::EXAMPLE_INVENTORY],
            [$launched['status'], $launched['inventory_id'], $launched['inventory_name']]
        );
        $requestId = $launched['approval_request_id'];
        $this->assertIsInt($requestId, 'The answer names the approval request.');

        $this->loginAs($this->admin);
        $request = $this->data((new ApprovalsController('api/v1/approvals', $this->app()))->actionView($requestId));
        $this->assertMatchesSchema('ApprovalRequest', $request);
        $this->assertSame(
            [ApprovalRequest::REQUIRED_BY_INVENTORY, (int)$inventory->id, self::EXAMPLE_INVENTORY],
            [$request['required_by'], $request['inventory_id'], $request['inventory_name']]
        );
        $this->assertSame(ApprovalRequest::REQUIRED_BY_VALUES, $this->enumOf('ApprovalRequest', 'required_by'));
        $this->assertStringContainsString(
            'the answer\'s `status` is then `pending_approval`, and `approval_request_id` names the request, whose `required_by` says which rule required it',
            $this->specText(['paths', '/api/v1/jobs', 'post', 'description'])
        );
    }

    /**
     * regression: the job trigger answered {job_id} only, so its caller
     * could not tell a job that waits for approval from a queued one, and
     * the spec documented job_id only. It answers the status, as
     * TriggerJobResult and its example document it.
     */
    public function testRegressionTheJobTriggerSaysWhetherItsJobWaitsForApproval(): void
    {
        $launcher = $this->userWithRole('trigger-contract-launcher', 'operator');
        $gated = $this->templateOn((int)$this->gate($this->staticInventory($this->adminId()), $this->adminApprovedRule())->id, $this->adminId());
        $open = $this->templateOn((int)$this->staticInventory($this->adminId())->id, $this->adminId());

        $waiting = $this->fire($gated->generateTriggerToken((int)$launcher->id));
        $queued = $this->fire($open->generateTriggerToken((int)$launcher->id));

        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $waiting['status'] ?? null);
        $this->assertSame(Job::STATUS_QUEUED, $queued['status'] ?? null);
        foreach ([$waiting, $queued] as $answer) {
            $this->assertMatchesSchema('TriggerJobResult', $answer);
            $this->assertSame(array_keys($answer), $this->required('TriggerJobResult'));
        }
        $this->assertSame([['job_id' => 42, 'status' => Job::STATUS_PENDING_APPROVAL]], $this->exampleBodies($this->responseOf('/trigger/fire', 'post', '201')));
        $this->assertSame([Job::STATUS_QUEUED, Job::STATUS_PENDING_APPROVAL], $this->enumOf('TriggerJobResult', 'status'));
        $this->assertStringContainsString(
            'the answer\'s `status` is then `pending_approval`, and `queued` for a job that waits for a runner',
            $this->specText(['paths', '/trigger/fire', 'post', 'description'])
        );
    }

    /**
     * A job step whose job template's inventory has a rule is a gated job
     * step: its closed gate is routed, an empty On Failure ends the run as
     * failed; a step whose template and inventory have different rules
     * cannot run (approval_rules_differ).
     */
    public function testAJobStepGatedThroughItsInventoryIsRoutedAsTheSpecSays(): void
    {
        $rule = $this->createApprovalRule($this->adminId());
        $gated = $this->onGatedInventory($rule);
        $differ = $this->onGatedInventory($rule, $this->createApprovalRule($this->adminId()));
        $workflow = $this->createWorkflowTemplate($this->adminId());
        $first = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$gated->id);
        $second = $this->createWorkflowStep((int)$workflow->id, 2, WorkflowStep::TYPE_JOB, (int)$differ->id);

        $detail = $this->data((new WorkflowTemplatesController('api/v1/workflow-templates', $this->app()))->actionView((int)$workflow->id));
        $this->assertMatchesSchema('WorkflowTemplateDetail', $detail);
        $this->assertIsArray($detail['steps']);
        $steps = array_column($detail['steps'], null, 'id');
        $gatedStep = $steps[(int)$first->id] ?? null;
        $differStep = $steps[(int)$second->id] ?? null;
        $this->assertIsArray($gatedStep);
        $this->assertIsArray($differStep);
        $this->assertMatchesSchema('WorkflowStep', $gatedStep);
        $this->assertNull($gatedStep['cannot_run']);
        $this->assertMatchesNested('WorkflowStepRoutes', 'closed_gate', $gatedStep['routes']['closed_gate']);
        $this->assertSame(
            ['kind' => 'end', 'via' => 'on_failure', 'step_id' => null, 'position' => null, 'run_outcome' => 'fails', 'loop' => false],
            $gatedStep['routes']['closed_gate'],
            'After a closed gate an empty On Failure ends the run as failed.'
        );
        $this->assertSame(WorkflowRouteStep::PROBLEM_APPROVAL_RULES_DIFFER, $differStep['cannot_run']);
        $this->assertNotNull($differStep['routes']['closed_gate'], 'Its routes are those of a gated job step.');

        $this->assertStringContainsString(
            'a job step whose job template\'s inventory has an approval rule is a gated job step too',
            $this->specText(['components', 'schemas', 'WorkflowStepRoutes', 'properties', 'closed_gate', 'description'])
        );
        $this->assertStringContainsString(
            '`approval_rules_differ`: a job step whose job template and its inventory have different approval rules, as a launch waits for one rule only (its `routes` are those of a gated job step)',
            $this->specText(['components', 'schemas', 'WorkflowStep', 'properties', 'cannot_run', 'description'])
        );
    }

    /**
     * The tags say what a rule on an inventory does, who may change such an
     * inventory, where the hosts of a file or dynamic one come from, and how
     * the claim fails an unapproved job, in the code's words.
     */
    public function testTheTagsSayWhatARuleOnAnInventoryDoes(): void
    {
        $inventories = $this->tagText('Inventories');
        $this->assertStringContainsString(GatedInventoryLock::REPOSITORY_HOSTS_NOTE, $inventories);
        $this->assertStringContainsString(
            'Only admins change an inventory with a rule, every field but its `name` and `description`, delete it, give a job template this inventory, or change what the job templates that use it run (403 otherwise)',
            $inventories
        );

        $rules = $this->tagText('Approval Rules');
        $this->assertStringContainsString('A rule gates job templates and inventories, not hosts or playbooks', $rules);
        $this->assertStringContainsString('An approval step gates the runs of its workflow, not the job templates in it', $rules);
        $this->assertStringContainsString('The inventory, the project and the credentials of a job template with a rule keep their own permissions, the inventory only while it has no rule', $rules);
        $this->assertStringContainsString('A rule that inventories, job templates, approval steps of workflows or pending requests use cannot be deleted (409).', $rules);
        $this->assertStringNotContainsString('is planned', $rules, 'Approvals of inventories came with 2.12.0.');

        $approvals = $this->tagText('Approvals');
        foreach (ApprovalRequest::REQUIRED_BY_VALUES as $value) {
            $this->assertStringContainsString('`' . $value . '`', $approvals);
        }

        $jobs = $this->tagText('Jobs');
        $start = 'Job aborted before execution:';
        $end = 'The job did not run: no credentials were decrypted or sent to the runner.';
        $this->assertStringContainsString('from `' . $start . '` to `' . $end . '`', $jobs);
        foreach (self::claimRefusals() as $refusal) {
            $this->assertStringStartsWith($start . ' ', $refusal);
            $this->assertStringEndsWith(' ' . $end, $refusal);
        }
        $reasons = [
            JobClaimRefusedException::REASON_NOT_APPROVED,
            JobClaimRefusedException::REASON_INVENTORY_MISSING,
            JobClaimRefusedException::REASON_INVENTORY_NOT_RECORDED,
            JobClaimRefusedException::REASON_INVENTORY_EMPTY,
            JobClaimRefusedException::REASON_INVENTORY_NO_SOURCE,
        ];
        $this->assertStringContainsString('`' . implode('`, `', array_slice($reasons, 0, 4)) . '` or `' . $reasons[4] . '`', $jobs);
    }

    /**
     * The job log lines of the claim's refusals, filled as the claim fills
     * them.
     *
     * @return list<string>
     */
    private static function claimRefusals(): array
    {
        $name = SurveyDefinition::quote(self::EXAMPLE_INVENTORY);

        return [
            sprintf(JobClaimRefusedException::NOT_APPROVED, sprintf(JobClaimRefusedException::GATE_INVENTORY, 3), 4),
            sprintf(JobClaimRefusedException::INVENTORY_MISSING, 3),
            JobClaimRefusedException::INVENTORY_NOT_RECORDED,
            sprintf(JobClaimRefusedException::INVENTORY_EMPTY, 3, $name),
            sprintf(JobClaimRefusedException::INVENTORY_NO_SOURCE, 3, $name),
        ];
    }

    /**
     * An InventoryWriteService that loses the inventory's row right before
     * it writes, as another request that deletes it meanwhile.
     */
    private static function writerThatLosesTheRow(): InventoryWriteService
    {
        return new class () extends InventoryWriteService {
            public function update(Inventory $inventory, #[\SensitiveParameter] array $submitted, int $userId, string $source): bool
            {
                Inventory::deleteAll(['id' => $inventory->id]);

                return parent::update($inventory, $submitted, $userId, $source);
            }

            public function delete(Inventory $inventory, int $userId, string $source): void
            {
                Inventory::deleteAll(['id' => $inventory->id]);
                parent::delete($inventory, $userId, $source);
            }
        };
    }

    /**
     * $answer is $expected, with the HTTP status $status.
     *
     * @param array<mixed> $expected
     * @param array<mixed> $answer
     */
    private function assertAnswer(int $status, array $expected, array $answer): void
    {
        $this->assertSame($status, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $this->assertSame($expected, $answer);
    }

    /**
     * The value of the example $name of the $code response of $method
     * $path, a named response resolved.
     *
     * @return array<mixed>
     */
    private function responseExample(string $path, string $method, string $code, string $name): array
    {
        $example = $this->dig($this->responseOf($path, $method, $code), ['content', 'application/json', 'examples', $name, 'value'], "{$method} {$path} {$code} has the example {$name}.");
        $this->assertIsArray($example);

        return $example;
    }

    /**
     * The error message of the example $name of the $code response of
     * $method $path.
     */
    private function exampleMessage(string $path, string $method, string $code, string $name): string
    {
        $message = $this->dig($this->responseExample($path, $method, $code, $name), ['error', 'message'], "The example {$name} has an error message.");
        $this->assertIsString($message);

        return $message;
    }

    /**
     * The value of the request body example $name of $method $path.
     *
     * @return array<string, mixed>
     */
    private function requestExample(string $path, string $method, string $name): array
    {
        $example = $this->specAt(['paths', $path, $method, 'requestBody', 'content', 'application/json', 'examples', $name, 'value']);
        $this->assertIsArray($example);
        /** @var array<string, mixed> $example */
        return $example;
    }

    /**
     * The text of the tag $name, on one line.
     */
    private function tagText(string $name): string
    {
        $tags = array_column((array)$this->specAt(['tags']), 'description', 'name');

        return trim((string)preg_replace('/\s+/', ' ', (string)($tags[$name] ?? '')));
    }

    /**
     * A job template of a new project on a new static inventory with the
     * rule $inventoryRule, and with $ownRule of its own (null: none).
     */
    private function onGatedInventory(ApprovalRule $inventoryRule, ?ApprovalRule $ownRule = null): JobTemplate
    {
        $inventory = $this->gate($this->staticInventory($this->adminId()), $inventoryRule);

        return $this->templateOn((int)$inventory->id, $this->adminId(), $ownRule);
    }

    /**
     * A rule whose one approver is the admin, who may decide on the jobs
     * others launch.
     */
    private function adminApprovedRule(): ApprovalRule
    {
        return $this->createApprovalRule($this->adminId(), ApprovalRule::APPROVER_TYPE_USERS, (string)json_encode(['user_ids' => [$this->adminId()]]));
    }

    /**
     * The answer of POST /trigger/fire with $token in the X-Trigger-Token
     * header and an empty body: 201 and its data.
     *
     * @return array<mixed>
     */
    private function fire(string $token): array
    {
        $this->presentTriggerToken($token);
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->setRawBody('{}');
        $result = (new TriggerController('trigger', $this->app()))->actionFire();
        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(201, $result->statusCode, (string)json_encode($result->data));
        $this->assertIsArray($result->data);

        return $result->data;
    }

    /**
     * A job template body for POST /api/v1/job-templates.
     *
     * @return array<string, mixed>
     */
    private function templateBody(string $name, int $projectId, int $inventoryId): array
    {
        return [
            'name' => $name,
            'project_id' => $projectId,
            'inventory_id' => $inventoryId,
            'playbook' => 'site.yml',
            'runner_group_id' => (int)$this->createRunnerGroup($this->adminId())->id,
            'verbosity' => 0,
            'forks' => 5,
            'timeout_minutes' => 120,
            'become' => false,
            'become_method' => 'sudo',
            'become_user' => 'root',
        ];
    }

    private function stored(JobTemplate $template): JobTemplate
    {
        $stored = JobTemplate::findOne($template->id);
        $this->assertInstanceOf(JobTemplate::class, $stored);

        return $stored;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function body(array $body): void
    {
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->setBodyParams($body);
    }

    private function adminId(): int
    {
        return (int)$this->admin->id;
    }
}
