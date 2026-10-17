<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\DbErrorSummary;
use app\components\SurveyDefinition;
use app\controllers\JobTemplateController;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\User;
use app\services\JobTemplateRuleAssignment;
use app\services\LintService;
use app\tests\integration\RestoresComponents;
use app\tests\integration\services\TemplateInventoryLockFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\base\Model;
use yii\web\Response;

/**
 * The job template form when another request changes what the save stores
 * while it runs, between the template's validation and its save's
 * transaction (JobTemplateRuleAssignment::persistVetoes()): an inventory
 * deleted meanwhile shows the form again with "The selected inventory does
 * not exist." on the inventory, never the foreign key's server error; an own
 * rule another admin gave the template meanwhile meets the rule of the
 * inventory chosen, and the form shows RULES_DIFFER. Nothing is saved.
 */
class JobTemplateControllerSaveRaceTest extends WebControllerTestCase
{
    use RestoresComponents;
    use TeamScopeFixtures;
    use TemplateInventoryLockFixtures;

    private User $admin;

    /** The handler of JobTemplate's EVENT_AFTER_VALIDATE a test attached; tearDown() detaches it. */
    private ?\Closure $afterValidate = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Before any write: tearDown() puts back only what was swapped here.
        $this->swapComponent('lintService', new class () extends LintService {
            public function runForTemplate(JobTemplate $template): void
            {
            }
        });
        $this->admin = $this->createUserWithRole('web-save-race-admin', 'admin');
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        if ($this->afterValidate !== null) {
            Event::off(JobTemplate::class, Model::EVENT_AFTER_VALIDATE, $this->afterValidate);
            $this->afterValidate = null;
        }
        parent::tearDown();
    }

    /**
     * Regression (2.12.0 review, LOCKS-5): the edit form re-pointing a
     * template to an inventory deleted while the request ran ended in a
     * server error: the foreign key refused the UPDATE.
     */
    public function testRegressionARepointToAnInventoryDeletedMeanwhileEndedInAServerError(): void
    {
        $adminId = (int)$this->admin->id;
        $template = $this->ungatedTemplate($adminId);
        $target = $this->createInventory($adminId);
        $this->afterValidation(static function () use ($target): void {
            Inventory::deleteAll(['id' => $target->id]);
        });
        $this->loginAs($this->admin);
        $this->setPost(['JobTemplate' => ['inventory_id' => (string)$target->id], JobTemplateController::KEEP_CREDENTIALS => '1']);
        $ctrl = $this->makeController();

        $result = $this->pageOf(fn (): mixed => $ctrl->actionUpdate((int)$template->id));

        $this->assertSame('rendered:form', $result, 'the form again, no server error');
        $this->assertNull($ctrl->capturedRedirect);
        $this->assertSame((int)$template->inventory_id, (int)$this->reloaded($template)->inventory_id, 'nothing is saved');
        $this->assertSame([$this->notFound()], $this->modelOf($ctrl)->getErrors('inventory_id'));
    }

    /**
     * Regression (2.12.0 review, LOCKS-5): the create form on an inventory
     * deleted while the request ran ended in a server error: the foreign key
     * refused the INSERT.
     */
    public function testRegressionACreateOnAnInventoryDeletedMeanwhileEndedInAServerError(): void
    {
        $adminId = (int)$this->admin->id;
        $target = $this->createInventory($adminId);
        $name = 'web-save-race-' . uniqid('', true);
        $this->afterValidation(static function () use ($target): void {
            Inventory::deleteAll(['id' => $target->id]);
        });
        $this->loginAs($this->admin);
        $this->setPost(['JobTemplate' => $this->formFields($name, (int)$this->createProject($adminId)->id, (int)$target->id)]);
        $ctrl = $this->makeController();

        $result = $this->pageOf(fn (): mixed => $ctrl->actionCreate());

        $this->assertSame('rendered:form', $result, 'the form again, no server error');
        $this->assertFalse(JobTemplate::find()->where(['name' => $name])->exists(), 'nothing is saved');
        $this->assertSame([$this->notFound()], $this->modelOf($ctrl)->getErrors('inventory_id'));
    }

    /**
     * Regression (2.12.0 review, LOCKS-2): the edit form re-pointing a
     * template to an inventory with an approval rule, while another admin
     * gave the template another rule of its own, saved both rules; every
     * launch of the template was then refused.
     */
    public function testRegressionARepointMetAnOwnRuleGivenMeanwhile(): void
    {
        $adminId = (int)$this->admin->id;
        $inventoryRule = $this->createApprovalRule($adminId);
        $ownRule = $this->createApprovalRule($adminId);
        $template = $this->ungatedTemplate($adminId);
        $gated = $this->inventoryWithRule($adminId, $inventoryRule);
        $this->afterValidation(static function () use ($template, $ownRule): void {
            JobTemplate::updateAll(['approval_rule_id' => $ownRule->id], ['id' => $template->id]);
        });
        $this->loginAs($this->admin);
        $this->setPost(['JobTemplate' => ['inventory_id' => (string)$gated->id], JobTemplateController::KEEP_CREDENTIALS => '1']);
        $ctrl = $this->makeController();

        $result = $this->pageOf(fn (): mixed => $ctrl->actionUpdate((int)$template->id));

        $this->assertSame('rendered:form', $result, 'the form again, no redirect');
        $stored = $this->reloaded($template);
        $this->assertSame((int)$template->inventory_id, (int)$stored->inventory_id, 'nothing is saved');
        $this->assertSame((int)$ownRule->id, (int)$stored->approval_rule_id, "the other admin's rule stays");
        $this->assertSame([$this->rulesDiffer($gated, $inventoryRule)], $this->modelOf($ctrl)->getErrors('approval_rule_id'));
    }

    // -- helpers ------------------------------------------------------------------------

    /**
     * Runs $handler after every validation of a job template, until the
     * test ends: the moment between the template's validation and its
     * save's transaction.
     */
    private function afterValidation(\Closure $handler): void
    {
        $this->afterValidate = $handler;
        Event::on(JobTemplate::class, Model::EVENT_AFTER_VALIDATE, $handler);
    }

    /**
     * What $action renders; for a database error it throws, the server
     * error the error handler shows, as the error's class and codes
     * (DbErrorSummary), never its text.
     *
     * @param callable(): mixed $action
     */
    private function pageOf(callable $action): mixed
    {
        try {
            return $action();
        } catch (\yii\db\Exception $e) {
            return 'server error: ' . implode(' ', DbErrorSummary::of($e));
        }
    }

    /**
     * JobTemplateRuleAssignment::INVENTORY_NOT_FOUND, read when the page is
     * checked.
     */
    private function notFound(): string
    {
        return JobTemplateRuleAssignment::INVENTORY_NOT_FOUND;
    }

    private function rulesDiffer(Inventory $inventory, ApprovalRule $rule): string
    {
        return sprintf(JobTemplateRuleAssignment::RULES_DIFFER, SurveyDefinition::quote((string)$inventory->name), SurveyDefinition::quote((string)$rule->name));
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
     * @return JobTemplateController&object{capturedRedirect: mixed, capturedParams: array<string, mixed>}
     */
    private function makeController(): JobTemplateController
    {
        $this->assertNotNull(\Yii::$app);

        return new class ('job-template', \Yii::$app) extends JobTemplateController {
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
