<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\SurveyDefinition;
use app\controllers\api\v1\JobTemplatesController;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\User;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\GatedJobTemplateLock;
use app\services\JobTemplateRuleAssignment;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\TemplateInventoryLockFixtures;
use app\tests\integration\TeamScopeFixtures;

/**
 * The template lock for job templates gated through their inventory, in the
 * REST API: for users without approval-rule.update, PUT
 * /api/v1/job-templates/{id} of such a template changes name and
 * description only, anything else answers 403
 * {"error": {"message": GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY}};
 * POST and PUT that give a template an inventory with a rule answer 403 with
 * GATED_INVENTORY_REFUSED; nothing is written. An inventory the caller may
 * not see answers 422 "The selected inventory does not exist.", and an own
 * rule that differs from the inventory's 422 with
 * JobTemplateRuleAssignment::RULES_DIFFER. ASSIGN_REFUSED still comes first.
 */
class JobTemplatesControllerInventoryLockTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use TemplateInventoryLockFixtures;

    private JobTemplatesController $ctrl;
    private User $admin;
    private User $operator;
    private ApprovalRule $rule;
    private ApprovalRule $otherRule;
    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotNull(\Yii::$app);
        $this->ctrl = new JobTemplatesController('api/v1/job-templates', \Yii::$app);
        $this->admin = $this->createUserWithRole('ail-admin', 'admin');
        $this->operator = $this->createUserWithRole('ail-operator', 'operator');
        $this->rule = $this->createApprovalRule((int)$this->admin->id);
        $this->otherRule = $this->createApprovalRule((int)$this->admin->id);
        $this->template = $this->inventoryGated((int)$this->admin->id, $this->rule);
    }

    // -- Regressions: what operators could do -------------------------------------------

    /**
     * Regression: PUT by an operator changed what a job template gated
     * through its inventory runs (200).
     */
    public function testRegressionAnOperatorChangedWhatATemplateGatedThroughItsInventoryRuns(): void
    {
        $audits = $this->templateAuditCount();
        $this->loginAs($this->operator);
        $this->setBody(['name' => 'ail-forged-' . uniqid('', true), 'playbook' => 'other.yml']);

        $result = $this->ctrl->actionUpdate((int)$this->template->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $stored = $this->reloaded($this->template);
        $this->assertSame('site.yml', $stored->playbook, 'nothing is written');
        $this->assertSame($this->template->name, $stored->name, 'not even the name');
        $this->assertSame($audits, $this->templateAuditCount());
        $this->assertSame(['error' => ['message' => GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY]], $result);
    }

    /**
     * Regression: POST by an operator created a job template on an inventory
     * with an approval rule (201). On a default install (no inventory
     * filter) too: a 403, never an SQL error.
     */
    public function testRegressionAnOperatorGaveANewTemplateAGatedInventory(): void
    {
        $this->assertDefaultInstall((int)$this->operator->id);
        $gated = $this->inventoryWithRule((int)$this->admin->id, $this->rule);
        $name = 'ail-created-' . uniqid('', true);
        $this->loginAs($this->operator);
        $this->setBody($this->createBody($name, (int)$this->template->project_id, (int)$gated->id));

        $result = $this->ctrl->actionCreate();

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertFalse(JobTemplate::find()->where(['name' => $name])->exists(), 'nothing is written');
        $this->assertSame(['error' => ['message' => GatedJobTemplateLock::GATED_INVENTORY_REFUSED]], $result);
    }

    /**
     * Regression: PUT by an operator re-pointed a job template without a
     * rule to an inventory with one (200). On a default install too.
     */
    public function testRegressionAnOperatorRepointedAnUngatedTemplateToAGatedInventory(): void
    {
        $this->assertDefaultInstall((int)$this->operator->id);
        $template = $this->ungatedTemplate((int)$this->admin->id);
        $gated = $this->inventoryWithRule((int)$this->admin->id, $this->rule);
        $this->loginAs($this->operator);
        $this->setBody(['inventory_id' => (int)$gated->id]);

        $result = $this->ctrl->actionUpdate((int)$template->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame((int)$template->inventory_id, (int)$this->reloaded($template)->inventory_id, 'nothing is written');
        $this->assertSame(['error' => ['message' => GatedJobTemplateLock::GATED_INVENTORY_REFUSED]], $result);
    }

    /**
     * Regression: PUT by an operator ungated a job template by re-pointing
     * it from its gated inventory to one without a rule (200).
     */
    public function testRegressionAnOperatorUngatedATemplateByRepointingIt(): void
    {
        $this->loginAs($this->operator);
        $this->setBody(['inventory_id' => (int)$this->createInventory((int)$this->admin->id)->id]);

        $result = $this->ctrl->actionUpdate((int)$this->template->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame((int)$this->template->inventory_id, (int)$this->reloaded($this->template)->inventory_id, 'nothing is written');
        $this->assertSame(['error' => ['message' => GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY]], $result);
    }

    // -- Regressions: one rule per launch ------------------------------------------------

    /**
     * Regression: PUT by an admin gave a job template a rule of its own
     * other than its inventory's (200).
     */
    public function testRegressionAnAdminGaveATemplateARuleOtherThanItsInventorys(): void
    {
        $this->loginAs($this->admin);
        $this->setBody(['approval_rule_id' => (int)$this->otherRule->id]);

        $result = $this->ctrl->actionUpdate((int)$this->template->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertNull($this->reloaded($this->template)->approval_rule_id, 'nothing is written');
        $this->assertSame(['error' => ['message' => $this->rulesDiffer((int)$this->template->inventory_id, $this->rule)]], $result);
    }

    /**
     * Regression: PUT by an admin re-pointed a job template with a rule of
     * its own to an inventory with another rule (200).
     */
    public function testRegressionAnAdminRepointedATemplateToAnInventoryWithAnotherRule(): void
    {
        $template = $this->withOwnRule($this->ungatedTemplate((int)$this->admin->id), $this->otherRule);
        $gated = $this->inventoryWithRule((int)$this->admin->id, $this->rule);
        $this->loginAs($this->admin);
        $this->setBody(['inventory_id' => (int)$gated->id]);

        $result = $this->ctrl->actionUpdate((int)$template->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame((int)$template->inventory_id, (int)$this->reloaded($template)->inventory_id, 'nothing is written');
        $this->assertSame(['error' => ['message' => $this->rulesDiffer((int)$gated->id, $this->rule)]], $result);
    }

    public function testAnAdminCreatesATemplateWithItsInventorysRuleButNotAnother(): void
    {
        $gated = $this->inventoryWithRule((int)$this->admin->id, $this->rule);
        $this->loginAs($this->admin);
        $name = 'ail-admin-' . uniqid('', true);
        $body = $this->createBody($name, (int)$this->template->project_id, (int)$gated->id);

        $this->setBody($body + ['approval_rule_id' => (int)$this->otherRule->id]);
        $refused = $this->ctrl->actionCreate();
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $this->rulesDiffer((int)$gated->id, $this->rule)]], $refused);
        $this->assertFalse(JobTemplate::find()->where(['name' => $name])->exists());

        $this->setBody($body + ['approval_rule_id' => (int)$this->rule->id]);
        $data = $this->data($this->ctrl->actionCreate());
        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame((int)$this->rule->id, $data['approval_rule_id']);
        $this->assertSame((int)$gated->id, $data['inventory_id']);
    }

    // -- What stays open -----------------------------------------------------------------

    public function testTheBodyOfAGetSentBackWithAnotherNameIsARename(): void
    {
        $this->loginAs($this->operator);
        $body = $this->data($this->ctrl->actionView((int)$this->template->id));
        $name = 'ail-get-put-' . uniqid('', true);
        $body['name'] = $name;
        $this->setBody($body);

        $data = $this->data($this->ctrl->actionUpdate((int)$this->template->id));

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($data));
        $this->assertSame($name, $data['name']);
        $this->assertSame('site.yml', $this->reloaded($this->template)->playbook);
    }

    public function testAnAdminChangesWhatATemplateGatedThroughItsInventoryRuns(): void
    {
        $other = $this->createInventory((int)$this->admin->id);
        $this->loginAs($this->admin);
        $this->setBody(['playbook' => 'admin.yml', 'inventory_id' => (int)$other->id]);

        $data = $this->data($this->ctrl->actionUpdate((int)$this->template->id));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame('admin.yml', $data['playbook']);
        $this->assertSame((int)$other->id, $data['inventory_id']);
    }

    /**
     * A template whose stored pair differs (its own rule and another one of
     * its inventory) can still be renamed, also with the GET body sent back.
     */
    public function testATemplateWhoseStoredPairDiffersCanStillBeRenamed(): void
    {
        $template = $this->withOwnRule($this->template, $this->otherRule);
        foreach (['admin' => $this->admin, 'operator' => $this->operator] as $role => $user) {
            $this->loginAs($user);
            $body = $this->data($this->ctrl->actionView((int)$template->id));
            $body['name'] = 'ail-pair-' . $role . '-' . uniqid('', true);
            $this->setBody($body);

            $data = $this->data($this->ctrl->actionUpdate((int)$template->id));

            $this->assertSame(200, \Yii::$app->response->statusCode, $role);
            $this->assertSame($body['name'], $data['name'], $role);
        }
    }

    public function testARuleChangeIsStillRefusedFirst(): void
    {
        $gated = $this->inventoryWithRule((int)$this->admin->id, $this->rule);
        $this->loginAs($this->operator);

        $this->setBody($this->createBody('ail-rule-' . uniqid('', true), (int)$this->template->project_id, (int)$gated->id) + ['approval_rule_id' => (int)$this->rule->id]);
        $this->assertSame(['error' => ['message' => ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED]], $this->ctrl->actionCreate());
        $this->assertSame(403, \Yii::$app->response->statusCode);

        $template = $this->ungatedTemplate((int)$this->admin->id);
        $this->setBody(['inventory_id' => (int)$gated->id, 'approval_rule_id' => (int)$this->rule->id]);
        $this->assertSame(['error' => ['message' => ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED]], $this->ctrl->actionUpdate((int)$template->id));
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    // -- Inventories the caller may not see ---------------------------------------------

    /**
     * No existence oracle: an inventory of a project the caller's teams do
     * not have answers 422 "does not exist" on POST and PUT, whether it has
     * a rule or not; one of a project the team only views is refused.
     */
    public function testAHiddenGatedInventoryDoesNotExistAndAViewedOneIsRefused(): void
    {
        $scope = $this->teamScope();
        $hidden = $this->inventoryWithRule((int)$scope['admin']->id, $this->rule, (int)$scope['foreign']->project_id);
        $viewed = $this->inventoryWithRule((int)$scope['admin']->id, $this->rule, (int)$scope['viewed']->project_id);
        $own = $scope['own'];
        $notFound = ['error' => ['message' => 'The selected inventory does not exist.']];
        $this->loginAs($scope['member']);

        $this->setBody(['inventory_id' => (int)$hidden->id]);
        $this->assertSame($notFound, $this->ctrl->actionUpdate((int)$own->id), 'PUT');
        $this->assertSame(422, \Yii::$app->response->statusCode);

        $name = 'ail-hidden-' . uniqid('', true);
        $this->setBody($this->createBody($name, (int)$own->project_id, (int)$hidden->id));
        $this->assertSame($notFound, $this->ctrl->actionCreate(), 'POST');
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertFalse(JobTemplate::find()->where(['name' => $name])->exists());

        $this->setBody(['inventory_id' => (int)$viewed->id]);
        $this->assertSame(['error' => ['message' => GatedJobTemplateLock::GATED_INVENTORY_REFUSED]], $this->ctrl->actionUpdate((int)$own->id));
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame((int)$own->inventory_id, (int)$this->reloaded($own)->inventory_id);
    }

    /**
     * RULES_DIFFER names the inventory and its rule, so it must never answer
     * for an inventory the caller may not see: a rule assigner whose teams
     * do not have its project (a custom role, not the admin role) gets "does
     * not exist" for a re-point to it, and nothing of its name.
     */
    public function testRulesDifferNeverNamesAnInventoryTheCallerMayNotSee(): void
    {
        $scope = $this->teamScope();
        $assigner = $this->customAssigner();
        $team = $this->createTeam((int)$scope['admin']->id);
        $this->addTeamMember((int)$team->id, (int)$assigner->id);
        $this->createTeamProject((int)$team->id, (int)$scope['own']->project_id);
        $hidden = $this->inventoryWithRule((int)$scope['admin']->id, $this->rule, (int)$scope['foreign']->project_id);
        Inventory::updateAll(['name' => 'ail-hidden-inventory-name'], ['id' => $hidden->id]);
        $template = $this->withOwnRule($scope['own'], $this->otherRule);
        $this->loginAs($assigner);
        $this->setBody(['inventory_id' => (int)$hidden->id]);

        $result = $this->ctrl->actionUpdate((int)$template->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'The selected inventory does not exist.']], $result);
        $this->assertStringNotContainsString('ail-hidden-inventory-name', (string)json_encode($result));
        $this->assertSame((int)$scope['own']->inventory_id, (int)$this->reloaded($template)->inventory_id);
    }

    // -- helpers ------------------------------------------------------------------------

    /**
     * @return array<string, int|string|bool>
     */
    private function createBody(string $name, int $projectId, int $inventoryId): array
    {
        return [
            'name' => $name,
            'project_id' => $projectId,
            'inventory_id' => $inventoryId,
            'playbook' => 'site.yml',
            'runner_group_id' => (int)$this->createRunnerGroup((int)$this->admin->id)->id,
            'verbosity' => 0,
            'forks' => 5,
            'timeout_minutes' => 120,
            'become' => false,
            'become_method' => 'sudo',
            'become_user' => 'root',
        ];
    }

    private function rulesDiffer(int $inventoryId, ApprovalRule $rule): string
    {
        $inventory = Inventory::findOne($inventoryId);
        $this->assertNotNull($inventory);

        return sprintf(
            JobTemplateRuleAssignment::RULES_DIFFER,
            SurveyDefinition::quote((string)$inventory->name),
            SurveyDefinition::quote((string)$rule->name)
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
        \Yii::$app->response->statusCode = 200;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function data(array $result): array
    {
        $this->assertArrayHasKey('data', $result, (string)json_encode($result));
        $this->assertIsArray($result['data']);

        return $result['data'];
    }
}
