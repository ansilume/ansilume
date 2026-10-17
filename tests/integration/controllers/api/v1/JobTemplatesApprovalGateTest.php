<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\SurveyDefinition;
use app\controllers\api\v1\JobTemplatesController;
use app\models\ApprovalRule;
use app\models\JobTemplate;
use app\models\User;
use app\services\JobTemplateRuleAssignment;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\CountsQueries;
use app\tests\integration\services\InventoryGatedStepFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\web\NotFoundHttpException;
use yii\web\UnauthorizedHttpException;

/**
 * approval_gate of /api/v1/job-templates: the approval rule a template's
 * launches wait for, its own or else its inventory's
 * (JobTemplateGate::toApi()), with what requires it (required_by:
 * job_template or inventory); null when nothing gates the template. It
 * follows approval_rule_name in every answer that returns a template (the
 * list, the detail, a create and an update), for every caller who may view
 * the template. approval_rule_id and approval_rule_name stay the template's
 * own rule. The list loads the gate's relations for its page at once.
 */
class JobTemplatesApprovalGateTest extends WebControllerTestCase
{
    use CountsQueries;
    use GateFieldsApiCalls;
    use InventoryGatedStepFixtures;
    use TeamScopeFixtures;

    /** An ID that no template, rule or inventory has. */
    private const UNKNOWN_ID = 987654321;

    private User $admin;

    /** @var array{a: ApprovalRule, b: ApprovalRule} */
    private array $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('gate-api-admin', 'admin');
        $adminId = (int)$this->admin->id;
        $this->rules = [
            'a' => $this->namedRule($adminId, 'Change "board" <b>&'),
            'b' => $this->namedRule($adminId, 'Second sign-off'),
        ];
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null, 2: array{0: string, 1: string}|null}>
     *     the template's own rule and its inventory's ('a', 'b' or none), and the gate the API answers:
     *     the rule and required_by
     */
    public static function gateProvider(): array
    {
        return [
            'no rule' => [null, null, null],
            'its own rule' => ['a', null, ['a', 'job_template']],
            'its inventory\'s rule' => [null, 'a', ['a', 'inventory']],
            'the same rule on both' => ['a', 'a', ['a', 'job_template']],
            'two different rules' => ['a', 'b', ['a', 'job_template']],
        ];
    }

    /**
     * The detail and the list name the rule that gates the template, the
     * template's own first, right after approval_rule_name; the names as
     * stored (JSON needs no HTML escaping). approval_rule_id and
     * approval_rule_name stay the template's own rule.
     *
     * @dataProvider gateProvider
     * @param array{0: string, 1: string}|null $gate
     */
    public function testApprovalGateNamesTheRuleThatGatesTheTemplate(?string $own, ?string $inventory, ?array $gate): void
    {
        $template = $this->templateOnGatedInventory((int)$this->admin->id, $this->ruleIdOf($inventory), $this->ruleIdOf($own));
        $this->bearerOf($this->admin);

        $answers = [
            'view' => $this->answerData($this->callApi($this->controller(), 'view', 'GET', ['id' => (int)$template->id])),
            'index' => $this->answerItems($this->callApi($this->controller(), 'index', 'GET'))[(int)$template->id] ?? null,
        ];

        $expected = $gate === null ? null : $this->gate($this->rules[$gate[0]], $gate[1]);
        foreach ($answers as $action => $item) {
            $this->assertIsArray($item, "{$action}: the template is answered");
            $this->assertSame($expected, $item['approval_gate'], $action);
            $this->assertSame($this->ruleIdOf($own), $item['approval_rule_id'], "{$action}: approval_rule_id stays the template's own rule");
            $this->assertSame($own === null ? null : $this->rules[$own]->name, $item['approval_rule_name'], $action);
            $keys = array_keys($item);
            $after = array_search('approval_rule_name', $keys, true);
            $this->assertIsInt($after);
            $this->assertSame('approval_gate', $keys[$after + 1], "{$action}: approval_gate follows approval_rule_name");
        }
    }

    /**
     * @return array<string, array{0: bool, 1: string}> whether the template's own rule (else its
     *     inventory's) is the one whose row is gone, and required_by
     */
    public static function goneRuleProvider(): array
    {
        return [
            'its own rule' => [true, 'job_template'],
            'its inventory\'s rule' => [false, 'inventory'],
        ];
    }

    /**
     * A gate whose rule row is gone (written without the foreign key, as
     * an old schema allowed) keeps the rule's ID and has no name: the
     * launch refuses it (RULE_MISSING, INVENTORY_RULE_MISSING).
     *
     * @dataProvider goneRuleProvider
     */
    public function testAGateWhoseRuleIsGoneKeepsItsIdWithoutAName(bool $own, string $requiredBy): void
    {
        $ruleId = (int)$this->rules['a']->id;
        $template = $this->templateOnGatedInventory((int)$this->admin->id, $own ? null : $ruleId, $own ? $ruleId : null);
        $this->withoutForeignKeyChecks(static fn (): int => ApprovalRule::deleteAll(['id' => $ruleId]));
        $this->bearerOf($this->admin);

        $data = $this->answerData($this->callApi($this->controller(), 'view', 'GET', ['id' => (int)$template->id]));

        $this->assertSame(['approval_rule_id' => $ruleId, 'approval_rule_name' => null, 'required_by' => $requiredBy], $data['approval_gate']);
    }

    /**
     * POST and PUT answer the gate the save left: an inventory with a rule,
     * then the same rule as the template's own, then another inventory
     * whose rule is another one (the relation read after the save, not the
     * one before), then an inventory without a rule.
     */
    public function testCreateAndUpdateAnswerTheGateTheSaveLeft(): void
    {
        $adminId = (int)$this->admin->id;
        $first = $this->gatedInventory($adminId, (int)$this->rules['a']->id);
        $second = $this->gatedInventory($adminId, (int)$this->rules['b']->id);
        $plain = $this->createInventory($adminId);
        $this->bearerOf($this->admin);

        $created = $this->answerData($this->callApi($this->controller(), 'create', 'POST', [], $this->templateBody((int)$first->id)), 201);
        $id = (int)$created['id'];
        $this->assertSame($this->gate($this->rules['a'], 'inventory'), $created['approval_gate']);
        $this->assertNull($created['approval_rule_id']);

        $own = $this->answerData($this->callApi($this->controller(), 'update', 'PUT', ['id' => $id], ['approval_rule_id' => (int)$this->rules['a']->id]));
        $this->assertSame($this->gate($this->rules['a'], 'job_template'), $own['approval_gate']);
        $this->assertSame((int)$this->rules['a']->id, $own['approval_rule_id']);

        $moved = $this->answerData($this->callApi($this->controller(), 'update', 'PUT', ['id' => $id], ['approval_rule_id' => null, 'inventory_id' => (int)$second->id]));
        $this->assertSame((int)$second->id, $moved['inventory_id']);
        $this->assertSame($this->gate($this->rules['b'], 'inventory'), $moved['approval_gate']);
        $this->assertNull($moved['approval_rule_id']);

        $ungated = $this->answerData($this->callApi($this->controller(), 'update', 'PUT', ['id' => $id], ['inventory_id' => (int)$plain->id]));
        $this->assertSame((int)$plain->id, $ungated['inventory_id']);
        $this->assertNull($ungated['approval_gate']);
    }

    /**
     * An operator may rename a template gated through its inventory (the
     * lock leaves the name open), and the answer names its gate; a
     * template they create on an inventory without a rule has none.
     */
    public function testAnOperatorsAnswersNameTheGateToo(): void
    {
        $adminId = (int)$this->admin->id;
        $template = $this->templateOnGatedInventory($adminId, (int)$this->rules['a']->id);
        $operator = $this->createUserWithRole('gate-api-operator', 'operator');
        $this->bearerOf($operator);

        $renamed = $this->answerData($this->callApi($this->controller(), 'update', 'PUT', ['id' => (int)$template->id], ['name' => 'gate-api-renamed-' . uniqid()]));
        $created = $this->answerData($this->callApi($this->controller(), 'create', 'POST', [], $this->templateBody((int)$this->createInventory($adminId)->id)), 201);

        $this->assertSame($this->gate($this->rules['a'], 'inventory'), $renamed['approval_gate']);
        $this->assertNull($created['approval_gate']);
    }

    /**
     * Every caller who may view a template gets its gate, a viewer
     * included, as approval_rule_id.
     */
    public function testAViewerGetsTheGate(): void
    {
        $template = $this->templateOnGatedInventory((int)$this->admin->id, (int)$this->rules['a']->id);
        $this->bearerOf($this->createUserWithRole('gate-api-viewer', 'viewer'));

        $viewed = $this->answerData($this->callApi($this->controller(), 'view', 'GET', ['id' => (int)$template->id]));
        $listed = $this->answerItems($this->callApi($this->controller(), 'index', 'GET'))[(int)$template->id] ?? null;

        $this->assertSame($this->gate($this->rules['a'], 'inventory'), $viewed['approval_gate']);
        $this->assertIsArray($listed);
        $this->assertSame($this->gate($this->rules['a'], 'inventory'), $listed['approval_gate']);
    }

    /**
     * A superadmin without any role gets the gate from every answer.
     */
    public function testARolelessSuperadminGetsTheGateFromEveryAnswer(): void
    {
        $superadmin = $this->rolelessSuperadmin();
        $inventory = $this->gatedInventory((int)$superadmin->id, (int)$this->rules['a']->id);
        $this->bearerOf($superadmin);

        $created = $this->answerData($this->callApi($this->controller(), 'create', 'POST', [], $this->templateBody((int)$inventory->id)), 201);
        $id = (int)$created['id'];
        $updated = $this->answerData($this->callApi($this->controller(), 'update', 'PUT', ['id' => $id], ['approval_rule_id' => (int)$this->rules['a']->id]));
        $viewed = $this->answerData($this->callApi($this->controller(), 'view', 'GET', ['id' => $id]));
        $listed = $this->answerItems($this->callApi($this->controller(), 'index', 'GET'))[$id] ?? null;

        $this->assertSame($this->gate($this->rules['a'], 'inventory'), $created['approval_gate']);
        $this->assertSame($this->gate($this->rules['a'], 'job_template'), $updated['approval_gate']);
        $this->assertSame($this->gate($this->rules['a'], 'job_template'), $viewed['approval_gate']);
        $this->assertIsArray($listed);
        $this->assertSame($this->gate($this->rules['a'], 'job_template'), $listed['approval_gate']);
    }

    /**
     * The list loads what the gates read for its whole page: more
     * templates, gated by their own rules and by the rules of their
     * inventories, cost no more queries.
     */
    public function testTheListCostsTheSameWhateverGatesItsTemplates(): void
    {
        $this->loginAs($this->admin);
        $controller = $this->controller();
        $this->gatedTemplates(1);
        $fewRows = count($controller->actionIndex()['data']);
        $few = $this->queriesOf(fn () => $controller->actionIndex());

        $templates = $this->gatedTemplates(5);
        $many = $this->queriesOf(fn () => $controller->actionIndex());
        $listed = array_column($controller->actionIndex()['data'], null, 'id');

        $this->assertCount($fewRows + 10, $listed, 'the ten new templates are listed');
        $this->assertSame($few, $many, '2 and 12 gated templates cost the same');
        foreach ($templates as [$template, $rule, $requiredBy]) {
            $this->assertSame($this->gate($rule, $requiredBy), $listed[$template->id]['approval_gate']);
        }
    }

    // -- What the endpoints answer otherwise ----------------------------------

    /**
     * @return array<string, array{0: string, 1: string}> the action and its verb
     */
    public static function endpointProvider(): array
    {
        return [
            'GET list' => ['index', 'GET'],
            'GET one' => ['view', 'GET'],
            'POST' => ['create', 'POST'],
            'PUT' => ['update', 'PUT'],
        ];
    }

    /**
     * @dataProvider endpointProvider
     */
    public function testWithoutAValidTokenTheAnswerIs401(string $action, string $verb): void
    {
        $template = $this->templateOnGatedInventory((int)$this->admin->id, (int)$this->rules['a']->id);
        $params = $action === 'index' || $action === 'create' ? [] : ['id' => (int)$template->id];

        $this->bearerOfNobody(false);
        $missing = $this->failedApiCall($this->controller(), $action, $verb, $params, ['name' => 'gate-api-401']);
        $this->bearerOfNobody(true);
        $invalid = $this->failedApiCall($this->controller(), $action, $verb, $params, ['name' => 'gate-api-401']);

        $this->assertInstanceOf(UnauthorizedHttpException::class, $missing);
        $this->assertSame([401, 'Bearer token required.'], [$missing->statusCode, $missing->getMessage()]);
        $this->assertInstanceOf(UnauthorizedHttpException::class, $invalid);
        $this->assertSame([401, 'Invalid or expired token.'], [$invalid->statusCode, $invalid->getMessage()]);
        $this->assertFalse(JobTemplate::find()->where(['name' => 'gate-api-401'])->exists(), 'nothing is written');
    }

    /**
     * A user without the endpoint's permission gets 403 before anything is
     * read or written.
     *
     * @dataProvider endpointProvider
     */
    public function testWithoutThePermissionTheAnswerIs403(string $action, string $verb): void
    {
        $template = $this->templateOnGatedInventory((int)$this->admin->id, (int)$this->rules['a']->id);
        $params = $action === 'index' || $action === 'create' ? [] : ['id' => (int)$template->id];
        $this->bearerOf($this->createUser('gate-api-nobody'));

        $answer = $this->callApi($this->controller(), $action, $verb, $params, ['name' => 'gate-api-403']);

        $this->assertApiRefusal(403, 'Forbidden.', $answer);
        $this->assertFalse(JobTemplate::find()->where(['name' => 'gate-api-403'])->exists(), 'nothing is written');
    }

    /**
     * A template of a project that only another team has is 403, whatever
     * gates it: its gate is not answered.
     */
    public function testATemplateOfAnotherTeamsProjectIs403(): void
    {
        $s = $this->teamScope();
        $this->giveInventoryRule((int)$s['foreign']->inventory_id, (int)$this->rules['a']->id);
        $this->bearerOf($s['member']);

        $answer = $this->callApi($this->controller(), 'view', 'GET', ['id' => (int)$s['foreign']->id]);
        $listed = $this->answerItems($this->callApi($this->controller(), 'index', 'GET'));

        $this->assertApiRefusal(403, 'Forbidden.', $answer);
        $this->assertArrayNotHasKey((int)$s['foreign']->id, $listed, 'the list leaves it out');
        $this->assertArrayHasKey((int)$s['own']->id, $listed);
        $this->assertSame($this->gate($this->rules['a'], 'inventory'), $listed[(int)$s['own']->id]['approval_gate'], 'own shares the inventory');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function oneTemplateProvider(): array
    {
        return ['GET one' => ['view', 'GET'], 'PUT' => ['update', 'PUT']];
    }

    /**
     * @dataProvider oneTemplateProvider
     */
    public function testAnUnknownTemplateIs404(string $action, string $verb): void
    {
        $this->bearerOf($this->admin);

        $error = $this->failedApiCall($this->controller(), $action, $verb, ['id' => self::UNKNOWN_ID], ['name' => 'gate-api-404']);

        $this->assertInstanceOf(NotFoundHttpException::class, $error);
        $this->assertSame('Template #' . self::UNKNOWN_ID . ' not found.', $error->getMessage());
    }

    /**
     * A save that the template's rules refuse answers 422 with the reason,
     * no template and no gate, and writes nothing: an own rule that differs
     * from the inventory's (RULES_DIFFER), and a template without a name.
     */
    public function testARefusedSaveAnswers422AndWritesNothing(): void
    {
        $inventory = $this->gatedInventory((int)$this->admin->id, (int)$this->rules['a']->id);
        $template = $this->templateOnGatedInventory((int)$this->admin->id, (int)$this->rules['a']->id);
        $this->bearerOf($this->admin);
        $templates = (int)JobTemplate::find()->count();

        $differs = $this->callApi($this->controller(), 'update', 'PUT', ['id' => (int)$template->id], ['approval_rule_id' => (int)$this->rules['b']->id]);
        $nameless = $this->callApi($this->controller(), 'create', 'POST', [], ['name' => ''] + $this->templateBody((int)$inventory->id));

        $template->refresh();
        $this->assertNull($template->approval_rule_id, 'the rule is not assigned');
        $this->assertSame($templates, (int)JobTemplate::find()->count(), 'no template is created');
        $this->assertApiRefusal(422, sprintf(
            JobTemplateRuleAssignment::RULES_DIFFER,
            SurveyDefinition::quote((string)$template->inventory?->name),
            SurveyDefinition::quote($this->rules['a']->name)
        ), $differs);
        $this->assertApiRefusal(422, 'Name cannot be blank.', $nameless);
    }

    // -- Helpers --------------------------------------------------------------

    private function controller(): JobTemplatesController
    {
        return new JobTemplatesController('api/v1/job-templates', \Yii::$app);
    }

    private function namedRule(int $createdBy, string $name): ApprovalRule
    {
        $rule = $this->createApprovalRule($createdBy);
        $rule->name = $name . ' ' . uniqid();
        $rule->save(false);

        return $rule;
    }

    private function ruleIdOf(?string $key): ?int
    {
        return $key === null ? null : (int)$this->rules[$key]->id;
    }

    /**
     * approval_gate as the API answers it for $rule and $requiredBy.
     *
     * @return array{approval_rule_id: int, approval_rule_name: string, required_by: string}
     */
    private function gate(ApprovalRule $rule, string $requiredBy): array
    {
        return ['approval_rule_id' => (int)$rule->id, 'approval_rule_name' => $rule->name, 'required_by' => $requiredBy];
    }

    /**
     * A new template on the inventory #$inventoryId, in a project and with a
     * runner group of its own (no team restricts the project).
     *
     * @return array<string, mixed>
     */
    private function templateBody(int $inventoryId): array
    {
        $adminId = (int)$this->admin->id;

        return [
            'name' => 'gate-api-' . uniqid('', true),
            'project_id' => (int)$this->createProject($adminId)->id,
            'inventory_id' => $inventoryId,
            'runner_group_id' => (int)$this->createRunnerGroup($adminId)->id,
            'playbook' => 'site.yml',
        ];
    }

    /**
     * $pairs templates gated by a rule of their own and $pairs gated by the
     * rule of their inventory, each with a new rule and an inventory of its
     * own; each with its rule and required_by.
     *
     * @return list<array{0: JobTemplate, 1: ApprovalRule, 2: string}>
     */
    private function gatedTemplates(int $pairs): array
    {
        $adminId = (int)$this->admin->id;
        $templates = [];
        for ($i = 0; $i < $pairs; $i++) {
            $own = $this->namedRule($adminId, 'own-' . $i);
            $templates[] = [$this->templateOnGatedInventory($adminId, null, (int)$own->id), $own, 'job_template'];
            $ofInventory = $this->namedRule($adminId, 'inventory-' . $i);
            $templates[] = [$this->templateOnGatedInventory($adminId, (int)$ofInventory->id), $ofInventory, 'inventory'];
        }

        return $templates;
    }

    /**
     * Run $action with foreign key checks off, to leave a reference to a
     * row that is gone.
     */
    private function withoutForeignKeyChecks(callable $action): void
    {
        $db = \Yii::$app->db;
        $db->createCommand('SET FOREIGN_KEY_CHECKS = 0')->execute();
        try {
            $action();
        } finally {
            $db->createCommand('SET FOREIGN_KEY_CHECKS = 1')->execute();
        }
    }
}
