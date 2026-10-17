<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\DbErrorSummary;
use app\components\SurveyDefinition;
use app\controllers\api\v1\JobTemplatesController;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\User;
use app\services\JobTemplateRuleAssignment;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\TemplateInventoryLockFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\base\Model;

/**
 * POST and PUT /api/v1/job-templates when another request changes what the
 * save stores while it runs, between the template's validation and its
 * save's transaction (JobTemplateRuleAssignment::persistVetoes()): an
 * inventory deleted meanwhile answers 422 "The selected inventory does not
 * exist.", never the foreign key's 500; an own rule another admin gave the
 * template meanwhile meets the rule of the inventory the PUT names, 422
 * RULES_DIFFER. Nothing is written.
 */
class JobTemplatesControllerSaveRaceTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use TemplateInventoryLockFixtures;

    private JobTemplatesController $ctrl;
    private User $admin;

    /** The handler of JobTemplate's EVENT_AFTER_VALIDATE a test attached; tearDown() detaches it. */
    private ?\Closure $afterValidate = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotNull(\Yii::$app);
        $this->ctrl = new JobTemplatesController('api/v1/job-templates', \Yii::$app);
        $this->admin = $this->createUserWithRole('api-save-race-admin', 'admin');
    }

    protected function tearDown(): void
    {
        if ($this->afterValidate !== null) {
            Event::off(JobTemplate::class, Model::EVENT_AFTER_VALIDATE, $this->afterValidate);
            $this->afterValidate = null;
        }
        parent::tearDown();
    }

    /**
     * Regression (2.12.0 review, LOCKS-5): PUT re-pointing a template to an
     * inventory deleted while the request ran answered 500: the foreign key
     * refused the UPDATE.
     */
    public function testRegressionARepointToAnInventoryDeletedMeanwhileAnswered500(): void
    {
        $adminId = (int)$this->admin->id;
        $template = $this->ungatedTemplate($adminId);
        $target = $this->createInventory($adminId);
        $this->afterValidation(static function () use ($target): void {
            Inventory::deleteAll(['id' => $target->id]);
        });
        $this->loginAs($this->admin);
        $this->setBody(['inventory_id' => (int)$target->id]);

        $result = $this->answerOf(fn (): array => $this->ctrl->actionUpdate((int)$template->id));

        $this->assertSame(422, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertSame((int)$template->inventory_id, (int)$this->reloaded($template)->inventory_id, 'nothing is written');
        $this->assertSame(['error' => ['message' => $this->notFound()]], $result);
    }

    /**
     * Regression (2.12.0 review, LOCKS-5): POST of a template on an
     * inventory deleted while the request ran answered 500: the foreign key
     * refused the INSERT.
     */
    public function testRegressionACreateOnAnInventoryDeletedMeanwhileAnswered500(): void
    {
        $adminId = (int)$this->admin->id;
        $target = $this->createInventory($adminId);
        $name = 'api-save-race-' . uniqid('', true);
        $this->afterValidation(static function () use ($target): void {
            Inventory::deleteAll(['id' => $target->id]);
        });
        $this->loginAs($this->admin);
        $this->setBody($this->createBody($name, (int)$this->createProject($adminId)->id, (int)$target->id));
        $audits = $this->templateAuditCount();

        $result = $this->answerOf(fn (): array => $this->ctrl->actionCreate());

        $this->assertSame(422, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertFalse(JobTemplate::find()->where(['name' => $name])->exists(), 'nothing is written');
        $this->assertSame($audits, $this->templateAuditCount());
        $this->assertSame(['error' => ['message' => $this->notFound()]], $result);
    }

    /**
     * Regression (2.12.0 review, LOCKS-2): PUT re-pointing a template to an
     * inventory with an approval rule, while another admin gave the template
     * another rule of its own, stored both rules (200); every launch of the
     * template was then refused.
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
        $this->setBody(['inventory_id' => (int)$gated->id]);

        $result = $this->answerOf(fn (): array => $this->ctrl->actionUpdate((int)$template->id));

        $this->assertSame(422, \Yii::$app->response->statusCode, (string)json_encode($result));
        $stored = $this->reloaded($template);
        $this->assertSame((int)$template->inventory_id, (int)$stored->inventory_id, 'nothing is written');
        $this->assertSame((int)$ownRule->id, (int)$stored->approval_rule_id, "the other admin's rule stays");
        $this->assertSame(['error' => ['message' => $this->rulesDiffer($gated, $inventoryRule)]], $result);
    }

    /**
     * Regression (2.12.0 review, LOCKS-2), the other order: PUT giving a
     * template a rule of its own, while another admin moved the template to
     * an inventory with another rule, stored both rules (200).
     */
    public function testRegressionAnOwnRuleMetARepointMadeMeanwhile(): void
    {
        $adminId = (int)$this->admin->id;
        $inventoryRule = $this->createApprovalRule($adminId);
        $ownRule = $this->createApprovalRule($adminId);
        $template = $this->ungatedTemplate($adminId);
        $gated = $this->inventoryWithRule($adminId, $inventoryRule);
        $this->afterValidation(static function () use ($template, $gated): void {
            JobTemplate::updateAll(['inventory_id' => $gated->id], ['id' => $template->id]);
        });
        $this->loginAs($this->admin);
        $this->setBody(['approval_rule_id' => (int)$ownRule->id]);

        $result = $this->answerOf(fn (): array => $this->ctrl->actionUpdate((int)$template->id));

        $this->assertSame(422, \Yii::$app->response->statusCode, (string)json_encode($result));
        $stored = $this->reloaded($template);
        $this->assertNull($stored->approval_rule_id, 'nothing is written');
        $this->assertSame((int)$gated->id, (int)$stored->inventory_id, "the other admin's inventory stays");
        $this->assertSame(['error' => ['message' => $this->rulesDiffer($gated, $inventoryRule)]], $result);
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
     * The answer of $action; for a database error it throws, the 500 the
     * error handler answers, with the error's class and codes
     * (DbErrorSummary), never its text.
     *
     * @param callable(): array<string, mixed> $action
     * @return array<string, mixed>
     */
    private function answerOf(callable $action): array
    {
        try {
            return $action();
        } catch (\yii\db\Exception $e) {
            \Yii::$app->response->statusCode = 500;

            return ['error' => ['message' => implode(' ', DbErrorSummary::of($e))]];
        }
    }

    /**
     * JobTemplateRuleAssignment::INVENTORY_NOT_FOUND, read when the answer
     * is checked.
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
     * @return array<string, mixed>
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
}
