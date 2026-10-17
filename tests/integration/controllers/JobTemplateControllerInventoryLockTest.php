<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\SurveyDefinition;
use app\controllers\JobTemplateController;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\User;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\GatedJobTemplateLock;
use app\services\JobTemplateRuleAssignment;
use app\services\LintService;
use app\tests\integration\services\TemplateInventoryLockFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\web\AssetManager;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\View;

/**
 * The template lock for job templates gated through their inventory, in the
 * web UI: an operator renames such a template through the locked form; any
 * other change is a 403 with GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY,
 * and giving a template, a new one too, an inventory with a rule a 403 with
 * GATED_INVENTORY_REFUSED (refusalOf()), with nothing saved. An inventory the
 * operator may not see shows the form again ("does not exist"), never a 403.
 * An admin's own rule that differs from the inventory's shows the form again
 * with JobTemplateRuleAssignment::RULES_DIFFER.
 */
class JobTemplateControllerInventoryLockTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use TemplateInventoryLockFixtures;

    /** @var array<string, mixed> component definitions the test replaced */
    private array $replaced = [];

    private User $admin;
    private User $operator;
    private ApprovalRule $rule;
    private ApprovalRule $otherRule;
    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->replaced['lintService'] = \Yii::$app->getComponents(true)['lintService'] ?? null;
        \Yii::$app->set('lintService', new class () extends LintService {
            public function runForTemplate(JobTemplate $template): void
            {
            }
        });
        $this->admin = $this->createUserWithRole('wil-admin', 'admin');
        $this->operator = $this->createUserWithRole('wil-operator', 'operator');
        $this->rule = $this->createApprovalRule((int)$this->admin->id);
        $this->otherRule = $this->createApprovalRule((int)$this->admin->id);
        $this->template = $this->inventoryGated((int)$this->admin->id, $this->rule);
    }

    protected function tearDown(): void
    {
        foreach ($this->replaced as $id => $definition) {
            \Yii::$app->set($id, $definition);
        }
        $this->replaced = [];
        parent::tearDown();
    }

    // -- Regressions: what operators could do -------------------------------------------

    /**
     * Regression: an operator changed what a job template gated through its
     * inventory runs, through the web form; the change was saved.
     */
    public function testRegressionAnOperatorChangedWhatATemplateGatedThroughItsInventoryRuns(): void
    {
        $audits = $this->templateAuditCount();
        $this->loginAs($this->operator);
        $this->setPost(['JobTemplate' => ['name' => 'forged-' . uniqid('', true), 'playbook' => 'other.yml'], JobTemplateController::KEEP_CREDENTIALS => '1']);

        $refusal = $this->refusalOf(fn (): mixed => $this->makeController()->actionUpdate((int)$this->template->id));

        $this->assertNotNull($refusal, 'a 403');
        $stored = $this->reloaded($this->template);
        $this->assertSame('site.yml', $stored->playbook, 'nothing is saved');
        $this->assertSame($this->template->name, $stored->name, 'not even the rename sent with it');
        $this->assertSame($audits, $this->templateAuditCount());
        $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY, $refusal);
    }

    /**
     * Regression: an operator created a job template on an inventory with
     * an approval rule. On a default install (no team-restricted project, so
     * no inventory filter) too: a 403, never an SQL error.
     */
    public function testRegressionAnOperatorGaveANewTemplateAGatedInventory(): void
    {
        $this->assertDefaultInstall((int)$this->operator->id);
        $gated = $this->inventoryWithRule((int)$this->admin->id, $this->rule);
        $name = 'wil-created-' . uniqid('', true);
        $this->loginAs($this->operator);
        $this->setPost(['JobTemplate' => $this->formFields($name, (int)$this->template->project_id, (int)$gated->id)]);

        $refusal = $this->refusalOf(fn (): mixed => $this->makeController()->actionCreate());

        $this->assertNotNull($refusal, 'a 403');
        $this->assertFalse(JobTemplate::find()->where(['name' => $name])->exists(), 'nothing is saved');
        $this->assertSame(GatedJobTemplateLock::GATED_INVENTORY_REFUSED, $refusal);
    }

    /**
     * Regression: an operator re-pointed a job template without a rule to an
     * inventory with one. On a default install too.
     */
    public function testRegressionAnOperatorRepointedAnUngatedTemplateToAGatedInventory(): void
    {
        $this->assertDefaultInstall((int)$this->operator->id);
        $template = $this->ungatedTemplate((int)$this->admin->id);
        $gated = $this->inventoryWithRule((int)$this->admin->id, $this->rule);
        $this->loginAs($this->operator);
        $this->setPost(['JobTemplate' => ['inventory_id' => (string)$gated->id], JobTemplateController::KEEP_CREDENTIALS => '1']);

        $refusal = $this->refusalOf(fn (): mixed => $this->makeController()->actionUpdate((int)$template->id));

        $this->assertNotNull($refusal, 'a 403');
        $this->assertSame((int)$template->inventory_id, (int)$this->reloaded($template)->inventory_id, 'nothing is saved');
        $this->assertSame(GatedJobTemplateLock::GATED_INVENTORY_REFUSED, $refusal);
    }

    /**
     * Regression: an operator ungated a job template by re-pointing it from
     * its gated inventory to one without a rule.
     */
    public function testRegressionAnOperatorUngatedATemplateByRepointingIt(): void
    {
        $ungated = $this->createInventory((int)$this->admin->id);
        $this->loginAs($this->operator);
        $this->setPost(['JobTemplate' => ['inventory_id' => (string)$ungated->id], JobTemplateController::KEEP_CREDENTIALS => '1']);

        $refusal = $this->refusalOf(fn (): mixed => $this->makeController()->actionUpdate((int)$this->template->id));

        $this->assertNotNull($refusal, 'a 403');
        $this->assertSame((int)$this->template->inventory_id, (int)$this->reloaded($this->template)->inventory_id, 'nothing is saved');
        $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY, $refusal);
    }

    // -- Regressions: one rule per launch ------------------------------------------------

    /**
     * Regression: an admin gave a job template a rule of its own other than
     * its inventory's; the form showed no error and the save went through.
     */
    public function testRegressionAnAdminGaveATemplateARuleOtherThanItsInventorys(): void
    {
        $this->loginAs($this->admin);
        $this->setPost(['JobTemplate' => ['approval_rule_id' => (string)$this->otherRule->id], JobTemplateController::KEEP_CREDENTIALS => '1']);
        $ctrl = $this->makeController();

        $result = $ctrl->actionUpdate((int)$this->template->id);

        $this->assertSame('rendered:form', $result, 'the form again, no redirect');
        $this->assertNull($ctrl->capturedRedirect);
        $this->assertNull($this->reloaded($this->template)->approval_rule_id, 'nothing is saved');
        $model = $ctrl->capturedParams['model'] ?? null;
        $this->assertInstanceOf(JobTemplate::class, $model);
        $this->assertSame([$this->rulesDiffer((int)$this->template->inventory_id, $this->rule)], $model->getErrors('approval_rule_id'));
    }

    /**
     * Regression: an admin re-pointed a job template with a rule of its own
     * to an inventory with another rule.
     */
    public function testRegressionAnAdminRepointedATemplateToAnInventoryWithAnotherRule(): void
    {
        $template = $this->withOwnRule($this->ungatedTemplate((int)$this->admin->id), $this->otherRule);
        $gated = $this->inventoryWithRule((int)$this->admin->id, $this->rule);
        $this->loginAs($this->admin);
        $this->setPost(['JobTemplate' => ['inventory_id' => (string)$gated->id], JobTemplateController::KEEP_CREDENTIALS => '1']);
        $ctrl = $this->makeController();

        $result = $ctrl->actionUpdate((int)$template->id);

        $this->assertSame('rendered:form', $result, 'the form again, no redirect');
        $this->assertSame((int)$template->inventory_id, (int)$this->reloaded($template)->inventory_id, 'nothing is saved');
        $model = $ctrl->capturedParams['model'] ?? null;
        $this->assertInstanceOf(JobTemplate::class, $model);
        $this->assertSame([$this->rulesDiffer((int)$gated->id, $this->rule)], $model->getErrors('approval_rule_id'));
    }

    public function testAnAdminCreatesATemplateWithItsInventorysRuleButNotAnother(): void
    {
        $gated = $this->inventoryWithRule((int)$this->admin->id, $this->rule);
        $this->loginAs($this->admin);
        $name = 'wil-admin-' . uniqid('', true);
        $fields = $this->formFields($name, (int)$this->template->project_id, (int)$gated->id);

        $this->setPost(['JobTemplate' => $fields + ['approval_rule_id' => (string)$this->otherRule->id]]);
        $ctrl = $this->makeController();
        $this->assertSame('rendered:form', $ctrl->actionCreate());
        $this->assertFalse(JobTemplate::find()->where(['name' => $name])->exists(), 'another rule: nothing is saved');

        $this->setPost(['JobTemplate' => $fields + ['approval_rule_id' => (string)$this->rule->id]]);
        $ctrl = $this->makeController();
        $ctrl->actionCreate();
        $created = JobTemplate::findOne(['name' => $name]);
        $this->assertNotNull($created, 'the same rule: saved');
        $this->assertSame(['view', 'id' => $created->id], $ctrl->capturedRedirect);
        $this->assertSame((int)$this->rule->id, (int)$created->approval_rule_id);
    }

    // -- What stays open -----------------------------------------------------------------

    /**
     * The locked form posts the name, the description and KEEP_CREDENTIALS:
     * every other value stays as stored.
     */
    public function testAnOperatorRenamesATemplateGatedThroughItsInventory(): void
    {
        $this->loginAs($this->operator);
        $name = 'wil-renamed-' . uniqid('', true);
        $this->setPost(['JobTemplate' => ['name' => $name, 'description' => 'Renamed.'], JobTemplateController::KEEP_CREDENTIALS => '1']);
        $ctrl = $this->makeController();

        $ctrl->actionUpdate((int)$this->template->id);

        $this->assertSame(['view', 'id' => $this->template->id], $ctrl->capturedRedirect);
        $stored = $this->reloaded($this->template);
        $this->assertSame($name, $stored->name);
        $this->assertSame('Renamed.', $stored->description);
        $this->assertSame((int)$this->template->inventory_id, (int)$stored->inventory_id);
    }

    public function testAnAdminChangesWhatATemplateGatedThroughItsInventoryRuns(): void
    {
        $other = $this->createInventory((int)$this->admin->id);
        $this->loginAs($this->admin);
        $this->setPost(['JobTemplate' => ['playbook' => 'admin.yml', 'inventory_id' => (string)$other->id], JobTemplateController::KEEP_CREDENTIALS => '1']);
        $ctrl = $this->makeController();

        $ctrl->actionUpdate((int)$this->template->id);

        $this->assertSame(['view', 'id' => $this->template->id], $ctrl->capturedRedirect);
        $stored = $this->reloaded($this->template);
        $this->assertSame('admin.yml', $stored->playbook);
        $this->assertSame((int)$other->id, (int)$stored->inventory_id);
    }

    /**
     * A template whose stored pair differs (its own rule and another one of
     * its inventory) can still be renamed: by an admin through the whole
     * form, which posts the stored inventory and rule as text, and by an
     * operator through the locked form.
     */
    public function testATemplateWhoseStoredPairDiffersCanStillBeRenamed(): void
    {
        $template = $this->withOwnRule($this->template, $this->otherRule);
        foreach (['admin' => $this->admin, 'operator' => $this->operator] as $role => $user) {
            $this->loginAs($user);
            $name = 'wil-pair-' . $role . '-' . uniqid('', true);
            $fields = $role === 'admin'
                ? $this->formFields($name, (int)$template->project_id, (int)$template->inventory_id) + ['approval_rule_id' => (string)$this->otherRule->id]
                : ['name' => $name];
            $this->setPost(['JobTemplate' => $fields, JobTemplateController::KEEP_CREDENTIALS => '1']);
            $ctrl = $this->makeController();

            $ctrl->actionUpdate((int)$template->id);

            $this->assertSame(['view', 'id' => $template->id], $ctrl->capturedRedirect, $role);
            $this->assertSame($name, $this->reloaded($template)->name, $role);
        }
    }

    public function testARuleChangeIsStillRefusedFirst(): void
    {
        $gated = $this->inventoryWithRule((int)$this->admin->id, $this->rule);
        $template = $this->ungatedTemplate((int)$this->admin->id);
        $this->loginAs($this->operator);
        $this->setPost(['JobTemplate' => ['approval_rule_id' => (string)$this->rule->id, 'inventory_id' => (string)$gated->id]]);

        $this->assertSame(
            ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED,
            $this->refusalOf(fn (): mixed => $this->makeController()->actionUpdate((int)$template->id))
        );
    }

    // -- Inventories the operator may not see ------------------------------------------

    /**
     * No existence oracle: an inventory of a project the operator's teams
     * do not have shows the form again with "does not exist", whether it has
     * a rule or not; one of a project the team only views is refused.
     */
    public function testAHiddenGatedInventoryDoesNotExistAndAViewedOneIsRefused(): void
    {
        $scope = $this->teamScope();
        $hidden = $this->inventoryWithRule((int)$scope['admin']->id, $this->rule, (int)$scope['foreign']->project_id);
        $viewed = $this->inventoryWithRule((int)$scope['admin']->id, $this->rule, (int)$scope['viewed']->project_id);
        $own = $scope['own'];
        $this->loginAs($scope['member']);

        $this->setPost(['JobTemplate' => ['inventory_id' => (string)$hidden->id], JobTemplateController::KEEP_CREDENTIALS => '1']);
        $ctrl = $this->makeController();
        $this->assertSame('rendered:form', $ctrl->actionUpdate((int)$own->id), 'an update: no 403');
        $this->assertSame(['inventory_id' => 'The selected inventory does not exist.'], $this->modelOf($ctrl)->getFirstErrors());

        $name = 'wil-hidden-' . uniqid('', true);
        $this->setPost(['JobTemplate' => $this->formFields($name, (int)$own->project_id, (int)$hidden->id)]);
        $ctrl = $this->makeController();
        $this->assertSame('rendered:form', $ctrl->actionCreate(), 'a create: no 403');
        $this->assertSame(['inventory_id' => 'The selected inventory does not exist.'], $this->modelOf($ctrl)->getFirstErrors());
        $this->assertFalse(JobTemplate::find()->where(['name' => $name])->exists());

        $this->setPost(['JobTemplate' => ['inventory_id' => (string)$viewed->id], JobTemplateController::KEEP_CREDENTIALS => '1']);
        $this->assertSame(
            GatedJobTemplateLock::GATED_INVENTORY_REFUSED,
            $this->refusalOf(fn (): mixed => $this->makeController()->actionUpdate((int)$own->id))
        );
        $this->assertSame((int)$own->inventory_id, (int)$this->reloaded($own)->inventory_id);
    }

    // -- Clones --------------------------------------------------------------------------

    /**
     * An operator's clone keeps the inventory (copying is not choosing), so
     * the form it opens is locked.
     */
    public function testAnOperatorsCloneKeepsTheGatedInventoryAndOpensALockedForm(): void
    {
        $this->loginAs($this->operator);
        $ctrl = $this->makeController();
        $ctrl->actionClone((int)$this->template->id);
        $clone = JobTemplate::findOne(['name' => $this->template->name . ' (copy)']);
        $this->assertNotNull($clone);
        $this->assertSame(['update', 'id' => $clone->id], $ctrl->capturedRedirect);
        $this->assertSame((int)$this->template->inventory_id, (int)$clone->inventory_id);
        $this->assertNull($clone->approval_rule_id);

        $form = $this->makeController();
        $form->actionUpdate((int)$clone->id);
        $this->assertTrue($form->capturedParams['changeLocked'] ?? null, 'the form is locked');

        $html = $this->rendered(fn (JobTemplateController $c): mixed => $c->actionUpdate((int)$clone->id));
        $this->assertStringContainsString('id="job-template-locked-notice"', $html);
        $this->assertStringContainsString('<fieldset id="job-template-run-fields" disabled>', $html);
    }

    // -- helpers ------------------------------------------------------------------------

    /**
     * The message of the ForbiddenHttpException $action throws, or null when
     * it throws none.
     *
     * @param callable(): mixed $action
     */
    private function refusalOf(callable $action): ?string
    {
        try {
            $action();
        } catch (ForbiddenHttpException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * The fields of the template form for a new template.
     *
     * @return array<string, string>
     */
    private function formFields(string $name, int $projectId, int $inventoryId): array
    {
        return [
            'name' => $name,
            'project_id' => (string)$projectId,
            'inventory_id' => (string)$inventoryId,
            'playbook' => 'site.yml',
            'runner_group_id' => (string)$this->createRunnerGroup((int)$this->admin->id)->id,
            'verbosity' => '0',
            'forks' => '5',
            'timeout_minutes' => '120',
            'become' => '0',
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
     * @param JobTemplateController&object{capturedParams: array<string, mixed>} $ctrl
     */
    private function modelOf(JobTemplateController $ctrl): JobTemplate
    {
        $model = $ctrl->capturedParams['model'] ?? null;
        $this->assertInstanceOf(JobTemplate::class, $model);

        return $model;
    }

    /**
     * The page $render renders, with the real views and without the layout.
     *
     * @param callable(JobTemplateController): mixed $render
     */
    private function rendered(callable $render): string
    {
        $components = \Yii::$app->getComponents(true);
        $originals = ['view' => $components['view'] ?? null, 'assetManager' => $components['assetManager'] ?? null];
        $previousController = \Yii::$app->controller;
        \Yii::$app->set('assetManager', new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']));
        \Yii::$app->set('view', new View());
        $ctrl = new class ('job-template', $this->app()) extends JobTemplateController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };
        \Yii::$app->controller = $ctrl;
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setUrl('/job-template/update');
        try {
            $html = $render($ctrl);
        } finally {
            \Yii::$app->controller = $previousController;
            foreach ($originals as $id => $definition) {
                \Yii::$app->set($id, $definition);
            }
        }
        $this->assertIsString($html);

        return $html;
    }

    private function app(): \yii\base\Module
    {
        $this->assertNotNull(\Yii::$app);

        return \Yii::$app;
    }

    /**
     * @return JobTemplateController&object{capturedRedirect: mixed, capturedParams: array<string, mixed>}
     */
    private function makeController(): JobTemplateController
    {
        return new class ('job-template', $this->app()) extends JobTemplateController {
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

            /**
             * @param array<mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                $this->capturedRedirect = $url;
                return new Response();
            }
        };
    }
}
